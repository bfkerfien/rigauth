const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');const Database=require('better-sqlite3');
const file=path.join(__dirname,'..','data','rigauth-template.sqlite');assert.ok(fs.existsSync(file),'SQLite template must exist');
const db=new Database(file,{readonly:true});const tables=new Set(db.prepare("SELECT name FROM sqlite_master WHERE type='table'").all().map(x=>x.name));
for(const name of ['settings','systems','gates','sovereignty','explored','signatures','system_scans','cyno_manual','cyno_esi','tracked_characters','character_tokens','site_sessions','wallet_entries','assets','migrations'])assert.ok(tables.has(name),'Missing table: '+name);
assert.equal(db.prepare("SELECT value FROM settings WHERE key='telemetry_type_id'").get().value,'89219');
assert.equal(db.prepare("SELECT value FROM settings WHERE key='telemetry_units_per_site'").get().value,'60');
assert.equal(db.prepare("SELECT value FROM settings WHERE key='telemetry_unit_value'").get().value,'300000');
assert.equal(db.prepare("SELECT value FROM settings WHERE key='site_payout_per_character'").get().value,'9000000');
db.close();console.log('SQLite template integrity verified.');
