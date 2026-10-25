# Triglavian Asset Manager

Native SeAT package source maintained inside the `rigauth/assetmgr` directory.

## Status

Stage 1 foundation scaffold. This is **not production-ready and must not be deployed yet**. The package must be checked against the exact SeAT/Laravel version and package-discovery conventions in the running `/opt/seat-docker` installation before activation.

## Product requirements

- Combine personal assets for every character associated with the authenticated SeAT user.
- Every personal asset row includes its character owner.
- Keep corporation assets separate and enforce corporation permissions independently.
- Persist ESI snapshots locally. Browsing, sorting, filtering, valuation and exports read the local database, never ESI directly.
- Cache Jita and regional price data with timestamps and source metadata.
- Use background jobs for synchronization, honor ESI cache expiry/rate limits, and preserve the last good snapshot on partial failure.
- Keep this package isolated from the existing rigauth application and other folders.

## Planned stages

1. Foundation, permissions, migrations and integration verification.
2. Character/corporation asset sync and owner-aware explorer.
3. Jita/regional price cache and valuation history.
4. Corporation logistics and reports.
5. Financial/industry integrations where available through SeAT/ESI.
6. Security tests, deployment checklist and rollback.

## Important implementation rules

- Resolve linked characters through SeAT's real user/character relationship model; never infer ownership from names or corporation membership.
- Use SeAT authorization and policies on every endpoint and query. UI hiding is not authorization.
- Only reconcile removed assets after a complete successful paginated ESI fetch.
- Do not store access tokens in asset tables or logs.
- Do not run migrations, rebuild containers, or change the live server as part of source development.
- Avoid introducing a second database or login service.

## Next step

Inspect the running SeAT version and its package-loading pattern, then adapt the service provider, routes, queue configuration and permissions to that version before installing this package.
