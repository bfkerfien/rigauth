#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "Usage: $0 DESTINATION" >&2
  exit 2
fi

DEST=$(realpath -m "$1")
if [ -e "$DEST" ]; then
  echo "Destination already exists: $DEST" >&2
  exit 1
fi
mkdir -p "$(dirname "$DEST")"
mkdir "$DEST"
cleanup() {
  rm -rf -- "$DEST"
}
trap cleanup ERR

clone_at() {
  local url="$1"
  local commit="$2"
  local path="$3"
  git clone --quiet "$url" "$path"
  git -C "$path" checkout --quiet "$commit"
}

clone_at https://github.com/eveseat/seat.git 34090c12800304a8cb38e1f2d7d1b9be2715b303 "$DEST/seat"
clone_at https://github.com/eveseat/seat-docker.git ea0922b83e7c8c31fd3959a6c2abe811328fb0cc "$DEST/seat-docker"
mkdir -p "$DEST/seat-docker/packages/eveseat"
clone_at https://github.com/eveseat/api.git fe523ffed5e298ea913242998a2b2274ff8a65e5 "$DEST/seat-docker/packages/eveseat/api"
clone_at https://github.com/eveseat/console.git e36ad7fe3f4ffd05a73ee6bcb4db5ac01cbe3bda "$DEST/seat-docker/packages/eveseat/console"
clone_at https://github.com/eveseat/eseye.git 26547934661eb1a14f19138c705ff1dc93be5338 "$DEST/seat-docker/packages/eveseat/eseye"
clone_at https://github.com/eveseat/eveapi.git 5725cf77ac80352f27130485857c8f5a35ac3cb9 "$DEST/seat-docker/packages/eveseat/eveapi"
clone_at https://github.com/eveseat/notifications.git 4e8c02b50506ec18d5af0697081707294262793c "$DEST/seat-docker/packages/eveseat/notifications"
clone_at https://github.com/eveseat/services.git b2db97fd75f03c68cf587dd05da5beba2718f360 "$DEST/seat-docker/packages/eveseat/services"
clone_at https://github.com/eveseat/web.git cd11287006bddbf00c48cf13fd1fa704b0ea25d6 "$DEST/seat-docker/packages/eveseat/web"

cat > "$DEST/seat-docker/packages/override.json" <<'OVERRIDE'
{
  "autoload": {
    "Seat\\Api\\": "packages/eveseat/api/src/",
    "Seat\\Console\\": "packages/eveseat/console/src/",
    "Seat\\Eseye\\": "packages/eveseat/eseye/src/",
    "Seat\\Eveapi\\": "packages/eveseat/eveapi/src/",
    "Seat\\Eveapi\\Database\\Seeders\\": "packages/eveseat/eveapi/src/database/seeders/",
    "Seat\\Eveapi\\Database\\Seeders\\Sde\\": "packages/eveseat/eveapi/src/database/seeders/Sde",
    "Seat\\Tests\\Eveapi\\Database\\Factories\\": "packages/eveseat/eveapi/tests/database/factories/",
    "Seat\\Notifications\\": "packages/eveseat/notifications/src/",
    "Seat\\Services\\": "packages/eveseat/services/src/",
    "Seat\\Services\\Database\\Seeders\\": "packages/eveseat/services/src/database/seeders/",
    "Seat\\Web\\": "packages/eveseat/web/src/",
    "Seat\\Web\\Database\\Seeders\\": "packages/eveseat/web/src/database/seeders/"
  }
}
OVERRIDE

git -C "$DEST/seat-docker/packages/eveseat/web" apply "$(dirname "$0")/patches/0001-header-logout-button.patch"
git -C "$DEST/seat-docker/packages/eveseat/web" apply "$(dirname "$0")/patches/0002-fittings-section.patch"
git -C "$DEST/seat-docker/packages/eveseat/web" apply --unidiff-zero "$(dirname "$0")/fittings/patches/0005-fittings-management.patch"
git -C "$DEST/seat-docker/packages/eveseat/web" apply --recount "$(dirname "$0")/patches/0003-triglavian-admin-setup.patch"
git -C "$DEST/seat-docker/packages/eveseat/web" apply --recount "$(dirname "$0")/patches/0004-seat-sso-bridge.patch"
git -C "$DEST/seat-docker/packages/eveseat/web" apply --recount "$(dirname "$0")/patches/0006-market-section.patch"
cp -a "$(dirname "$0")/custom/seat-web/." "$DEST/seat-docker/packages/eveseat/web/"
mkdir -p "$DEST/seat-docker/packages/eveseat/web/src/Http/Controllers" "$DEST/seat-docker/packages/eveseat/web/src/resources/views/fittings" "$DEST/seat-docker/packages/eveseat/web/src/resources/views/market"
cp "$(dirname "$0")/fittings/seat/src/Http/Controllers/FittingsController.php" "$DEST/seat-docker/packages/eveseat/web/src/Http/Controllers/FittingsController.php"
cp "$(dirname "$0")/fittings/seat/src/resources/views/fittings/manage.blade.php" "$DEST/seat-docker/packages/eveseat/web/src/resources/views/fittings/manage.blade.php"
cp "$(dirname "$0")/fittings/seat/src/resources/views/fittings/doctrines.blade.php" "$DEST/seat-docker/packages/eveseat/web/src/resources/views/fittings/doctrines.blade.php"
cp "$(dirname "$0")/fittings/seat/src/resources/views/fittings/doctrine.blade.php" "$DEST/seat-docker/packages/eveseat/web/src/resources/views/fittings/doctrine.blade.php"
cp "$(dirname "$0")/fittings/seat/src/resources/views/fittings/fit-tabs.blade.php" "$DEST/seat-docker/packages/eveseat/web/src/resources/views/fittings/fit-tabs.blade.php"
cp "$(dirname "$0")/sources.lock.json" "$DEST/sources.lock.json"
trap - ERR
echo "Restored SeAT sources with Triglavian UI patches to $DEST"
