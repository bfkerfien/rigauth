#!/usr/bin/env bash
set -Eeuo pipefail

REPO="/opt/rigauth"
SEAT_DIR="/opt/seat-docker"
WEB="$SEAT_DIR/packages/eveseat/web"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/rigauth-mining-backup-$STAMP"
SUCCESS=0
FILES=(
  "src/Http/Controllers/MiningLedgerController.php"
  "src/Http/Routes/MiningLedger.php"
  "src/database/migrations/2026_10_29_000001_create_mining_ledger_tables.php"
  "src/resources/views/mining/index.blade.php"
  "src/Http/routes.php"
  "src/resources/views/includes/sidebar.blade.php"
)

rollback() {
  rc=$?
  if [[ "$SUCCESS" -eq 0 && -d "$BACKUP" ]]; then
    echo
    echo "Deployment failed; restoring backed-up files."
    for rel in "${FILES[@]}"; do
      if sudo test -f "$BACKUP/$rel.__MISSING"; then
        sudo rm -f "$WEB/$rel"
      elif sudo test -f "$BACKUP/$rel"; then
        sudo mkdir -p "$WEB/$(dirname "$rel")"
        sudo cp -a "$BACKUP/$rel" "$WEB/$rel"
      fi
    done
    sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear >/dev/null 2>&1 || true
    sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear >/dev/null 2>&1 || true
    echo "Backup retained at $BACKUP"
  fi
  exit "$rc"
}
trap rollback EXIT

[[ -d "$REPO/.git" ]] || { echo "STOP: $REPO is not a Git checkout."; exit 1; }
[[ -d "$WEB/src/Http" && -f "$WEB/src/Http/routes.php" && -f "$WEB/src/resources/views/includes/sidebar.blade.php" ]] || {
  echo "STOP: expected SeAT web source files not found at $WEB."; exit 1;
}
[[ -f "$SEAT_DIR/docker-compose.yml" ]] || { echo "STOP: SeAT Compose file not found."; exit 1; }
for source in \
  "$REPO/mining/src/Http/Controllers/MiningLedgerController.php" \
  "$REPO/mining/src/Http/Routes/MiningLedger.php" \
  "$REPO/mining/database/migrations/2026_10_29_000001_create_mining_ledger_tables.php" \
  "$REPO/mining/resources/views/mining/index.blade.php"; do
  [[ -f "$source" ]] || { echo "STOP: missing source file $source"; exit 1; }
done
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" ps --status running --services | grep -qx front || {
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
echo "Backup: $BACKUP"

sudo install -D -m 0644 "$REPO/mining/src/Http/Controllers/MiningLedgerController.php" "$WEB/src/Http/Controllers/MiningLedgerController.php"
sudo install -D -m 0644 "$REPO/mining/src/Http/Routes/MiningLedger.php" "$WEB/src/Http/Routes/MiningLedger.php"
sudo install -D -m 0644 "$REPO/mining/database/migrations/2026_10_29_000001_create_mining_ledger_tables.php" "$WEB/src/database/migrations/2026_10_29_000001_create_mining_ledger_tables.php"
sudo install -D -m 0644 "$REPO/mining/resources/views/mining/index.blade.php" "$WEB/src/resources/views/mining/index.blade.php"

# Make route and navigation registration idempotent. Require exactly one known insertion anchor.
sudo python3 - "$WEB/src/Http/routes.php" "$WEB/src/resources/views/includes/sidebar.blade.php" <<'PY'
from pathlib import Path
import sys

routes_path = Path(sys.argv[1])
sidebar_path = Path(sys.argv[2])
routes = routes_path.read_text()
sidebar = sidebar_path.read_text()

route_include = "include __DIR__ . '/Routes/MiningLedger.php';"
route_anchor = "include __DIR__ . '/Routes/Home.php';"
if route_include not in routes:
    if routes.count(route_anchor) != 1:
        raise SystemExit("STOP: could not find one unique Home route include anchor.")
    routes = routes.replace(route_anchor, route_anchor + "\n        " + route_include, 1)

sidebar_item = """        <li class="nav-item">
          <a href="{{ route('seatcore::mining.index') }}" class="nav-link {{ Request::segment(1) === 'mining' ? 'active' : null }}">
            <i class="nav-icon fas fa-gem"></i>
            <p>Mining Ledger</p>
          </a>
        </li>

"""
sidebar_anchor = "        @foreach($menu as $entry)"
if "seatcore::mining.index" not in sidebar:
    if sidebar.count(sidebar_anchor) != 1:
        raise SystemExit("STOP: could not find one unique sidebar menu anchor.")
    sidebar = sidebar.replace(sidebar_anchor, sidebar_item + sidebar_anchor, 1)

routes_path.write_text(routes)
sidebar_path.write_text(sidebar)
PY

sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php -l /var/www/seat/packages/eveseat/web/src/Http/Controllers/MiningLedgerController.php
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan migrate --force
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear
ROUTES="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:list --path=mining)"
printf '%s\n' "$ROUTES"
printf '%s\n' "$ROUTES" | grep -q 'seatcore::mining.index' || {
  echo "ERROR: Mining Ledger routes were not registered."; exit 1;
}
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:cache

echo "Mining Ledger deployed. Backup retained at $BACKUP"
echo "Review corporation ACL before granting mining.corporation.view."
SUCCESS=1
