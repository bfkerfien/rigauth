# SeAT-backed Potchven Finances synchronization

This worker uses the three encrypted character credentials already stored in SeAT's `fin_oauth_credentials` table. It does not require refresh tokens in an `.env` file. The old standalone Potchven Finances app remains running and supplies the existing `TOKEN_ENCRYPTION_KEY`, EVE client ID, and EVE client secret to the worker runtime.

## Deploy

On the server:

```bash
cd /opt/rigauth
git fetch origin
git switch finances/seat-integration
git pull --ff-only origin finances/seat-integration
sudo bash ./deploy-finances-seat-sync.sh
sudo /usr/local/sbin/potchven-finance-sync
```

The deploy script copies the worker and installs a runner. It does not restart or stop the old finance app. The runner reads SeAT database connection values from the running SeAT front container and passes them only to the one-shot worker process.

## What it syncs

- Three active character owners: wallet balances, wallet journals, and assets.
- Corporation owner (default EVE ID 98843282): wallet division 1 balance and journal, plus corporation assets filtered to `location_flag = CorpSAG3` (Hangar Division 3) across all corporation locations returned by ESI. Items in other hangar divisions are excluded from the finance asset snapshots. The sync logs both the total corporation assets scanned and the Hangar 3 item count. It uses one of the three character credentials that has both corporate wallet and asset scopes.
- Rotated SSO refresh tokens are encrypted with the existing AES-256-GCM key and saved back into SeAT's `fin_oauth_credentials` row immediately after refresh.
- Wallet journals are upserted; wallet and asset snapshots are retained as history.

Asset market valuation is not calculated by this worker. It also does not infer completed sites from payouts or create SRP obligations; those are separate business-logic tasks and must not be inferred from incomplete data.

## Safety

The worker never prints tokens or secrets. The runner does not print database credentials. Do not copy the `.env` file or token ciphertext into chat. The standalone finance app and its database should remain available until the native page and live sync are verified.
