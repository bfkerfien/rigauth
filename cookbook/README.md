# Triglavian Cookbook — native SeAT integration

The Cookbook runs inside SeAT using the authenticated SeAT session, existing SeAT database, SDE tables and ESI-cached character assets. It does not create a standalone service, database, user account, OAuth application or separate token store.

## Included functionality

### Manufacturing planner
- Search published manufacturing products from the SeAT SDE.
- Recursively expand blueprint materials and separate minerals from other required inputs.
- Apply the entered product ME and component ME to the corresponding blueprint levels.
- Compare each required material against the selected linked character's cached assets.
- Show required quantity, cached quantity and remaining shortfall.

### Character inventory selector
- Uses SeAT-linked characters; the user cannot query a character that is not associated with their SeAT account.
- Reads `character_assets` and includes all locations in that character's current SeAT asset cache.
- The inventory view lists compressed ore assets and their cached quantities.
- Asset quantities are only as fresh as SeAT's last successful ESI asset refresh. Refresh the character's assets in SeAT before relying on the results.

### Compressed ore basket and refining estimate
- Loads the selected character's compressed ore assets into an editable basket.
- Lets the user adjust the quantity of each ore in the basket.
- Uses each ore's SDE `portionSize` and `invTypeMaterials` reprocessing quantities.
- Calculates complete batches, mineral output and leftover ore units.
- Accepts the user's combined reprocessing yield percentage instead of inventing character skills, implants, structure rigs, bonuses or taxes.

**Refining estimate limitation:** enter the actual combined yield percentage for the character and facility. This increment does not calculate that percentage from skill levels, implants, rigs, structure bonuses or tax. It also does not yet filter inventory to a specific structure or station; it uses all locations in the selected character's SeAT asset cache. It does not claim live Jita prices.

## Required SeAT tables

- `invTypes`
- `invGroups`
- `industryActivityProducts`
- `industryActivityMaterials`
- `invTypeMaterials`
- `character_assets`

The CCP SDE importer in `cookbook/import-cookbook-sde.py` imports manufacturing blueprint materials and products into the existing SeAT database. It does not create or populate `invTypeMaterials`; that table must already be populated by SeAT's SDE import/update process.

## Import manufacturing SDE data

Run on the server after pulling the branch. The script reads the existing `/opt/seat-docker/.env` and uses the existing MariaDB Compose service. Run with sudo so it can read the SeAT environment file:

```bash
cd /opt/rigauth
sudo python3 cookbook/import-cookbook-sde.py
```

The importer reports the number of imported manufacturing material and product rows. If it fails, do not deploy; capture the complete error output.

## Deploy

```bash
cd /opt/rigauth || exit 1
git fetch origin
git switch feature/cookbook-seat-integration 2>/dev/null || git switch -c feature/cookbook-seat-integration --track origin/feature/cookbook-seat-integration
git pull --ff-only origin feature/cookbook-seat-integration
git status --short --branch
bash cookbook/deploy-cookbook.sh
```

The deployment script checks the SeAT source paths, running front service, bind mount, required tables and populated manufacturing/reprocessing SDE data. It backs up each SeAT file it changes, installs the controller, route include and Blade view, registers routes and sidebar navigation idempotently, clears Laravel caches, verifies route registration and caches views. On failure it restores backed-up SeAT files. It does not restart containers or create a separate database.

## Test after deployment

1. Sign in to SeAT and open **Triglavian Cookbook** from the sidebar.
2. Search for a manufacturing product and calculate its material requirements.
3. Select a linked character and calculate again; confirm the cached-assets and shortfall columns appear.
4. In **Character inventory and compressed ore basket**, load that character's compressed ore assets.
5. Adjust basket quantities if needed, enter the actual combined reprocessing yield percentage, and calculate mineral output.
6. Refresh the character's ESI assets in SeAT before trusting inventory figures.

## Remaining scope

This implementation does not yet reproduce any standalone-only behavior that depends on unavailable original project code or unconfirmed facility identifiers. In particular, it does not provide structure-specific asset filtering for BKG-Q2, automatic refining-skill/implant/rig yield derivation, or Jita market valuation. No inventory quantities, refining bonuses or prices are fabricated.
