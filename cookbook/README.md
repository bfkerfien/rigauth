# Triglavian Cookbook — native SeAT integration

This is the native SeAT source-overlay implementation in `rigauth/cookbook/`. It uses the authenticated SeAT session and the existing SeAT database. It does not install a standalone Node/Express service, create a separate database, create independent user accounts, store EVE tokens, or register a new EVE developer application.

## First deployable increment

- Adds a Triglavian Cookbook entry to the SeAT sidebar.
- Adds authenticated routes under `/cookbook`.
- Searches published manufacturing products that have a manufacturing activity blueprint in SeAT's SDE.
- Recursively expands blueprint materials and separates minerals from other required inputs.
- Applies the selected product ME to the top-level blueprint and component ME to recursively expanded component blueprints.
- Uses only SDE data already loaded into SeAT; no guessed material quantities or market prices are displayed.

Required SDE tables:
- `invTypes` (already maintained by SeAT)
- `industryActivityProducts`
- `industryActivityMaterials`

The importer `cookbook/import-cookbook-sde.py` downloads CCP's current JSONL SDE, reads `blueprints.jsonl`, and imports manufacturing materials and products into the existing SeAT MariaDB database. It creates the two missing tables if needed and replaces only manufacturing activity rows (`activityID=1`). It does not alter `invTypes`, PI tables, ESI tables, or other activity rows. The row replacement is transactional.

## Import manufacturing SDE data

Run this on the server after pulling the branch. It reads the existing `/opt/seat-docker/.env` database settings and uses the existing `mariadb` Compose service. It does not print the database password. Python 3 and outbound HTTPS access are required.

```bash
cd /opt/rigauth
python3 cookbook/import-cookbook-sde.py
```

The importer reports the number of imported manufacturing material and product rows. If it fails, do not deploy; paste the complete error output.

## Deploy

On the server, from the existing checkout, fetch and switch to this feature branch:

```bash
cd /opt/rigauth
git fetch origin
git switch feature/cookbook-seat-integration 2>/dev/null || git switch -c feature/cookbook-seat-integration --track origin/feature/cookbook-seat-integration
git pull --ff-only origin feature/cookbook-seat-integration
git status --short --branch
bash /opt/rigauth/cookbook/deploy-cookbook.sh
```

The deployment script checks the repository, SeAT source paths, running front service, and bind mount; backs up every SeAT file it may change; installs the controller, route include and Blade view; registers routes and sidebar navigation idempotently; clears Laravel caches; verifies route registration; and caches views. On failure it restores the backed-up files and clears the route/view caches. It does not restart containers or run database migrations because this increment has no new tables.

## Test after deployment

1. Sign in to SeAT.
2. Open **Triglavian Cookbook** from the sidebar (or `/cookbook`).
3. Search for a published manufacturing product, select it, and calculate materials.
4. Confirm mineral and non-mineral inputs are shown separately.

## Explicit limitations in this increment

The recovered standalone project also had character asset aggregation for the Slyce Forge Sotiyo and a compressed-ore planner. Those features are **not** represented as complete here: they need to be connected to SeAT's actual ESI character-token and asset services, and to the SDE reprocessing data available in this installation. This increment deliberately does not query or invent asset quantities, mining stock, reprocessing yield, or Jita prices. The UI labels this limitation clearly.

No standalone OAuth callback, client secret, token-encryption key, cookie/session store, Docker Compose project, or independent MySQL schema is required.
