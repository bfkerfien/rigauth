-- Run once on an existing SeAT database before deploying doctrine scope support.
-- MariaDB 10.11 supports ADD COLUMN IF NOT EXISTS, so this is safe to rerun.
ALTER TABLE fit_doctrines
  ADD COLUMN IF NOT EXISTS location_scope ENUM('home','deployment','both') NOT NULL DEFAULT 'both';
