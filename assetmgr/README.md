# Triglavian Asset Manager

Native SeAT asset-management package source maintained inside the `rigauth/assetmgr` directory.

## Status

Active development branch: `feature/assetmgr-complete`. **Do not deploy or enable yet.** This source now includes personal and corporation inventory, cached market pricing/valuations, character wallet journals and transactions, personal/corporation market orders, corporation wallet journals, and a character contract explorer. SeAT authorization, schemas, scopes, and installation must still be verified against the exact running image.

## Current foundation

- Feature and synchronization are disabled by default.
- Local asset inventory, sync-state, price-cache and snapshot-staging schemas.
- Personal inventory queries are restricted to character IDs returned by SeAT's linked-character relationship; arbitrary character filters outside that list produce no rows.
- Search, sort and pagination operate on the local database.
- Queue job contract with per-owner overlap protection, retry/backoff, sync status and ESI cache cooldown.
- ESI page fetcher checks page counts and cache expiry, deduplicates repeated item IDs and validates required identifiers.
- Type names and known station/system/structure names and region IDs resolve through SeAT's SDE/universe models.
- Background price jobs cache best buy and best sell orders for Jita and the inventory's local regions; browsing reads that cache and flags stale prices.
- Snapshot reconciliation stages and upserts a complete snapshot transactionally; failed or incomplete fetches retain the previous good inventory.
- PHPUnit unit tests and a GitHub Actions workflow are included.

## Planned next stages

1. Verify the package against the exact deployed SeAT/PHP/Laravel version and run CI.
2. Finish corporation token selection and separate corporation permissions.
3. Complete corporation contract sync and industry-job tracking.
4. Add deeper ship fitting/container views and financial rollups.
5. Verify all scopes, SeAT role checks, migrations, package discovery and rollback against the exact running image in a disposable environment.

## Configuration

Defaults intentionally keep the module off:

- `SEAT_ASSETMGR_ENABLED=false`
- `SEAT_ASSETMGR_SYNC_ENABLED=false`
- `SEAT_ASSETMGR_QUEUE=default`

Only after SeAT integration and authorization tests pass should the feature and sync be enabled. Corporation sync additionally requires `SEAT_ASSETMGR_CORPORATION_SYNC_ENABLED=true` and `SEAT_ASSETMGR_CORPORATION_IDS` set to a comma-separated allowlist. Configure `SEAT_ASSETMGR_ESI_COMPATIBILITY_DATE` when reviewing ESI compatibility updates, and set `SEAT_ASSETMGR_USER_AGENT` to identify the app and a contact method. The Artisan commands `assetmgr:sync-characters`, `assetmgr:sync-prices`, `assetmgr:sync-contracts`, `assetmgr:sync-wallet-journals`, `assetmgr:sync-wallet-transactions`, and `assetmgr:sync-market-orders` queue eligible personal-data jobs when both main flags are enabled. `assetmgr:sync-corporations` additionally requires the corporation-sync flag and allowlist. It must be scheduled at a cadence compatible with ESI cache expiry; the job also enforces a per-character cooldown.

## Safety rules

- Resolve linked characters through SeAT's real user/character relationship; never infer ownership from names or corporation membership.
- Enforce authorization server-side. Hiding a menu item is not authorization.
- Personal assets and corporation assets remain separate datasets and permissions.
- Do not put access tokens in asset tables, job payloads or logs.
- Only reconcile removed assets after all ESI pages succeed and pass validation.
- Do not run migrations, rebuild containers, or change the live server during source development.
- Do not introduce a second database or login service.

## ESI notes

ESI requests use the compatibility-date header, `page` and the `X-Pages` response header for pagination. ESI responses are cache-backed and expose an `Expires` header; the source adapter rejects cache expiry/page-count inconsistencies instead of reconciling a possibly mixed snapshot.

Official references:
- [ESI pagination](https://developers.eveonline.com/docs/services/esi/pagination/x-pages/)
- [ESI caching and error limits](https://docs.esi.evetech.net/docs/esi_introduction.html)
- [SeAT package development](https://seat-docs.readthedocs.io/en/latest/developer_guides/package_development/)
