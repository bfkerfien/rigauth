-- Seed the user-designated home Keepstar in 4-HWWF.
-- Safe to rerun: fit_alliance_stations is keyed by location_type and station_id.
INSERT IGNORE INTO fit_alliance_stations
  (location_type, station_id, station_name, system_id, system_name)
VALUES
  ('home', 1054994218305, 'Keepstar 1054994218305', 30000240, '4-HWWF');
