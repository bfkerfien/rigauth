# Database setup

Fittings and Market Intelligence uses SeAT's **existing configured database connection and database**. It does not create another database, database user, or Laravel connection.

All tables owned by this feature use the `fit_` prefix, so they are clearly separated from SeAT's own tables and from other extensions. The schema has no `DROP` statements and does not alter or delete existing tables.

## Initialize the tables

First confirm the database configured for SeAT in its private environment. Back up the database before adding the feature tables. Then run `fittings/database/schema.sql` against that same database.

For example, from a host that can connect to the SeAT MariaDB service, use the existing SeAT database name and credentials without putting the password in shell history:

```bash
mariadb --host=YOUR_SEAT_DB_HOST --user=YOUR_SEAT_DB_USER --password YOUR_EXISTING_SEAT_DATABASE < fittings/database/schema.sql
```

The command prompts for the password. Replace the three uppercase placeholders with the values already configured for SeAT. Do not run the schema against a different database by mistake.

## Tables created

- `fit_collection_runs`, `fit_market_orders`, `fit_item_comparisons`, `fit_market_type_volumes`: market snapshots, orders, comparisons, and item-volume cache.
- `fit_fits`, `fit_items`: canonical fittings and per-ship item quantities.
- `fit_categories`, `fit_category_assignments`: category catalogue and many-to-many category assignments.
- `fit_doctrines`, `fit_doctrine_assignments`: doctrine catalogue and fitting assignments.
- `fit_shopping_lists`, `fit_shopping_list_items`: home/deployment shopping lists.

A fitting is stored once and can be assigned to multiple categories. For example, the same Scimitar fit can appear in Logistics, Shield Doctrine, and Fleet Support.

No database connection patch is required: the SeAT controller uses Laravel's existing default connection.
