# Fittings & Market Intelligence

Native fitting management and market intelligence for the Triglavian Consortium's SeAT installation at https://auth.triglavianconsortium.com.

## Fitting management

The native SeAT page is built from the existing SeAT web package layout and uses the existing authenticated session. The management scaffold supports creating, editing, deleting, filtering, and categorizing fitting records. Administrators can also set separate home and deployment station/system names and IDs. Creating a home or deployment shopping list copies the corresponding saved station and system onto that list. Writes are restricted to SeAT users with the `global.superuser` permission.

**A fitting can belong to multiple categories.** The database uses `fit_fits` as the canonical fit record, `fit_categories` as the category catalogue, and `fit_category_assignments` as a many-to-many join. For example, one Scimitar fitting can appear under Logistics, Shield Doctrine, and Fleet Support without duplicating the fit. Doctrine tags are modeled separately.

The old standalone project is the reference for the fitting workflow: its fitting collection, doctrine assignment, and item list are documented in the legacy collector source. The new page is native to SeAT rather than embedding the old standalone website.

## Data architecture

- Use SeAT's existing configured database and Laravel connection. All feature tables use the `fit_` prefix.
- Do not create a separate database or database user for this feature.
- Leave legacy `eve_market` and its collector untouched until an explicit migration is planned and verified.
- Keep credentials, tokens, and real `.env` files out of Git.

See `database/schema.sql`, `docs/FRESH_DATABASE.md`, and `.env.example`.

## Source integration

- `seat/src/Http/Controllers/FittingsController.php`: native Laravel controller.
- `seat/src/resources/views/fittings/manage.blade.php`: fitting library and category UI.
- `patches/0005-fittings-management.patch`: routes.
- - `restore-seat-source.sh`: reconstructs the pinned SeAT source tree and applies integration patches.

Repository changes are not a live deployment. Do not switch production traffic or stop the legacy collector until the database connection, permissions, fitting save/edit flow, and market collector have been tested.
