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
# The RUN steps run inside dockerd, where `nice` does not reach: put them in
# the low-weight robinbuild.slice (CPUWeight 10) when the server has it, so a
# 3 GB build does not take the CPU from the live site and mail (B-147).
low=()
[ -d /sys/fs/cgroup/robinbuild.slice ] && low=(--cgroup-parent=/robinbuild.slice)
docker build -q "${low[@]}" -t robincloud:34.0.5 . >/dev/null
cd /opt/stack
# One maintenance job at a time: the nightly backup (/opt/backup/robin-backup.sh)
# switches maintenance mode on and off too, and whichever finished first would
# turn it off under the other. Wait up to 30 min for it (03:15, a minute or so).
exec 9>"${ROBIN_MAINT_LOCK:-/run/lock/robin-maint.lock}"
flock -n 9 || { echo "another maintenance job (backup?) is running, waiting..." >&2; flock -w 1800 9 || { echo "maintenance lock busy for 30 min, giving up" >&2; exit 1; }; }
occ() { docker compose exec -T -u www-data robincloud php occ "$@"; }

installed=no
occ status --output=json 2>/dev/null | grep -q '"installed":true' && installed=yes
[ "$installed" = yes ] && occ maintenance:mode --on >/dev/null
trap '[ "$installed" = yes ] && occ maintenance:mode --off >/dev/null' EXIT

if [ "${1:-}" = --recreate ]; then
  docker compose up -d robincloud cron >/dev/null
fi
# -t keeps timestamps so later runs only copy what changed. .htaccess is held
# back: the source copy lacks the pretty-URL rules, and without them /login and
# /apps/... answer 404 until they are regenerated. It goes in last, swapped and
# regenerated in one step.
docker run --rm -v stack_robincloud:/var/www/html --entrypoint sh robincloud:34.0.5 -c '
  rsync -rlDogt --chown www-data:www-data --delete --exclude-from=/upgrade.exclude \
    --exclude=/.htaccess --exclude=/.htaccess.robincloud-new /usr/src/nextcloud/ /var/www/html/ &&
  cp /usr/src/nextcloud/.htaccess /var/www/html/.htaccess.robincloud-new &&
  chown www-data:www-data /var/www/html/.htaccess.robincloud-new'
if [ "$installed" = yes ]; then
  docker compose exec -T -u www-data robincloud sh -c \
    'mv /var/www/html/.htaccess.robincloud-new /var/www/html/.htaccess && php occ maintenance:update:htaccess >/dev/null'
  occ upgrade --no-interaction >/dev/null || true
else
  docker compose exec -T -u www-data robincloud mv /var/www/html/.htaccess.robincloud-new /var/www/html/.htaccess
fi

# app-store apps live outside the image: give them the rebrand and the Korean overlay too
docker compose exec -T -u www-data robincloud php /var/www/html/robincloud-rebrand.php /var/www/html/custom_apps >/dev/null
docker compose exec -T robincloud apachectl -k graceful 2>/dev/null
# URLGenerator keeps image paths in Redis under a key that only changes with
# the version, so a changed icon would otherwise keep being linked by its old URL
docker compose exec -T redis sh -c 'redis-cli --scan --pattern "*imagePath-*" | xargs -r redis-cli del' >/dev/null

[ "$installed" = yes ] && occ maintenance:mode --off >/dev/null
trap - EXIT
# Core CSS and JS go out as immutable for six months under ?v=<hash>-<counter>,
# and the hash only moves with the version: without a new counter a changed
# robincloud.css stays cached (Cloudflare, browsers) under the old URL. After
# maintenance mode, which prints a warning on every occ call.
if [ "$installed" = yes ]; then
  cb=$(occ config:app:get theming cachebuster | tr -dc 0-9)
  occ config:app:set theming cachebuster --value=$(( ${cb:-0} + 1 )) >/dev/null
fi

# fail loudly if the login page doesn't come back
code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/login)
[ "$code" = 200 ] || { echo "RobinCloud deploy check failed: /login answered $code" >&2; exit 1; }
echo "RobinCloud deployed: $(git -C "$SRC" log -1 --format='%h %s')"
