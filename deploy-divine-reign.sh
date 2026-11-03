#!/usr/bin/env bash
set -Eeuo pipefail
REPO="/opt/rigauth"
SEAT_DIR="/opt/seat-docker"
WEB="$SEAT_DIR/packages/eveseat/web"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/divine-reign-deploy-$STAMP"
SUCCESS=0
FILES=("src/Http/Controllers/DivineReignController.php" "src/Http/Routes/DivineReign.php" "src/database/migrations/2026_11_03_000001_create_divine_reign_ratting_tables.php" "src/resources/views/divine-reign/index.blade.php")
ROUTE_MARKER="Routes/DivineReign.php"
SIDEBAR_MARKER="seatcore::divine-reign.index"
rollback() {
  rc=$?
  if [[ "$SUCCESS" -eq 0 && -d "$BACKUP" ]]; then
    echo "Deployment failed; restoring backed-up SeAT files."
    for rel in "src/Http/routes.php" "src/resources/views/includes/sidebar.blade.php" "${FILES[@]}"; do
      if sudo test -f "$BACKUP/$rel.__MISSING"; then sudo rm -f "$WEB/$rel"
      elif sudo test -f "$BACKUP/$rel"; then sudo install -D -m 0644 "$BACKUP/$rel" "$WEB/$rel"; fi
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
[[ -z "$(git status --porcelain)" ]] || { echo "STOP: rigauth has local changes; commit or stash them first."; git status --short; exit 1; }
git fetch origin
[[ "$(git branch --show-current)" == "main" ]] || git switch main
git pull --ff-only origin main
[[ -f "$WEB/src/Http/routes.php" && -f "$WEB/src/resources/views/includes/sidebar.blade.php" ]] || { echo "STOP: expected SeAT web source files missing at $WEB."; exit 1; }
for rel in "${FILES[@]}"; do [[ -f "$REPO/divine-reign/seat-web/$rel" ]] || { echo "STOP: module source missing: $rel"; exit 1; }; done
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" ps --status running --services | grep -qx front || { echo "STOP: SeAT front service is not running."; exit 1; }
MOUNTS="$(sudo docker inspect --format '{{range .Mounts}}{{println .Source "|" .Destination}}{{end}}' seat-docker-front-1)"
if ! printf '%s\n' "$MOUNTS" | awk -F '|' -v web="$WEB" '{ src=$1; gsub(/^[ \t]+|[ \t]+$/, "", src); if (src != "" && index(web, src) == 1) found=1 } END { exit(found ? 0 : 1) }'; then
  echo "STOP: running SeAT container has no bind mount covering $WEB; no changes made."; exit 1
fi
sudo mkdir -p "$BACKUP"
for rel in "src/Http/routes.php" "src/resources/views/includes/sidebar.blade.php" "${FILES[@]}"; do
  if sudo test -f "$WEB/$rel"; then sudo mkdir -p "$BACKUP/$(dirname "$rel")"; sudo cp -a "$WEB/$rel" "$BACKUP/$rel"
  else sudo mkdir -p "$BACKUP/$(dirname "$rel")"; sudo touch "$BACKUP/$rel.__MISSING"; fi
done
echo "Backup: $BACKUP"

# Install module files before wiring routes/sidebar.
for rel in "${FILES[@]}"; do sudo install -D -m 0644 "$REPO/divine-reign/seat-web/$rel" "$WEB/$rel"; done

# Add the route include once, anchored after the Home route include inside the authenticated route group.
ROUTES_FILE="$WEB/src/Http/routes.php"
if ! grep -Fq "$ROUTE_MARKER" "$ROUTES_FILE"; then
  HOME_LINE="$(grep -nF "include __DIR__ . '/Routes/Home.php';" "$ROUTES_FILE" | head -n1 | cut -d: -f1 || true)"
  [[ -n "$HOME_LINE" ]] || { echo "STOP: could not find the Home route include anchor."; exit 1; }
  sudo sed -i "${HOME_LINE}a\\
        // Divine Reign ratting and finance tracker.\\
        include __DIR__ . '/Routes/DivineReign.php';" "$ROUTES_FILE"
fi

# Add a sidebar entry once, immediately before the menu loop.
SIDEBAR_FILE="$WEB/src/resources/views/includes/sidebar.blade.php"
if ! grep -Fq "$SIDEBAR_MARKER" "$SIDEBAR_FILE"; then
  MENU_LINE="$(grep -nF '@foreach($menu as $entry)' "$SIDEBAR_FILE" | head -n1 | cut -d: -f1 || true)"
  [[ -n "$MENU_LINE" ]] || { echo "STOP: could not find the sidebar menu-loop anchor."; exit 1; }
  SIDEBAR_TMP="$(mktemp)"
  sudo awk -v marker="$SIDEBAR_MARKER" '
    index($0, "@foreach($menu as $entry)") && !done {
      print "        <li class=\"nav-item\">"
      print "          <a href=\"{{ route(\x27seatcore::divine-reign.index\x27) }}\" class=\"nav-link {{ Request::segment(1) === \x27divine-reign\x27 ? \x27active\x27 : null }}\">"
      print "            <i class=\"nav-icon fas fa-skull-crossbones\"></i>"
      print "            <p>Divine Reign</p>"
      print "          </a>"
      print "        </li>"
      print ""
      done=1
    }
    { print }
  ' "$SIDEBAR_FILE" > "$SIDEBAR_TMP"
  sudo install -m 0644 "$SIDEBAR_TMP" "$SIDEBAR_FILE"
  rm -f "$SIDEBAR_TMP"
fi

sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan migrate --force
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:clear
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:clear
ROUTES="$(sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan route:list --path=divine-reign)"
printf '%s\n' "$ROUTES"
printf '%s\n' "$ROUTES" | grep -q 'divine-reign' || { echo "ERROR: Divine Reign routes not registered."; exit 1; }
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" exec -T front php artisan view:cache
sudo docker compose -f "$SEAT_DIR/docker-compose.yml" restart front
echo "Divine Reign deployed successfully."
echo "Backup retained at $BACKUP"
SUCCESS=1
