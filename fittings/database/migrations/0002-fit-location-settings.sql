-- Additive migration for installations that already ran the initial fittings schema.
CREATE TABLE IF NOT EXISTS fit_location_settings (
 location_type ENUM('home','deployment') NOT NULL PRIMARY KEY,
 station_id BIGINT UNSIGNED NULL,
 station_name VARCHAR(255) NULL,
 system_id INT UNSIGNED NULL,
 system_name VARCHAR(100) NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
