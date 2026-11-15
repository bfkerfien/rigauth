# Implementation status

## Deployment state

The Asset Manager package and provider are included in the SeAT deployment at `auth.triglavianconsortium.com`. Its route set is registered, and the restored SeAT database contains 5,034 cached asset rows. Browsing pages uses local database tables.

This confirms package discovery, route registration, and restored records. It does not confirm that a linked character can complete a fresh ESI synchronization with the deployed SeAT image.

## Integration checks before scheduling refreshes

- Verify SeAT 5's `associatedCharacterIds()` relationship and user ownership checks against a linked account.
- Verify corporation access uses the intended permission and allowlist.
- Confirm the token provider has the required ESI scopes and handles expired tokens.
- Validate successful and failed paginated snapshots against the deployed package versions.
- Keep existing cached rows until a complete ESI snapshot has been validated; incomplete fetches must not reconcile them away.

The feature and synchronization defaults remain controlled by the server environment. Do not put secrets or generated tokens in this repository.
