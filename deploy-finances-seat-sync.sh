#!/usr/bin/env bash
set -Eeuo pipefail
REPO="/opt/rigauth"
FIN_DIR="/opt/potchven-finances"
SOURCE="$REPO/finance-sync/sync-finances-seat-db.js"
DEST="$FIN_DIR/src/sync-finances-seat-db.js"
RUNNER="$REPO/run-finances-seat-sync.sh"
INSTALLED_RUNNER="/usr/local/sbin/potchven-finance-sync"
STAMP="$(date +%Y%m%d-%H%M%S)"

[[ -f "$SOURCE" ]] || { echo "STOP: sync worker missing at $SOURCE"; exit 1; }
[[ -d "$FIN_DIR/src" && -f "$FIN_DIR/package.json" ]] || { echo "STOP: expected finance project not found at $FIN_DIR"; exit 1; }
[[ -f "$RUNNER" ]] || { echo "STOP: runner missing at $RUNNER"; exit 1; }

if [[ -f "$DEST" ]]; then cp -a "$DEST" "$DEST.backup-$STAMP"; fi
install -m 0644 "$SOURCE" "$DEST"
sudo install -m 0750 "$RUNNER" "$INSTALLED_RUNNER"

echo "Installed sync worker: $DEST"
echo "Installed safe runner: $INSTALLED_RUNNER"
echo "No app container was restarted."
echo "Run one sync with: sudo $INSTALLED_RUNNER"
