# Implementation status

## Current branch scope

This branch contains an early Stage 1 scaffold for the SeAT Asset Manager:
- Composer package metadata and Laravel service provider.
- Local asset, sync-state, price-cache and snapshot-staging schemas.
- Personal asset query builder with a server-side character ID allowlist, search, sorting and pagination.
- A queue job contract with retries/backoff and sync-state reporting.
- Complete-snapshot validation and transactional staging/reconciliation.
- Feature and synchronization disabled by default.

## Critical integration blockers

Do not deploy or enable this package yet:
- The actual SeAT user-to-character relationship is not yet wired. The resolver intentionally fails closed until this is implemented against the exact installed SeAT models.
- The SeAT-native sidebar and corporation permission are wired, but must be verified against the installed SeAT image and role/permission behavior.
- The ESI adapter is implemented for personal character assets, but must be verified against the exact deployed SeAT image and tested with real scopes/tokens.
- Type/location name resolution and background Jita/regional market price ingestion are implemented but have not yet passed CI or integration testing.
- Automated PHP/feature tests have not yet run against the deployed SeAT/Laravel/PHP versions.
- Composer constraints and package-discovery behavior must be checked against the exact running SeAT 5 image before installation.

## Safety acceptance criteria

1. Feature is disabled by default.
2. A SeAT user cannot query another user's character assets by changing URL parameters.
3. Corporation rows require a distinct corporation permission.
4. Browsing/filtering/sorting/exporting reads local database tables only.
5. An incomplete or failed ESI pagination sequence cannot reconcile or delete previous asset rows.
6. A deployment test confirms existing SeAT containers and routes remain healthy.
