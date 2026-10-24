#!/usr/bin/env bash
set -Eeuo pipefail
REPO="/opt/rigauth"
SEAT_DIR="/opt/seat-docker"
WEB="$SEAT_DIR/packages/eveseat/web"
PATCH="$REPO/patches/0007-potchven-finances.patch"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/rigauth-finances-backup-$STAMP"
FILES=("src/Http/Controllers/PotchvenFinancesController.php" "src/Http/Routes/PotchvenFinances.php" "src/resources/views/potchven-finances/index.blade.php")

cd "$REPO"
[[ -d .git ]] || { echo "STOP: $REPO is not a Git checkout."; exit 1; }
[[ -z "$(git status --porcelain)" ]] || { echo "STOP: repository has local changes; back them up first."; git status --short; exit 1; }
git fetch origin
[[ "$(git branch --show-current)" == "finances/seat-integration" ]] || git switch finances/seat-integration
git pull --ff-only origin finances/seat-integration
[[ -d "$WEB" && -f "$WEB/src/Http/routes.php" && -f "$WEB/src/resources/views/includes/sidebar.blade.php" ]] || { echo "STOP: expected SeAT source files not found."; exit 1; }
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" ps --status running --services | grep -qx front || { echo "STOP: SeAT front service is not running."; exit 1; }
MOUNTS="$(sudo docker inspect --format '{{range .Mounts}}{{println .Source "|" .Destination}}{{end}}' seat-docker-front-1)"
if ! printf '%s\\n' "$MOUNTS" | awk -F '|' -v web="$WEB" '{ src=$1; gsub(/^[ \\t]+|[ \\t]+$/, "", src); if (src != "" && index(web, src) == 1) found=1 } END { exit(found ? 0 : 1) }'; then
  echo "STOP: running SeAT container has no bind mount covering $WEB; no changes made."; exit 1
fi
sudo mkdir -p "$BACKUP"
for rel in "${FILES[@]}" "src/Http/routes.php" "src/resources/views/includes/sidebar.blade.php"; do
  if sudo test -f "$WEB/$rel"; then sudo mkdir -p "$BACKUP/$(dirname "$rel")"; sudo cp -a "$WEB/$rel" "$BACKUP/$rel"; fi
done
echo "Backup: $BACKUP"
if sudo git -C "$WEB" apply --recount --reverse --check "$PATCH" >/dev/null 2>&1; then
  echo "Finance patch already applied."
elif sudo git -C "$WEB" apply --recount --check "$PATCH"; then
  sudo git -C "$WEB" apply --recount "$PATCH"
else
  echo "STOP: patch does not apply cleanly. Backup retained at $BACKUP"; exit 1
fi
for rel in "${FILES[@]}"; do sudo install -D -m 0644 "$REPO/custom/seat-web/$rel" "$WEB/$rel"; done
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:list --path=potchven-finances
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:cache
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" restart front
echo "Deployment finished. Backup retained at $BACKUP"
echo "Database import is separate: bash $REPO/import-finances-db.sh"
echo "Keep the standalone database until the page and Turaneau-only access have been tested."
