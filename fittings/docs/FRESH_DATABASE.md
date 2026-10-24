# Fresh database setup

The Fittings section uses a dedicated `triglavian_market` schema on the existing SeAT MariaDB server. SeAT's own `seat` schema is not used for market records, and the legacy `eve_market` schema is left untouched.

## Create the dedicated database and account

Connect to MariaDB with a working administrative account and run the following SQL after replacing the example password with a long random value:

    CREATE DATABASE IF NOT EXISTS triglavian_market
      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'triglavian_market'@'%'
      IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';
    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
      ON triglavian_market.* TO 'triglavian_market'@'%';
    FLUSH PRIVILEGES;

Do not paste the real password into GitHub, chat, or shell history. Keep port 3306 private to the Docker network.

## Initialize tables

Import `fittings/database/schema.sql` into the new schema. Run it only against `triglavian_market`; it contains no DROP statements, but it is not intended to be run against SeAT's own schema.

    mariadb --user=triglavian_market --password triglavian_market < fittings/database/schema.sql

If the database is only reachable over the internal Docker network, run the client from a container attached to that network.

## Tables defined

- `collection_runs`, `market_orders`, `item_comparisons`, `market_type_volumes`: market snapshots, orders, comparisons, and item-volume cache.
- `ship_fits`, `ship_fit_items`: canonical fitting and its per-ship item quantities.
- `fit_categories`, `ship_fit_categories`: many-to-many category assignments; a fit can appear in multiple categories.
- `doctrines`, `ship_fit_doctrines`: doctrine catalogue and fitting assignments.
- `shopping_lists`, `shopping_list_items`: home/deployment shopping lists.

The native SeAT database connection is added by `fittings/patches/0006-market-db-connection.patch`. The real environment variables must be set in the private SeAT environment before the page is enabled.
