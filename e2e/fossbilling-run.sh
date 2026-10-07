#!/usr/bin/env bash
# End-to-end run of the FOSSBilling module against a real panel API.
#
#   API_KEY=<reseller key> API_URL=http://host.docker.internal:18092 plugins/e2e/fossbilling-run.sh
#
# Starts MariaDB + the official fossbilling/fossbilling image (containers xc-fossbilling-*), installs
# FOSSBilling non-interactively, copies plugins/fossbilling into it, activates the module and runs
# plugins/e2e/fossbilling-scenario.php inside the container. Everything is removed at the end.
# API_URL is the panel API as seen FROM Docker (host.docker.internal reaches the host).
set -euo pipefail
cd "$(dirname "$0")/../.."

: "${API_KEY:?set API_KEY to the reseller API key of the panel}"
API_URL="${API_URL:-http://host.docker.internal:18092}"
NET=xc-fossbilling-net
DB=xc-fossbilling-db
APP=xc-fossbilling-app
TOKEN="e2e$(od -An -tx1 -N14 /dev/urandom | tr -d ' \n')"

cleanup() {
  [ "${KEEP:-0}" = 1 ] && { echo "KEEP=1: leaving $APP and $DB running"; return; }
  docker rm -f "$APP" "$DB" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
say() { printf '\n### %s\n' "$*"; }

say "FOSSBilling: MariaDB + official image"
docker rm -f "$APP" "$DB" >/dev/null 2>&1 || true
docker network rm "$NET" >/dev/null 2>&1 || true
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=fb mariadb:11 >/dev/null
docker run -d --name "$APP" --network "$NET" --add-host=host.docker.internal:host-gateway \
  ${FOSSBILLING_PORT:+-p 127.0.0.1:${FOSSBILLING_PORT}:80} fossbilling/fossbilling >/dev/null
for _ in $(seq 1 60); do docker exec "$DB" mariadb -uroot -proot -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
for _ in $(seq 1 60); do docker exec "$APP" curl -fsS -o /dev/null http://127.0.0.1/install/install.php 2>/dev/null && break; sleep 1; done

say "install FOSSBilling (non-interactive installer) and an admin API token"
docker exec "$APP" curl -sS -o /tmp/install.html -w 'installer: HTTP %{http_code}\n' -X POST 'http://127.0.0.1/install/install.php?a=install' \
  -d system_url=http://127.0.0.1/ -d database_hostname="$DB" -d database_port=3306 -d database_name=fb -d database_username=root \
  -d database_password=root -d admin_name=Admin -d admin_email=admin@example.test -d admin_password=Admin-pass-1234 -d currency_code=USD
docker exec "$APP" grep -q 'Installation Completed' /tmp/install.html || { echo "the installer did not finish"; exit 1; }
docker exec "$DB" mariadb -uroot -proot fb -e "update admin set api_token='$TOKEN' where id=1"

# FOSSBilling limits sign-in attempts per IP; the scenario signs in several times from one address.
docker exec "$APP" php -r '$f="/var/www/html/config.php"; $c=require $f; $c["rate_limiter"]["enabled"]=false; file_put_contents($f, "<?php\nreturn ".var_export($c,true).";\n");'
docker exec "$APP" chown www-data:www-data /var/www/html/config.php

say "copy the connector into FOSSBilling and activate it"
docker cp plugins/fossbilling/modules/. "$APP":/var/www/html/modules/
docker exec "$APP" chown -R www-data:www-data /var/www/html/modules/Servicextreampro
for f in $(docker exec "$APP" find /var/www/html/modules/Servicextreampro -name '*.php'); do
  docker exec "$APP" php -l "$f" | grep -v '^No syntax errors' || true
done
docker exec "$APP" curl -sS -u "admin:$TOKEN" -d id=servicextreampro -d type=mod http://127.0.0.1/api/admin/extension/activate | grep -q '"error":null' \
  || { echo "the module did not activate"; exit 1; }

say "scenario"
docker cp plugins/e2e/fossbilling-scenario.php "$APP":/tmp/scenario.php
OUT="$(docker exec -e API_URL="$API_URL" -e API_KEY="$API_KEY" -e FB_TOKEN="$TOKEN" -e DB_HOST="$DB" "$APP" php /tmp/scenario.php 2>&1 || true)"
echo "$OUT"
grep -q '^ALL OK' <<<"$OUT" && { say "FOSSBILLING OK"; exit 0; }
say "FOSSBILLING FAILED"
exit 1
