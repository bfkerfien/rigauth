# Triglavian Consortium SeAT Mining Ledger

Native SeAT source-overlay add-on maintained in `rigauth/mining/`. It uses the existing SeAT database and authenticated user session; it does not create a separate service or database.

## Initial scope

- Record mining operations, systems, locations, dates, participating characters, and yield rows.
- Record yields manually or import a CSV using the documented format below.
- Show Jita estimated sell value when the ESI regional market response contains sell orders at Jita IV - Moon 4 - Caldari Navy Assembly Plant (station ID 60003760).
- Record source method and verification status. A manual entry or CSV row is reported data, not an ESI-verified mining event.
- Pilots see their own yield records. Corporation-wide records are available only to users with the SeAT ability `mining.corporation.view` or `global.superuser`. Do not grant this ability broadly.
- Use SeAT's current database connection and existing authentication.

## ESI limitations

ESI does not expose a complete historical stream of mined ore or live anomaly presence. Inventory snapshots and their differences are not treated as proof of mining. No yield is generated automatically from assets, anomalies, or market data. Jita pricing is an estimate, is cached, and can be unavailable if ESI has no matching sell order or is temporarily unavailable.

## CSV import

UTF-8 CSV with a header row. Required columns:

```csv
character_id,type_id,quantity,recorded_at,operation_id,source_reference
123456789,1234,2500,2026-10-29 13:00:00,1,session-notes-001
```

- `character_id` must belong to the logged-in pilot unless the user has corporation-view permission.
- `type_id` must exist in SeAT's `invTypes` SDE table when that table is available.
- `quantity` must be a positive integer.
- `operation_id` may be blank; a nonblank ID must reference an existing operation visible to the user.
- Duplicate file uploads are detected by SHA-256 and rejected. The import preview is not treated as ESI verification.
- A CSV is only accepted as reported/imported data; it does not become verified merely because it imported successfully.

## Install / deploy

From the repository checkout at `/opt/rigauth`:

```bash
sudo bash /opt/rigauth/mining/deploy-mining.sh
```

The script expects the existing SeAT source at `/opt/seat-docker/packages/eveseat/web` and running Compose service `front`. It backs up files it changes, installs the migration/controller/routes/view, registers the route and sidebar link idempotently, runs migrations, clears Laravel caches, and verifies the route. It does not deploy, restart containers, modify secrets, or create a separate database.

## Access configuration

After deployment, register/grant the SeAT ACL ability `mining.corporation.view` only to trusted corporation mining managers. The controller also accepts `global.superuser`. If the ACL ability is not registered in the running SeAT ACL system, only superusers will see corporation-wide data until the ability is configured.

## Current limitations / next increment

This first version supports operations, manual yield entry, CSV import, audit entries, and best-effort Jita pricing. It does not claim ESI-verified mining yields, automatic anomaly discovery, automatic mining-session detection, or an in-game mining log integration.


## Mining timer board and scan-window paste

The Mining page includes a timer board for manually timed site clears, anomaly timers, and fleet coordination. Add a label, system, optional anomaly, start time, and duration; the board displays its expiry and countdown. Timers are coordination records, not claims about authoritative EVE respawn mechanics.

Copy rows from the in-game Probe Scanner / scan results and paste them into the anomaly import box, selecting the solar system for that paste. Tab-separated text is supported. The first column is stored as the anomaly label, and the original row is retained for reference. Repeated anomaly names already marked present in the same system refresh their last-seen timestamp. Operators can mark rows present, mined, or despawned.

The parser is intentionally forgiving because EVE clipboard layouts can vary. Confirm the labels after the first paste and share a sample of the actual copied scan text if your client exports a different layout; the parser can then be tuned to that exact format.
