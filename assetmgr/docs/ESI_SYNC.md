# ESI asset sync contract

The ESI source adapter fetches every page from the character or corporation asset endpoint, validates required item/type/location IDs, checks that the reported page count stays stable, and rejects a response whose reported cache expiry has elapsed during the fetch. It does not store access tokens.

## SeAT integration

- Personal character IDs resolve through SeAT 5's `associatedCharacterIds()` relationship method; names are never used as an ownership key.
- Character access tokens are read from SeAT's existing `RefreshToken` model and are never copied into asset-manager tables.
- Expired tokens fail closed; SeAT remains responsible for token refresh.
- Corporation token selection requires both `esi-assets.read_corporation_assets.v1` and a SeAT `CharacterRole` record with `role=Director`, `scope=roles`, plus matching `CharacterAffiliation`.

## Still required before production

- Verify these model methods against the exact deployed SeAT 5 image and add integration tests.
- Validate type/location/region mapping against the exact deployed SeAT SDE schema.
- Add endpoint-specific error-limit handling and conditional ETag requests; avoid retries before ESI cache expiry where applicable.
- Market prices use the best buy and best sell order found in the requested region. The current valuation UI uses sell prices, not buy prices or adjusted-price estimates.
- Add integration tests against mocked HTTP responses for multi-page data, page-count changes, 401/403, 5xx, empty inventory, and expiry during pagination.

ESI requests use `X-Compatibility-Date` and paginate with the `X-Pages` response header and a `page` query parameter. Responses also expose cache expiry; don't hammer an endpoint before that expiry. See the official EVE Developer documentation linked in the project README.
