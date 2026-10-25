# ESI asset sync contract

The first ESI source adapter now fetches every page from the character or corporation assets endpoint, validates required item/type/location IDs, checks that the reported page count stays stable, and rejects a response whose reported cache expiry has elapsed during the fetch. It does not store access tokens.

## Still required before the job can run

- Implement `AssetAccessTokenProvider` using the installed SeAT version's token retrieval/refresh services. Do not implement a second token store.
- Ensure personal-character sync is dispatched only for characters related to the current SeAT user, and corporation sync only after explicit SeAT permission checks.
- Add ESI compatibility-date and response ETag/conditional requests as appropriate for the current ESI requirements.
- Map item type IDs and location IDs to names through SeAT's existing SDE/universe tables.
- Add endpoint-specific rate/error-limit handling and avoid retrying before ESI cache expiry where applicable.
- Add integration tests against mocked HTTP responses for multi-page data, page-count changes, 401/403, 5xx, empty inventory, and expiry during pagination.

ESI paginates with the `X-Pages` response header and a `page` query parameter. Responses also expose cache expiry; don't hammer an endpoint before that expiry. See the official EVE Developer documentation linked in the project README.
