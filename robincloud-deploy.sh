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
occ() { docker compose exec -T -u www-data robincloud php occ "$@"; }

docker compose up -d robincloud cron >/dev/null
docker compose exec -T robincloud rsync -rlDog --chown www-data:www-data --delete \
  --exclude-from=/upgrade.exclude /usr/src/nextcloud/ /var/www/html/

if occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
  # the sync puts back the bare .htaccess from the source tree, which drops
  # the pretty-URL rewrite rules; without them /login and /apps/... break
  occ maintenance:update:htaccess >/dev/null
  occ upgrade --no-interaction >/dev/null || true
fi

# app-store apps live outside the image: give them the rebrand and the Korean overlay too
docker compose exec -T -u www-data robincloud php /var/www/html/robincloud-rebrand.php /var/www/html/custom_apps >/dev/null
docker compose exec -T robincloud apachectl -k graceful 2>/dev/null

# fail loudly if the login page doesn't come back
code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/login)
[ "$code" = 200 ] || { echo "RobinCloud deploy check failed: /login answered $code" >&2; exit 1; }
echo "RobinCloud deployed: $(git -C "$SRC" log -1 --format='%h %s')"
