const { app, BrowserWindow, ipcMain, shell, dialog, safeStorage } = require('electron');
const path = require('node:path');
const fs = require('node:fs');
const http = require('node:http');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const Database = require('better-sqlite3');

const REGIONS = ['Branch', 'Tenal', 'Venal', 'Geminate'];
const SYN_ALLIANCE_ID = 99002685;
const BRANCH_RED = ['3KNA-N','ME-4IU','BU-IU4','1-7JR4','I-7JR4','QYZM-W','CH9L-K','9-B1DS'];
const TENAL_RED_CONSTELLATIONS = ['1D-65L','O-E019','E-72A3','9P57-V','2-ZRM0'];
const BRANCH_TEAL = ['KJ-QWL','SBB-RE','KMQ4-V','5-P1Y2','J52-BH','C-LBQS','BWI1-9','KL3O-J','X4UV-Z','JRZ-B9','V8W-QS','OJ-A8M','R4O-I6','Q-NJZ4','NLPB-0','3-TD6L','CX-1XF','SVB-RE'];
let win, db, layouts = {}, oauthServer = null;

function initDb() {
  const dbPath = path.join(app.getPath('userData'), 'rigauth.sqlite');
  fs.mkdirSync(path.dirname(dbPath), { recursive: true });
  const template=path.join(__dirname,'..','data','rigauth-template.sqlite');
  const schemaFile=path.join(__dirname,'..','data','schema.sql');
  if(!fs.existsSync(dbPath) && fs.existsSync(template)) fs.copyFileSync(template,dbPath);
  db = new Database(dbPath);
  db.pragma('journal_mode = WAL');
  db.pragma('foreign_keys = ON');
  db.exec(fs.readFileSync(schemaFile,'utf8'));

  db.prepare('INSERT OR IGNORE INTO migrations(version) VALUES(?)').run(1);
  const file = path.join(__dirname, '..', 'data', 'layouts.php');
  if (fs.existsSync(file)) {
    const source = fs.readFileSync(file, 'utf8');
    const match = source.match(/gzdecode\(base64_decode\('([A-Za-z0-9+/=]+)'\)\)/);
    if (match) { try { layouts = JSON.parse(zlib.gunzipSync(Buffer.from(match[1], 'base64')).toString('utf8')); } catch (e) { console.error('Map layout decode failed', e); } }
  }
}
function setting(key) { const value=db.prepare('SELECT value FROM settings WHERE key=?').get(key)?.value ?? '';if(key==='sso_client_secret'&&value.startsWith('enc:')&&safeStorage.isEncryptionAvailable()){try{return safeStorage.decryptString(Buffer.from(value.slice(4),'base64'));}catch{return '';}}return value; }
function setSetting(key,value) { let stored=String(value);if(key==='sso_client_secret'&&stored&&safeStorage.isEncryptionAvailable())stored='enc:'+safeStorage.encryptString(stored).toString('base64');db.prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value').run(key,stored); }
function jsonResponse(url, options={}) { return fetch(url, options).then(async r => { if (!r.ok) throw new Error('ESI ' + r.status + ': ' + (await r.text()).slice(0,300)); return r.status===204?null:r.json(); }); }
async function esi(pathname, options={}) { return jsonResponse('https://esi.evetech.net/latest'+pathname+(pathname.includes('?')?'&':'?')+'datasource=tranquility',options); }
async function accessToken() {
  const refresh = setting('refresh_token'); if (!refresh) throw new Error('Connect an EVE character in Settings first.');
  const client = setting('sso_client_id'), secret = setting('sso_client_secret');
  if (!client || !secret) throw new Error('Enter your EVE developer application Client ID and Secret in Settings.');
  const response = await jsonResponse('https://login.eveonline.com/v2/oauth/token', {method:'POST',headers:{'Authorization':'Basic '+Buffer.from(client+':'+secret).toString('base64'),'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({grant_type:'refresh_token',refresh_token:refresh})});
  setSetting('refresh_token',response.refresh_token || refresh); setSetting('access_token',response.access_token); setSetting('token_expires',Date.now()+response.expires_in*1000);
  return response.access_token;
}
async function authedEsi(pathname, characterId=Number(setting('character_id'))) {
  const stored=characterId?db.prepare('SELECT * FROM character_tokens WHERE character_id=?').get(characterId):null;
  if(characterId && characterId!==Number(setting('character_id')) && !stored) throw new Error('Connect this tracked character with EVE SSO before syncing its wallet.');
  let token=stored?.access_token || setting('access_token');
  const expires=stored?.token_expires || Number(setting('token_expires'));
  if (!token || expires < Date.now()+60000) {
    const refresh=stored?.refresh_token || setting('refresh_token');
    if(!refresh) throw new Error('Connect this EVE character in Settings first.');
    const client=setting('sso_client_id'),secret=setting('sso_client_secret');
    const t=await jsonResponse('https://login.eveonline.com/v2/oauth/token',{method:'POST',headers:{'Authorization':'Basic '+Buffer.from(client+':'+secret).toString('base64'),'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({grant_type:'refresh_token',refresh_token:refresh})});
    token=t.access_token;
    if(stored) db.prepare('UPDATE character_tokens SET refresh_token=?,access_token=?,token_expires=? WHERE character_id=?').run(t.refresh_token||refresh,token,Date.now()+t.expires_in*1000,characterId);
    else {setSetting('refresh_token',t.refresh_token||refresh);setSetting('access_token',token);setSetting('token_expires',Date.now()+t.expires_in*1000);}
  }
  try { return await jsonResponse('https://esi.evetech.net/latest'+pathname+(pathname.includes('?')?'&':'?')+'datasource=tranquility',{headers:{Authorization:'Bearer '+token}}); }
  catch(e) { if(String(e).includes('ESI 401')) { if(stored) db.prepare('UPDATE character_tokens SET token_expires=0 WHERE character_id=?').run(characterId); else setSetting('token_expires',0); return authedEsi(pathname,characterId); } throw e; }
}
function createWindow() {
  win = new BrowserWindow({width:1500,height:950,minWidth:1100,minHeight:700,backgroundColor:'#0b1220',title:'RIGAUTH',autoHideMenuBar:true,webPreferences:{preload:path.join(__dirname,'preload.js'),contextIsolation:true,nodeIntegration:false,sandbox:true}});
  win.loadFile(path.join(__dirname,'index.html'));
  win.on('closed',()=>win=null);
}
function rows(sql,...args){return db.prepare(sql).all(...args);}
function getMap(region) {
  const systems=rows('SELECT * FROM systems WHERE region=? ORDER BY name',region);
  const explored=new Set(rows('SELECT system_id FROM explored').map(x=>x.system_id));
  const manualRed=new Set();
  if(region==='Branch') for(const name of BRANCH_RED) { const s=systems.find(x=>x.name===name); if(s) manualRed.add(s.id); }
  if(region==='Tenal') for(const name of TENAL_RED_CONSTELLATIONS) for(const s of systems.filter(x=>x.constellation===name)) manualRed.add(s.id);
  const sov=new Set(rows('SELECT system_id FROM sovereignty WHERE alliance_id=?',SYN_ALLIANCE_ID).map(x=>x.system_id));
  const map=layouts[region];
  const manualCyno=new Map(rows('SELECT system_id,beacon,jammer FROM cyno_manual').map(x=>[x.system_id,x]));
  const esiBeacon=new Set(rows('SELECT system_id FROM cyno_esi WHERE structure_type_id=35840').map(x=>x.system_id));
  const esiJammer=new Set(rows('SELECT system_id FROM cyno_esi WHERE structure_type_id=37534').map(x=>x.system_id));
  let nodes=[],edges=[];
  if(map?.nodes?.length && region!=='Geminate') {
    nodes=map.nodes.map(n=>({id:Number(n[0]),name:n[1],x:Number(n[2]),y:Number(n[3]),rect:n[5],extra:n[6],external:!systems.some(s=>s.id===Number(n[0]))}));
    edges=(map.edges||[]).map(e=>({from:Number(e[0]),to:Number(e[1]),x1:Number(e[2]),y1:Number(e[3]),x2:Number(e[4]),y2:Number(e[5]),kind:e[6]||'j'}));
  } else {
    const minX=Math.min(...systems.map(s=>s.x||0)),maxX=Math.max(...systems.map(s=>s.x||0)),minZ=Math.min(...systems.map(s=>s.z||0)),maxZ=Math.max(...systems.map(s=>s.z||0));
    const scale=Math.min(850/Math.max(1,maxX-minX),610/Math.max(1,maxZ-minZ));
    nodes=systems.map(s=>({id:s.id,name:s.name,x:512+(s.x-(minX+maxX)/2)*scale,y:384-(s.z-(minZ+maxZ)/2)*scale,rect:[-23,-12,46,24]}));
    const seen=new Set(); for(const g of rows('SELECT from_id,to_id FROM gates')) if(systems.some(s=>s.id===g.from_id)&&systems.some(s=>s.id===g.to_id)){const k=[g.from_id,g.to_id].sort().join(':');if(!seen.has(k)){seen.add(k);const a=nodes.find(n=>n.id===g.from_id),b=nodes.find(n=>n.id===g.to_id);edges.push({from:g.from_id,to:g.to_id,x1:a.x,y1:a.y,x2:b.x,y2:b.y,kind:'j'});}}
  }
  const systemById=new Map(systems.map(s=>[s.id,s]));
  return {region,nodes:nodes.map(n=>{const s=systemById.get(n.id),m=manualCyno.get(n.id)||{};return {...n,status:n.external?'external':(manualRed.has(n.id)||sov.has(n.id))?'red':explored.has(n.id)?'green':'white',teal:region==='Branch'&&BRANCH_TEAL.includes(n.name),constellation:s?.constellation||'',beacon:!!m.beacon||esiBeacon.has(n.id),jammer:!!m.jammer||esiJammer.has(n.id)};}),edges,exploredCount:systems.filter(s=>explored.has(s.id)).length,total:systems.length};
}
async function syncUniverse() {
  const regionIds=await esi('/universe/regions/');
  const names=await esi('/universe/regions/names/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(regionIds)});
  const regionMap=new Map(names.map(x=>[x.name,x.region_id]));
  const all=[];
  for(const region of REGIONS){
    const id=regionMap.get(region); if(!id) continue;
    const detail=await esi('/universe/regions/'+id+'/');
    const constellations=[];
    for(let i=0;i<detail.constellations.length;i+=20){constellations.push(...detail.constellations.slice(i,i+20));}
    const systems=[];
    for(const cid of constellations){const c=await esi('/universe/constellations/'+cid+'/');for(const sid of c.systems)systems.push({id:sid,constellation:c.name});}
    for(let i=0;i<systems.length;i+=12){
      const batch=await Promise.all(systems.slice(i,i+12).map(async s=>{const d=await esi('/universe/systems/'+s.id+'/');return {id:d.system_id,name:d.name,region,constellation:s.constellation,x:d.position.x,y:d.position.y,z:d.position.z};}));
      all.push(...batch);
    }
  }
  const insert=db.prepare('INSERT INTO systems(id,name,region,constellation,x,y,z) VALUES(@id,@name,@region,@constellation,@x,@y,@z) ON CONFLICT(id) DO UPDATE SET name=excluded.name,region=excluded.region,constellation=excluded.constellation,x=excluded.x,y=excluded.y,z=excluded.z');
  const tx=db.transaction(items=>items.forEach(x=>insert.run(x)));tx(all);
  const sov=await esi('/sovereignty/systems/');
  db.prepare('DELETE FROM sovereignty').run();
  const putSov=db.prepare('INSERT INTO sovereignty(system_id,alliance_id) VALUES(?,?) ON CONFLICT(system_id) DO UPDATE SET alliance_id=excluded.alliance_id');
  const sovTx=db.transaction(items=>items.forEach(x=>putSov.run(x.system_id,x.alliance_id)));sovTx(sov.filter(x=>x.alliance_id===SYN_ALLIANCE_ID));
  const jumps=await esi('/universe/system_jumps/');
  const put=db.prepare('INSERT OR IGNORE INTO gates(from_id,to_id) VALUES(?,?)');
  const tx2=db.transaction(items=>items.forEach(x=>{put.run(x.from,x.to);put.run(x.to,x.from);}));tx2(jumps);
  setSetting('universe_synced',new Date().toISOString());
  return {systems:all.length,gates:jumps.length};
}
function parseProbeScan(text) {
  const lines=text.trim().split(/\r?\n/).filter(Boolean);
  if(!lines.length) throw new Error('Paste the Probe Scanner rows first.');
  const normalize=s=>String(s||'').toLowerCase().replace(/[^a-z0-9]/g,'');
  const header=lines[0].split('\t').map(normalize), hasHeader=header.includes('signatureid')||header.includes('signalstrength');
  const cols=hasHeader?{id:header.findIndex(x=>['id','signatureid','scanid'].includes(x)),group:header.findIndex(x=>['group','groupname','category'].includes(x)),type:header.findIndex(x=>['type','typename'].includes(x)),name:header.findIndex(x=>['name','signature','signaturename'].includes(x)),strength:header.findIndex(x=>['signalstrength','strength','certainty'].includes(x))}:{id:0,group:1,type:2,name:3,strength:4};
  const data=hasHeader?lines.slice(1):lines, out=[];
  for(const line of data){const c=line.split('\t'),id=String(c[cols.id]||'').trim().toUpperCase(),strength=parseFloat(String(c[cols.strength]||'').replace('%',''));if(!/^[A-Z0-9]{3}-[A-Z0-9]{3}$/.test(id)||!Number.isFinite(strength))continue;out.push({id,group:c[cols.group]||'',type:c[cols.type]||'',name:c[cols.name]||'',strength:Math.max(0,Math.min(100,strength)),raw:line});}
  if(!out.length) throw new Error('No valid EVE signatures were found in that clipboard data.');
  return out;
}
async function beginSso() {
  const client=setting('sso_client_id'),secret=setting('sso_client_secret');if(!client||!secret)throw new Error('Configure the EVE SSO Client ID and Secret first.');
  if(oauthServer)throw new Error('An EVE sign-in is already waiting.');
  const port=Number(setting('sso_callback_port')||17841),state=crypto.randomBytes(24).toString('hex'),verifier=crypto.randomBytes(48).toString('base64url'),challenge=crypto.createHash('sha256').update(verifier).digest('base64url');
  setSetting('sso_pkce_verifier',verifier);
  oauthServer=http.createServer(async(req,res)=>{
    const u=new URL(req.url,'http://127.0.0.1:'+port);
    if(u.pathname!=='/callback'){res.writeHead(404);res.end();return;}
    if(u.searchParams.get('state')!==state){res.writeHead(400);res.end('SSO state mismatch. Return to RIGAUTH.');oauthServer.close();oauthServer=null;return;}
    const code=u.searchParams.get('code');if(!code){res.writeHead(400);res.end('No authorization code returned.');return;}
    try{
      const token=await jsonResponse('https://login.eveonline.com/v2/oauth/token',{method:'POST',headers:{'Authorization':'Basic '+Buffer.from(client+':'+secret).toString('base64'),'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({grant_type:'authorization_code',code,code_verifier:verifier,redirect_uri:'http://127.0.0.1:'+port+'/callback'})});
      setSetting('refresh_token',token.refresh_token);setSetting('access_token',token.access_token);setSetting('token_expires',Date.now()+token.expires_in*1000);
      const verify=await jsonResponse('https://login.eveonline.com/oauth/verify',{headers:{Authorization:'Bearer '+token.access_token}});
      setSetting('character_id',verify.CharacterID);setSetting('character_name',verify.CharacterName);setSetting('character_owner_hash',verify.CharacterOwnerHash);
      db.prepare('INSERT INTO character_tokens(character_id,refresh_token,access_token,token_expires) VALUES(?,?,?,?) ON CONFLICT(character_id) DO UPDATE SET refresh_token=excluded.refresh_token,access_token=excluded.access_token,token_expires=excluded.token_expires').run(verify.CharacterID,token.refresh_token,token.access_token,Date.now()+token.expires_in*1000);
      db.prepare('INSERT INTO tracked_characters(character_id,name,enabled) VALUES(?,?,1) ON CONFLICT(character_id) DO UPDATE SET name=excluded.name,enabled=1').run(verify.CharacterID,verify.CharacterName);
      const charInfo=await jsonResponse('https://esi.evetech.net/latest/characters/'+verify.CharacterID+'/?datasource=tranquility');
      if(charInfo.corporation_id) setSetting('tracked_corporation_id',charInfo.corporation_id);
      res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});res.end('<h2>RIGAUTH connected</h2><p>You can close this window and return to RIGAUTH.</p>');
      win?.webContents.send('sso-complete',{name:verify.CharacterName,id:verify.CharacterID});
    }catch(e){res.writeHead(500);res.end('EVE SSO failed: '+e.message);}
    finally{oauthServer?.close();oauthServer=null;}
  });
  await new Promise((resolve,reject)=>oauthServer.listen(port,'127.0.0.1',resolve).once('error',reject));
  const scopes=['esi-location.read_location.v1','esi-wallet.read_character_wallet.v1','esi-assets.read_assets.v1','esi-assets.read_corporation_assets.v1'];
  const auth='https://login.eveonline.com/v2/oauth/authorize/?response_type=code&redirect_uri='+encodeURIComponent('http://127.0.0.1:'+port+'/callback')+'&client_id='+encodeURIComponent(client)+'&scope='+encodeURIComponent(scopes.join(' '))+'&state='+state+'&code_challenge='+challenge+'&code_challenge_method=S256';
  await shell.openExternal(auth);
  return {status:'waiting',port};
}
ipcMain.handle('app:info',()=>({version:app.getVersion(),dbPath:path.join(app.getPath('userData'),'rigauth.sqlite'),character:setting('character_name'),characterId:setting('character_id'),lastSync:setting('universe_synced')}));
ipcMain.handle('settings:get',()=>Object.fromEntries(rows('SELECT key,value FROM settings').map(x=>[x.key,x.key==='sso_client_secret'?setting(x.key):x.value])));
ipcMain.handle('settings:set',(_e,values)=>{for(const [k,v] of Object.entries(values))setSetting(k,v);return true;});
ipcMain.handle('sso:connect',()=>beginSso());
ipcMain.handle('sso:disconnect',()=>{const id=Number(setting('character_id'));if(id)db.prepare('DELETE FROM character_tokens WHERE character_id=?').run(id);for(const k of ['refresh_token','access_token','token_expires','character_id','character_name'])setSetting(k,'');return true;});
ipcMain.handle('db:backup',async()=>{const out=await dialog.showSaveDialog(win,{title:'Back up RIGAUTH database',defaultPath:'rigauth-backup.sqlite',filters:[{name:'SQLite database',extensions:['sqlite','db']}]});if(out.canceled||!out.filePath)return {canceled:true};db.pragma('wal_checkpoint(FULL)');fs.copyFileSync(path.join(app.getPath('userData'),'rigauth.sqlite'),out.filePath);return {filePath:out.filePath};});
ipcMain.handle('db:restore',async()=>{const pick=await dialog.showOpenDialog(win,{title:'Restore RIGAUTH database',properties:['openFile'],filters:[{name:'SQLite database',extensions:['sqlite','db']}]});if(pick.canceled||!pick.filePaths[0])return {canceled:true};const source=pick.filePaths[0];const test=new Database(source,{readonly:true});test.prepare('SELECT value FROM settings WHERE key=?').get('sso_client_id');test.close();db.close();fs.copyFileSync(source,path.join(app.getPath('userData'),'rigauth.sqlite'));initDb();return {restored:true};});
ipcMain.handle('universe:sync',async()=>syncUniverse());
ipcMain.handle('map:get',(_e,region)=>getMap(region));
ipcMain.handle('map:toggle',(_e,id)=>{const s=db.prepare('SELECT * FROM systems WHERE id=?').get(id);if(!s)throw new Error('Unknown solar system.');if(getMap(s.region).nodes.find(n=>n.id===id)?.status==='red')throw new Error('This system is permanently excluded.');const exists=db.prepare('SELECT 1 FROM explored WHERE system_id=?').get(id);if(exists)db.prepare('DELETE FROM explored WHERE system_id=?').run(id);else db.prepare('INSERT INTO explored(system_id,region) VALUES(?,?)').run(id,s.region);return getMap(s.region);});
ipcMain.handle('map:reset',(_e,region)=>{db.prepare('DELETE FROM explored WHERE region=?').run(region);return getMap(region);});
ipcMain.handle('system:signatures',(_e,id)=>({system:db.prepare('SELECT * FROM systems WHERE id=?').get(id),signatures:rows('SELECT * FROM signatures WHERE system_id=? ORDER BY signature_id',id),scan:db.prepare('SELECT * FROM system_scans WHERE system_id=?').get(id)}));
ipcMain.handle('scan:save',(_e,{systemId,mode,scanText})=>{const system=db.prepare('SELECT * FROM systems WHERE id=?').get(systemId);if(!system)throw new Error('Unknown system.');const parsed=parseProbeScan(scanText),now=new Date().toISOString();const tx=db.transaction(()=>{if(mode!=='add')db.prepare('DELETE FROM signatures WHERE system_id=?').run(systemId);const put=db.prepare('INSERT INTO signatures(region,system_id,signature_id,group_name,type_name,signature_name,strength,raw_data,scanned_at) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(system_id,signature_id) DO UPDATE SET group_name=excluded.group_name,type_name=excluded.type_name,signature_name=excluded.signature_name,strength=excluded.strength,raw_data=excluded.raw_data,scanned_at=excluded.scanned_at');for(const p of parsed)put.run(system.region,systemId,p.id,p.group,p.type,p.name,p.strength,p.raw,now);const count=db.prepare('SELECT count(*) n FROM signatures WHERE system_id=?').get(systemId).n;db.prepare('INSERT INTO system_scans(system_id,region,scanned_at,signature_count,scan_status) VALUES(?,?,?,?,?) ON CONFLICT(system_id) DO UPDATE SET scanned_at=excluded.scanned_at,signature_count=excluded.signature_count,scan_status=excluded.scan_status').run(systemId,system.region,now,count,'with_signatures');});tx();return {count:parsed.length,...ipcMain.emit?{}:{}};});
ipcMain.handle('scan:timestamp',(_e,id)=>{const s=db.prepare('SELECT * FROM systems WHERE id=?').get(id);if(!s)throw new Error('Unknown system.');const now=new Date().toISOString();const count=db.prepare('SELECT count(*) n FROM signatures WHERE system_id=?').get(id).n;db.prepare('INSERT INTO system_scans(system_id,region,scanned_at,signature_count,scan_status) VALUES(?,?,?,?,?) ON CONFLICT(system_id) DO UPDATE SET scanned_at=excluded.scanned_at,scan_status=excluded.scan_status').run(id,s.region,now,count,count?'with_signatures':'timestamp_only');return {scanned_at:now,count};});
ipcMain.handle('wormholes:list',()=>rows(`SELECT s.*,y.name system_name FROM signatures s JOIN systems y ON y.id=s.system_id WHERE lower(coalesce(s.group_name,'')) LIKE '%wormhole%' OR lower(coalesce(s.type_name,'')) LIKE '%wormhole%' OR wormhole_type IS NOT NULL OR connection_system IS NOT NULL OR wormhole_exits IS NOT NULL ORDER BY scanned_at DESC`));
ipcMain.handle('wormholes:save',(_e,p)=>{db.prepare('UPDATE signatures SET wormhole_type=?,connection_system=?,wormhole_exits=? WHERE system_id=? AND signature_id=?').run(p.wormhole_type||null,p.connection_system||null,p.wormhole_exits||null,p.systemId,p.signatureId);return true;});
ipcMain.handle('wormholes:delete',(_e,p)=>db.prepare('DELETE FROM signatures WHERE system_id=? AND signature_id=?').run(p.systemId,p.signatureId).changes);
ipcMain.handle('scans:list',()=>rows(`SELECT y.id system_id,y.name system_name,y.region,coalesce(s.scanned_at,(SELECT max(scanned_at) FROM signatures WHERE system_id=y.id)) scanned_at,coalesce(s.signature_count,(SELECT count(*) FROM signatures WHERE system_id=y.id),0) signature_count,coalesce(s.scan_status,'with_signatures') scan_status FROM systems y LEFT JOIN system_scans s ON s.system_id=y.id ORDER BY y.region,y.name`));
ipcMain.handle('cyno:list',()=>rows('SELECT y.id,y.name,y.region,coalesce(c.beacon,0) beacon,coalesce(c.jammer,0) jammer,exists(SELECT 1 FROM cyno_esi e WHERE e.system_id=y.id AND e.structure_type_id=35840) esi_beacon,exists(SELECT 1 FROM cyno_esi e WHERE e.system_id=y.id AND e.structure_type_id=37534) esi_jammer FROM systems y LEFT JOIN cyno_manual c ON c.system_id=y.id ORDER BY y.region,y.name'));
ipcMain.handle('cyno:save',(_e,p)=>{db.prepare('INSERT INTO cyno_manual(system_id,beacon,jammer,updated_at) VALUES(?,?,?,?) ON CONFLICT(system_id) DO UPDATE SET beacon=excluded.beacon,jammer=excluded.jammer,updated_at=excluded.updated_at').run(p.systemId,+p.beacon,+p.jammer,new Date().toISOString());return true;});
ipcMain.handle('cyno:sync',async()=>{const s=await esi('/sovereignty/structures/');const found=s.filter(x=>x.structure_type_id===35840||x.structure_type_id===37534);const tx=db.transaction(()=>{db.prepare('DELETE FROM cyno_esi').run();const put=db.prepare('INSERT INTO cyno_esi(system_id,structure_type_id,structure_id,updated_at) VALUES(?,?,?,?)');for(const x of found)put.run(x.solar_system_id,x.structure_type_id,x.structure_id,new Date().toISOString());});tx();return {beacons:found.filter(x=>x.structure_type_id===35840),jammers:found.filter(x=>x.structure_type_id===37534)};});
ipcMain.handle('location:get',async()=>{const id=setting('character_id');if(!id)throw new Error('Connect your EVE character first.');const data=await authedEsi('/characters/'+id+'/location/');const system=db.prepare('SELECT * FROM systems WHERE id=?').get(data.solar_system_id);return {character:setting('character_name'),systemId:data.solar_system_id,system};});
ipcMain.handle('finance:summary',()=>{const telemetry=Number(setting('telemetry_type_id')||89219),unit=Number(setting('telemetry_unit_value')||300000),perSite=Number(setting('telemetry_units_per_site')||60),payout=Number(setting('site_payout_per_character')||9000000);const units=rows('SELECT coalesce(sum(quantity),0) n FROM assets WHERE type_id=?',telemetry)[0].n;const sites=Math.floor(units/perSite);const sessions=rows('SELECT * FROM site_sessions ORDER BY id DESC LIMIT 30');const events=rows('SELECT * FROM finance_events ORDER BY occurred_at DESC LIMIT 50');return {telemetryUnits:units,sites,telemetryValue:units*unit,sitePayout:sites*payout*3,estimatedRevenue:sites*(payout*3),sessions,events,characters:rows('SELECT * FROM tracked_characters ORDER BY name'),walletEntries:rows('SELECT * FROM wallet_entries ORDER BY occurred_at DESC LIMIT 100')};});
ipcMain.handle('finance:add-character',(_e,p)=>{db.prepare('INSERT INTO tracked_characters(character_id,name) VALUES(?,?) ON CONFLICT(character_id) DO UPDATE SET name=excluded.name,enabled=1').run(p.id,p.name);return true;});
ipcMain.handle('finance:start-session',()=>{const now=new Date().toISOString();db.prepare('INSERT INTO site_sessions(started_at) VALUES(?)').run(now);return db.prepare('SELECT * FROM site_sessions ORDER BY id DESC LIMIT 1').get();});
ipcMain.handle('finance:stop-session',(_e,p)=>{const s=db.prepare('SELECT * FROM site_sessions WHERE stopped_at IS NULL ORDER BY id DESC LIMIT 1').get();if(!s)throw new Error('No active session.');const now=new Date().toISOString(),units=Number(p.telemetryUnits||0),perSite=Number(setting('telemetry_units_per_site')||60),unitValue=Number(setting('telemetry_unit_value')||300000),payout=Number(setting('site_payout_per_character')||9000000),sites=Math.floor(units/perSite),wallet=rows('SELECT amount,reason FROM wallet_entries WHERE occurred_at>=? AND occurred_at<=? AND amount<0',s.started_at,now).filter(x=>!String(x.reason||'').toLowerCase().includes('corporation tax')&&!String(x.reason||'').toLowerCase().includes('corporation_tax')).reduce((a,x)=>a+Math.abs(Number(x.amount)),0),expenses=wallet+Number(p.expenses||0),revenue=sites*payout*3+units*unitValue;db.prepare('UPDATE site_sessions SET stopped_at=?,telemetry_units=?,sites=?,revenue=?,expenses=?,notes=? WHERE id=?').run(now,units,sites,revenue,expenses,p.notes||'',s.id);return db.prepare('SELECT * FROM site_sessions WHERE id=?').get(s.id);});
ipcMain.handle('finance:refresh-wallet',async()=>{const chars=rows('SELECT * FROM tracked_characters WHERE enabled=1');let added=0,skipped=[];const put=db.prepare('INSERT OR IGNORE INTO wallet_entries(entry_id,character_id,amount,reason,occurred_at) VALUES(?,?,?,?,?)');for(const c of chars){try{for(let page=1;page<=100;page++){const entries=await authedEsi('/characters/'+c.character_id+'/wallet/journal/?page='+page,c.character_id);for(const e of entries){const id=String(c.character_id)+':'+String(e.id);if(put.run(id,c.character_id,e.amount,e.reason,new Date(e.date).toISOString()).changes)added++;}if(entries.length<50)break;}}catch(e){skipped.push(c.name+': '+e.message);}}return {added,skipped};});
ipcMain.handle('finance:refresh-assets',async()=>{const chars=rows('SELECT * FROM tracked_characters WHERE enabled=1');const corp=setting('tracked_corporation_id');if(!corp)throw new Error('Connect a character with corporation asset access and set the corporation ID before syncing CorpSAG3 telemetry.');let found=[];let lastError=null;for(const c of chars){try{for(let page=1;page<=50;page++){const items=await authedEsi('/corporations/'+corp+'/assets/?page='+page,c.character_id);if(!items.length)break;found.push(...items.filter(a=>Number(a.type_id)===Number(setting('telemetry_type_id')||89219)&&a.location_flag==='CorpSAG3'));if(items.length<1000)break;}if(found.length||chars.length===1)break;}catch(e){lastError=e;}}if(!found.length&&lastError)throw lastError;const grouped=new Map();for(const a of found){const k=String(a.location_id);grouped.set(k,(grouped.get(k)||0)+Number(a.quantity||0));}const now=new Date().toISOString();const tx=db.transaction(()=>{db.prepare('DELETE FROM assets WHERE type_id=? AND owner_id=?').run(Number(setting('telemetry_type_id')||89219),Number(corp));const put=db.prepare('INSERT INTO assets(type_id,type_name,quantity,location_id,owner_id,estimated_value,updated_at) VALUES(?,?,?,?,?,?,?)');for(const [location,quantity] of grouped)put.run(Number(setting('telemetry_type_id')||89219),'Telemetry',quantity,location,Number(corp),0,now);});tx();return {telemetryUnits:[...grouped.values()].reduce((a,b)=>a+b,0),locations:grouped.size};});
app.whenReady().then(()=>{initDb();if(process.argv.includes('--smoke-test')){const map=getMap('Branch');if(!layouts.Branch?.nodes?.length||!layouts.Tenal?.nodes?.length||!layouts.Venal?.nodes?.length)throw new Error('Bundled regional map layouts failed to load.');if(!map.nodes.length||!map.edges.length)throw new Error('Branch map topology failed smoke test.');const checks=db.prepare('SELECT count(*) n FROM migrations').get().n;if(!checks)throw new Error('SQLite migration table is missing.');console.log('RIGAUTH smoke test passed: SQLite initialized, map layouts decoded, '+map.nodes.length+' Branch map nodes and '+map.edges.length+' connections loaded.');app.quit();return;}createWindow();app.on('activate',()=>{if(BrowserWindow.getAllWindows().length===0)createWindow();});});
app.on('window-all-closed',()=>{if(process.platform!=='darwin')app.quit();});
app.on('before-quit',()=>{try{db?.close();}catch{}});
