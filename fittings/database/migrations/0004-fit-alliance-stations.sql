-- Add location-specific alliance station lists and seed all stations in Jita for both selectors.
CREATE TABLE IF NOT EXISTS fit_alliance_stations (
 location_type ENUM('home','deployment') NOT NULL,
 station_id BIGINT UNSIGNED NOT NULL,
 station_name VARCHAR(255) NOT NULL,
 system_id INT UNSIGNED NOT NULL,
 system_name VARCHAR(100) NOT NULL,
 added_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (location_type, station_id),
 KEY ix_alliance_stations_name (location_type, station_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fit_alliance_stations (location_type, station_id, station_name, system_id, system_name)
SELECT 'home', s.stationID, s.stationName, s.solarSystemID, m.name
FROM staStations AS s
JOIN solar_systems AS m ON m.system_id = s.solarSystemID
WHERE m.name = 'Jita'
UNION ALL
SELECT 'deployment', s.stationID, s.stationName, s.solarSystemID, m.name
FROM staStations AS s
JOIN solar_systems AS m ON m.system_id = s.solarSystemID
WHERE m.name = 'Jita';
