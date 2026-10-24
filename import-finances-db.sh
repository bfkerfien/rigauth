#!/usr/bin/env bash
set -Eeuo pipefail
SOURCE_CONTAINER="${SOURCE_CONTAINER:-potchven-finances-db-1}"
SEAT_CONTAINER="${SEAT_CONTAINER:-seat-docker-mariadb-1}"
SEAT_DATABASE="${SEAT_DATABASE:-seat}"
STAMP="$(date +%Y%m%d-%H%M%S)"
WORK="$(mktemp -d)"
BACKUP="/root/seat-before-finances-import-$STAMP.sql"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

docker inspect "$SOURCE_CONTAINER" >/dev/null 2>&1 || { echo "ERROR: source container not found: $SOURCE_CONTAINER"; exit 1; }
docker inspect "$SEAT_CONTAINER" >/dev/null 2>&1 || { echo "ERROR: SeAT database container not found: $SEAT_CONTAINER"; exit 1; }
read -r -s -p "SeAT MariaDB root password: " SEAT_ROOT_PASSWORD; echo
EXISTING="$(docker exec -e MYSQL_PWD="$SEAT_ROOT_PASSWORD" "$SEAT_CONTAINER" mariadb -u root -N -e "SHOW TABLES FROM \`$SEAT_DATABASE\`" | grep '^fin_' || true)"
if [[ -n "$EXISTING" ]]; then
  echo "STOP: fin_ tables already exist; refusing to overwrite them:"
  printf '%s\\n' "$EXISTING"
  exit 1
fi

echo "Backing up SeAT database to $BACKUP"
docker exec -e MYSQL_PWD="$SEAT_ROOT_PASSWORD" "$SEAT_CONTAINER" mariadb-dump -u root --single-transaction --routines --triggers "$SEAT_DATABASE" > "$BACKUP"
chmod 600 "$BACKUP"

echo "Dumping standalone Potchven Finances database..."
docker exec "$SOURCE_CONTAINER" sh -lc '
  DB_USER="${MARIADB_USER:-${MYSQL_USER:-root}}"
  DB_PASS="${MARIADB_PASSWORD:-${MYSQL_PASSWORD:-${MYSQL_ROOT_PASSWORD:-}}}"
  DB_NAME="${MARIADB_DATABASE:-${MYSQL_DATABASE:-potchven_finances}}"
  exec mariadb-dump --single-transaction --skip-comments --default-character-set=utf8mb4 -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"
' > "$WORK/source.sql"
if ! grep -q 'CREATE TABLE.*finance_' "$WORK/source.sql"; then
  echo "STOP: dump did not contain expected finance_* tables. No import performed."
  exit 1
fi
sed 's/finance_/fin_/g' "$WORK/source.sql" > "$WORK/renamed.sql"
echo "Importing tables and records with fin_ prefixes into $SEAT_DATABASE..."
docker exec -i -e MYSQL_PWD="$SEAT_ROOT_PASSWORD" "$SEAT_CONTAINER" mariadb -u root "$SEAT_DATABASE" < "$WORK/renamed.sql"
echo "Import completed. Source DB untouched. SeAT backup: $BACKUP"
echo "Keep the old finance DB until the dashboard is fully verified."
