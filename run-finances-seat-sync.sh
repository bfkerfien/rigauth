#!/usr/bin/env bash
set -Eeuo pipefail

REPO="/opt/rigauth"
RUNTIME="/opt/potchven-finances-sync-runtime"
ENV_FILE="/etc/potchven-finances-seat-sync.env"
IMAGE="node:22-bookworm-slim"
DB_NETWORK="seat-docker_seat-internal"
OUTBOUND_NETWORK="bridge"
DB_CONTAINER="seat-docker-mariadb-1"
WORKER="$REPO/finance-sync/sync-finances-seat-db.js"
CONTAINER_NAME="potchven-finance-sync-$$"

[[ -f "$ENV_FILE" ]] || { echo "ERROR: private sync environment is missing; run deploy-finances-seat-sync.sh"; exit 1; }
[[ -f "$WORKER" ]] || { echo "ERROR: finance sync worker missing: $WORKER"; exit 1; }
[[ -d "$RUNTIME/node_modules/mysql2" ]] || { echo "ERROR: mysql2 runtime dependency missing; run deploy-finances-seat-sync.sh"; exit 1; }
docker inspect "$DB_CONTAINER" >/dev/null 2>&1 || { echo "ERROR: SeAT MariaDB container is not running"; exit 1; }
docker network inspect "$DB_NETWORK" >/dev/null 2>&1 || { echo "ERROR: SeAT database network is missing: $DB_NETWORK"; exit 1; }
docker network inspect "$OUTBOUND_NETWORK" >/dev/null 2>&1 || { echo "ERROR: outbound Docker bridge is missing: $OUTBOUND_NETWORK"; exit 1; }

cleanup() {
  docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Create on the normal bridge for outbound DNS/HTTPS, then attach the isolated
# SeAT network before startup so the worker can also reach MariaDB by container name.
docker create --name "$CONTAINER_NAME" --network "$OUTBOUND_NETWORK" \
  --env-file "$ENV_FILE" \
  -e NODE_PATH=/runtime/node_modules \
  -v "$WORKER:/app/sync-finances-seat-db.js:ro" \
  -v "$RUNTIME:/runtime:ro" \
  "$IMAGE" node /app/sync-finances-seat-db.js >/dev/null

docker network connect "$DB_NETWORK" "$CONTAINER_NAME"
docker start --attach "$CONTAINER_NAME"
