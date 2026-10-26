#!/usr/bin/env node
'use strict';

const crypto = require('node:crypto');
const mysql = require('mysql2/promise');

const SSO = 'https://login.eveonline.com';
const ESI = 'https://esi.evetech.net/latest';
const UA = 'TriglavianConsortium-PotchvenFinances/1.0 (self-hosted)';
const REQUIRED_CHARACTER = ['esi-wallet.read_character_wallet.v1', 'esi-assets.read_assets.v1'];
const REQUIRED_CORP = ['esi-wallet.read_corporation_wallets.v1', 'esi-assets.read_corporation_assets.v1'];

function required(name) {
  const value = String(process.env[name] || '').trim();
  if (!value) throw new Error('Missing required runtime setting: ' + name);
  return value;
}
function encryptionKey() {
  const raw = required('TOKEN_ENCRYPTION_KEY');
  if (!/^[a-f\d]{64}$/i.test(raw)) throw new Error('TOKEN_ENCRYPTION_KEY has invalid format');
  return Buffer.from(raw, 'hex');
}
function decrypt(value) {
  if (!value) throw new Error('Missing encrypted credential');
  const parts = value.split('.');
  if (parts.length !== 3) throw new Error('Stored credential has an invalid format');
  const d = crypto.createDecipheriv('aes-256-gcm', encryptionKey(), Buffer.from(parts[0], 'base64'));
  d.setAuthTag(Buffer.from(parts[1], 'base64'));
  return Buffer.concat([d.update(Buffer.from(parts[2], 'base64')), d.final()]).toString();
}
function encrypt(value) {
  const iv = crypto.randomBytes(12);
  const c = crypto.createCipheriv('aes-256-gcm', encryptionKey(), iv);
  const data = Buffer.concat([c.update(value), c.final()]);
  return [iv.toString('base64'), c.getAuthTag().toString('base64'), data.toString('base64')].join('.');
}
function tokenClaims(token) {
  const parts = token.split('.');
  if (parts.length !== 3) throw new Error('EVE SSO returned an unexpected access-token format');
  return JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));
}
function scopesFrom(token, fallback) {
  const claims = tokenClaims(token);
  const raw = claims.scp ?? claims.scope ?? fallback ?? [];
  return Array.isArray(raw) ? raw : String(raw).split(/\s+/).filter(Boolean);
}
async function jsonResponse(response, label) {
  const body = await response.text();
  if (!response.ok) {
    let code = 'HTTP_' + response.status;
    try { code = JSON.parse(body).error || code; } catch {}
    throw new Error(label + ' failed (' + code + ')');
  }
  try { return body ? JSON.parse(body) : null; }
  catch { throw new Error(label + ' returned invalid JSON'); }
}
async function refreshCredential(db, row, basic) {
  const oldRefresh = decrypt(row.refresh_token_ciphertext);
  const response = await fetch(SSO + '/v2/oauth/token', {
    method: 'POST',
    headers: { Authorization: 'Basic ' + basic, 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ grant_type: 'refresh_token', refresh_token: oldRefresh }),
    signal: AbortSignal.timeout(25000)
  });
  const result = await jsonResponse(response, 'SSO refresh for ' + row.display_name);
  if (!result.access_token) throw new Error('SSO did not return an access token for ' + row.display_name);
  const scopes = scopesFrom(result.access_token, result.scope || row.scopes);
  const missing = REQUIRED_CHARACTER.filter(s => !scopes.includes(s));
  if (missing.length) throw new Error(row.display_name + ' missing required character scopes: ' + missing.join(', '));
  const claims = tokenClaims(result.access_token);
  if (claims.exp && claims.exp * 1000 <= Date.now()) throw new Error('SSO returned an expired token for ' + row.display_name);
  const refresh = result.refresh_token || oldRefresh;
  const expires = new Date(Date.now() + Number(result.expires_in || 1200) * 1000);
  await db.execute(`
    UPDATE fin_oauth_credentials
    SET access_token_ciphertext = ?, refresh_token_ciphertext = ?,
        access_token_expires_at = ?, scopes = ?, revoked_at = NULL
    WHERE owner_id = ?
  `, [encrypt(result.access_token), encrypt(refresh), expires, scopes.join(' '), row.owner_id]);
  return { token: result.access_token, scopes, claims };
}
async function esi(path, token) {
  return jsonResponse(await fetch(ESI + path, {
    headers: { Authorization: 'Bearer ' + token, 'User-Agent': UA },
    signal: AbortSignal.timeout(30000)
  }), 'ESI ' + path);
}
async function pages(path, token) {
  const first = await fetch(ESI + path, {
    headers: { Authorization: 'Bearer ' + token, 'User-Agent': UA },
    signal: AbortSignal.timeout(30000)
  });
  const rows = await jsonResponse(first, 'ESI ' + path);
  const all = Array.isArray(rows) ? rows : [];
  const count = Math.max(1, Math.min(1000, Number(first.headers.get('X-Pages') || 1)));
  for (let page = 2; page <= count; page++) {
    const separator = path.includes('?') ? '&' : '?';
    const data = await esi(path + separator + 'page=' + page, token);
    if (Array.isArray(data)) all.push(...data);
  }
  return all;
}
async function ownerById(db, type, eveId) {
  const [rows] = await db.execute(
    'SELECT id, eve_id, display_name, owner_type FROM fin_owners WHERE owner_type = ? AND eve_id = ? AND is_active = 1 LIMIT 1',
    [type, eveId]
  );
  if (!rows.length) throw new Error('No active finance owner row for ' + type + ' EVE ID ' + eveId);
  return rows[0];
}
async function saveWallet(db, ownerId, balance) {
  await db.execute(
    'INSERT INTO fin_wallet_snapshots(owner_id, balance_isk, captured_at) VALUES(?,?,UTC_TIMESTAMP())',
    [ownerId, Number(balance)]
  );
}
async function saveJournal(db, ownerId, entries) {
  for (const entry of entries) {
    const amount = Number(entry.amount || 0);
    await db.execute(`
      INSERT INTO fin_wallet_entries
        (owner_id, source_type, external_id, occurred_at, reference_type, amount_isk,
         description, is_operational_expense, expense_category, raw_payload)
      VALUES (?, 'journal', ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE amount_isk = VALUES(amount_isk),
        raw_payload = VALUES(raw_payload), description = VALUES(description),
        reference_type = VALUES(reference_type), occurred_at = VALUES(occurred_at),
        is_operational_expense = VALUES(is_operational_expense),
        expense_category = VALUES(expense_category)
    `, [
      ownerId, String(entry.id), new Date(entry.date), entry.ref_type || null, amount,
      entry.description || '', amount < 0 && String(entry.ref_type || '').toLowerCase() !== 'player_donation' ? 1 : 0,
      amount < 0 && String(entry.ref_type || '').toLowerCase() !== 'player_donation'
        ? (entry.ref_type || 'uncategorized') : null, JSON.stringify(entry)
    ]);
  }
}
async function saveAssets(db, ownerId, assets) {
  const [result] = await db.execute(
    "INSERT INTO fin_asset_snapshots(owner_id, captured_at, pricing_source) VALUES(?, UTC_TIMESTAMP(), 'not_priced')",
    [ownerId]
  );
  for (const asset of assets) {
    await db.execute(
      'INSERT INTO fin_asset_items(snapshot_id, item_type_id, quantity, location_id, raw_payload) VALUES(?,?,?,?,?)',
      [result.insertId, asset.type_id, asset.quantity, asset.location_id || null, JSON.stringify(asset)]
    );
  }
}
async function syncState(db, ownerId, resource, error = null) {
  const [rows] = await db.execute(
    'SELECT id FROM fin_sync_state WHERE owner_id = ? AND resource_key = ? ORDER BY id DESC LIMIT 1',
    [ownerId, resource]
  );
  if (rows.length) {
    await db.execute(
      'UPDATE fin_sync_state SET last_success_at = ?, last_error = ? WHERE id = ?',
      [error ? null : new Date(), error, rows[0].id]
    );
  } else {
    await db.execute(
      'INSERT INTO fin_sync_state(owner_id, resource_key, last_success_at, last_error) VALUES(?,?,?,?)',
      [ownerId, resource, error ? null : new Date(), error]
    );
  }
}
async function syncCharacter(db, owner, token) {
  const id = Number(owner.eve_id);
  const [balance, journal, assets] = await Promise.all([
    esi('/characters/' + id + '/wallet/', token),
    pages('/characters/' + id + '/wallet/journal/', token),
    pages('/characters/' + id + '/assets/', token)
  ]);
  await saveWallet(db, owner.id, balance);
  await saveJournal(db, owner.id, journal);
  await saveAssets(db, owner.id, assets);
  await syncState(db, owner.id, 'character_wallet', null);
  await syncState(db, owner.id, 'character_assets', null);
  return { character: owner.display_name, journalEntries: journal.length, assets: assets.length };
}
async function syncCorporation(db, owner, token) {
  const scopes = scopesFrom(token, []);
  const missing = REQUIRED_CORP.filter(s => !scopes.includes(s));
  if (missing.length) throw new Error('Selected character lacks corporation scopes: ' + missing.join(', '));
  const corpId = Number(owner.eve_id);
  // Division 3 is the Corp Main Wallet; division 2 is reserved for SRP.
  // Keep the existing snapshot schema for division 3 and store division 2 separately
  // in fin_app_settings so the two balances are never added together.
  const mainDivision = 3;
  const srpDivision = 2;
  const [wallets, journal, allAssets] = await Promise.all([
    esi('/corporations/' + corpId + '/wallets/', token),
    pages('/corporations/' + corpId + '/wallets/' + mainDivision + '/journal/', token),
    // ESI returns corporation assets across all accessible offices/structures.
    // CorpSAG3 is Hangar Division 3; filter by division, not by a single location.
    pages('/corporations/' + corpId + '/assets/', token)
  ]);
  const assetFlagCounts = {};
  const assetLocationTypeCounts = {};
  for (const asset of allAssets) {
    const flag = String(asset.location_flag || '(missing)');
    const locationType = String(asset.location_type || '(missing)');
    assetFlagCounts[flag] = (assetFlagCounts[flag] || 0) + 1;
    assetLocationTypeCounts[locationType] = (assetLocationTypeCounts[locationType] || 0) + 1;
  }
  const assets = allAssets.filter(asset => String(asset.location_flag || '') === 'CorpSAG3');
  if (!Array.isArray(wallets)) throw new Error('Corporation wallet list was not returned by ESI');
  const mainWallet = wallets.find(w => Number(w.division) === mainDivision);
  const srpWallet = wallets.find(w => Number(w.division) === srpDivision);
  if (!mainWallet) throw new Error('Corporation wallet division 1 was not returned by ESI');
  if (!srpWallet) throw new Error('Corporation wallet division 2 was not returned by ESI');
  await saveWallet(db, owner.id, Number(mainWallet.balance));
  await db.execute(
    `INSERT INTO fin_app_settings(setting_key, setting_value, updated_at)
     VALUES('srp_wallet_balance_isk', ?, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)`,
    [String(Number(srpWallet.balance))]
  );
  await saveJournal(db, owner.id, journal);
  await saveAssets(db, owner.id, assets);
  await syncState(db, owner.id, 'corporation_wallets', null);
  await syncState(db, owner.id, 'corporation_assets', null);
  return {
    corporation: owner.display_name,
    corpMainWalletDivision: mainDivision,
    corpMainWalletBalance: Number(mainWallet.balance),
    srpWalletDivision: srpDivision,
    srpWalletBalance: Number(srpWallet.balance),
    journalEntries: journal.length,
    assets: assets.length,
    corporationAssetsScanned: allAssets.length,
    hangar3Assets: assets.length,
    assetFlagCounts,
    assetLocationTypeCounts,
    assetSamples: allAssets.slice(0, 6).map(asset => ({ type_id: asset.type_id, location_flag: asset.location_flag || null, location_type: asset.location_type || null, location_id: asset.location_id || null }))
  };
}
async function reconcileSitePayouts(db, corporationOwnerId) {
  const [characters] = await db.execute(
    "SELECT display_name FROM fin_owners WHERE owner_type = 'character' AND is_active = 1"
  );
  const names = characters.map(c => String(c.display_name));
  if (names.length !== 3) throw new Error('Payout reconciliation requires exactly three active finance characters');

  const [entries] = await db.execute(`
    SELECT e.id, e.occurred_at, e.amount_isk, e.description
    FROM fin_wallet_entries e
    WHERE e.owner_id = ?
      AND e.reference_type = 'corporate_reward_payout'
      AND e.amount_isk = 9000000
    ORDER BY e.occurred_at ASC, e.id ASC
  `, [corporationOwnerId]);

  const payouts = entries.map(e => {
    const description = String(e.description || '');
    const character = names.find(name => description.includes(name));
    return character ? { ...e, character, timestamp: new Date(e.occurred_at).getTime() } : null;
  }).filter(Boolean);

  const groups = [];
  for (const payout of payouts) {
    let group = groups[groups.length - 1];
    const duplicateCharacter = group && group.some(p => p.character === payout.character);
    const tooFarApart = group && (payout.timestamp - group[0].timestamp > 120000);
    if (!group || duplicateCharacter || tooFarApart) {
      group = [];
      groups.push(group);
    }
    group.push(payout);
  }

  // Rebuild only ESI-derived site records so late-arriving payouts repair incomplete groups
  // on the next sync. Manual records and all wallet history are preserved.
  await db.beginTransaction();
  try {
    await db.execute(`DELETE p FROM fin_site_payouts p
      JOIN fin_site_events s ON s.id = p.site_event_id WHERE s.source = 'esi'`);
    await db.execute(`DELETE o FROM fin_srp_obligations o
      JOIN fin_site_events s ON s.id = o.site_event_id WHERE s.source = 'esi'`);
    await db.execute("DELETE FROM fin_site_events WHERE source = 'esi'");

    let successfulSites = 0;
    let incompleteGroups = 0;
    for (const group of groups) {
      const distinctCharacters = new Set(group.map(p => p.character));
      const successful = group.length === 3 && distinctCharacters.size === 3;
      const total = group.reduce((sum, p) => sum + Number(p.amount_isk), 0);
      const occurredAt = group[0].occurred_at;
      const note = successful
        ? 'Automatically matched three 9,000,000 ISK corporate reward payouts.'
        : 'Partial corporate reward: ' + group.length + ' of 3 payouts matched; transfer ' +
          Math.max(0, 27000000 - total).toFixed(2) + ' ISK to the SRP fund.';
      const [inserted] = await db.execute(`
        INSERT INTO fin_site_events
          (occurred_at, status, payout_count, total_payout_isk, revenue_credit_isk, source, review_note)
        VALUES (?, ?, ?, ?, ?, 'esi', ?)
      `, [occurredAt, successful ? 'successful' : 'needs_review', group.length, total,
        successful ? 45000000 : 0, note]);
      for (const payout of group) {
        await db.execute(`
          INSERT INTO fin_site_payouts(site_event_id, wallet_entry_id, payout_amount_isk)
          VALUES (?, ?, ?)
        `, [inserted.insertId, payout.id, payout.amount_isk]);
      }
      if (successful) {
        successfulSites++;
      } else {
        incompleteGroups++;
        await db.execute(`
          INSERT INTO fin_srp_obligations(site_event_id, amount_isk, status, note)
          VALUES (?, ?, 'pending_transfer', ?)
        `, [inserted.insertId, Math.max(0, 27000000 - total), note]);
      }
    }
    await db.commit();
    return { matchedPayouts: payouts.length, successfulSites, incompleteGroups };
  } catch (error) {
    await db.rollback();
    throw error;
  }
}

async function main() {
  const db = await mysql.createConnection({
    host: required('FIN_DB_HOST'),
    port: Number(process.env.FIN_DB_PORT || 3306),
    database: required('FIN_DB_NAME'),
    user: required('FIN_DB_USER'),
    password: required('FIN_DB_PASSWORD'),
    connectTimeout: 10000
  });
  try {
    const [owners] = await db.execute(`
      SELECT o.id AS owner_id, o.eve_id, o.display_name, o.owner_type,
             c.access_token_ciphertext, c.refresh_token_ciphertext,
             c.scopes, c.revoked_at
      FROM fin_owners o
      JOIN fin_oauth_credentials c ON c.owner_id = o.id
      WHERE o.owner_type = 'character' AND o.is_active = 1 AND c.revoked_at IS NULL
      ORDER BY o.id
    `);
    if (owners.length !== 3) throw new Error('Expected 3 active authorized finance characters; found ' + owners.length);
    const corporation = await ownerById(db, 'corporation', Number(process.env.FIN_CORP_EVE_ID || 98843282));
    const basic = Buffer.from(required('EVE_CLIENT_ID') + ':' + required('EVE_CLIENT_SECRET')).toString('base64');
    const refreshed = [];
    for (const owner of owners) {
      const credential = await refreshCredential(db, owner, basic);
      const fullOwner = { id: owner.owner_id, eve_id: owner.eve_id, display_name: owner.display_name };
      const result = await syncCharacter(db, fullOwner, credential.token);
      console.log(JSON.stringify({ ok: true, ...result }));
      refreshed.push({ owner: fullOwner, token: credential.token, scopes: credential.scopes });
    }
    const corpAuth = refreshed.find(r => REQUIRED_CORP.every(s => r.scopes.includes(s)));
    if (!corpAuth) throw new Error('None of the three characters has both corporation wallet and asset scopes');
    console.log(JSON.stringify({
      diagnostic: 'corporation_asset_authorization',
      selectedCharacter: corpAuth.owner.display_name,
      selectedCharacterEveId: Number(corpAuth.owner.eve_id),
      targetCorporationEveId: Number(corporation.eve_id),
      requiredCorpScopesPresent: REQUIRED_CORP.every(s => corpAuth.scopes.includes(s))
    }));
    const corpResult = await syncCorporation(db, corporation, corpAuth.token);
    console.log(JSON.stringify({ ok: true, ...corpResult }));
    const reconciliation = await reconcileSitePayouts(db, corporation.id);
    console.log(JSON.stringify({ ok: true, payoutReconciliation: reconciliation }));
    console.log(JSON.stringify({ ok: true, syncedCharacters: owners.length, corporation: corporation.display_name }));
  } finally {
    await db.end();
  }
}
main().catch(error => {
  console.error('Potchven finance sync failed:', String(error.message || 'unknown error').replace(/[\r\n]/g, ' ').slice(0, 350));
  process.exitCode = 1;
});
