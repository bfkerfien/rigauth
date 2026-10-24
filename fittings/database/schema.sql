-- Import only into the dedicated triglavian_market database.
-- No DROP statements. Do not run against SeAT's own seat schema.

CREATE TABLE IF NOT EXISTS collection_runs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 structure_id BIGINT UNSIGNED NOT NULL,
 started_at DATETIME NOT NULL,
 completed_at DATETIME NULL,
 status ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
 structure_order_count INT UNSIGNED NOT NULL DEFAULT 0,
 jita_order_count INT UNSIGNED NOT NULL DEFAULT 0,
 item_type_count INT UNSIGNED NOT NULL DEFAULT 0,
 error_message TEXT NULL,
 KEY ix_runs_started (started_at),
 KEY ix_runs_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_orders (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 run_id BIGINT UNSIGNED NOT NULL,
 source ENUM('structure','jita_44') NOT NULL,
 type_id INT UNSIGNED NOT NULL,
 type_name VARCHAR(255) NULL,
 order_id BIGINT UNSIGNED NOT NULL,
 location_id BIGINT UNSIGNED NOT NULL,
 system_id INT UNSIGNED NULL,
 is_buy_order BOOLEAN NOT NULL,
 price DECIMAL(24,2) NOT NULL,
 volume_remain BIGINT UNSIGNED NOT NULL,
 volume_total BIGINT UNSIGNED NOT NULL,
 min_volume BIGINT UNSIGNED NOT NULL DEFAULT 1,
 issued DATETIME NOT NULL,
 duration SMALLINT UNSIGNED NOT NULL,
 order_range VARCHAR(32) NOT NULL,
 collected_at DATETIME NOT NULL,
 CONSTRAINT fk_market_orders_run FOREIGN KEY (run_id) REFERENCES collection_runs(id) ON DELETE CASCADE,
 UNIQUE KEY uq_run_source_order (run_id, source, order_id),
 KEY ix_market_run_type_source_side (run_id,type_id,source,is_buy_order),
 KEY ix_market_collected (collected_at),
 KEY ix_market_type_time (type_id,collected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_comparisons (
 run_id BIGINT UNSIGNED NOT NULL,
 type_id INT UNSIGNED NOT NULL,
 type_name VARCHAR(255) NULL,
 structure_sell_min DECIMAL(24,2) NULL,
 jita_sell_min DECIMAL(24,2) NULL,
 sell_difference DECIMAL(24,2) NULL,
 sell_difference_percent DECIMAL(12,4) NULL,
 structure_buy_max DECIMAL(24,2) NULL,
 jita_buy_max DECIMAL(24,2) NULL,
 structure_sell_volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
 jita_sell_volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (run_id,type_id),
 CONSTRAINT fk_comparisons_run FOREIGN KEY (run_id) REFERENCES collection_runs(id) ON DELETE CASCADE,
 KEY ix_comparison_type (type_id,run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_type_volumes (
 type_id INT UNSIGNED NOT NULL PRIMARY KEY,
 volume DECIMAL(24,6) NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ship_fits (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 fit_name VARCHAR(160) NOT NULL,
 ship_type_id INT UNSIGNED NOT NULL,
 ship_name VARCHAR(255) NOT NULL,
 fit_text MEDIUMTEXT NULL,
 visibility ENUM('alliance','admins') NOT NULL DEFAULT 'alliance',
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_ship_fits_name (fit_name),
 KEY ix_ship_fits_ship_type (ship_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fit_categories (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 category_name VARCHAR(120) NOT NULL,
 slug VARCHAR(140) NOT NULL,
 description VARCHAR(500) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_fit_categories_name (category_name),
 UNIQUE KEY uq_fit_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-many: one fit can appear in several categories.
CREATE TABLE IF NOT EXISTS ship_fit_categories (
 fit_id BIGINT UNSIGNED NOT NULL,
 category_id BIGINT UNSIGNED NOT NULL,
 assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (fit_id,category_id),
 KEY ix_ship_fit_categories_category (category_id,fit_id),
 CONSTRAINT fk_ship_fit_categories_fit FOREIGN KEY (fit_id) REFERENCES ship_fits(id) ON DELETE CASCADE,
 CONSTRAINT fk_ship_fit_categories_category FOREIGN KEY (category_id) REFERENCES fit_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ship_fit_doctrines (
 fit_id BIGINT UNSIGNED NOT NULL,
 doctrine VARCHAR(120) NOT NULL,
 PRIMARY KEY (fit_id,doctrine),
 KEY ix_fit_doctrine (doctrine),
 CONSTRAINT fk_fit_doctrines_fit FOREIGN KEY (fit_id) REFERENCES ship_fits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ship_fit_items (
 fit_id BIGINT UNSIGNED NOT NULL,
 type_id INT UNSIGNED NOT NULL,
 type_name VARCHAR(255) NOT NULL,
 quantity_per_ship INT UNSIGNED NOT NULL,
 is_hull BOOLEAN NOT NULL DEFAULT FALSE,
 is_rig BOOLEAN NOT NULL DEFAULT FALSE,
 is_consumable BOOLEAN NULL DEFAULT NULL,
 PRIMARY KEY (fit_id,type_id),
 CONSTRAINT fk_fit_items_fit FOREIGN KEY (fit_id) REFERENCES ship_fits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shopping_lists (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 list_name VARCHAR(160) NOT NULL,
 list_type ENUM('home','deployment') NOT NULL,
 owner_user_id BIGINT UNSIGNED NULL,
 system_id INT UNSIGNED NULL,
 system_name VARCHAR(100) NULL,
 notes TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_shopping_lists_type (list_type,list_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shopping_list_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 shopping_list_id BIGINT UNSIGNED NOT NULL,
 type_id INT UNSIGNED NULL,
 type_name VARCHAR(255) NOT NULL,
 category ENUM('hull','module','rig','consumable','other') NOT NULL DEFAULT 'other',
 quantity INT UNSIGNED NOT NULL DEFAULT 1,
 notes VARCHAR(500) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 CONSTRAINT fk_shopping_list_items_list FOREIGN KEY (shopping_list_id) REFERENCES shopping_lists(id) ON DELETE CASCADE,
 KEY ix_shopping_items_list_category (shopping_list_id,category,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
