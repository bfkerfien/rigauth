#!/usr/bin/env bash
set -Eeuo pipefail

APP_CONTAINER="${APP_CONTAINER:-potchven-finances-app-1}"
SEAT_FRONT_CONTAINER="${SEAT_FRONT_CONTAINER:-seat-docker-front-1}"
SEAT_DB_CONTAINER="${SEAT_DB_CONTAINER:-seat-docker-mariadb-1}"
SEAT_NETWORK="${SEAT_NETWORK:-seat-docker_seat-internal}"

command -v docker >/dev/null 2>&1 || { echo "ERROR: docker command not found."; exit 1; }
docker inspect "$APP_CONTAINER" >/dev/null 2>&1 || { echo "ERROR: finance app container is not running."; exit 1; }
docker inspect "$SEAT_FRONT_CONTAINER" >/dev/null 2>&1 || { echo "ERROR: SeAT front container is not running."; exit 1; }

DB_USER="$(docker exec "$SEAT_FRONT_CONTAINER" printenv DB_USERNAME)"
DB_PASS="$(docker exec "$SEAT_FRONT_CONTAINER" printenv DB_PASSWORD)"
DB_NAME="$(docker exec "$SEAT_FRONT_CONTAINER" printenv DB_DATABASE)"
if [[ -z "$DB_USER" || -z "$DB_PASS" || -z "$DB_NAME" ]]; then
  echo "ERROR: unable to read SeAT database settings; no sync performed."
  exit 1
fi

docker network connect "$SEAT_NETWORK" "$APP_CONTAINER" >/dev/null 2>&1 || true

exec docker exec -i \
  -e FIN_DB_HOST="$SEAT_DB_CONTAINER" \
  -e FIN_DB_PORT=3306 \
  -e FIN_DB_USER="$DB_USER" \
  -e FIN_DB_PASSWORD="$DB_PASS" \
  -e FIN_DB_NAME="$DB_NAME" \
  -e FIN_CORP_EVE_ID="${FIN_CORP_EVE_ID:-98843282}" \
  -e FIN_CORP_WALLET_DIVISION="${FIN_CORP_WALLET_DIVISION:-1}" \
  "$APP_CONTAINER" node /app/src/sync-finances-seat-db.js
