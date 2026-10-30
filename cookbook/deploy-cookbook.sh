#!/usr/bin/env bash
set -Eeuo pipefail

REPO="/opt/rigauth"
SEAT_DIR="/opt/seat-docker"
WEB="$SEAT_DIR/packages/eveseat/web"
COMPOSE="$SEAT_DIR/docker-compose.yml"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/rigauth-cookbook-backup-$STAMP"
SUCCESS=0
FILES=(
  "src/Http/Controllers/CookbookController.php"
  "src/Http/Routes/Cookbook.php"
  "src/resources/views/cookbook/index.blade.php"
  "src/Http/routes.php"
  "src/resources/views/includes/sidebar.blade.php"
)

rollback() {
  rc=$?
  if [[ "$SUCCESS" -eq 0 && -d "$BACKUP" ]]; then
    echo "Deployment failed; restoring original SeAT files."
    for rel in "${FILES[@]}"; do
      if sudo test -f "$BACKUP/$rel.__MISSING"; then
        sudo rm -f "$WEB/$rel"
      elif sudo test -f "$BACKUP/$rel"; then
        sudo mkdir -p "$WEB/$(dirname "$rel")"
        sudo cp -a "$BACKUP/$rel" "$WEB/$rel"
      fi
    done
    sudo docker compose -f "$COMPOSE" exec -T front php artisan route:clear >/dev/null 2>&1 || true
    sudo docker compose -f "$COMPOSE" exec -T front php artisan view:clear >/dev/null 2>&1 || true
    echo "Backup retained at $BACKUP"
  fi
  exit "$rc"
}
trap rollback EXIT

[[ -d "$REPO/.git" ]] || { echo "STOP: $REPO is not a Git checkout."; exit 1; }
[[ -z "$(git -C "$REPO" status --porcelain)" ]] || {
  echo "STOP: rigauth has local changes. Commit or back them up before deploying."
  git -C "$REPO" status --short
  exit 1
}
[[ -f "$COMPOSE" && -f "$WEB/src/Http/routes.php" && -f "$WEB/src/resources/views/includes/sidebar.blade.php" ]] || {
  echo "STOP: expected SeAT source or Compose files were not found."; exit 1;
}
for source in \
  "$REPO/cookbook/seat/src/Http/Controllers/CookbookController.php" \
  "$REPO/cookbook/seat/src/Http/Routes/Cookbook.php" \
  "$REPO/cookbook/seat/resources/views/cookbook/index.blade.php"; do
  [[ -f "$source" ]] || { echo "STOP: missing source file $source"; exit 1; }
done
sudo docker compose -f "$COMPOSE" ps --status running --services | grep -qx front || {
  echo "STOP: SeAT front service is not running."; exit 1;
}
MOUNTS="$(sudo docker inspect --format '{{range .Mounts}}{{println .Source "|" .Destination}}{{end}}' seat-docker-front-1)"
if ! printf '%s\n' "$MOUNTS" | awk -F '|' -v web="$WEB" '{ src=$1; gsub(/^[ \t]+|[ \t]+$/, "", src); if (src != "" && index(web, src) == 1) found=1 } END { exit(found ? 0 : 1) }'; then
  echo "STOP: running SeAT front container has no bind mount covering $WEB; no changes made."; exit 1
fi

sudo mkdir -p "$BACKUP"
for rel in "${FILES[@]}"; do
  if sudo test -f "$WEB/$rel"; then
    sudo mkdir -p "$BACKUP/$(dirname "$rel")"
    sudo cp -a "$WEB/$rel" "$BACKUP/$rel"
  else
    sudo mkdir -p "$BACKUP/$(dirname "$rel")"
    sudo touch "$BACKUP/$rel.__MISSING"
  fi
done

sudo install -D -m 0644 "$REPO/cookbook/seat/src/Http/Controllers/CookbookController.php" "$WEB/src/Http/Controllers/CookbookController.php"
sudo install -D -m 0644 "$REPO/cookbook/seat/src/Http/Routes/Cookbook.php" "$WEB/src/Http/Routes/Cookbook.php"
sudo install -D -m 0644 "$REPO/cookbook/seat/resources/views/cookbook/index.blade.php" "$WEB/src/resources/views/cookbook/index.blade.php"

sudo python3 - "$WEB/src/Http/routes.php" "$WEB/src/resources/views/includes/sidebar.blade.php" <<'PY'
from pathlib import Path
import sys

routes_path, sidebar_path = map(Path, sys.argv[1:3])
routes = routes_path.read_text()
sidebar = sidebar_path.read_text()

include = "include __DIR__ . '/Routes/Cookbook.php';"
anchor = "include __DIR__ . '/Routes/Home.php';"
if include not in routes:
    if routes.count(anchor) != 1:
        raise SystemExit("STOP: could not find one unique Home route include anchor.")
    routes = routes.replace(anchor, anchor + "\n        " + include, 1)

link = """        <li class="nav-item">
          <a href="{{ route('seatcore::cookbook.index') }}" class="nav-link {{ Request::segment(1) === 'cookbook' ? 'active' : null }}">
            <i class="nav-icon fas fa-industry"></i>
            <p>Triglavian Cookbook</p>
          </a>
        </li>

"""
menu_anchor = "        @foreach($menu as $entry)"
if "seatcore::cookbook.index" not in sidebar:
    if sidebar.count(menu_anchor) != 1:
        raise SystemExit("STOP: could not find one unique sidebar menu anchor.")
    sidebar = sidebar.replace(menu_anchor, link + menu_anchor, 1)

routes_path.write_text(routes)
sidebar_path.write_text(sidebar)
PY

sudo docker compose -f "$COMPOSE" exec -T front php -l /var/www/seat/packages/eveseat/web/src/Http/Controllers/CookbookController.php
sudo docker compose -f "$COMPOSE" exec -T front php artisan route:clear
sudo docker compose -f "$COMPOSE" exec -T front php artisan view:clear
ROUTES="$(sudo docker compose -f "$COMPOSE" exec -T front php artisan route:list --path=cookbook)"
printf '%s\n' "$ROUTES"
printf '%s\n' "$ROUTES" | grep -q 'seatcore::cookbook.index' || {
  echo "ERROR: Cookbook routes were not registered."; exit 1;
}
sudo docker compose -f "$COMPOSE" exec -T front php artisan view:cache

echo
echo "Cookbook native SeAT build planner deployed."
echo "Backup retained at $BACKUP"
echo "Open SeAT and select Triglavian Cookbook from the sidebar."
echo "Inventory aggregation and compressed-ore planning are not part of this first deployable increment."
SUCCESS=1
