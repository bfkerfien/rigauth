#!/usr/bin/env bash
set -Eeuo pipefail

REPO="/opt/rigauth"
SEAT_DIR="/opt/seat-docker"
WEB="$SEAT_DIR/packages/eveseat/web"
PATCH="$REPO/patches/0006-exploration-section.patch"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/rigauth-exploration-backup-$STAMP"
SUCCESS=0
ROLLBACK_ENABLED=0
FILES=(
  "src/Http/Controllers/ExplorationController.php"
  "src/Http/Routes/Exploration.php"
  "src/database/migrations/2026_11_08_000001_create_tc_exploration_system_status_table.php"
  "src/resources/views/exploration/index.blade.php"
)

rollback() {
  rc=$?
  if [[ "$SUCCESS" -eq 0 && "$ROLLBACK_ENABLED" -eq 1 ]]; then
    echo
    echo "ERROR: Exploration deployment did not complete; restoring backed-up SeAT files."
    for rel in "${FILES[@]}" "src/Http/routes.php" "src/resources/views/includes/sidebar.blade.php"; do
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

cd "$REPO"
[[ -d .git ]] || { echo "STOP: $REPO is not a Git checkout."; exit 1; }
[[ -f "$PATCH" && -d "$WEB" && -f "$WEB/src/Http/routes.php" && -f "$WEB/src/resources/views/includes/sidebar.blade.php" ]] || {
  echo "STOP: Exploration patch or expected SeAT source files are missing."
  exit 1
}
for rel in "${FILES[@]}"; do
  [[ -f "$REPO/custom/seat-web/$rel" ]] || { echo "STOP: required source file missing: $REPO/custom/seat-web/$rel"; exit 1; }
done
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" ps --status running --services | grep -qx front || {
  echo "STOP: SeAT front service is not running."
  exit 1
}

MOUNTS="$(sudo docker inspect --format '{{range .Mounts}}{{println .Source "|" .Destination}}{{end}}' seat-docker-front-1)"
if ! printf '%s\n' "$MOUNTS" | awk -F '|' -v web="$WEB" '{
  src=$1; gsub(/^[ \t]+|[ \t]+$/, "", src);
  if (src != "" && index(web, src) == 1) found=1
} END { exit(found ? 0 : 1) }'; then
  echo "STOP: running SeAT container has no bind mount covering $WEB; no changes made."
  exit 1
fi

sudo mkdir -p "$BACKUP"
for rel in "${FILES[@]}" "src/Http/routes.php" "src/resources/views/includes/sidebar.blade.php"; do
  if sudo test -f "$WEB/$rel"; then
    sudo mkdir -p "$BACKUP/$(dirname "$rel")"
    sudo cp -a "$WEB/$rel" "$BACKUP/$rel"
  else
    sudo mkdir -p "$BACKUP/$(dirname "$rel")"
    sudo touch "$BACKUP/$rel.__MISSING"
  fi
done
echo "Backup: $BACKUP"
ROLLBACK_ENABLED=1

sudo python3 "$REPO/apply-exploration-navigation.py" "$WEB/src/Http/routes.php" "$WEB/src/resources/views/includes/sidebar.blade.php"

for rel in "${FILES[@]}"; do
  sudo install -D -m 0644 "$REPO/custom/seat-web/$rel" "$WEB/$rel"
done

sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan migrate --force
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear

ROUTES="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:list --path=exploration)"
printf '%s\n' "$ROUTES"
printf '%s\n' "$ROUTES" | grep -q 'exploration' || {
  echo "ERROR: Exploration routes were not registered."
  exit 1
}

sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:cache
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" restart front

echo "Exploration deployed successfully."
echo "Backup retained at $BACKUP"
SUCCESS=1
