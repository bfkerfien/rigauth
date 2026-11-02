#!/usr/bin/env bash
set -Eeuo pipefail

REPO="/opt/rigauth"
RUNTIME="/opt/potchven-finances-sync-runtime"
ENV_FILE="/etc/potchven-finances-seat-sync.env"
IMAGE="node:22-bookworm-slim"
NETWORK="seat-docker_seat-internal"
DB_CONTAINER="seat-docker-mariadb-1"
WORKER="$REPO/finance-sync/sync-finances-seat-db.js"

[[ -f "$ENV_FILE" ]] || { echo "ERROR: private sync environment is missing; run deploy-finances-seat-sync.sh"; exit 1; }
[[ -f "$WORKER" ]] || { echo "ERROR: finance sync worker missing: $WORKER"; exit 1; }
[[ -d "$RUNTIME/node_modules/mysql2" ]] || { echo "ERROR: mysql2 runtime dependency missing; run deploy-finances-seat-sync.sh"; exit 1; }
docker inspect "$DB_CONTAINER" >/dev/null 2>&1 || { echo "ERROR: SeAT MariaDB container is not running"; exit 1; }

exec docker run --rm --network "$NETWORK" \
  --env-file "$ENV_FILE" \
  -e NODE_PATH=/runtime/node_modules \
  -v "$WORKER:/app/sync-finances-seat-db.js:ro" \
  -v "$RUNTIME:/runtime:ro" \
  "$IMAGE" node /app/sync-finances-seat-db.js
