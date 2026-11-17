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

# What the browsers keep: every stylesheet, script, icon and font the pages
# link, as one fingerprint. Taken before and after the copy to tell whether
# the cache counter has to move (see the end).
static_sum() {
  docker compose exec -T robincloud sh -c 'cd /var/www/html && find core apps dist custom_apps themes -type f \
    \( -name "*.css" -o -name "*.js" -o -name "*.mjs" -o -name "*.svg" -o -name "*.png" -o -name "*.woff2" \) -print0 2>/dev/null |
    sort -z | xargs -0 sha1sum 2>/dev/null | sha1sum | cut -d" " -f1' 2>/dev/null || true
}
before=
[ "$installed" = yes ] && before=$(static_sum)

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
  # With maintenance mode on (it is, since line 32) `occ upgrade` answers 2 when
  # no upgrade is needed and 0 after a successful one. Anything else (1, 4, 5)
  # is a failed upgrade: `|| true` used to hide it and the EXIT trap then opened
  # the site on a half-upgraded database (B-235). Stop with maintenance mode on.
  rc=0
  up=$(occ upgrade --no-interaction 2>&1) || rc=$?
  case "$rc" in
    0|2) ;;
    *)
      trap - EXIT
      echo "RobinCloud upgrade FAILED (occ upgrade exit $rc); maintenance mode stays ON." >&2
      printf '%s\n' "$up" | tail -20 >&2
      echo "Fix it, then: docker compose exec -u www-data robincloud php occ upgrade; php occ maintenance:mode --off" >&2
      exit 1
      ;;
  esac
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
# Only when one of those files did change: a new counter throws away all two
# hundred files each browser holds, and from Korea the first page after that
# takes 13 to 18 seconds (Cloudflare's Los Angeles edge has none of them
# either, and fetches each from Tokyo; measured 2026-11-17). The counter went
# from 27 to 50 in three weeks of deploys that mostly touched PHP.
# ROBINCLOUD_BUMP=1 moves it regardless.
if [ "$installed" = yes ]; then
  after=$(static_sum)
  if [ "${ROBINCLOUD_BUMP:-}" = 1 ] || [ -z "$before" ] || [ -z "$after" ] || [ "$before" != "$after" ]; then
    cb=$(occ config:app:get theming cachebuster | tr -dc 0-9)
    occ config:app:set theming cachebuster --value=$(( ${cb:-0} + 1 )) >/dev/null
    echo "static files changed: cache counter $(( ${cb:-0} + 1 ))"
  else
    echo "static files unchanged: cache counter stays"
  fi
fi

# fail loudly if the login page doesn't come back
code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/login)
[ "$code" = 200 ] || { echo "RobinCloud deploy check failed: /login answered $code" >&2; exit 1; }
echo "RobinCloud deployed: $(git -C "$SRC" log -1 --format='%h %s')"
