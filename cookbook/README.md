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
- `invTypes`
- `industryActivityProducts`
- `industryActivityMaterials`

If those tables are missing, the page explains that the SeAT SDE must be updated. The deploy script does not download or modify SDE data.

## Deploy

On the server, from the existing checkout, fetch and switch to this feature branch:

```bash
cd /opt/rigauth
git fetch origin
git switch feature/cookbook-seat-integration 2>/dev/null || git switch -c feature/cookbook-seat-integration --track origin/feature/cookbook-seat-integration
git pull --ff-only origin feature/cookbook-seat-integration
git status --short --branch
sudo bash /opt/rigauth/cookbook/deploy-cookbook.sh
```

The script checks the repository, SeAT source paths, running front service, and bind mount; backs up every SeAT file it may change; installs the controller, route include and Blade view; registers routes and sidebar navigation idempotently; clears Laravel caches; verifies route registration; and caches views. On failure it restores the backed-up files and clears the route/view caches. It does not restart containers or run database migrations because this increment has no new tables.

## Test after deployment

1. Sign in to SeAT.
2. Open **Triglavian Cookbook** from the sidebar (or `/cookbook`).
3. Search for a published manufacturing product, select it, and calculate materials.
4. Confirm mineral and non-mineral inputs are shown separately.

## Explicit limitations in this increment

The recovered standalone project also had character asset aggregation for the Slyce Forge Sotiyo and a compressed-ore planner. Those features are **not** represented as complete here: they need to be connected to SeAT's actual ESI character-token and asset services, and to the SDE reprocessing data available in this installation. This increment deliberately does not query or invent asset quantities, mining stock, reprocessing yield, or Jita prices. The UI labels this limitation clearly.

No standalone OAuth callback, client secret, token-encryption key, cookie/session store, Docker Compose project, or independent MySQL schema is required.
