#!/usr/bin/env bash
# End-to-end run of the three connectors against a real panel API.
#
#   plugins/e2e/run.sh            # all three
#   plugins/e2e/run.sh whmcs      # one of: whmcs blesta wisecp hostbill clientexec
#                                 #   wordpress odoo fossbilling paymenter
#                                 #   prestashop opencart magento dolibarr erpnext bridge
#
# It starts a throwaway Postgres, seeds it, runs cmd/api on it, creates a
# reseller with an API key, and then drives each connector for real.
#
# Inside the real system (Docker):
#   wordpress    the plugin in WordPress, with WooCommerce and with Easy
#                Digital Downloads
#   odoo         the addon installed in Odoo 17
#   fossbilling  the service module in FOSSBilling
#   paymenter    the server extension in Paymenter
# Through a stand-in for the system, which needs a licence (the real module
# and API client run; only the host system's own classes are faked) — these
# need php on this machine:
#   whmcs, blesta, wisecp
# From the command line, as HostBill's Script Provisioning would call it:
#   hostbill     (needs php and python3)
# Core only (the API client and the provisioning rules of the connector run
# against the panel; the part that plugs into the platform is not started):
#   clientexec, prestashop, opencart (plus its database layer on MariaDB),
#   magento, dolibarr, erpnext (python3 with a virtualenv), and the SureCart
#   part of the WordPress plugin
# The webhook bridge runs for real (PHP's built-in server) and receives signed
# sample webhooks of Shopify, Upmind, Invoice Ninja and the generic format:
#   bridge
# Everything it starts is removed at the end. Needs docker, go, curl and openssl.
set -euo pipefail
cd "$(dirname "$0")/../.."

WHAT="${1:-all}"
DB_PORT="${E2E_DB_PORT:-55440}"
API_PORT="${E2E_API_PORT:-18092}"
NET=xc-e2e
DSN="postgres://postgres:verify@127.0.0.1:${DB_PORT}/verify?sslmode=disable"
API_PID=""
FAILED=0

cleanup() {
  [ -n "$API_PID" ] && kill "$API_PID" 2>/dev/null || true
  docker rm -f xc-e2e-db xc-e2e-mysql xc-e2e-wp xc-e2e-odoo-db xc-e2e-odoo-web >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  rm -f "${API_BIN:-}" "${API_LOG:-}" "${KEY_FILE:-}"
  [ -n "${VENV_DIR:-}" ] && rm -rf "$VENV_DIR" || true
}
trap cleanup EXIT

say() { printf '\n### %s\n' "$*"; }
wants() { [ "$WHAT" = all ] || [ "$WHAT" = "$1" ]; }

say "panel: throwaway database, seed, API on :$API_PORT"
[ -d dist/html ] || { echo "dist/ is missing: run 'pnpm run build' first (cmd/api embeds it)"; exit 1; }
docker network create "$NET" >/dev/null 2>&1 || true
docker rm -f xc-e2e-db >/dev/null 2>&1 || true
docker run -d --name xc-e2e-db -e POSTGRES_PASSWORD=verify -e POSTGRES_DB=verify -p "127.0.0.1:${DB_PORT}:5432" postgres:18 >/dev/null
for _ in $(seq 1 40); do docker exec xc-e2e-db pg_isready -U postgres >/dev/null 2>&1 && break; sleep 1; done
sleep 2
# SEED_DEMO=1: the packages the connectors sell come with the sample catalogue (a plain seed creates none).
SEED_DEMO=1 DATABASE_URL="$DSN" go run ./cmd/seed >/dev/null 2>&1
API_KEY="$(DATABASE_URL="$DSN" go run ./plugins/e2e/setup | tail -1)"
API_BIN="$(mktemp -t xc-e2e-api.XXXXXX)"
API_LOG="$(mktemp -t xc-e2e-api-log.XXXXXX)"
go build -o "$API_BIN" ./cmd/api
# The API refuses secrets shorter than 32 bytes: fresh random ones per run.
DATABASE_URL="$DSN" API_PORT="0.0.0.0:${API_PORT}" JWT_SECRET="$(openssl rand -hex 32)" XTREAM_SECRET="$(openssl rand -hex 32)" \
  "$API_BIN" >"$API_LOG" 2>&1 &
API_PID=$!
for _ in $(seq 1 40); do curl -fsS "http://127.0.0.1:${API_PORT}/healthz" >/dev/null 2>&1 && break; sleep 1; done
PROBE="$(curl -fsS -H "X-API-Key: $API_KEY" "http://127.0.0.1:${API_PORT}/reseller/v1?action=user_info" || true)"
grep -q STATUS_SUCCESS <<<"$PROBE" \
  || { echo "the panel API does not answer with the test key; its log ends with:"; tail -5 "$API_LOG"; exit 1; }
# Containers reach the API on the host.
API_URL="http://host.docker.internal:${API_PORT}"
HOSTGW=(--add-host=host.docker.internal:host-gateway)

if wants whmcs; then
  say "WHMCS module (stand-in for WHMCS, real panel)"
  MODULE="$PWD/plugins/whmcs/modules/servers/xtreampro/xtreampro.php" API_PORT_NUM="$API_PORT" API_KEY="$API_KEY" \
    php plugins/e2e/whmcs-harness.php || FAILED=1
fi

if wants blesta; then
  say "Blesta module (stand-in for Blesta, real panel)"
  MODULE_DIR="$PWD/plugins/blesta/components/modules/xtreampro" API_PORT_NUM="$API_PORT" API_KEY="$API_KEY" \
    php plugins/e2e/blesta-harness.php || FAILED=1
fi

if wants wisecp; then
  say "WISECP module (stand-in for WISECP, real panel)"
  MODULE_DIR="$PWD/plugins/wisecp/coremio/modules/Product/XtreamPro" API_PORT_NUM="$API_PORT" API_KEY="$API_KEY" \
    php plugins/e2e/wisecp-harness.php || FAILED=1
fi

if wants hostbill; then
  say "HostBill provisioning script (command line, real panel)"
  # The scenario reads the key from a file so it never sits on a command line.
  KEY_FILE="$(mktemp -t xc-e2e-key.XXXXXX)"
  printf '%s' "$API_KEY" > "$KEY_FILE"
  XC_API_URL="http://127.0.0.1:${API_PORT}" XC_KEY_FILE="$KEY_FILE" plugins/e2e/hostbill-scenario.sh || FAILED=1
fi

# The connectors of the code-first batch: their core against the panel.
core_harness() { # name, label, command...
  local name="$1" label="$2"; shift 2
  if wants "$name"; then
    say "$label"
    env API_PORT_NUM="$API_PORT" API_KEY="$API_KEY" "$@" || FAILED=1
  fi
}
core_harness clientexec "ClientExec plugin (stand-in for ClientExec, real panel)" \
  env PLUGIN_DIR="$PWD/plugins/clientexec/plugins/server/xtreampro" php plugins/e2e/clientexec-harness.php
core_harness prestashop "PrestaShop module (core, real panel)" \
  env MODULE_DIR="$PWD/plugins/prestashop/xtreampro" php plugins/e2e/prestashop-harness.php
core_harness magento "Magento 2 module (core, real panel)" php plugins/e2e/magento-harness.php
core_harness magento "Magento 2 module (order flow with stand-ins, real panel)" php plugins/e2e/magento-orderflow-harness.php
core_harness dolibarr "Dolibarr module (core, real panel)" php plugins/e2e/dolibarr-harness.php
core_harness opencart "OpenCart extension (core and database layer on MariaDB, real panel)" plugins/e2e/opencart-run.sh
core_harness bridge "Webhook bridge (Shopify, Upmind, Invoice Ninja, generic; real panel)" php plugins/e2e/bridge-harness.php

if wants erpnext; then
  say "ERPNext app (core, real panel)"
  VENV_DIR="$(mktemp -d -t xc-e2e-venv.XXXXXX)"
  python3 -m venv "$VENV_DIR" && "$VENV_DIR/bin/pip" install --quiet requests
  PYTHONDONTWRITEBYTECODE=1 API_PORT_NUM="$API_PORT" API_KEY="$API_KEY" "$VENV_DIR/bin/python" plugins/e2e/erpnext-harness.py || FAILED=1
fi

if wants wordpress; then
  say "WordPress plugin: SureCart integration (stand-in for SureCart, real panel)"
  KEY_FILE="${KEY_FILE:-$(mktemp -t xc-e2e-key.XXXXXX)}"
  printf '%s' "$API_KEY" > "$KEY_FILE"
  API_URL="http://127.0.0.1:${API_PORT}" API_KEY_FILE="$KEY_FILE" php plugins/e2e/wordpress-surecart-harness.php || FAILED=1

  say "WordPress + WooCommerce"
  docker rm -f xc-e2e-mysql xc-e2e-wp >/dev/null 2>&1 || true
  docker run -d --name xc-e2e-mysql --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
  docker run -d --name xc-e2e-wp --network "$NET" "${HOSTGW[@]}" \
    -e WORDPRESS_DB_HOST=xc-e2e-mysql -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root -e WORDPRESS_DB_NAME=wp \
    -v "$PWD/plugins/wordpress/xtreampro:/var/www/html/wp-content/plugins/xtreampro:ro" wordpress:latest >/dev/null
  wp() { docker exec -e API_KEY="$API_KEY" -e API_URL="$API_URL" -e RUN_ID="$RANDOM" xc-e2e-wp wp --allow-root --path=/var/www/html "$@"; }
  sleep 20
  docker exec xc-e2e-wp sh -c 'curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp'
  for _ in $(seq 1 30); do
    wp core install --url=http://localhost --title=E2E --admin_user=admin --admin_password=admin-e2e-pass \
      --admin_email=admin@example.test --skip-email >/dev/null 2>&1 && break
    sleep 3
  done
  wp plugin install woocommerce --activate >/dev/null
  wp plugin activate xtreampro >/dev/null
  docker cp plugins/e2e/wordpress-scenario.php xc-e2e-wp:/tmp/scenario.php >/dev/null
  docker cp plugins/e2e/wordpress-admin.php xc-e2e-wp:/tmp/admin.php >/dev/null
  OUT="$(wp eval-file /tmp/scenario.php 2>&1 | grep -v sendmail || true)"; echo "$OUT"
  grep -q '^ALL OK' <<<"$OUT" || FAILED=1
  OUT="$(wp eval-file /tmp/admin.php 2>&1 | grep -v sendmail || true)"; echo "$OUT"
  if grep -q 'FAIL\|Fatal' <<<"$OUT"; then FAILED=1; fi

  say "WordPress + Easy Digital Downloads (a fresh site, without WooCommerce)"
  docker exec xc-e2e-mysql mariadb -uroot -proot -e 'DROP DATABASE wp; CREATE DATABASE wp' >/dev/null
  wp core install --url=http://localhost --title=E2E --admin_user=admin --admin_password=admin-e2e-pass \
    --admin_email=admin@example.test --skip-email >/dev/null
  wp plugin install easy-digital-downloads --activate >/dev/null
  wp plugin activate xtreampro >/dev/null
  docker cp plugins/e2e/wordpress-edd-scenario.php xc-e2e-wp:/tmp/edd-scenario.php >/dev/null
  OUT="$(wp eval-file /tmp/edd-scenario.php 2>&1 | grep -v sendmail || true)"; echo "$OUT"
  grep -q '^ALL OK' <<<"$OUT" || FAILED=1
fi

if wants odoo; then
  say "Odoo 17"
  docker rm -f xc-e2e-odoo-db >/dev/null 2>&1 || true
  docker run -d --name xc-e2e-odoo-db --network "$NET" -e POSTGRES_USER=odoo -e POSTGRES_PASSWORD=odoo -e POSTGRES_DB=postgres postgres:16 >/dev/null
  sleep 8
  odoo() { docker run --rm -i --network "$NET" "${HOSTGW[@]}" -v "$PWD/plugins/odoo:/mnt/extra-addons:ro" \
    -e HOST=xc-e2e-odoo-db -e USER=odoo -e PASSWORD=odoo -e API_KEY="$API_KEY" -e API_URL="$API_URL" odoo:17 odoo "$@"; }
  INSTALL="$(odoo -d e2e -i xtreampro_connector --stop-after-init --without-demo=all 2>&1 || true)"
  if grep -q 'CRITICAL\|ParseError' <<<"$INSTALL"; then
    echo "$INSTALL" | grep -A12 'ParseError\|CRITICAL' | head -40
    echo "the addon did not install"; FAILED=1
  else
    echo "  ok   the addon installs"
    OUT="$(odoo shell -d e2e --no-http < plugins/e2e/odoo-scenario.py 2>&1 | grep -v ' INFO \|DeprecationWarning\|warnings.warn' || true)"; echo "$OUT"
    grep -q '^ALL OK' <<<"$OUT" || FAILED=1

    # The webhook route itself, served by a real Odoo HTTP server (the scenario above has no HTTP).
    say "Odoo 17: webhook route over HTTP"
    ODOO_PORT="${E2E_ODOO_PORT:-18069}"
    docker run -d --name xc-e2e-odoo-web --network "$NET" "${HOSTGW[@]}" -p "127.0.0.1:${ODOO_PORT}:8069" -v "$PWD/plugins/odoo:/mnt/extra-addons:ro" \
      -e HOST=xc-e2e-odoo-db -e USER=odoo -e PASSWORD=odoo odoo:17 odoo -d e2e >/dev/null
    for _ in $(seq 1 60); do curl -fsS "http://127.0.0.1:${ODOO_PORT}/web/login" >/dev/null 2>&1 && break; sleep 2; done
    HOOK="http://127.0.0.1:${ODOO_PORT}/xtreampro/webhook"
    hook() { # label  want-status  want-text  type  timestamp  suffix-of-body  signed(1/0)  secret  [event id: a fresh one by default]
      local label="$1" want="$2" text="$3" type="$4" ts="$5" suffix="$6" signed="$7" secret="$8" evid="${9:-evt_e2e_$RANDOM$RANDOM}" body sig reply code
      body="{\"id\":\"$evid\",\"type\":\"$type\",\"created\":$(date +%s),\"data\":{\"line_id\":987654321}}"
      sig="sha256=$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$secret" | awk '{print $NF}')"
      if [ "$signed" = 1 ]; then
        reply="$(curl -sS -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-Xtream-Timestamp: $ts" -H "X-Xtream-Signature: $sig" --data "$body$suffix" "$HOOK" || true)"
      else
        reply="$(curl -sS -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-Xtream-Timestamp: $ts" --data "$body$suffix" "$HOOK" || true)"
      fi
      code="${reply##*$'\n'}"
      if [ "$code" = "$want" ] && grep -q "$text" <<<"$reply"; then echo "  ok   $label"; else echo "  FAIL $label -> $reply"; FAILED=1; fi
    }
    NOW="$(date +%s)"
    hook "a signed ping is accepted over HTTP" 200 pong ping "$NOW" "" 1 whsec_e2e_http
    hook "an event of an unknown line is acknowledged" 200 "unknown line" line.deleted "$NOW" "" 1 whsec_e2e_http
    hook "an event is acknowledged (first delivery of its id)" 200 "unknown line" line.deleted "$NOW" "" 1 whsec_e2e_http evt_e2e_dup
    hook "the same event id again is acknowledged and not applied twice" 200 duplicate line.deleted "$NOW" "" 1 whsec_e2e_http evt_e2e_dup
    hook "a tampered body is refused (401)" 401 invalid line.expired "$NOW" " " 1 whsec_e2e_http
    hook "an unsigned call is refused (401)" 401 missing line.expired "$NOW" "" 0 whsec_e2e_http
    hook "a wrong secret is refused (401)" 401 invalid line.expired "$NOW" "" 1 whsec_other
    hook "a replay outside the time window is refused (400)" 400 window line.expired "$((NOW - 3600))" "" 1 whsec_e2e_http
    GET="$(curl -sS -o /dev/null -w '%{http_code}' "$HOOK" || true)"
    if [ "$GET" = 404 ] || [ "$GET" = 405 ]; then echo "  ok   a GET is not served ($GET)"; else echo "  FAIL a GET answered $GET"; FAILED=1; fi
  fi
fi

if wants fossbilling; then
  say "FOSSBilling"
  API_KEY="$API_KEY" API_URL="$API_URL" plugins/e2e/fossbilling-run.sh || FAILED=1
fi

if wants paymenter; then
  say "Paymenter"
  API_KEY="$API_KEY" API_URL="$API_URL" plugins/e2e/paymenter-run.sh || FAILED=1
fi

say "$([ "$FAILED" = 0 ] && echo 'ALL CONNECTORS OK' || echo 'FAILURES — see above')"
exit "$FAILED"
