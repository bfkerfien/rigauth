# SeAT Planetary Industry (PI)

Native PI planner module for the Triglavian Consortium SeAT integration.

## Version 1

- P2, P3 and P4 tabs with expandable schematic/input details.
- Profit ranking per schematic batch using the existing authenticated SeAT Reactions market endpoint and its cached Jita IV-4 prices.
- Input valuation is the midpoint of best buy and sell; output valuation is Jita sell, less the configurable sell fee and modeled customs tax.
- Colony status tracker with manually entered extractor reset times. These records are saved in the browser's local storage; they are not yet synced to ESI or shared across browsers.

## Source files

- `PlanetaryController.php`: controller source.
- `index.blade.php`: native SeAT view.
- `Planetary.php`: route definition reference.
- `deploy-pi.sh`: guarded deployment script.

## Deploy

Run from the repository checkout on the server after reviewing the branch:

```bash
cd /opt/rigauth
git fetch origin
git switch feature/seat-pi-planner-v1
git pull --ff-only origin feature/seat-pi-planner-v1
bash pi/deploy-pi.sh
```

The deployment script backs up the affected live files, checks the SeAT front service and bind mount, edits routes/sidebar only at known anchors, clears Laravel route/view caches, and attempts rollback if the local health check fails. It does not remove the standalone PI website.

## Important v1 limitation

The controller looks for planetary schematic tables in the SeAT database. If the database does not contain those EVE SDE tables, the view displays an explicit warning and recipe rankings remain empty. Before live use, verify that the SDE schematic tables are present; if not, ship a validated PI recipe catalog in this folder (rather than inventing or silently omitting recipes).
