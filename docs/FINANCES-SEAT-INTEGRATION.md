# Potchven Finances — SeAT integration

This branch adds a native SeAT page at `/potchven-finances`, labelled **Potchven Finances** in the sidebar. The authenticated SeAT account must have the EVE character **Turaneau** linked to it. The sidebar condition is only a convenience; the controller also checks every request.

## Deploy the UI

```bash
cd /opt/rigauth
bash ./deploy-finances.sh
```

The script backs up the files it changes, clears route/view caches, verifies the route list, and restarts only the SeAT front service. It does not import or delete database data.

## One-time database import

```bash
cd /opt/rigauth
bash ./import-finances-db.sh
```

Defaults: source container `potchven-finances-db-1`, target container `seat-docker-mariadb-1`, target database `seat`. The import script refuses to run if `fin_*` tables already exist, backs up the SeAT database to `/root`, dumps the standalone finance database without modifying it, renames `finance_*` identifiers to `fin_*`, and imports the tables and data. Keep both the standalone database and the backup until verification is complete.

## Current scope

The dashboard reads imported finance owners, wallet entries, site events, pending SRP obligations, wallet snapshots, and asset snapshots. The **Reset Financial Period** action sets a new reporting-period start and restarts the 180-day countdown. Site counts, revenue credits, character operating expenses, estimated profit, and average sites per day are calculated from that period start. Historical wallet and site records are retained, current wallet/assets remain current balances, and outstanding SRP obligations are carried forward. Before the first manual reset, period totals default to the current calendar month. The dashboard can manually record a successful site using the established three-payout/45,000,000 ISK revenue-credit convention. The original Node application's EVE SSO token-refresh and ESI wallet/assets synchronization routes have **not yet been ported** into SeAT's ESI token system, so live synchronization remains follow-up work.

## Access verification

- Turaneau's SeAT account: sidebar entry appears and `/potchven-finances` loads.
- A different account: no sidebar entry and direct access returns HTTP 403.
- Confirm the POST route also rejects other accounts.
- Verify `fin_*` table names and row counts before retiring the standalone database.


## Corporation wallet balances

The dashboard displays **Corp Main Wallet (Division 3)** from the latest corporation wallet snapshot and **SRP Wallet (Division 2)** from the separate `srp_wallet_balance_isk` setting refreshed by the 15-minute sync. Both balances come from the corporation wallets ESI endpoint; the sync also reads the Division 3 journal. The second division balance is stored separately because the existing wallet snapshot schema does not record a division number. The two displayed balances are not summed together.


## Telemetry-based site counter

The dashboard's **Sites this period** counter is based on telemetry items in the corporation's **Office Hangar 1 (CorpSAG1)** at the Nani I station, not Ipowimo's personal hangar and not the count of payout-matched site events. The station ID defaults to `60011173` (Nani I — Svarog Clade Extractive Terminus). The default telemetry type IDs are `87222,87258` (Restricted and Unrestricted Hyperspace Telemetry); both can be overridden with SeAT environment variables `FIN_TELEMETRY_STATION_ID` and `FIN_TELEMETRY_TYPE_IDS` if the corporation uses a different station or telemetry item type.

The displayed site count is `floor(max(0, current qualifying telemetry quantity - period baseline) / 60)`; site revenue remains that count multiplied by 45,000,000 ISK. The first dashboard activation captures the current telemetry quantity as a baseline so pre-existing items are not incorrectly counted as new sites. **Reset Financial Period** captures a fresh baseline alongside the reporting-period start. Corporation assets are synced from ESI and their raw payload retains the hangar location flag used by this filter.
