#!/usr/bin/env bash
# End-to-end run of the Paymenter server extension from a clean state.
#
#   API_URL=http://host.docker.internal:18092 API_KEY=<reseller key> plugins/e2e/paymenter-run.sh
#
# Starts MariaDB and the official Paymenter image (ghcr.io/paymenter/paymenter,
# which migrates and seeds itself on start), copies plugins/paymenter/extensions/Servers/XtreamPro
# in, runs plugins/e2e/paymenter-scenario.php inside the container and removes
# everything again. API_URL is the panel API as the *container* sees it.
# Containers and the network are named xc-paymenter-*. Needs only docker.
set -euo pipefail
cd "$(dirname "$0")/../.."

: "${API_URL:?set API_URL (panel API as seen from a container, e.g. http://host.docker.internal:18092)}"
: "${API_KEY:?set API_KEY (a reseller API key of that panel)}"
NET=xc-paymenter-net
DB=xc-paymenter-db
APP=xc-paymenter-app
IMAGE="${PAYMENTER_IMAGE:-ghcr.io/paymenter/paymenter:latest}"

cleanup() {
  docker rm -f "$APP" "$DB" >/dev/null 2>&1 || true
  docker volume rm xc-paymenter-var >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
# KEEP=1 leaves the containers up for inspection.
[ -n "${KEEP:-}" ] || trap cleanup EXIT
cleanup

docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=paymenter \
  -e MARIADB_USER=paymenter -e MARIADB_PASSWORD=pmpass mariadb:lts >/dev/null
for _ in $(seq 1 60); do
  docker exec "$DB" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
  sleep 2
done

docker run -d --name "$APP" --network "$NET" --add-host=host.docker.internal:host-gateway \
  -v xc-paymenter-var:/app/var \
  -e APP_KEY="base64:$(head -c32 /dev/urandom | base64)" \
  -e DB_HOST="$DB" -e DB_PORT=3306 -e DB_CONNECTION=mariadb -e DB_DATABASE=paymenter \
  -e DB_USERNAME=paymenter -e DB_PASSWORD=pmpass \
  -e APP_ENV=production -e APP_URL=http://localhost \
  -e QUEUE_CONNECTION=sync -e CACHE_STORE=file -e SESSION_DRIVER=file -e MAIL_MAILER=log \
  "$IMAGE" >/dev/null
# The image runs "php artisan migrate --seed" on start; wait for it.
# The log is read into a variable first: with pipefail, `docker logs | grep -q`
# fails whenever grep finds the line early and closes the pipe on docker.
started() { local logs; logs="$(docker logs "$APP" 2>&1 || true)"; grep -q 'Starting supervisord' <<<"$logs"; }
for _ in $(seq 1 90); do
  started && break
  sleep 2
done
started || { docker logs "$APP" 2>&1 | tail -20 || true; echo "Paymenter did not start"; exit 1; }
echo "Paymenter $(docker exec "$APP" grep -m1 "'version'" /app/config/app.php | tr -d " ',>=-" | sed 's/version//')"

docker cp plugins/paymenter/extensions/Servers/XtreamPro "$APP":/app/extensions/Servers/
docker exec "$APP" chown -R nginx:nginx /app/extensions/Servers/XtreamPro
docker exec "$APP" sh -c 'for f in $(find /app/extensions/Servers/XtreamPro -name "*.php"); do php -l "$f" >/dev/null || exit 1; done'
docker cp plugins/e2e/paymenter-scenario.php "$APP":/tmp/scenario.php

OUT="$(docker exec -e API_URL="$API_URL" -e API_KEY="$API_KEY" "$APP" php /tmp/scenario.php 2>&1 || true)"
echo "$OUT"
grep -q '^ALL OK' <<<"$OUT" || exit 1
