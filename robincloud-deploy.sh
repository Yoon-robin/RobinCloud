#!/usr/bin/env bash
# Rebuilds the RobinCloud image from this source tree and rolls it into the
# running stack (/opt/stack).
#
# The new code is copied into the html volume by a throwaway container from
# the new image, while the running container keeps serving: recreating the
# container meant 10-30 seconds of Cloudflare 502s. Visitors see RobinCloud's
# maintenance page for the few seconds the copy takes instead.
# Pass --recreate when the image changed outside /usr/src/nextcloud (Apache
# config, PHP settings), which only a new container picks up.
#   bash robincloud-deploy.sh [--recreate]
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)"
cd "$SRC/.."
cp "$SRC/Dockerfile.robincloud" Dockerfile
docker build -q -t robincloud:34.0.5 . >/dev/null
cd /opt/stack
occ() { docker compose exec -T -u www-data robincloud php occ "$@"; }

installed=no
occ status --output=json 2>/dev/null | grep -q '"installed":true' && installed=yes
[ "$installed" = yes ] && occ maintenance:mode --on >/dev/null
trap '[ "$installed" = yes ] && occ maintenance:mode --off >/dev/null' EXIT

if [ "${1:-}" = --recreate ]; then
  docker compose up -d robincloud cron >/dev/null
fi
docker run --rm -v stack_robincloud:/var/www/html --entrypoint rsync robincloud:34.0.5 \
  -rlDog --chown www-data:www-data --delete --exclude-from=/upgrade.exclude \
  /usr/src/nextcloud/ /var/www/html/

if [ "$installed" = yes ]; then
  # the copy puts back the bare .htaccess from the source tree, which drops
  # the pretty-URL rewrite rules; without them /login and /apps/... break
  occ maintenance:update:htaccess >/dev/null
  occ upgrade --no-interaction >/dev/null || true
fi

# app-store apps live outside the image: give them the rebrand and the Korean overlay too
docker compose exec -T -u www-data robincloud php /var/www/html/robincloud-rebrand.php /var/www/html/custom_apps >/dev/null
docker compose exec -T robincloud apachectl -k graceful 2>/dev/null

[ "$installed" = yes ] && occ maintenance:mode --off >/dev/null
trap - EXIT

# fail loudly if the login page doesn't come back
code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/login)
[ "$code" = 200 ] || { echo "RobinCloud deploy check failed: /login answered $code" >&2; exit 1; }
echo "RobinCloud deployed: $(git -C "$SRC" log -1 --format='%h %s')"
