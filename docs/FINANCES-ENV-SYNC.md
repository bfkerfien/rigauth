# Environment-driven Potchven Finance sync

The sync job refreshes three character wallets/assets and the corporation wallet/assets using EVE SSO refresh tokens held in a private `.env`. Routine syncs do not require a user to log in to SeAT or complete interactive SSO.

## Configure
1. Copy `finance-sync/.env.example` to the private `/opt/potchven-finances/.env` and fill in the four EVE IDs and refresh tokens, the EVE client ID/secret, and a least-privilege database user for the `fin_*` tables.
2. Run the sync job from a runtime that can reach SeAT MariaDB: `node /opt/rigauth/finance-sync/sync-finances-env.js` (install `mysql2` and `dotenv` in that runtime if needed).
3. Test once manually before adding an hourly cron/systemd timer.

Each character token needs `esi-wallet.read_character_wallet.v1` and `esi-assets.read_assets.v1`. The corporation token must be a character refresh token with `esi-wallet.read_corporation_wallets.v1` and `esi-assets.read_corporation_assets.v1`, plus the required in-game corporation roles. A corporation itself does not log in to EVE SSO.

Refresh tokens are initially supplied by `.env`. If EVE SSO rotates a token, the new value is stored in `.finance-token-state.json` with file mode 0600 and used next run; keep this state file private and backed up securely. Never commit actual secrets or token-state files.

The job imports all ESI pages and upserts wallet journal rows. It records asset snapshots but does not calculate market value, and does not yet infer site completion from payouts.
