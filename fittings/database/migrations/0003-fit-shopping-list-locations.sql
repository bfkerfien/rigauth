-- Add station fields to shopping lists that were created before location integration.
ALTER TABLE fit_shopping_lists
  ADD COLUMN station_id BIGINT UNSIGNED NULL AFTER owner_user_id,
  ADD COLUMN station_name VARCHAR(255) NULL AFTER station_id;
