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

The dashboard reads finance owners, wallet entries, site events, pending SRP obligations, wallet snapshots, and asset snapshots from SeAT's database. The **Reset Financial Period** action sets a new reporting-period start and captures a fresh telemetry baseline. Historical wallet and site records are retained, current wallet/assets remain current balances, and outstanding SRP obligations are carried forward. The SeAT-backed Node worker refreshes the three character wallets/journals/assets and corporation wallet balances, Division 3 and Division 2 journals, and CorpSAG3 asset snapshots every 15 minutes using the encrypted EVE SSO credentials already stored in SeAT. The ISK per Hour tab records timed sessions, manually entered telemetry deposited, the three tracked characters' reward payouts, and character wallet expenses excluding corporation tax; it builds weekday averages and a collapsible session ledger.

## Access verification

- Turaneau's SeAT account: sidebar entry appears and `/potchven-finances` loads.
- A different account: no sidebar entry and direct access returns HTTP 403.
- Confirm the POST route also rejects other accounts.
- Verify `fin_*` table names and row counts before retiring the standalone database.


## Corporation wallet balances

The dashboard displays **Corp Main Wallet (Division 3)** from the latest corporation wallet snapshot. The 15-minute sync also imports corporation Division 2 data for the existing site/SRP accounting workflows, but Division 2 is not part of ISK per Hour calculations.


## Telemetry-based site counter

The dashboard's site counter uses corporation telemetry item type **89219** in **Hangar Division 3 (CorpSAG3)** across all stations. Hangar Divisions 1 and 2 and all other divisions are excluded. The displayed site count is the positive increase over the saved period baseline divided by 60 (integer sites). The first dashboard activation captures the current qualifying quantity as a baseline so pre-existing items are not counted as new sites. **Reset Financial Period** captures a fresh baseline alongside the reporting-period start.

## ISK per Hour accounting

Each session begins with **Start Session** and ends with **Stop Session**. At stop, enter the number of telemetry units deposited during that session; each unit contributes 300,000 ISK. Revenue is counted from positive 9,000,000 ISK `corporate_reward_payout` entries in the three active character wallet journals. Negative operational expenses from those same character journals are deducted, excluding `corporation_tax`; corporation wallet payouts and Division 2 expenses are not used in this calculation. The session's initial totals are calculated at stop. The scheduled 15-minute finance worker then recalculates every stopped session whose `stopped_at` is within the preceding 90 minutes, immediately including the first sync after stop and repeating on each subsequent run. After a session is more than 90 minutes past `stopped_at`, its totals are frozen. No separate pending/reconciled flag is stored; the ledger derives **Reconciling** versus **Final** from `stopped_at`. Dashboard page loads do not recalculate sessions. The weekday chart averages the saved session ISK/hour by session start weekday, and the ledger retains all sessions while displaying the latest 100.
