const fs=require('node:fs');const path=require('node:path');const Database=require('better-sqlite3');
const root=path.join(__dirname,'..');const schema=fs.readFileSync(path.join(root,'data','schema.sql'),'utf8');const file=path.join(root,'data','rigauth-template.sqlite');
fs.mkdirSync(path.dirname(file),{recursive:true});if(fs.existsSync(file))fs.unlinkSync(file);const db=new Database(file);db.pragma('journal_mode = DELETE');db.exec(schema);db.close();console.log('Created fresh SQLite template:',file);
