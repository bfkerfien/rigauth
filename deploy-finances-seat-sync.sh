#!/usr/bin/env bash
set -Eeuo pipefail
REPO="/opt/rigauth"
ARCHIVE="/root/archives/potchven-finances-standalone/source-and-config.tar.gz"
ARCHIVE_ENV="potchven-finances/.env"
ENV_FILE="/etc/potchven-finances-seat-sync.env"
RUNTIME="/opt/potchven-finances-sync-runtime"
IMAGE="node:22-bookworm-slim"
DB_CONTAINER="seat-docker-front-1"

[[ -f "$ARCHIVE" ]] || { echo "STOP: archived finance configuration not found: $ARCHIVE"; exit 1; }
[[ -f "$REPO/finance-sync/sync-finances-seat-db.js" ]] || { echo "STOP: finance sync worker missing"; exit 1; }
docker inspect "$DB_CONTAINER" >/dev/null 2>&1 || { echo "STOP: SeAT front container is not running"; exit 1; }

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
tar -xOzf "$ARCHIVE" "$ARCHIVE_ENV" |
  grep -E '^(TOKEN_ENCRYPTION_KEY|EVE_CLIENT_ID|EVE_CLIENT_SECRET)=' > "$TMP"
for key in TOKEN_ENCRYPTION_KEY EVE_CLIENT_ID EVE_CLIENT_SECRET; do
  grep -q "^$key=" "$TMP" || { echo "STOP: $key missing from archived configuration"; exit 1; }
done
KEY="$(sed -n 's/^TOKEN_ENCRYPTION_KEY=//p' "$TMP")"
[[ "$KEY" =~ ^[A-Fa-f0-9]{64}$ ]] || { echo "STOP: archived token encryption key has invalid format"; exit 1; }

DB_USER="$(docker exec "$DB_CONTAINER" printenv DB_USERNAME)"
DB_PASS="$(docker exec "$DB_CONTAINER" printenv DB_PASSWORD)"
DB_NAME="$(docker exec "$DB_CONTAINER" printenv DB_DATABASE)"
[[ -n "$DB_USER" && -n "$DB_PASS" && -n "$DB_NAME" ]] || { echo "STOP: could not read SeAT DB settings"; exit 1; }

install -d -m 0700 "$RUNTIME"
install -m 0600 "$TMP" "$ENV_FILE"
{
  printf 'FIN_DB_HOST=seat-docker-mariadb-1\n'
  printf 'FIN_DB_PORT=3306\n'
  printf 'FIN_DB_NAME=%s\n' "$DB_NAME"
  printf 'FIN_DB_USER=%s\n' "$DB_USER"
  printf 'FIN_DB_PASSWORD=%s\n' "$DB_PASS"
  printf 'FIN_CORP_EVE_ID=98843282\n'
} >> "$ENV_FILE"
chmod 0600 "$ENV_FILE"

echo "Installing mysql2 into the persistent Node runtime..."
docker run --rm -v "$RUNTIME:/runtime" "$IMAGE" npm install --prefix /runtime --omit=dev mysql2@^3

install -m 0750 "$REPO/run-finances-seat-sync.sh" /usr/local/sbin/potchven-finance-sync
install -m 0644 "$REPO/deploy/potchven-finances-seat-sync.service" /etc/systemd/system/potchven-finances-seat-sync.service
install -m 0644 "$REPO/deploy/potchven-finances-seat-sync.timer" /etc/systemd/system/potchven-finances-seat-sync.timer
systemctl daemon-reload
systemctl enable --now potchven-finances-seat-sync.timer

echo "Private sync configuration installed at $ENV_FILE (mode 0600)."
echo "Node runtime dependencies installed at $RUNTIME."
echo "Timer enabled. Running one immediate sync..."
/usr/local/sbin/potchven-finance-sync
