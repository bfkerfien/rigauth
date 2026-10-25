#!/usr/bin/env bash
# Deploy the native Reaction Planner to the existing SeAT source tree.
set -Eeuo pipefail
REPO="/opt/rigauth"
SEAT_DIR="/opt/seat-docker"
WEB="$SEAT_DIR/packages/eveseat/web"
PATCH="$REPO/patches/0005-reaction-planner.patch"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/rigauth-reactions-backup-$STAMP"
SUCCESS=0
ROLLBACK_ENABLED=0
FILES=("src/Http/routes.php" "src/resources/views/includes/sidebar.blade.php" "src/Http/Controllers/ReactionsController.php" "src/resources/views/reactions/index.blade.php")
rollback() {
  rc=$?
  if [[ "$SUCCESS" -eq 0 && "$ROLLBACK_ENABLED" -eq 1 ]]; then
    echo "Deployment failed; restoring backed-up files..."
    for rel in "${FILES[@]}"; do
      if sudo test -f "$BACKUP/$rel.__MISSING"; then sudo rm -f "$WEB/$rel"
      elif sudo test -f "$BACKUP/$rel"; then sudo mkdir -p "$WEB/$(dirname "$rel")"; sudo cp -a "$BACKUP/$rel" "$WEB/$rel"; fi
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
if [[ -n "$(git status --porcelain)" ]]; then echo "STOP: Local Git changes exist; commit or back them up first."; git status --short; exit 1; fi
git fetch origin
[[ "$(git branch --show-current)" == "market/seat-integration" ]] || git switch market/seat-integration
git pull --ff-only origin market/seat-integration
for f in "$PATCH" "$REPO/custom/seat-web/src/Http/Controllers/ReactionsController.php" "$REPO/custom/seat-web/src/resources/views/reactions/index.blade.php"; do
  [[ -f "$f" ]] || { echo "STOP: Required source file missing: $f"; exit 1; }
done
[[ -f "$WEB/src/Http/routes.php" && -f "$WEB/src/resources/views/includes/sidebar.blade.php" ]] || { echo "STOP: SeAT web source files not found."; exit 1; }
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" ps --status running --services | grep -qx front || { echo "STOP: SeAT front service is not running."; exit 1; }
MOUNTS="$(sudo docker inspect --format '{{range .Mounts}}{{println .Source "|" .Destination}}{{end}}' seat-docker-front-1)"
if ! printf '%s\n' "$MOUNTS" | awk -F '|' -v web="$WEB" '{ src=$1; gsub(/^[ \t]+|[ \t]+$/, "", src); if (src != "" && index(web, src) == 1) found=1 } END { exit(found ? 0 : 1) }'; then
 echo "STOP: No bind mount covers $WEB. No files changed."; exit 1
fi
sudo mkdir -p "$BACKUP"
for rel in "${FILES[@]}"; do
 if sudo test -f "$WEB/$rel"; then sudo mkdir -p "$BACKUP/$(dirname "$rel")"; sudo cp -a "$WEB/$rel" "$BACKUP/$rel"
 else sudo mkdir -p "$BACKUP/$(dirname "$rel")"; sudo touch "$BACKUP/$rel.__MISSING"; fi
done
echo "Backup: $BACKUP"
ROLLBACK_ENABLED=1
if sudo git -C "$WEB" apply --recount --reverse --check "$PATCH" >/dev/null 2>&1; then
 echo "Reaction route/sidebar patch already applied."
elif sudo git -C "$WEB" apply --recount --check "$PATCH"; then
 sudo git -C "$WEB" apply --recount "$PATCH"
else
 echo "STOP: Reaction patch does not apply cleanly; restoring without making deployment changes."
 exit 1
fi
sudo install -D -m 0644 "$REPO/custom/seat-web/src/Http/Controllers/ReactionsController.php" "$WEB/src/Http/Controllers/ReactionsController.php"
sudo install -D -m 0644 "$REPO/custom/seat-web/src/resources/views/reactions/index.blade.php" "$WEB/src/resources/views/reactions/index.blade.php"
echo "Clearing Laravel caches..."
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:list --path=reactions
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" restart front
HTTP=""
for attempt in $(seq 1 30); do
 HTTP="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front sh -lc 'curl -sS -o /dev/null -w "%{http_code}" --max-time 5 http://127.0.0.1:8080/reactions' 2>/dev/null | tr -d '\r' || true)"
 case "$HTTP" in 200|301|302|401|403|404|500|502|503) break;; esac
 sleep 2
done
echo "Local /reactions HTTP status: ${HTTP:-no response}"
case "$HTTP" in 200|301|302|401|403) ;; *) sudo docker compose -f "$SEAT_DIR/docker-compose.yml" logs --tail=80 front || true; echo "Deployment health check failed; rollback attempted."; exit 1;; esac
SUCCESS=1
echo "Reaction Planner deployed. Backup retained at $BACKUP"
