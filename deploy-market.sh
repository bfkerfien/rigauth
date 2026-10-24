#!/usr/bin/env bash
# Safely deploy the native Market section into the existing SeAT source tree.
# Run as the normal administrator account: bash /opt/rigauth/deploy-market.sh
set -Eeuo pipefail

REPO="/opt/rigauth"
SEAT_DIR="/opt/seat-docker"
WEB="$SEAT_DIR/packages/eveseat/web"
PATCH="$REPO/patches/0006-market-section.patch"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/rigauth-market-backup-$STAMP"
SUCCESS=0
ROLLBACK_ENABLED=0
FILES=(
  "src/Http/routes.php"
  "src/resources/views/includes/sidebar.blade.php"
  "src/Http/Controllers/MarketController.php"
  "src/Http/Routes/Market.php"
  "src/resources/views/market/index.blade.php"
  "src/resources/views/market/comparison-table.blade.php"
)

rollback() {
  rc=$?
  if [[ "$SUCCESS" -eq 0 && "$ROLLBACK_ENABLED" -eq 1 ]]; then
    echo
    echo "ERROR: Deployment did not complete. Restoring backed-up files..."
    for rel in "${FILES[@]}"; do
      if sudo test -f "$BACKUP/$rel.__MISSING"; then
        sudo rm -f "$WEB/$rel"
      elif sudo test -f "$BACKUP/$rel"; then
        sudo mkdir -p "$WEB/$(dirname "$rel")"
        sudo cp -a "$BACKUP/$rel" "$WEB/$rel"
      fi
    done
    if sudo docker compose -f "$SEAT_DIR/docker-compose.yml" ps --status running --services 2>/dev/null | grep -qx front; then
      sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear >/dev/null 2>&1 || true
      sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear >/dev/null 2>&1 || true
    fi
    echo "Backup retained at $BACKUP"
  fi
  exit "$rc"
}
trap rollback EXIT

cd "$REPO"
if [[ ! -d .git ]]; then
  echo "STOP: $REPO is not a Git checkout."
  exit 1
fi
if [[ -n "$(git status --porcelain)" ]]; then
  echo "STOP: $REPO has local changes. Commit or back them up before deploying."
  git status --short
  exit 1
fi

git fetch origin
if [[ "$(git branch --show-current)" != "market/seat-integration" ]]; then
  git switch market/seat-integration
fi
git pull --ff-only origin market/seat-integration

for f in "$PATCH" \
  "$REPO/custom/seat-web/src/Http/Controllers/MarketController.php" \
  "$REPO/custom/seat-web/src/Http/Routes/Market.php" \
  "$REPO/custom/seat-web/src/resources/views/market/index.blade.php" \
  "$REPO/custom/seat-web/src/resources/views/market/comparison-table.blade.php"; do
  [[ -f "$f" ]] || { echo "STOP: Required source file missing: $f"; exit 1; }
done

[[ -d "$WEB" ]] || { echo "STOP: SeAT web package not found: $WEB"; exit 1; }
[[ -f "$WEB/src/Http/routes.php" ]] || { echo "STOP: SeAT routes.php not found."; exit 1; }
[[ -f "$WEB/src/resources/views/includes/sidebar.blade.php" ]] || { echo "STOP: SeAT sidebar view not found."; exit 1; }
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" ps --status running --services | grep -qx front || {
  echo "STOP: SeAT front service is not running. No deployment changes made."
  exit 1
}

# Confirm the active front container uses a bind mount covering the source tree.
# If it does not, copying host files would not deploy the changes, so stop safely.
MOUNTS="$(sudo docker inspect --format '{{range .Mounts}}{{println .Source "|" .Destination}}{{end}}' seat-docker-front-1)"
if ! printf '%s\n' "$MOUNTS" | awk -F '|' -v web="$WEB" '
  { src=$1; gsub(/^[ \t]+|[ \t]+$/, "", src); if (src != "" && index(web, src) == 1) found=1 }
  END { exit(found ? 0 : 1) }'; then
  echo "STOP: The running SeAT container has no bind mount covering $WEB."
  echo "No files changed. Inspect the container mounts before deploying."
  exit 1
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
echo "Backed up deployment files to $BACKUP"
ROLLBACK_ENABLED=1

# Apply the route/sidebar patch once. If it is already present, leave it in place.
if sudo git -C "$WEB" apply --recount --reverse --check "$PATCH" >/dev/null 2>&1; then
  echo "Market route/sidebar patch is already applied."
elif sudo git -C "$WEB" apply --recount --check "$PATCH"; then
  sudo git -C "$WEB" apply --recount "$PATCH"
  echo "Applied Market route/sidebar patch."
else
  echo "STOP: Market patch does not apply cleanly to the deployed SeAT source."
  exit 1
fi

for rel in \
  "src/Http/Controllers/MarketController.php" \
  "src/Http/Routes/Market.php" \
  "src/resources/views/market/index.blade.php" \
  "src/resources/views/market/comparison-table.blade.php"; do
  sudo install -D -m 0644 "$REPO/custom/seat-web/$rel" "$WEB/$rel"
done

echo "Clearing Laravel route and view caches..."
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear

echo "Checking Market routes..."
ROUTES="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:list --path=market)"
printf '%s\n' "$ROUTES"
printf '%s\n' "$ROUTES" | grep -q 'market' || {
  echo "ERROR: Laravel did not report any Market routes."
  exit 1
}

echo "Restarting only the SeAT front web service (no database/volume changes)..."
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" restart front

echo "Checking local Market response inside the front container..."
LOCAL_HTTP="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front sh -lc 'curl -sS -o /dev/null -w "%{http_code}" --max-time 8 http://127.0.0.1:8080/market' | tr -d '\r')"
echo "Local /market HTTP status: $LOCAL_HTTP"
case "$LOCAL_HTTP" in
  200|301|302|401|403) ;;
  *) echo "WARNING: Unexpected local HTTP status. Check front logs before considering deployment complete." ;;
esac

echo
echo "Market source deployment finished. Backup: $BACKUP"
echo "No database migration was run and no Docker volumes were removed."
echo "Check the public site and test /market as a superuser, a Marketeer, and a normal user."
SUCCESS=1
