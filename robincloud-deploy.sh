#!/usr/bin/env bash
# Rolls this source tree into the running RobinCloud (/opt/stack).
#
#   bash robincloud-deploy.sh             # the quick way when it can, else a full deploy
#   bash robincloud-deploy.sh --full      # always rebuild the image
#   bash robincloud-deploy.sh --recreate  # rebuild and recreate the container
#
# Quick way: when everything that changed since the last deploy is a file the
# image build copies as it is and nothing has to restart for (stylesheets,
# pictures, fonts, their licence and text files), those files go straight
# into the html volume (each written next to its place and renamed over it),
# the cache counter moves and that is all: no image build, no maintenance
# mode, a few seconds (a CSS change used to take 4-10 minutes, mostly the
# 3 GB image build). "Since the last deploy" is against $STATE, which a
# deploy writes once /login answers: the image id, the commit, and its time
# as the file's mtime. It counts as changed: what git sees between that
# commit and the working tree (also uncommitted and new files) and any file
# written after that time (git-ignored ones too). Any other change, a deleted
# file, a new image since, or no $STATE means the full deploy below. The
# image keeps the files of the last full deploy until the next one builds it
# again from here.
#
# Full deploy: the image is rebuilt and the new code copied into the html
# volume by a throwaway container from it, while the running container keeps
# serving: recreating the container meant 10-30 seconds of Cloudflare 502s.
# Visitors see RobinCloud's maintenance page for the few seconds the copy
# takes instead. --recreate is for when the image changed outside
# /usr/src/nextcloud (Apache config, PHP settings), which only a new
# container picks up.
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)"
IMAGE=robincloud:34.0.5
STATE=${ROBINCLOUD_DEPLOY_STATE:-/var/lib/robincloud-deploy/state}
MAINT_LOCK=${ROBIN_MAINT_LOCK:-/run/lock/robin-maint.lock}
mode=auto
case "${1:-}" in
  '') ;;
  --full) mode=full ;;
  --recreate) mode=recreate ;;
  *) echo "usage: bash robincloud-deploy.sh [--full | --recreate]" >&2; exit 2 ;;
esac
# the snapshot time of this deploy: files written after it count for the next one
started=$(date +%s)

occ() { docker compose exec -T -u www-data robincloud php occ "$@"; }

# One maintenance job at a time: the nightly backup (/opt/backup/robin-backup.sh)
# switches maintenance mode on and off too, and whichever finished first would
# turn it off under the other. Wait up to 30 min for it (03:15, a minute or so).
maint_lock() {
  exec 9>"$MAINT_LOCK"
  flock -n 9 || { echo "another maintenance job (backup?) is running, waiting..." >&2; flock -w 1800 9 || { echo "maintenance lock busy for 30 min, giving up" >&2; exit 1; }; }
}

# Core CSS and JS go out as immutable for six months under ?v=<hash>-<counter>,
# and the hash only moves with the version: without a new counter a changed
# robincloud.css stays cached (Cloudflare, browsers) under the old URL.
bump_cache_counter() {
  local cb
  cb=$(occ config:app:get theming cachebuster | tr -dc 0-9)
  occ config:app:set theming cachebuster --value=$(( ${cb:-0} + 1 )) >/dev/null
  echo "static files changed: cache counter $(( ${cb:-0} + 1 ))"
}

# URLGenerator keeps image paths in Redis under a key that only changes with
# the version, so a changed icon would otherwise keep being linked by its old URL
forget_image_paths() {
  docker compose exec -T redis sh -c 'redis-cli --scan --pattern "*imagePath-*" | xargs -r redis-cli del' >/dev/null
}

login_check() {
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/login)
  [ "$code" = 200 ] || { echo "RobinCloud deploy check failed: /login answered $code" >&2; exit 1; }
}

# The volume now holds this image's files plus the commit below (and the
# working tree as it was at $started).
write_state() {
  local id
  id=$(docker image inspect -f '{{.Id}}' "$IMAGE")
  mkdir -p "$(dirname "$STATE")"
  printf '%s %s\n' "$id" "$(git -C "$SRC" rev-parse HEAD)" > "$STATE.tmp"
  touch -d "@$started" "$STATE.tmp"
  mv "$STATE.tmp" "$STATE"
}

# ---- what changed since the last deploy, and can it go the quick way -----------
# Paths (relative to $SRC) the image does not have, or the copy into the volume
# leaves out (/upgrade.exclude): a change there deploys nothing either way.
not_deployed() {
  case $1 in
    .git/*|.github/*|tests/*|build/*|*/node_modules/*|node_modules/*) return 0 ;;
    config/*|data/*|custom_apps/*|themes/*|version.php|nextcloud-init-sync.lock) return 0 ;;
    # this script runs from here; its copy in the volume is never used
    robincloud-deploy.sh) return 0 ;;
  esac
  return 1
}
# Files the image build copies unchanged (robincloud-rebrand.php only rewrites
# l10n, .js/.mjs bundles, .php and appinfo/info.xml) and that take effect
# without a restart, upgrade or Apache reload.
quick_ok() {
  case $1 in
    core/skeleton/*|core/signature.json|apps/firstrunwizard/*|apps/nextcloud_announcements/*|\
    apps/survey_client/*|apps/support/*|apps/office/*) return 1 ;;   # the Dockerfile removes these
  esac
  case $1 in
    *.css|*.svg|*.png|*.jpg|*.jpeg|*.gif|*.webp|*.ico|*.woff|*.woff2|*.ttf|*.otf|*.txt|*.md) return 0 ;;
    */fonts/*LICENSE*|*/fonts/*/*LICENSE*) return 0 ;;
  esac
  return 1
}
QUICK=()
WHY=
quick_list() {
  local st_id st_sha id status path
  declare -A seen=()
  [ -f "$STATE" ] || { WHY="no record of the last deploy ($STATE)"; return 1; }
  read -r st_id st_sha < "$STATE" || true
  id=$(docker image inspect -f '{{.Id}}' "$IMAGE" 2>/dev/null || true)
  if [ -z "$id" ] || [ "$id" != "${st_id:-}" ]; then WHY="the image changed since the last deploy"; return 1; fi
  git -C "$SRC" cat-file -e "${st_sha:-x}^{commit}" 2>/dev/null || { WHY="commit ${st_sha:-?} of the last deploy is not here"; return 1; }
  while IFS=$'\t' read -r status path; do
    [ -n "$path" ] || continue
    not_deployed "$path" && continue
    [ "$status" != D ] || { WHY="deleted: $path"; return 1; }
    quick_ok "$path" || { WHY="not a static file: $path"; return 1; }
    [ -n "${seen[$path]:-}" ] || { seen[$path]=1; QUICK+=("$path"); }
  done < <(
    git -C "$SRC" -c core.quotePath=false diff --name-status --no-renames "$st_sha" --
    git -C "$SRC" -c core.quotePath=false ls-files -o --exclude-standard | sed 's/^/A\t/'
    (cd "$SRC" && find . \( -path ./.git -o -name node_modules \) -prune -o -type f -newer "$STATE" -print) | sed 's|^\./|M\t|'
  )
}

quick_deploy() {
  local differ=() f
  cd /opt/stack
  maint_lock
  # only what the volume does not have like this already
  if [ ${#QUICK[@]} -gt 0 ]; then
    declare -A have=()
    while read -r sum f; do have[$f]=$sum; done < <(
      docker compose exec -T robincloud sh -c 'cd /var/www/html && for f; do [ -f "$f" ] && sha1sum "$f"; done; true' sh "${QUICK[@]}")
    while read -r sum f; do
      [ "${have[$f]:-}" = "$sum" ] || differ+=("$f")
    done < <(cd "$SRC" && sha1sum -- "${QUICK[@]}")
  fi
  if [ ${#differ[@]} -eq 0 ]; then
    echo "RobinCloud: nothing to deploy (${#QUICK[@]} static file(s) changed, all already live)"
  else
    printf '  %s\n' "${differ[@]}"
    # written next to their place inside the volume, then renamed over it:
    # a request never reads half a file
    tar -C "$SRC" -cf - -- "${differ[@]}" | docker compose exec -T robincloud sh -c '
      set -e
      stage=/var/www/html/.robincloud-quick
      rm -rf "$stage" && mkdir -m 700 "$stage"
      tar --no-same-owner -xf - -C "$stage"
      cd "$stage"
      find . -type f | while IFS= read -r f; do
        f=${f#./}
        # a new folder: made and given to www-data one level at a time
        (p=/var/www/html; IFS=/; set -f
         for part in $(dirname "$f"); do
           p="$p/$part"; [ -d "$p" ] || { mkdir "$p" && chown www-data:www-data "$p"; }
         done)
        chown www-data:www-data "$f" && chmod 0644 "$f"
        mv -f "$f" "/var/www/html/$f"
      done
      cd / && rm -rf "$stage"'
    forget_image_paths
    bump_cache_counter
  fi
  login_check
  write_state
  echo "RobinCloud deployed the quick way (${#differ[@]} file(s), no image build): $(git -C "$SRC" log -1 --format='%h %s')"
}

if [ "$mode" = auto ]; then
  if quick_list; then
    quick_deploy
    exit 0
  fi
  echo "full deploy: $WHY"
fi

# ---- full deploy -------------------------------------------------------------------
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# What the browsers keep: every stylesheet, script, icon and font the pages
# link, as one fingerprint, before and after the copy, to tell whether the
# cache counter has to move (see the end). The one before is taken while the
# image builds: it reads the volume, which nothing changes until the copy.
static_sum() {
  docker compose exec -T robincloud sh -c 'cd /var/www/html && find core apps dist custom_apps themes -type f \
    \( -name "*.css" -o -name "*.js" -o -name "*.mjs" -o -name "*.svg" -o -name "*.png" -o -name "*.woff2" \) -print0 2>/dev/null |
    sort -z | xargs -0 sha1sum 2>/dev/null | sha1sum | cut -d" " -f1' 2>/dev/null || true
}
(cd /opt/stack && static_sum) > "$tmp/before" &
before_pid=$!

cd "$SRC/.."
cp "$SRC/Dockerfile.robincloud" Dockerfile
# The RUN steps run inside dockerd, where `nice` does not reach: put them in
# the low-weight robinbuild.slice (CPUWeight 10) when the server has it, so a
# 3 GB build does not take the CPU from the live site and mail (B-147).
low=()
[ -d /sys/fs/cgroup/robinbuild.slice ] && low=(--cgroup-parent=/robinbuild.slice)
docker build -q "${low[@]}" --label "org.opencontainers.image.revision=$(git -C "$SRC" rev-parse HEAD)" \
  -t "$IMAGE" . >/dev/null
cd /opt/stack
maint_lock
wait "$before_pid" || true
before=$(cat "$tmp/before")

installed=no
occ status --output=json 2>/dev/null | grep -q '"installed":true' && installed=yes
[ "$installed" = yes ] && occ maintenance:mode --on >/dev/null
trap 'rm -rf "$tmp"; [ "$installed" = yes ] && occ maintenance:mode --off >/dev/null' EXIT

if [ "$mode" = recreate ]; then
  docker compose up -d robincloud cron >/dev/null
fi
# -t keeps timestamps so later runs only copy what changed. .htaccess is held
# back: the source copy lacks the pretty-URL rules, and without them /login and
# /apps/... answer 404 until they are regenerated. It goes in last, swapped and
# regenerated in one step.
docker run --rm -v stack_robincloud:/var/www/html --entrypoint sh "$IMAGE" -c '
  rsync -rlDogt --chown www-data:www-data --delete --exclude-from=/upgrade.exclude \
    --exclude=/.htaccess --exclude=/.htaccess.robincloud-new /usr/src/nextcloud/ /var/www/html/ &&
  cp /usr/src/nextcloud/.htaccess /var/www/html/.htaccess.robincloud-new &&
  chown www-data:www-data /var/www/html/.htaccess.robincloud-new'
if [ "$installed" = yes ]; then
  docker compose exec -T -u www-data robincloud sh -c \
    'mv /var/www/html/.htaccess.robincloud-new /var/www/html/.htaccess && php occ maintenance:update:htaccess >/dev/null'
  # With maintenance mode on `occ upgrade` answers 2 when no upgrade is needed
  # and 0 after a successful one. Anything else (1, 4, 5) is a failed upgrade:
  # `|| true` used to hide it and the EXIT trap then opened the site on a
  # half-upgraded database (B-235). Stop with maintenance mode on.
  rc=0
  up=$(occ upgrade --no-interaction 2>&1) || rc=$?
  case "$rc" in
    0|2) ;;
    *)
      trap 'rm -rf "$tmp"' EXIT
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
forget_image_paths

[ "$installed" = yes ] && occ maintenance:mode --off >/dev/null
trap 'rm -rf "$tmp"' EXIT
# The counter moves only when one of those files did change (after maintenance
# mode, which prints a warning on every occ call): a new counter throws away
# all two hundred files each browser holds, and from Korea the first page
# after that takes 13 to 18 seconds (Cloudflare's Los Angeles edge has none of
# them either, and fetches each from Tokyo; measured 2026-11-17). The counter
# went from 27 to 50 in three weeks of deploys that mostly touched PHP.
# ROBINCLOUD_BUMP=1 moves it regardless.
if [ "$installed" = yes ]; then
  after=$(static_sum)
  if [ "${ROBINCLOUD_BUMP:-}" = 1 ] || [ -z "$before" ] || [ -z "$after" ] || [ "$before" != "$after" ]; then
    bump_cache_counter
  else
    echo "static files unchanged: cache counter stays"
  fi
fi

# fail loudly if the login page doesn't come back
login_check
write_state
echo "RobinCloud deployed: $(git -C "$SRC" log -1 --format='%h %s')"
