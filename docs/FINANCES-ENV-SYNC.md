# Potchven Finances — environment-driven sync

The finance page is private to Turaneau in SeAT, but data synchronization does not depend on an interactive SeAT login. It uses EVE SSO refresh tokens supplied in the standalone finance application's private .env file.

## Install

From the server, with the repository checked out on finances/seat-integration:

```bash
cd /opt/rigauth
bash ./deploy-finance-sync.sh
```

The script copies the sync worker into /opt/potchven-finances/src/sync-finances-env.js and preserves a timestamped backup if that file already exists. It does not change the real .env.

## Configure the private .env

Use finance-sync/.env.example as a reference and add these values to /opt/potchven-finances/.env:

- EVE_CLIENT_ID and EVE_CLIENT_SECRET
- FIN_CHAR1_EVE_ID / FIN_CHAR1_REFRESH_TOKEN
- FIN_CHAR2_EVE_ID / FIN_CHAR2_REFRESH_TOKEN
- FIN_CHAR3_EVE_ID / FIN_CHAR3_REFRESH_TOKEN
- FIN_CORP_EVE_ID / FIN_CORP_REFRESH_TOKEN
- FIN_DB_HOST, FIN_DB_PORT, FIN_DB_NAME, FIN_DB_USER, FIN_DB_PASSWORD

Use a MariaDB account with access only to the required fin_* tables. Do not expose MariaDB publicly. The process must run in a Docker network that can reach the SeAT database.

Each character refresh token needs esi-wallet.read_character_wallet.v1 and esi-assets.read_assets.v1. The corporation token is also a character token; it needs esi-wallet.read_corporation_wallets.v1 and esi-assets.read_corporation_assets.v1 and the relevant in-game corporate roles. You authorize each token once to get the refresh token. Routine sync then refreshes tokens automatically without interactive SSO.

## Run and schedule

Test it manually inside the finance app runtime, from its project working directory:

```bash
node src/sync-finances-env.js
```

Only after verifying a successful run, schedule that command hourly using cron/systemd in the same runtime/network. Do not schedule it on the host until database reachability and Node dependencies are confirmed.

The worker updates all pages of character/corporation wallet journals, records wallet balances, and captures asset snapshots. If SSO rotates a refresh token, it saves the updated token in a private .finance-token-state.json file with mode 0600. Keep that file private and backed up securely. The worker does not yet calculate market valuations or automatically infer site completion from payout timing.
