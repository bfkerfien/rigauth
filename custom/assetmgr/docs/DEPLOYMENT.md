# Deployment checklist (not ready for production)

This directory is the package source under bfkerfien/rigauth/assetmgr. It has not been installed into the running SeAT instance.

## Before installation

1. Confirm the exact image tag, PHP/Laravel versions, mounted source paths, and Composer package-discovery behavior in /opt/seat-docker.
2. Verify the SeAT model methods and schemas used by the resolver/token adapter: associatedCharacterIds(), RefreshToken, CharacterRole, CharacterAffiliation, CharacterInfo, CorporationInfo, and SDE/universe models.
3. Review the draft pull request and make CI pass. Also run integration tests against a disposable SeAT 5 environment.
4. Confirm the deployed queue driver, worker concurrency, cache backend, ESI compatibility date, and User-Agent contact.
5. Back up the existing database and document rollback before applying migrations.

## Dependency security note

The current SeAT 5 dependency line uses Laravel 10, which current Composer advisory policy may block because of upstream advisories. The CI workflow bypasses advisory blocking only for its isolated compatibility test run. Never copy that CI-only override or the --no-blocking option into a production install. Review the SeAT framework upgrade path separately before production rollout.

## Package installation architecture

Because the package is nested in the rigauth repository, Composer cannot install it directly as a normal GitHub package from the repository root. The deployment process must either:

- make assetmgr/ available as a local Composer path repository inside the SeAT application/container; or
- create a dedicated package artifact/subtree during the build pipeline.

Choose the method only after inspecting the existing /opt/seat-docker deployment and its mounted paths. Do not alter the live compose stack just to test this package.

## First test deployment

1. Build a disposable/test SeAT container and a separate test database.
2. Install the package and verify Laravel auto-discovery, package.sidebar, the corporation.assetmgr_view permission, routes, and command registration.
3. Run migrations in the test database only.
4. Test a SeAT user with multiple linked characters, then test a different SeAT user and verify there is no cross-account asset access.
5. Verify corporation access with a Director, a delegated role, a user without permission, and a corporation outside the allowlist.
6. Mock ESI responses for complete/partial pagination, cache expiry, revoked scope, token expiry, rate limiting, and empty inventory.
7. Verify cached Jita/regional prices, stale labels, sorting, search, and CSV exports.
8. Confirm the last good snapshot remains intact after any failed sync.
9. Test the rollback plan and confirm existing SeAT services remain healthy.

## Feature flags

Defaults keep all functionality disabled:

- SEAT_ASSETMGR_ENABLED=false
- SEAT_ASSETMGR_SYNC_ENABLED=false
- SEAT_ASSETMGR_CORPORATION_SYNC_ENABLED=false
- SEAT_ASSETMGR_CORPORATION_IDS= (empty allowlist)
- SEAT_ASSETMGR_QUEUE=default

Only after the test deployment passes should these be enabled in a controlled rollout. Set the corporation allowlist to the exact corporation IDs authorized for caching. Use a contact-bearing SEAT_ASSETMGR_USER_AGENT and a reviewed SEAT_ASSETMGR_ESI_COMPATIBILITY_DATE.

## Commands (only after installation and testing)

- php artisan assetmgr:sync-characters
- php artisan assetmgr:sync-prices
- php artisan assetmgr:sync-corporations (requires corporation sync enabled and an explicit allowlist)
- php artisan assetmgr:sync-wallet-journals (for character wallet journals with the required scope)
- php artisan assetmgr:sync-wallet-transactions (for market transactions with the required wallet scope)
- php artisan assetmgr:sync-market-orders (for active character market orders with the required market-order scope)
- php artisan assetmgr:sync-corporation-market-orders (requires corporation sync enabled, an explicit corporation allowlist, and a Director token)
- php artisan assetmgr:sync-corporation-wallet-journals (syncs corporation wallet divisions 1–7; requires the corporation wallet scope and a separately permissioned viewer role)
- php artisan assetmgr:sync-contracts (for personal contracts; each character must grant esi-contracts.read_character_contracts.v1)
- php artisan assetmgr:capture-valuations (stores hourly valuation snapshots)

Schedule these using the installed SeAT scheduler only after checking the ESI cache expiry behavior and queue capacity. Do not poll ESI from page requests.

Never commit real EVE client secrets, access/refresh tokens, database passwords, or production environment files.
