#!/usr/bin/env node
'use strict';
const fs = require('node:fs');
const path = require('node:path');
require('dotenv').config({path:process.env.DOTENV_CONFIG_PATH || path.resolve(process.cwd(), '.env')});
const mysql = require('mysql2/promise');

const SSO = (process.env.EVE_SSO_URL || 'https://login.eveonline.com').replace(/\/$/, '');
const ESI = (process.env.EVE_ESI_URL || 'https://esi.evetech.net/latest').replace(/\/$/, '');
const STATE_FILE = process.env.FIN_TOKEN_STATE_FILE || path.join(__dirname, '.finance-token-state.json');
const UA = 'TriglavianConsortium-PotchvenFinances/1.0 (self-hosted)';
function req(k) { const v = String(process.env[k] || '').trim(); if (!v) throw new Error('Missing environment variable: ' + k); return v; }

function ownersFromEnv() {
  const owners = [];
  for (let n = 1; n <= 3; n++) {
    const id = String(process.env['FIN_CHAR' + n + '_EVE_ID'] || '').trim();
    const refresh = String(process.env['FIN_CHAR' + n + '_REFRESH_TOKEN'] || '').trim();
    if (!id || !refresh) throw new Error('Set FIN_CHAR' + n + '_EVE_ID and FIN_CHAR' + n + '_REFRESH_TOKEN.');
    owners.push({key:'char' + n, type:'character', eveId:Number(id), name:String(process.env['FIN_CHAR' + n + '_NAME'] || ('Character ' + n)), refresh});
  }
  const id = String(process.env.FIN_CORP_EVE_ID || '').trim();
  const refresh = String(process.env.FIN_CORP_REFRESH_TOKEN || '').trim();
  if (!id || !refresh) throw new Error('Set FIN_CORP_EVE_ID and FIN_CORP_REFRESH_TOKEN.');
  owners.push({key:'corp', type:'corporation', eveId:Number(id), name:String(process.env.FIN_CORP_NAME || ('Corporation ' + id)), refresh});
  if (owners.some(o => !Number.isSafeInteger(o.eveId) || o.eveId < 1)) throw new Error('EVE IDs must be positive integers.');
  return owners;
}
async function bodyJson(r, label) {
  const body = await r.text();
  if (!r.ok) throw new Error(label + ' returned HTTP ' + r.status + ': ' + body.slice(0, 300));
  try { return body ? JSON.parse(body) : null; } catch { throw new Error(label + ' returned invalid JSON'); }
}
function persistState(state) {\n  const temp = STATE_FILE + '.tmp';\n  fs.mkdirSync(path.dirname(STATE_FILE), {recursive:true});\n  fs.writeFileSync(temp,JSON.stringify(state,null,2) + '\\n',{mode:0o600});\n  fs.renameSync(temp,STATE_FILE);\n  try { fs.chmodSync(STATE_FILE,0o600); } catch {}\n}\nasync function accessToken(owner, state) {
  const refresh = (state[owner.key] && state[owner.key].refresh) || owner.refresh;
  const basic = Buffer.from(req('EVE_CLIENT_ID') + ':' + req('EVE_CLIENT_SECRET')).toString('base64');
  const r = await fetch(SSO + '/v2/oauth/token', {
    method:'POST', headers:{Authorization:'Basic ' + basic, 'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({grant_type:'refresh_token', refresh_token:refresh})
  });
  const t = await bodyJson(r, 'SSO refresh for ' + owner.name);
  if (!t.access_token) throw new Error('No access token returned for ' + owner.name);
  state[owner.key] = {refresh:t.refresh_token || refresh, updatedAt:new Date().toISOString()};
  return t.access_token;
}
async function esi(p, token) {
  return bodyJson(await fetch(ESI + p, {headers:{Authorization:'Bearer ' + token, 'User-Agent':UA}}), 'ESI ' + p);
}
async function pages(p, token) {
  const first = await fetch(ESI + p, {headers:{Authorization:'Bearer ' + token, 'User-Agent':UA}});
  const rows = await bodyJson(first, 'ESI ' + p);
  const all = Array.isArray(rows) ? rows : [];
  const count = Number(first.headers.get('X-Pages') || 1);
  for (let i = 2; i <= count; i++) {
    const sep = p.includes('?') ? '&' : '?';
    const data = await esi(p + sep + 'page=' + i, token);
    if (Array.isArray(data)) all.push(...data);
  }
  return all;
}
async function ownerId(db, o) {
  await db.execute('INSERT INTO fin_owners(owner_type,eve_id,display_name,is_active) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),is_active=1', [o.type,o.eveId,o.name]);
  const [r] = await db.execute('SELECT id FROM fin_owners WHERE owner_type=? AND eve_id=? LIMIT 1', [o.type,o.eveId]);
  if (!r.length) throw new Error('Could not find finance owner row for ' + o.name);
  return Number(r[0].id);
}
async function journalUpsert(db, id, entries) {
  for (const e of entries) {
    const amount = Number(e.amount || 0);
    await db.execute("INSERT INTO fin_wallet_entries(owner_id,source_type,external_id,occurred_at,reference_type,amount_isk,description,is_operational_expense,expense_category,raw_payload) VALUES(?,'journal',?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE amount_isk=VALUES(amount_isk),raw_payload=VALUES(raw_payload),description=VALUES(description),reference_type=VALUES(reference_type)",
      [id,String(e.id),new Date(e.date),e.ref_type || null,amount,e.description || '',amount < 0 ? 1 : 0,amount < 0 ? (e.ref_type || 'uncategorized') : null,JSON.stringify(e)]);
  }
}
async function storeAssets(db, id, assets) {
  const [snap] = await db.execute("INSERT INTO fin_asset_snapshots(owner_id,captured_at,pricing_source) VALUES(?,UTC_TIMESTAMP(),'not_priced')", [id]);
  for (const a of assets) {
    await db.execute('INSERT INTO fin_asset_items(snapshot_id,item_type_id,quantity,location_id,raw_payload) VALUES(?,?,?,?,?)',
      [snap.insertId,a.type_id,a.quantity,a.location_id || null,JSON.stringify(a)]);
  }
}
async function syncCharacter(db, o, token) {
  const id = await ownerId(db, o);
  const [balance, journal, assets] = await Promise.all([
    esi('/characters/' + o.eveId + '/wallet/', token),
    pages('/characters/' + o.eveId + '/wallet/journal/', token),
    pages('/characters/' + o.eveId + '/assets/', token)
  ]);
  await db.execute('INSERT INTO fin_wallet_snapshots(owner_id,balance_isk,captured_at) VALUES(?,?,UTC_TIMESTAMP())',[id,Number(balance)]);
  await journalUpsert(db,id,journal);
  await storeAssets(db,id,assets);
  return {owner:o.name,walletEntries:journal.length,assets:assets.length,balance:Number(balance)};
}
async function syncCorporation(db, o, token) {
  const id = await ownerId(db, o);
  const div = Math.min(7,Math.max(1,Number(process.env.FIN_CORP_WALLET_DIVISION || 3)));
  const [balance, journal, assets] = await Promise.all([
    esi('/corporations/' + o.eveId + '/wallets/' + div + '/',token),
    pages('/corporations/' + o.eveId + '/wallets/' + div + '/journal/',token),
    pages('/corporations/' + o.eveId + '/assets/',token)
  ]);
  await db.execute('INSERT INTO fin_wallet_snapshots(owner_id,balance_isk,captured_at) VALUES(?,?,UTC_TIMESTAMP())',[id,Number(balance)]);
  await journalUpsert(db,id,journal);
  await storeAssets(db,id,assets);
  return {owner:o.name,walletEntries:journal.length,assets:assets.length,balance:Number(balance),walletDivision:div};
}
async function main() {
  const owners = ownersFromEnv();
  const db = await mysql.createConnection({host:req('FIN_DB_HOST'),port:Number(process.env.FIN_DB_PORT || 3306),database:req('FIN_DB_NAME'),user:req('FIN_DB_USER'),password:req('FIN_DB_PASSWORD'),connectTimeout:10000});
  let state = {};
  try { state = JSON.parse(fs.readFileSync(STATE_FILE,'utf8')); } catch(e) { if (e.code !== 'ENOENT') throw e; }
  try {
    const out = [];
    for (const o of owners) {
      const token = await accessToken(o,state);
      out.push(o.type === 'corporation' ? await syncCorporation(db,o,token) : await syncCharacter(db,o,token));
      console.log(JSON.stringify({ok:true,...out[out.length-1]}));
    }
    const temp = STATE_FILE + '.tmp';
    fs.writeFileSync(temp,JSON.stringify(state,null,2) + '\n',{mode:0o600});
    fs.renameSync(temp,STATE_FILE);
    try { fs.chmodSync(STATE_FILE,0o600); } catch {}
    console.log(JSON.stringify({ok:true,synced:out.length,note:'All ESI pages imported. Asset market valuation is not calculated by this sync job.'}));
  } finally { await db.end(); }
}
main().catch(e => { console.error('Finance sync failed:',e.message); process.exitCode=1; });
