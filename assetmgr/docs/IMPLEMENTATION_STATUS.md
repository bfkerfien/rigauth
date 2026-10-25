# Implementation status

## Current branch scope

This branch contains the current development implementation for the SeAT Asset Manager:
- Composer package metadata and Laravel service provider.
- Local asset, sync-state, price-cache and snapshot-staging schemas.
- Personal asset query builder with a server-side character ID allowlist, search, sorting and pagination.
- A queue job contract with retries/backoff and sync-state reporting.
- Complete-snapshot validation and transactional staging/reconciliation.
- Character contracts are cached, filtered by linked-character ownership, refreshable, and exportable. Corporation contracts and industry jobs remain to be implemented.
- Character and corporation market orders, and corporation wallet journals for divisions 1–7, are separate from personal asset and finance records.
- Feature and synchronization disabled by default.

## Critical integration blockers

Do not deploy or enable this package yet:
- The personal portfolio resolver now uses SeAT 5's `associatedCharacterIds()` relationship; verify it against the exact installed image.
- The SeAT-native sidebar and corporation permission are wired, but must be verified against the installed SeAT image and role/permission behavior.
- Character and allowlisted corporation ESI adapters are implemented; verify the token models, scopes, and corporation-role checks against the exact deployed SeAT image.
- Type/location name resolution and background Jita/regional market price ingestion are implemented but have not yet passed CI or integration testing.
- Automated PHP syntax, Composer validation and unit/feature tests pass in GitHub Actions, but these are package tests—not a live-image integration test.
- Composer constraints and package-discovery behavior must be checked against the exact running SeAT 5 image before installation.

## Safety acceptance criteria

1. Feature is disabled by default.
2. A SeAT user cannot query another user's character assets by changing URL parameters.
3. Corporation rows require a distinct corporation permission.
4. Browsing/filtering/sorting/exporting reads local database tables only.
5. An incomplete or failed ESI pagination sequence cannot reconcile or delete previous asset rows.
6. A deployment test confirms existing SeAT containers and routes remain healthy.
