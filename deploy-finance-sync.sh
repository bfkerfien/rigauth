#!/usr/bin/env bash
set -Eeuo pipefail
REPO="/opt/rigauth"
FIN_DIR="/opt/potchven-finances"
DEST="$FIN_DIR/src/sync-finances-env.js"
cd "$REPO"
[[ -f "$REPO/finance-sync/sync-finances-env.js" ]] || { echo "Missing finance sync source."; exit 1; }
[[ -d "$FIN_DIR/src" && -f "$FIN_DIR/package.json" ]] || { echo "STOP: expected standalone finance project at $FIN_DIR."; exit 1; }
mkdir -p "$FIN_DIR/src"
if [[ -f "$DEST" ]]; then
  cp -a "$DEST" "$DEST.backup-$(date +%Y%m%d-%H%M%S)"
fi
install -m 0644 "$REPO/finance-sync/sync-finances-env.js" "$DEST"
if [[ ! -f "$FIN_DIR/.env.example" ]]; then
  install -m 0644 "$REPO/finance-sync/.env.example" "$FIN_DIR/.env.example"
fi
echo "Installed sync script: $DEST"
echo "Existing .env was not read, overwritten, or printed."
echo "Before running, verify the finance app container has mysql2/dotenv and can reach SeAT MariaDB."
echo "Run from the finance app's working directory or container so dotenv loads its .env."
