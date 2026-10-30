# Triglavian Cookbook — native SeAT integration

The Cookbook runs inside SeAT using the authenticated SeAT session, existing SeAT database, SDE tables and ESI-cached character assets. It does not create a standalone service, database, user account, OAuth application or separate token store.

## Manufacturing planner
- Search published manufacturing products from the SeAT SDE.
- Recursively expand blueprint materials and separate minerals from other inputs.
- Apply entered product ME and component ME.
- Compare requirements only against stock at **BKG-Q2 — Draken Manufacturing**.
- Show required quantity, facility stock and remaining mineral/material shortfall.

## Facility-scoped inventory
The two BKG-Q2 facilities are deliberately treated separately:
- **Ore:** only **BKG-Q2 — Draken Barrel Refinery**.
- **Minerals and manufacturing inputs:** only **BKG-Q2 — Draken Manufacturing**.

Character asset rows inside nested containers are traced to their root location, so hangar divisions and containers inside the configured structure are included. Assets in other stations, structures, ship cargo, personal hangars or corporation hangars are not counted. The dropdown selects the linked SeAT character whose personal asset cache is read. The cache can be stale until SeAT successfully refreshes that character's ESI assets.

Structure resolution uses SeAT's `universe_structures` cache when available. If the installation does not have either cache, configure these IDs in `/opt/seat-docker/.env`:
- `COOKBOOK_REFINERY_STRUCTURE_ID` — item ID of Draken Barrel Refinery.
- `COOKBOOK_MANUFACTURING_STRUCTURE_ID` — item ID of Draken Manufacturing.

The application refuses to fall back to global inventory if either BKG-Q2 structure cannot be resolved.

## Compressed ore planner
- Loads compressed ore stock from Draken Barrel Refinery only.
- Uses SDE `portionSize` and `invTypeMaterials` reprocessing composition.
- Applies the entered combined refining yield percentage.
- Calculates complete batches and mineral output from the ore already stored at the refinery.
- Deducts minerals already at Draken Manufacturing from the blueprint requirement.
- Lets the user select a compressed ore type for the remaining shortfall and calculates additional ore units / complete batches required, with resulting surplus.

The additional-ore calculation uses one selected ore type for the new mining requirement. It is not an optimizer that chooses a multi-ore blend. The user-entered yield is not inferred from skills, implants, rigs, taxes or structure bonuses. No market price is invented.

## Required SeAT tables
- `invTypes`
- `invGroups`
- `industryActivityProducts`
- `industryActivityMaterials`
- `invTypeMaterials`
- `character_assets`
- `universe_structures`, unless both structure IDs are explicitly configured in `/opt/seat-docker/.env`

The CCP SDE importer in `cookbook/import-cookbook-sde.py` imports manufacturing blueprint materials and products into the existing SeAT database. It does not create or populate SeAT's `invTypeMaterials`; that table must already be populated by SeAT's SDE import/update process.

## Import manufacturing SDE data

```bash
cd /opt/rigauth
sudo python3 cookbook/import-cookbook-sde.py
```

The importer reads the existing `/opt/seat-docker/.env` and uses the existing MariaDB Compose service.

## Deploy

```bash
cd /opt/rigauth || exit 1
git fetch origin
git switch feature/cookbook-seat-integration 2>/dev/null || git switch -c feature/cookbook-seat-integration --track origin/feature/cookbook-seat-integration
git pull --ff-only origin feature/cookbook-seat-integration
bash cookbook/deploy-cookbook.sh
```

The deployment script checks required SDE and asset tables, structure-name resolution support, the running SeAT front service and bind mount. It backs up changed SeAT files, installs the controller, route include and Blade view, registers the sidebar link and routes idempotently, clears caches, verifies routes and caches views. It restores files on failure.

## Test
1. Sign in to SeAT and open **Triglavian Cookbook**.
2. Select a linked character and load facility inventory.
3. Confirm ore shown is only from Draken Barrel Refinery.
4. Calculate a blueprint and confirm manufacturing stock is only from Draken Manufacturing.
5. Choose a compressed ore type and calculate the additional ore required.
6. Refresh the character's ESI assets in SeAT before relying on the quantities.

