#!/usr/bin/env bash
set -Eeuo pipefail
REPO="/opt/rigauth"
SEAT_DIR="/opt/seat-docker"
WEB="$SEAT_DIR/packages/eveseat/web"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/rigauth-finances-backup-$STAMP"
SUCCESS=0
ROLLBACK_ENABLED=0
FILES=("src/Http/Controllers/PotchvenFinancesController.php" "src/Http/Routes/PotchvenFinances.php" "src/resources/views/potchven-finances/index.blade.php")
ASSET_REL="public/img/potchven-triglavian-gate-banner.jpg"
NAV_ICON_REL="public/img/potchven-navbar-icon.svg"
LAYOUT="src/resources/views/layouts/app.blade.php"
ROUTES="src/Http/routes.php"
SIDEBAR="src/resources/views/includes/sidebar.blade.php"
CONTAINER="seat-docker-front-1"
PUBLIC_BANNER="/var/www/seat/public/img/potchven-triglavian-gate-banner.jpg"
PUBLIC_NAV_ICON="/var/www/seat/public/img/potchven-navbar-icon.svg"

rollback() {
  rc=$?
  if [[ "$SUCCESS" -eq 0 && "$ROLLBACK_ENABLED" -eq 1 ]]; then
    echo
    echo "ERROR: deployment did not complete; restoring backed-up files."
    for rel in "${FILES[@]}" "$ASSET_REL" "$ROUTES" "$SIDEBAR" "$LAYOUT"; do
      if sudo test -f "$BACKUP/$rel.__MISSING"; then
        sudo rm -f "$WEB/$rel"
      elif sudo test -f "$BACKUP/$rel"; then
        sudo mkdir -p "$WEB/$(dirname "$rel")"
        sudo cp -a "$BACKUP/$rel" "$WEB/$rel"
      fi
    done
    if sudo test -f "$BACKUP/laravel-public-banner.jpg"; then
      sudo docker cp "$BACKUP/laravel-public-banner.jpg" "$CONTAINER:$PUBLIC_BANNER" >/dev/null 2>&1 || true
    elif sudo test -f "$BACKUP/laravel-public-banner.__MISSING"; then
      sudo docker exec "$CONTAINER" rm -f "$PUBLIC_BANNER" >/dev/null 2>&1 || true
    fi
    sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear >/dev/null 2>&1 || true
    sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear >/dev/null 2>&1 || true
    echo "Backup retained at $BACKUP"
  fi
  exit "$rc"
}
trap rollback EXIT

cd "$REPO"
[[ -d .git ]] || { echo "STOP: $REPO is not a Git checkout."; exit 1; }
[[ -z "$(git status --porcelain)" ]] || { echo "STOP: repository has local changes; back them up first."; git status --short; exit 1; }
git fetch origin
[[ "$(git branch --show-current)" == "finances/seat-integration" ]] || git switch finances/seat-integration
git pull --ff-only origin finances/seat-integration

for rel in "${FILES[@]}" "$ASSET_REL" "$NAV_ICON_REL"; do
  [[ -f "$REPO/custom/seat-web/$rel" ]] || { echo "STOP: required source file missing: $REPO/custom/seat-web/$rel"; exit 1; }
done
[[ -d "$WEB" && -f "$WEB/$ROUTES" && -f "$WEB/$SIDEBAR" && -f "$WEB/$LAYOUT" ]] || { echo "STOP: expected SeAT source files missing."; exit 1; }
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" ps --status running --services | grep -qx front || { echo "STOP: SeAT front service is not running."; exit 1; }

MOUNTS="$(sudo docker inspect --format '{{range .Mounts}}{{println .Source "|" .Destination}}{{end}}' "$CONTAINER")"
if ! printf '%s\n' "$MOUNTS" | awk -F '|' -v web="$WEB" '{ src=$1; gsub(/^[ \t]+|[ \t]+$/, "", src); if (src != "" && index(web, src) == 1) found=1 } END { exit(found ? 0 : 1) }'; then
  echo "STOP: running SeAT container has no bind mount covering $WEB; no changes made."; exit 1
fi

sudo mkdir -p "$BACKUP"
for rel in "${FILES[@]}" "$ROUTES" "$SIDEBAR" "$LAYOUT"; do
  if sudo test -f "$WEB/$rel"; then
    sudo mkdir -p "$BACKUP/$(dirname "$rel")"
    sudo cp -a "$WEB/$rel" "$BACKUP/$rel"
  else
    sudo mkdir -p "$BACKUP/$(dirname "$rel")"
    sudo touch "$BACKUP/$rel.__MISSING"
  fi
done
if sudo docker exec "$CONTAINER" test -f "$PUBLIC_BANNER"; then
  sudo docker cp "$CONTAINER:$PUBLIC_BANNER" "$BACKUP/laravel-public-banner.jpg"
else
  sudo touch "$BACKUP/laravel-public-banner.__MISSING"
fi
echo "Backup: $BACKUP"
ROLLBACK_ENABLED=1

# Apply only the three small integration changes. The live SeAT files also
# contain other Triglavian customizations, so do not apply a broad git patch.
sudo python3 - "$WEB" <<'PY'
from pathlib import Path
import re
import sys

web = Path(sys.argv[1])
routes_path = web / "src/Http/routes.php"
sidebar_path = web / "src/resources/views/includes/sidebar.blade.php"
layout_path = web / "src/resources/views/layouts/app.blade.php"

routes = routes_path.read_text()
route_include = "include __DIR__ . '/Routes/PotchvenFinances.php';"
if route_include not in routes:
    market_include = "include __DIR__ . '/Routes/Market.php';"
    if market_include not in routes:
        raise SystemExit("STOP: could not find the native Market route include; refusing to guess where to add Potchven Finances.")
    routes = routes.replace(market_include, market_include + "\n        " + route_include, 1)
    routes_path.write_text(routes)
    print("Added Potchven Finances route include.")
else:
    print("Potchven Finances route include already present.")

sidebar = sidebar_path.read_text()
marker = "route('seatcore::potchven-finances.index')"
if marker not in sidebar:
    raise SystemExit("STOP: Potchven Finances sidebar entry is missing; refusing to insert into an unknown custom menu layout.")
triangle_marker = '<svg class="nav-icon" viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" focusable="false" style="vertical-align:-0.12em"><path d="M12 2.4 21.1 19H2.9L12 2.4Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 2.4V12M12 12 5.1 16.2M12 12l6.9 4.2" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
custom_icon = '<img class="nav-icon" src="{{ asset(\'img/potchven-navbar-icon.svg\') }}" alt="" aria-hidden="true" style="width:1.25em;height:1.25em;object-fit:contain;vertical-align:-0.28em;border-radius:0.22em">'
lines = sidebar.splitlines(keepends=True)
changed = False
for i, line in enumerate(lines):
    if marker in line:
        if "potchven-navbar-icon.svg" in line:
            print("Custom Potchven navbar icon already present.")
        elif triangle_marker in line:
            lines[i] = line.replace(triangle_marker, custom_icon, 1)
            changed = True
            print("Replaced inline Potchven triangle with custom navbar icon.")
        elif '<i class="nav-icon fas fa-coins"></i>' in line:
            lines[i] = line.replace('<i class="nav-icon fas fa-coins"></i>', custom_icon, 1)
            changed = True
            print("Replaced Potchven coin icon with custom navbar icon.")
        else:
            raise SystemExit("STOP: found Potchven Finances menu entry, but its icon differs from the expected coin icon; refusing to alter it.")
        break
if changed:
    sidebar_path.write_text("".join(lines))

layout = layout_path.read_text()
body_pattern = re.compile(r'(<body\s+class=")([^"]*\bsidebar-mini\b)([^"]*)(")')
match = body_pattern.search(layout)
if not match:
    if 'layout-fixed' in layout and '<body' in layout:
        print("Fixed layout class appears to be present already.")
    else:
        raise SystemExit("STOP: could not identify the SeAT body class; refusing to rewrite the layout.")
else:
    classes = (match.group(2) + match.group(3)).split()
    if "layout-fixed" not in classes:
        classes.insert(classes.index("sidebar-mini") + 1, "layout-fixed")
        replacement = match.group(1) + " ".join(classes) + match.group(4)
        layout = layout[:match.start()] + replacement + layout[match.end():]
        layout_path.write_text(layout)
        print("Enabled fixed sidebar layout.")
    else:
        print("Fixed sidebar layout already enabled.")
PY

for rel in "${FILES[@]}"; do
  sudo install -D -m 0644 "$REPO/custom/seat-web/$rel" "$WEB/$rel"
done
# Keep the package asset and also publish it to Laravel's actual Apache
# document root. The latter is not a bind mount, so repeat this on every deploy.
sudo install -D -m 0644 "$REPO/custom/seat-web/$ASSET_REL" "$WEB/$ASSET_REL"
sudo install -D -m 0644 "$REPO/custom/seat-web/$NAV_ICON_REL" "$WEB/$NAV_ICON_REL"
sudo docker exec "$CONTAINER" mkdir -p /var/www/seat/public/img
sudo docker cp "$REPO/custom/seat-web/$ASSET_REL" "$CONTAINER:$PUBLIC_BANNER"
sudo docker cp "$REPO/custom/seat-web/$NAV_ICON_REL" "$CONTAINER:$PUBLIC_NAV_ICON"
echo "Installed finance banner and custom Potchven navbar icon."

sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear
ROUTE_OUTPUT="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:list --path=potchven-finances)"
printf '%s\n' "$ROUTE_OUTPUT"
printf '%s\n' "$ROUTE_OUTPUT" | grep -q 'potchven-finances' || { echo "ERROR: finance routes not registered."; exit 1; }
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:cache
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" restart front

# Install a host systemd timer so SeAT's fin_ tables stay refreshed without manual runs.
[[ -f "$REPO/deploy/potchven-finances-seat-sync.service" && -f "$REPO/deploy/potchven-finances-seat-sync.timer" ]] || { echo "STOP: finance sync systemd units missing."; exit 1; }
sudo install -m 0644 "$REPO/deploy/potchven-finances-seat-sync.service" /etc/systemd/system/potchven-finances-seat-sync.service
sudo install -m 0644 "$REPO/deploy/potchven-finances-seat-sync.timer" /etc/systemd/system/potchven-finances-seat-sync.timer
sudo systemctl daemon-reload
sudo systemctl enable --now potchven-finances-seat-sync.timer
sudo systemctl status --no-pager potchven-finances-seat-sync.timer || true

echo "Deployment finished. Backup retained at $BACKUP"
echo "Database import is separate: bash $REPO/import-finances-db.sh"
echo "Keep the standalone database until the page and Turaneau-only access have been tested."
SUCCESS=1
