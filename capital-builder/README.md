# Capital Builder — native SeAT integration

Capital Builder is a separate SeAT manufacturing planner cloned from the Triglavian Cookbook's manufacturing foundation. It does not modify or replace the existing Cookbook.

## Purpose
Search for a manufactured product (for example, a capital ship), calculate its blueprint materials, recursively expand every material that itself has a manufacturing blueprint, and show:
- Consolidated manufactured component and subcomponent quantities.
- Consolidated mineral requirements across the complete manufacturing chain.
- Remaining non-mineral inputs that do not have a manufacturing blueprint in the loaded SDE.

Shared component types are consolidated so repeated requirements are shown as one total. Product ME applies to the selected final product; Component ME applies to recursively manufactured components and their subcomponents.

## Data source
Uses the authenticated SeAT session and the existing SeAT database. Required tables:
- `invTypes`
- `industryActivityProducts`
- `industryActivityMaterials`

The product must have a manufacturing blueprint represented in the loaded SDE. Some faction or special-edition ships may not have a manufacturing blueprint; if so, they will not be selectable through this planner until their manufacturing data exists in the SDE.

## Deploy
```bash
cd /opt/rigauth || exit 1
git fetch origin
git switch feature/capital-builder-seat-integration 2>/dev/null || git switch -c feature/capital-builder-seat-integration --track origin/feature/capital-builder-seat-integration
git pull --ff-only origin feature/capital-builder-seat-integration
sudo bash capital-builder/deploy-capital-builder.sh
```

The deployment script preflights SeAT SDE tables and the running SeAT bind mount, backs up affected files, installs the controller/route/view, registers the route include and sidebar link idempotently, clears caches, verifies routes and compiles views. It rolls back SeAT files if deployment fails.
