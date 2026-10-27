#!/usr/bin/env bash
# Puts robincloud-l10n/ko.json (the Korean overlay) live without the full
# deploy: no image build, no maintenance mode, no restart. The overlay is
# copied into the running container and merged into every app's
# l10n/ko.json + ko.js by robincloud-rebrand.php (the same merge the image
# build and robincloud-deploy.sh run), then the theming cachebuster moves so
# browsers fetch the new ko.js (served immutable under ?v=<hash>-<counter>).
# PHP reads ko.json per request, so server-side strings change at once.
# robincloud-rebrand.php goes in with it, so a change to the merge itself
# (e.g. the "_bundle" rewrite of Korean baked into JS bundles) runs too.
# The next full deploy bakes the same files into the image.
#   bash robincloud-l10n-apply.sh
set -euo pipefail
SRC="$(cd "$(dirname "$0")" && pwd)"
python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$SRC/robincloud-l10n/ko.json"
cd /opt/stack
docker compose cp "$SRC/robincloud-l10n/ko.json" robincloud:/var/www/html/robincloud-l10n/ko.json
docker compose cp "$SRC/robincloud-rebrand.php" robincloud:/var/www/html/robincloud-rebrand.php
docker compose exec -T robincloud chown www-data:www-data /var/www/html/robincloud-l10n/ko.json /var/www/html/robincloud-rebrand.php
docker compose exec -T -u www-data robincloud php /var/www/html/robincloud-rebrand.php /var/www/html | grep -E '^(js|ko)'
occ() { docker compose exec -T -u www-data robincloud php occ "$@"; }
cb=$(occ config:app:get theming cachebuster | tr -dc 0-9)
occ config:app:set theming cachebuster --value=$(( ${cb:-0} + 1 )) >/dev/null
code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/login)
[ "$code" = 200 ] || { echo "RobinCloud l10n apply: /login answered $code" >&2; exit 1; }
echo "RobinCloud ko overlay applied (cachebuster $(( ${cb:-0} + 1 )))"
