# Implementation status

## Current commit scope

This branch contains a deliberately disabled Stage 1 scaffold:
- Composer package metadata and Laravel service provider.
- Initial local asset snapshot schema.
- A paginated, database-only explorer view with an explicit character-owner column.
- Configuration defaults that keep the feature and sync disabled.

## Not yet implemented

Do not deploy or enable this scaffold yet. The following are required before a safe test installation:
- Confirm the deployed SeAT and Laravel/PHP versions and package discovery mechanism.
- Bind routes to SeAT's supported authentication and permission middleware.
- Enforce the authenticated user's actual character-to-SeAT-user relationship in the query. The current controller is a UI/query skeleton and does not yet implement authorization scoping.
- Implement ESI token retrieval/refresh using SeAT's supported models and services.
- Implement complete paginated character and corporation asset synchronization, staging and safe reconciliation.
- Populate type/location names and price cache from trusted data.
- Add queue jobs, scheduler integration, retry/backoff and rate/cache-expiry handling.
- Add automated tests for authorization, synchronization failures, owner grouping and pagination.
- Verify migrations against the live database engine/version on a disposable test environment.

## Safety acceptance criteria

1. Feature is disabled by default.
2. A SeAT user cannot query another user's character assets by changing URL parameters.
3. Corporation rows require a distinct corporation permission.
4. No ESI requests are triggered by browsing or filtering.
5. A partial or failed ESI response cannot delete or mark unseen assets as removed.
6. A deployment test confirms existing SeAT containers and routes remain healthy.
