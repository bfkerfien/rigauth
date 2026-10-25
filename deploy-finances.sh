#!/usr/bin/env bash
set -Eeuo pipefail
REPO="/opt/rigauth"
SEAT_DIR="/opt/seat-docker"
WEB="$SEAT_DIR/packages/eveseat/web"
PATCH="$REPO/patches/0007-potchven-finances.patch"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/rigauth-finances-backup-$STAMP"
SUCCESS=0
ROLLBACK_ENABLED=0
FILES=("src/Http/Controllers/PotchvenFinancesController.php" "src/Http/Routes/PotchvenFinances.php" "src/resources/views/potchven-finances/index.blade.php")

rollback() {
  rc=$?
  if [[ "$SUCCESS" -eq 0 && "$ROLLBACK_ENABLED" -eq 1 ]]; then
    echo
    echo "ERROR: deployment did not complete; restoring backed-up files."
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
[[ -z "$(git status --porcelain)" ]] || { echo "STOP: repository has local changes; back them up first."; git status --short; exit 1; }
git fetch origin
[[ "$(git branch --show-current)" == "finances/seat-integration" ]] || git switch finances/seat-integration
git pull --ff-only origin finances/seat-integration

for rel in "${FILES[@]}"; do
  [[ -f "$REPO/custom/seat-web/$rel" ]] || { echo "STOP: required source file missing: $REPO/custom/seat-web/$rel"; exit 1; }
done
[[ -f "$PATCH" && -d "$WEB" && -f "$WEB/src/Http/routes.php" && -f "$WEB/src/resources/views/includes/sidebar.blade.php" ]] || { echo "STOP: patch or expected SeAT source files missing."; exit 1; }
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" ps --status running --services | grep -qx front || { echo "STOP: SeAT front service is not running."; exit 1; }

MOUNTS="$(sudo docker inspect --format '{{range .Mounts}}{{println .Source "|" .Destination}}{{end}}' seat-docker-front-1)"
if ! printf '%s\\n' "$MOUNTS" | awk -F '|' -v web="$WEB" '{ src=$1; gsub(/^[ \\t]+|[ \\t]+$/, "", src); if (src != "" && index(web, src) == 1) found=1 } END { exit(found ? 0 : 1) }'; then
  echo "STOP: running SeAT container has no bind mount covering $WEB; no changes made."; exit 1
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

if sudo git -C "$WEB" apply --recount --reverse --check "$PATCH" >/dev/null 2>&1; then
  echo "Finance route/sidebar patch already applied."
elif sudo git -C "$WEB" apply --recount --check "$PATCH"; then
  sudo git -C "$WEB" apply --recount "$PATCH"
else
  echo "STOP: patch does not apply cleanly."
  exit 1
fi

for rel in "${FILES[@]}"; do
  sudo install -D -m 0644 "$REPO/custom/seat-web/$rel" "$WEB/$rel"
done

sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear
ROUTES="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:list --path=potchven-finances)"
printf '%s\\n' "$ROUTES"
printf '%s\\n' "$ROUTES" | grep -q 'potchven-finances' || { echo "ERROR: finance routes not registered."; exit 1; }
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
