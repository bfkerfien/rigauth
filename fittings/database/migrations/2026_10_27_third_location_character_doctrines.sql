-- Apply to an existing SeAT database before deploying third-location and character doctrine settings.
ALTER TABLE fit_location_settings MODIFY location_type ENUM('home','deployment','third') NOT NULL;
ALTER TABLE fit_alliance_stations MODIFY location_type ENUM('home','deployment','third') NOT NULL;
ALTER TABLE fit_shopping_lists MODIFY list_type ENUM('home','deployment','third') NOT NULL;
ALTER TABLE fit_doctrines MODIFY location_scope ENUM('home','deployment','third','both') NOT NULL DEFAULT 'both';

CREATE TABLE IF NOT EXISTS fit_character_doctrine_settings (
 character_id BIGINT UNSIGNED NOT NULL,
 location_type ENUM('home','deployment','third') NOT NULL,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 is_configured BOOLEAN NOT NULL DEFAULT TRUE,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (character_id, location_type),
 KEY ix_fit_character_doctrine_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fit_character_doctrine_assignments (
 character_id BIGINT UNSIGNED NOT NULL,
 location_type ENUM('home','deployment','third') NOT NULL,
 doctrine VARCHAR(120) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (character_id, location_type, doctrine),
 KEY ix_fit_character_doctrine_lookup (location_type, doctrine),
 CONSTRAINT fk_fit_character_doctrine_name FOREIGN KEY (doctrine) REFERENCES fit_doctrines(name) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
