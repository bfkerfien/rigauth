CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS systems(id INTEGER PRIMARY KEY,name TEXT NOT NULL,region TEXT NOT NULL,constellation TEXT,x REAL,y REAL,z REAL);
CREATE INDEX IF NOT EXISTS idx_systems_region_name ON systems(region,name);
CREATE TABLE IF NOT EXISTS gates(from_id INTEGER NOT NULL,to_id INTEGER NOT NULL,PRIMARY KEY(from_id,to_id));
CREATE TABLE IF NOT EXISTS sovereignty(system_id INTEGER PRIMARY KEY,alliance_id INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS explored(system_id INTEGER PRIMARY KEY,region TEXT NOT NULL,marked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS idx_explored_region ON explored(region);
CREATE TABLE IF NOT EXISTS signatures(id INTEGER PRIMARY KEY AUTOINCREMENT,region TEXT NOT NULL,system_id INTEGER NOT NULL,signature_id TEXT NOT NULL,group_name TEXT,type_name TEXT,signature_name TEXT,strength REAL,raw_data TEXT,wormhole_type TEXT,connection_system TEXT,wormhole_exits TEXT,scanned_at TEXT NOT NULL,UNIQUE(system_id,signature_id));
CREATE INDEX IF NOT EXISTS idx_signatures_system ON signatures(system_id);
CREATE TABLE IF NOT EXISTS system_scans(system_id INTEGER PRIMARY KEY,region TEXT NOT NULL,scanned_at TEXT NOT NULL,signature_count INTEGER NOT NULL DEFAULT 0,scan_status TEXT NOT NULL DEFAULT 'timestamp_only');
CREATE TABLE IF NOT EXISTS cyno_manual(system_id INTEGER PRIMARY KEY,beacon INTEGER NOT NULL DEFAULT 0,jammer INTEGER NOT NULL DEFAULT 0,updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS cyno_esi(system_id INTEGER NOT NULL,structure_type_id INTEGER NOT NULL,structure_id INTEGER NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(structure_id));
CREATE TABLE IF NOT EXISTS tracked_characters(character_id INTEGER PRIMARY KEY,name TEXT NOT NULL,enabled INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS character_tokens(character_id INTEGER PRIMARY KEY,refresh_token TEXT NOT NULL,access_token TEXT,token_expires INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS finance_events(id INTEGER PRIMARY KEY AUTOINCREMENT,event_type TEXT NOT NULL,character_id INTEGER,amount REAL NOT NULL,description TEXT,occurred_at TEXT NOT NULL,source_id TEXT UNIQUE);
CREATE TABLE IF NOT EXISTS site_sessions(id INTEGER PRIMARY KEY AUTOINCREMENT,started_at TEXT NOT NULL,stopped_at TEXT,telemetry_units INTEGER NOT NULL DEFAULT 0,sites INTEGER NOT NULL DEFAULT 0,revenue REAL NOT NULL DEFAULT 0,expenses REAL NOT NULL DEFAULT 0,notes TEXT);
CREATE TABLE IF NOT EXISTS wallet_entries(entry_id TEXT PRIMARY KEY,character_id INTEGER,amount REAL NOT NULL,reason TEXT NOT NULL,occurred_at TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_wallet_entries_date ON wallet_entries(occurred_at);
CREATE TABLE IF NOT EXISTS assets(type_id INTEGER NOT NULL,type_name TEXT NOT NULL,quantity REAL NOT NULL,location_id TEXT,owner_id INTEGER,estimated_value REAL NOT NULL DEFAULT 0,updated_at TEXT NOT NULL,PRIMARY KEY(type_id,location_id,owner_id));
CREATE TABLE IF NOT EXISTS migrations(version INTEGER PRIMARY KEY,applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
INSERT OR IGNORE INTO settings(key,value) VALUES
('sso_client_id',''),('sso_client_secret',''),('sso_callback_port','17841'),
('last_region','Branch'),('telemetry_type_id','89219'),('telemetry_units_per_site','60'),
('telemetry_unit_value','300000'),('site_payout_per_character','9000000'),
('tracked_corporation_id',''),('corp_hangar_division','3');
INSERT OR IGNORE INTO migrations(version) VALUES(1);
