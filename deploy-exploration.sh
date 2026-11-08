#!/usr/bin/env bash
set -Eeuo pipefail

REPO="/opt/rigauth"
SEAT_DIR="/opt/seat-docker"
WEB="$SEAT_DIR/packages/eveseat/web"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/rigauth-exploration-backup-$STAMP"
SUCCESS=0
ROLLBACK_ENABLED=0
MAP_FILES=(
  "resources/exploration/Branch.dark.svg"
  "resources/exploration/Tenal.dark.svg"
  "resources/exploration/Venal.dark.svg"
)
FILES=(
  "src/Http/Controllers/ExplorationController.php"
  "src/Http/Routes/Exploration.php"
  "src/database/migrations/2026_11_08_000001_create_tc_exploration_system_status_table.php"
  "src/resources/views/exploration/index.blade.php"
)
PROFILE_FILE="src/resources/views/profile/view.blade.php"

CURRENT_STEP="initialization"
rollback() {
  rc=$?
  if [[ "$SUCCESS" -eq 0 ]]; then
    echo "Deployment stopped during: $CURRENT_STEP (exit code $rc)"
  fi
  if [[ "$SUCCESS" -eq 0 && "$ROLLBACK_ENABLED" -eq 1 ]]; then
    echo
    echo "ERROR: Exploration deployment did not complete; restoring backed-up SeAT files."
    for rel in "${FILES[@]}" "${MAP_FILES[@]}" "src/Http/routes.php" "src/resources/views/includes/sidebar.blade.php" "$PROFILE_FILE"; do
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
[[ -d "$WEB" && -f "$WEB/src/Http/routes.php" && -f "$WEB/src/resources/views/includes/sidebar.blade.php" ]] || {
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

# Download DOTLAN's original vector maps before changing the live SeAT installation.
# These SVGs contain the exact DOTLAN node placement, grid, and stargate routing.
MAP_CACHE="$REPO/.exploration-map-cache"
mkdir -p "$MAP_CACHE"
for region in Branch Tenal Venal; do
  map_file="$MAP_CACHE/$region.dark.svg"
  curl --fail --location --silent --show-error --retry 2 \
    "https://evemaps.dotlan.net/svg/$region.dark.svg" -o "$map_file"
  grep -q '<svg' "$map_file" || { echo "STOP: DOTLAN did not return a valid SVG for $region."; exit 1; }
  grep -Eq 'class="[^"]*ss' "$map_file" || { echo "STOP: DOTLAN SVG for $region has no system-label class; refusing an unusable map."; exit 1; }
done

sudo mkdir -p "$BACKUP"
for rel in "${FILES[@]}" "${MAP_FILES[@]}" "src/Http/routes.php" "src/resources/views/includes/sidebar.blade.php" "$PROFILE_FILE"; do
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

CURRENT_STEP="patching SeAT navigation"
sudo python3 "$REPO/apply-exploration-navigation.py" "$WEB/src/Http/routes.php" "$WEB/src/resources/views/includes/sidebar.blade.php"
CURRENT_STEP="adding main-character selector to SeAT profile"
sudo python3 "$REPO/apply-main-character-selector.py" "$WEB/$PROFILE_FILE"

CURRENT_STEP="installing Exploration source files"
for rel in "${FILES[@]}"; do
  sudo install -D -m 0644 "$REPO/custom/seat-web/$rel" "$WEB/$rel"
done
for region in Branch Tenal Venal; do
  sudo install -D -m 0644 "$MAP_CACHE/$region.dark.svg" "$WEB/resources/exploration/$region.dark.svg"
done

CURRENT_STEP="running migrations"
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan migrate --force
CURRENT_STEP="clearing route cache"
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear
CURRENT_STEP="clearing view cache"
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear

CURRENT_STEP="checking Exploration routes"
ROUTES="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:list --path=exploration)"
printf '%s\n' "$ROUTES"
printf '%s\n' "$ROUTES" | grep -q 'seatcore::exploration.index' || {
  echo "ERROR: Exploration index route was not registered."
  exit 1
}

CURRENT_STEP="compiling Blade views"
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:cache
CURRENT_STEP="restarting SeAT front"
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" restart front

echo "Exploration deployed successfully."
echo "Backup retained at $BACKUP"
SUCCESS=1
