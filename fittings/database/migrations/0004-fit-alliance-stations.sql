-- Add the alliance-managed station list and seed every station in Jita solar system.
CREATE TABLE IF NOT EXISTS fit_alliance_stations (
 station_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 station_name VARCHAR(255) NOT NULL,
 system_id INT UNSIGNED NOT NULL,
 system_name VARCHAR(100) NOT NULL,
 added_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_alliance_stations_name (station_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fit_alliance_stations (station_id, station_name, system_id, system_name)
SELECT s.stationID, s.stationName, s.solarSystemID, m.solarSystemName
FROM staStations AS s
JOIN mapSolarSystems AS m ON m.solarSystemID = s.solarSystemID
WHERE m.solarSystemName = 'Jita';
