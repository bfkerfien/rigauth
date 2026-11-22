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
  "src/database/migrations/2026_11_08_000002_create_tc_exploration_signatures_table.php"
  "src/database/migrations/2026_11_08_000003_add_wormhole_notes_to_tc_exploration_signatures.php"
  "src/database/migrations/2026_11_08_000004_ensure_wormhole_note_columns.php"
  "src/database/migrations/2026_11_09_000001_create_tc_exploration_system_scans_table.php"
  "src/database/migrations/2026_11_09_000002_add_scan_status_to_tc_exploration_system_scans.php"
  "src/database/migrations/2026_11_09_000003_create_tc_exploration_cyno_infrastructure_table.php"
  "src/resources/views/exploration/index.blade.php"
  "src/resources/exploration/layouts.php"
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

# Native Exploration maps are generated directly from the SeAT EVE SDE at request time.
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
# Retire the old downloaded map artwork; the controller now renders native SDE maps.
for rel in "${MAP_FILES[@]}"; do
  sudo rm -f "$WEB/$rel"
done

CURRENT_STEP="running migrations"
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan migrate --force
CURRENT_STEP="verifying wormhole notes migration"
# Capture the complete output before searching it. With pipefail enabled,
# grep -q can close the pipe early and make docker exec appear to have failed
# with SIGPIPE even when migrate:status succeeded and the migration is applied.
if MIGRATION_STATUS="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan migrate:status 2>&1)"; then
  echo "$MIGRATION_STATUS"
else
  MIGRATION_STATUS_EXIT=$?
  echo "ERROR: php artisan migrate:status failed (exit code $MIGRATION_STATUS_EXIT):"
  echo "$MIGRATION_STATUS"
  exit "$MIGRATION_STATUS_EXIT"
fi
if ! printf '%s\\n' "$MIGRATION_STATUS" | grep -Fq '2026_11_08_000004_ensure_wormhole_note_columns'; then
  echo "ERROR: Wormhole notes schema repair migration is not recorded as applied."
  exit 1
fi
if ! printf '%s\\n' "$MIGRATION_STATUS" | grep -Fq '2026_11_09_000001_create_tc_exploration_system_scans_table'; then
  echo "ERROR: Exploration system scan history migration is not recorded as applied."
  exit 1
fi
if ! printf '%s\\n' "$MIGRATION_STATUS" | grep -Fq '2026_11_09_000002_add_scan_status_to_tc_exploration_system_scans'; then
  echo "ERROR: Exploration scan status migration is not recorded as applied."
  exit 1
fi
if ! printf '%s\\n' "$MIGRATION_STATUS" | grep -Fq '2026_11_09_000003_create_tc_exploration_cyno_infrastructure_table'; then
  echo "ERROR: Manual cyno infrastructure migration is not recorded as applied."
  exit 1
fi

CURRENT_STEP="clearing route cache"
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear
CURRENT_STEP="clearing view cache"
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear

CURRENT_STEP="checking Exploration routes"
# Capture route:list failures explicitly: with set -e, command substitution
# otherwise exits before its stderr/output can be shown.
if ROUTES="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:list --path=exploration 2>&1)"; then
  echo "$ROUTES"
else
  ROUTE_STATUS=$?
  echo "ERROR: php artisan route:list --path=exploration failed (exit code $ROUTE_STATUS):"
  echo "$ROUTES"
  exit "$ROUTE_STATUS"
fi

# Verify actual Exploration routes without depending on package route-name formatting.
printf '%s\n' "$ROUTES" | grep -qiE 'exploration|ExplorationController' || {
  echo "ERROR: No Exploration routes were registered. Route output is shown above."
  exit 1
}
printf '%s\n' "$ROUTES" | grep -qE '^[[:space:]]*GET\\|HEAD[[:space:]]+exploration([[:space:]]|$)' || {
  echo "ERROR: Exploration index URI is missing. Route output is shown above."
  exit 1
}

printf '%s\\n' "$ROUTES" | grep -qi 'wormhole-notes' || {
  echo "ERROR: Wormhole notes route is missing. The notes editor cannot save."
  exit 1
}
printf '%s\\n' "$ROUTES" | grep -qi 'player-location' || {
  echo "ERROR: Player location route is missing. System Information cannot load."
  exit 1
}
printf '%s\\n' "$ROUTES" | grep -qi 'system-scans' || {
  echo "ERROR: System scan tracking routes are missing."
  exit 1
}
printf '%s\\n' "$ROUTES" | grep -qi 'cyno-infrastructure/manual' || {
  echo "ERROR: Manual cyno infrastructure routes are missing."
  exit 1
}

CURRENT_STEP="compiling Blade views"
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:cache
CURRENT_STEP="restarting SeAT front"
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" restart front

echo "Exploration deployed successfully."
echo "Backup retained at $BACKUP"
SUCCESS=1
