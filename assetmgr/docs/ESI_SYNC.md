# ESI asset sync contract

The ESI source adapter fetches every page from the character or corporation asset endpoint, validates required item/type/location IDs, checks that the reported page count stays stable, and rejects a response whose reported cache expiry has elapsed during the fetch. It does not store access tokens.

## SeAT integration

- Personal character IDs resolve through SeAT 5's `associatedCharacterIds()` relationship method; names are never used as an ownership key.
- Character access tokens are read from SeAT's existing `RefreshToken` model and are never copied into asset-manager tables.
- Expired tokens fail closed; SeAT remains responsible for token refresh.
- Corporation asset token resolution remains intentionally disabled until it can select a token with the required corporation asset scope and verify the character's corporation role.

## Still required before production

- Verify these model methods against the exact deployed SeAT 5 image and add integration tests.
- Add ESI compatibility-date and response ETag/conditional requests as appropriate for the current ESI requirements.
- Map item type IDs and location IDs to names through SeAT's existing SDE/universe tables.
- Add endpoint-specific rate/error-limit handling and avoid retrying before ESI cache expiry where applicable.
- Add integration tests against mocked HTTP responses for multi-page data, page-count changes, 401/403, 5xx, empty inventory, and expiry during pagination.

ESI paginates with the `X-Pages` response header and a `page` query parameter. Responses also expose cache expiry; don't hammer an endpoint before that expiry. See the official EVE Developer documentation linked in the project README.
