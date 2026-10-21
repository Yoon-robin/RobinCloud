#!/usr/bin/env bash
# Rebuilds the RobinCloud image from this source tree and rolls it into the
# running stack (/opt/stack). The image keeps Nextcloud's version number, so
# the official entrypoint would never re-sync the code into the html volume;
# this does it with the entrypoint's own exclude list.
#   bash robincloud-deploy.sh
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)"
cd "$SRC/.."
cp "$SRC/Dockerfile.robincloud" Dockerfile
docker build -q -t robincloud:34.0.5 . >/dev/null
cd /opt/stack
docker compose up -d robincloud cron >/dev/null
docker compose exec -T robincloud rsync -rlDog --chown www-data:www-data --delete \
  --exclude-from=/upgrade.exclude /usr/src/nextcloud/ /var/www/html/
docker compose exec -T robincloud apachectl -k graceful
if docker compose exec -T -u www-data robincloud php occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
  docker compose exec -T -u www-data robincloud php occ upgrade --no-interaction >/dev/null || true
fi
echo "RobinCloud deployed: $(git -C "$SRC" log -1 --format='%h %s')"
