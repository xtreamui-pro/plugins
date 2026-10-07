#!/usr/bin/env bash
# End-to-end run of plugins/hostbill/xtreampro/xtreampro-provision.php from the
# command line against a running panel API (the way HostBill's Script
# Provisioning module runs it). Not shipped.
#
#   XC_API_URL=http://127.0.0.1:18095 XC_KEY_FILE=/path/to/file-with-the-reseller-key \
#     plugins/e2e/hostbill-scenario.sh
#
# Needs php, python3 and curl. The reseller needs credits and the right to create
# sub-resellers. Everything is created with a random suffix; lines and accounts
# are terminated at the end (sub-reseller accounts stay disabled: the panel has
# no delete for them).
set -uo pipefail
cd "$(dirname "$0")/../.."

API_URL="${XC_API_URL:-http://127.0.0.1:18095}"
KEY_FILE="${XC_KEY_FILE:?set XC_KEY_FILE to a file holding the reseller API key}"
SCRIPT="$PWD/plugins/hostbill/xtreampro/xtreampro-provision.php"
KEY="$(tr -d '\n' < "$KEY_FILE")"
[ -n "$KEY" ] || { echo "the key file is empty"; exit 2; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
chmod 700 "$WORK"
LOG="$WORK/all-output.log"      # everything the script printed (stdout and stderr)
: > "$LOG"
( umask 077; printf 'X-API-Key: %s\n' "$KEY" > "$WORK/hdr" )   # keeps the key out of curl's argv

export XTREAMPRO_API_URL="$API_URL" XTREAMPRO_API_KEY="$KEY" XTREAMPRO_DATA_DIR="$WORK/data"
RUN="$RANDOM$RANDOM"
SVC=$(( 100000 + RANDOM ))      # numeric "HostBill account ids"
FAIL=0
LAST=""; RC=0

ok()   { printf '  ok   %s\n' "$1"; }
bad()  { printf '  FAIL %s\n' "$1"; FAIL=$((FAIL + 1)); }
check() { if [ "$2" = "1" ]; then ok "$1"; else bad "$1 -> ${3:-}"; fi; }

# json <path>: read a dotted path from $LAST ("" when missing); lists/dicts are printed as JSON
json() { python3 - "$1" "$LAST" <<'PY'
import json, sys
try:
    v = json.loads(sys.argv[2])
    for p in sys.argv[1].split('.'):
        v = v[p] if isinstance(v, dict) else v[int(p)]
except Exception:
    v = ""
print(v if isinstance(v, (str, int, float)) else json.dumps(v))
PY
}

# run the script; capture stdout in LAST, exit code in RC, stderr into the log too
hb() {
  local err; err="$(mktemp -p "$WORK")"
  LAST="$(php "$SCRIPT" "$@" 2>"$err")"; RC=$?
  { printf '$ hostbill %s\n%s\n' "${1:-}" "$LAST"; cat "$err"; } >> "$LOG"
  STDERR="$(cat "$err")"
}

# panel read through the API (not through the script)
api() { curl -fsS -m 20 -H "@$WORK/hdr" "$API_URL/reseller/v1?$1"; }
apij() { LAST="$(api "$1")"; }      # then use json data.field

# Two official packages that sell as a plain line, and one that is for boxes only (`sells` without "line").
read -r ONE TWO BOX < <(api 'action=packages' | python3 -c 'import json,sys
d=json.load(sys.stdin)["data"]
line=[p["id"] for p in d if p.get("is_official") and "line" in p.get("sells", ["line"])]
box=[p["id"] for p in d if "line" not in p.get("sells", ["line"])]
print(line[0], line[1], box[0])') || { echo "cannot read packages from the panel"; exit 2; }
[ -n "${BOX:-}" ] || { echo "the panel has no box-only package (run the seed with SEED_DEMO=1)"; exit 2; }

echo "== configuration"
printf '<?php return array("api_url"=>"%s","api_key"=>"%s");\n' "$API_URL" "$KEY" > "$WORK/config.php"
chmod 644 "$WORK/config.php"
XTREAMPRO_API_URL= XTREAMPRO_API_KEY= XTREAMPRO_CONFIG="$WORK/config.php" hb info --service=1 --username=nobody
check "a config.php readable by others is refused" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"readable by other"* ]] && echo 1)" "$LAST"
chmod 600 "$WORK/config.php"
XTREAMPRO_API_URL= XTREAMPRO_API_KEY= XTREAMPRO_CONFIG="$WORK/config.php" hb info --service=1 --username=nobody
check "a 0600 config.php is read (key from file; the service is unknown)" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"not found in the panel"* ]] && echo 1)" "$LAST"
XTREAMPRO_API_KEY="xk_wrong" hb info --service=1 --username=nobody
check "a wrong key fails readably" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"API key"* ]] && echo 1)" "$LAST"
XTREAMPRO_API_URL="" XTREAMPRO_API_KEY="" XTREAMPRO_CONFIG="$WORK/none.php" hb info --service=1
check "no api_url / api_key is a readable error" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"not configured"* ]] && echo 1)" "$LAST"
hb create --service=abc
check "a non-numeric service id is refused" "$([ "$RC" = 1 ] && [ "$(json ok)" = "False" ] && echo 1)" "$LAST"
hb frobnicate --service=1
check "an unknown action is refused" "$([ "$RC" = 1 ] && echo 1)" "$LAST"

echo "== IPTV line"
USER1="hb${RUN}a"; PASS1="Passw0rd-$RUN"
hb info --service=$SVC --username="$USER1"
check "info of an unknown service fails with exit 1" "$([ "$RC" = 1 ] && [ "$(json ok)" = "False" ] && echo 1)" "$LAST"
hb create --service=$SVC --package="$ONE" --username="$USER1" --password="$PASS1"
check "create (exit 0, ok)" "$([ "$RC" = 0 ] && [ "$(json ok)" = "True" ] && echo 1)" "$LAST"
LINE1="$(json line_id)"; CUSER="$(json username)"; CPASS="$(json password)"
check "create returns line id, username and password" "$([ -n "$LINE1" ] && [ -n "$CUSER" ] && [ -n "$CPASS" ] && echo 1)" "$LAST"
check "create returns the play links of the panel" "$([[ "$(json links.m3u)" == *get.php* ]] && [ -n "$(json links.player_api)" ] && [ -n "$(json links.web_player)" ] && echo 1)" "$LAST"
check "stdout is exactly one line" "$([ "$(printf '%s\n' "$LAST" | wc -l | tr -d ' ')" = 1 ] && echo 1)"
check "nothing on stderr (no PHP notices)" "$([ -z "$STDERR" ] && echo 1)" "$STDERR"
apij "action=get_line&id=$LINE1"
check "the panel has the line, active, with the username returned" "$([ "$(json data.status)" = active ] && [ "$(json data.username)" = "$CUSER" ] && echo 1)" "$LAST"
EXP0="$(json data.exp_date)"
hb create --service=$SVC --package="$ONE" --username="$USER1" --password="$PASS1"
check "create repeated replays the same line" "$([ "$RC" = 0 ] && [ "$(json line_id)" = "$LINE1" ] && echo 1)" "$LAST"
hb info --service=$SVC --username="$CUSER"
check "info" "$([ "$RC" = 0 ] && [ "$(json line_id)" = "$LINE1" ] && [ "$(json password)" = "$CPASS" ] && echo 1)" "$LAST"
hb info --service=$SVC --no-secrets
check "info --no-secrets has no password and masks the links" "$([ -z "$(json password)" ] && [[ "$LAST" != *"$CPASS"* ]] && [[ "$(json links.m3u)" == *'password=********'* ]] && echo 1)" "$LAST"
XTREAMPRO_SERVICE=$SVC XTREAMPRO_USERNAME="$CUSER" hb info
check "values from XTREAMPRO_* environment variables work" "$([ "$RC" = 0 ] && [ "$(json line_id)" = "$LINE1" ] && echo 1)" "$LAST"
hb suspend --service=$SVC
check "suspend" "$([ "$RC" = 0 ] && [ "$(json ok)" = True ] && echo 1)" "$LAST"
apij "action=get_line&id=$LINE1"; check "  panel: line disabled" "$([ "$(json data.status)" = disabled ] && echo 1)" "$LAST"
hb unsuspend --service=$SVC
check "unsuspend" "$([ "$RC" = 0 ] && echo 1)" "$LAST"
apij "action=get_line&id=$LINE1"; check "  panel: line active again" "$([ "$(json data.status)" = active ] && echo 1)" "$LAST"
hb renew --service=$SVC --due=2031-01-15
check "renew" "$([ "$RC" = 0 ] && [ "$(json ok)" = True ] && echo 1)" "$LAST"
apij "action=get_line&id=$LINE1"; EXP1="$(json data.exp_date)"
check "  panel: expiry moved by the renewal" "$([ "$EXP1" -gt "$EXP0" ] && echo 1)" "$EXP0 -> $EXP1"
hb renew --service=$SVC --due=2031-01-15
check "renew repeated for the same due date succeeds" "$([ "$RC" = 0 ] && echo 1)" "$LAST"
apij "action=get_line&id=$LINE1"
check "  panel: ... and does not extend a second time" "$([ "$(json data.exp_date)" = "$EXP1" ] && echo 1)" "$EXP1 -> $(json data.exp_date)"
hb renew --service=$SVC --due=2032-01-15
apij "action=get_line&id=$LINE1"
check "  panel: a renewal for the next due date does extend" "$([ "$(json data.exp_date)" -gt "$EXP1" ] && echo 1)"
echo "-- state file lost: the line is found again by exact username"
rm -rf "$WORK/data"
hb suspend --service=$SVC --username="$CUSER"
check "suspend after losing state (resolved by username)" "$([ "$RC" = 0 ] && echo 1)" "$LAST"
apij "action=get_line&id=$LINE1"; check "  panel: line disabled" "$([ "$(json data.status)" = disabled ] && echo 1)" "$LAST"
hb unsuspend --service=$SVC --username="$CUSER"
hb terminate --service=$SVC --username="$CUSER"
check "terminate by default only disables the line (deleting is final and needs --delete-on-terminate)" "$([ "$RC" = 0 ] && [ "$(json ok)" = True ] && echo 1)" "$LAST"
apij "action=get_line&id=$LINE1"
check "  panel: the line is disabled, not deleted" "$([ "$(json data.status)" = disabled ] && echo 1)" "$LAST"
hb create --service=$SVC --package="$ONE" --username="$USER1" --password="$PASS1"
hb terminate --service=$SVC --username="$CUSER" --delete-on-terminate
check "terminate --delete-on-terminate" "$([ "$RC" = 0 ] && [ "$(json ok)" = True ] && echo 1)" "$LAST"
LAST="$(curl -s -m 20 -H "@$WORK/hdr" "$API_URL/reseller/v1?action=get_line&id=$LINE1")"
check "  panel: the line is deleted" "$([ "$(json error)" = RESOURCE_NOT_FOUND ] && echo 1)" "$LAST"
hb terminate --service=$SVC --username="$CUSER" --delete-on-terminate
check "terminate again is still success" "$([ "$RC" = 0 ] && echo 1)" "$LAST"
hb create --service=$SVC --package="$ONE" --username="hb${RUN}b" --password="$PASS1"
LINE2="$(json line_id)"
check "create after terminate sells a NEW line" "$([ "$RC" = 0 ] && [ -n "$LINE2" ] && [ "$LINE2" != "$LINE1" ] && echo 1)" "$LAST"
echo "-- 1.1.0: connector name, change package, box-only package, spent request id"
apij "action=api_logs&limit=50"
check "the panel call log names the connector" "$([[ "$LAST" == *'"connector":"hostbill/1.1.0"'* ]] && echo 1)" "$LAST"
apij "action=user_info"; CRED0="$(json data.credits)"
hb change-package --service=$SVC --username="hb${RUN}b" --package="$BOX"
check "a change to a box-only package is refused before anything is sold, readably" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"boxes only"* ]] && echo 1)" "$LAST"
apij "action=get_line&id=$LINE2"
check "  panel: the line keeps its package" "$([ "$(json data.package_id)" = "$ONE" ] && echo 1)" "$LAST"
hb change-package --service=$SVC --username="hb${RUN}b" --package="$TWO"
check "change-package sells the new package on the line" "$([ "$RC" = 0 ] && [ "$(json ok)" = True ] && [[ "$(json panel)" == "On the panel "*"credits." ]] && echo 1)" "$LAST"
apij "action=get_line&id=$LINE2"
check "  panel: the line is on the new package" "$([ "$(json data.package_id)" = "$TWO" ] && echo 1)" "$LAST"
apij "action=user_info"; CRED1="$(json data.credits)"
check "  and it was charged" "$([ "$CRED1" -lt "$CRED0" ] && echo 1)" "$CRED0 -> $CRED1"
hb change-package --service=$SVC --username="hb${RUN}b" --package="$TWO"
apij "action=user_info"
check "repeating the change is not charged again" "$([ "$RC" = 0 ] && [ "$(json data.credits)" = "$CRED1" ] && echo 1)" "$LAST"
hb create --service=$((SVC + 2)) --package="$BOX" --username="hb${RUN}box" --password="$PASS1"
check "create with a box-only package is refused readably and sells nothing" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"boxes only"* ]] && echo 1)" "$LAST"
hb create --service=$((SVC + 3)) --package="$ONE" --username="hb${RUN}sp" --password="$PASS1"
SPLINE="$(json line_id)"
curl -s -m 20 -H "@$WORK/hdr" -d "action=delete_line&id=$SPLINE" "$API_URL/reseller/v1" >/dev/null
rm -rf "$WORK/data"
hb create --service=$((SVC + 3)) --package="$ONE" --username="hb${RUN}sp" --password="$PASS1"
check "selling again under the request id of a line deleted on the panel is a readable message (REQUEST_ID_SPENT)" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"already made"* ]] && echo 1)" "$LAST"
hb terminate --service=$SVC --username="hb${RUN}b" --keep-on-terminate
apij "action=get_line&id=$LINE2"
check "terminate --keep-on-terminate only disables the line (still accepted)" "$([ "$RC" = 0 ] && [ "$(json data.status)" = disabled ] && echo 1)" "$LAST"
hb create --service=$SVC --package=99999
check "an unknown package is a readable error" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"package"* ]] && echo 1)" "$LAST"
hb create --service=$SVC
check "create without a package is a readable error" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"--package"* ]] && echo 1)" "$LAST"
hb create --service=$((SVC + 1)) --package="$ONE" --trial
check "--trial on a package without a trial is refused readably (the test panel has none)" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"package"* ]] && echo 1)" "$LAST"
# lines left behind by the keep-on-terminate step: delete them for real
curl -s -m 20 -H "@$WORK/hdr" -d "action=delete_line&id=$LINE2" "$API_URL/reseller/v1" >/dev/null

echo "== sub-reseller account"
SSVC=$(( SVC + 10 ))
SUSER="hbr${RUN}"; SPASS="Sup3rPass-$RUN"; SMAIL="hb-$RUN@example.test"
hb create --service=$SSVC --type=reseller --credits=50 --renew-credits=20 --username="$SUSER" --password="$SPASS" --email="$SMAIL" --name="Ann Berg"
check "create (exit 0, ok)" "$([ "$RC" = 0 ] && [ "$(json ok)" = True ] && [ "$(json type)" = reseller ] && echo 1)" "$LAST"
UID1="$(json user_id)"
check "create returns account id, username, password and credits 50" "$([ -n "$UID1" ] && [ "$(json username)" = "$SUSER" ] && [ "$(json password)" = "$SPASS" ] && [ "$(json credits)" = 50 ] && echo 1)" "$LAST"
check "nothing on stderr" "$([ -z "$STDERR" ] && echo 1)" "$STDERR"
apij "action=get_users&search=$SUSER"
check "panel: account exists with 50 credits, active, right email" "$([ "$(json data.0.credits)" = 50 ] && [ "$(json data.0.status)" = active ] && [ "$(json data.0.id)" = "$UID1" ] && [ "$(json data.0.email)" = "$SMAIL" ] && echo 1)" "$LAST"
hb create --service=$SSVC --type=reseller --credits=50 --renew-credits=20 --username="$SUSER" --password="$SPASS" --email="$SMAIL"
apij "action=get_users&search=$SUSER"
check "create repeated neither fails nor credits twice" "$([ "$RC" = 0 ] && [ "$(json data.0.credits)" = 50 ] && [ "$(json user_id)" = "" ] && echo 1)" "$LAST"
hb info --service=$SSVC --type=reseller --username="$SUSER"
check "info (credits, no password: the panel keeps only a hash)" "$([ "$RC" = 0 ] && [ "$(json credits)" = 50 ] && [ -z "$(json password)" ] && echo 1)" "$LAST"
hb suspend --service=$SSVC --type=reseller
apij "action=get_users&search=$SUSER"; check "suspend -> panel: disabled" "$([ "$(json data.0.status)" = disabled ] && echo 1)" "$LAST"
hb unsuspend --service=$SSVC --type=reseller
apij "action=get_users&search=$SUSER"; check "unsuspend -> panel: active" "$([ "$(json data.0.status)" = active ] && echo 1)" "$LAST"
hb renew --service=$SSVC --type=reseller --renew-credits=20 --due=2031-01-15
apij "action=get_users&search=$SUSER"; check "renew tops up: 70 credits" "$([ "$(json data.0.credits)" = 70 ] && echo 1)" "$LAST"
hb renew --service=$SSVC --type=reseller --renew-credits=20 --due=2031-01-15
apij "action=get_users&search=$SUSER"; check "renew repeated for the same due date does not credit twice" "$([ "$RC" = 0 ] && [ "$(json data.0.credits)" = 70 ] && echo 1)" "$LAST"
hb renew --service=$SSVC --type=reseller --renew-credits=20 --due=2032-01-15
apij "action=get_users&search=$SUSER"; check "renew for the next due date credits again: 90" "$([ "$(json data.0.credits)" = 90 ] && echo 1)" "$LAST"
hb renew --service=$SSVC --type=reseller --renew-credits=0 --due=2033-01-15
apij "action=get_users&search=$SUSER"; check "renew with 0 credits does nothing" "$([ "$RC" = 0 ] && [ "$(json data.0.credits)" = 90 ] && echo 1)" "$LAST"
rm -rf "$WORK/data"
hb suspend --service=$SSVC --type=reseller --username="$SUSER"
apij "action=get_users&search=$SUSER"; check "state lost: the account is found again by exact username" "$([ "$RC" = 0 ] && [ "$(json data.0.status)" = disabled ] && echo 1)" "$LAST"
hb terminate --service=$SSVC --type=reseller --username="$SUSER"
apij "action=get_users&search=$SUSER"
check "terminate disables the account (the panel cannot delete it)" "$([ "$RC" = 0 ] && [ "$(json data.0.status)" = disabled ] && echo 1)" "$LAST"
hb terminate --service=$SSVC --type=reseller --username="$SUSER"
check "terminate again is still success" "$([ "$RC" = 0 ] && echo 1)" "$LAST"
hb create --service=$SSVC --type=reseller --credits=50 --email="hb-$RUN-b@example.test" --name="Ann Berg"
UID2="$(json user_id)"
check "create after terminate (generated username and password) makes a NEW account" "$([ "$RC" = 0 ] && [ -n "$UID2" ] && [ "$UID2" != "$UID1" ] && [ "${#SUSER}" -gt 0 ] && [ "$(json username)" != "$SUSER" ] && [ "${#PASS1}" -gt 0 ] && [ "$(json password | wc -c)" -ge 9 ] && echo 1)" "$LAST"
hb create --service=$((SSVC + 1)) --type=reseller --username="a b" --email="hb-$RUN-c@example.test"
check "an invalid sub-reseller username is a readable error" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"not valid"* ]] && echo 1)" "$LAST"
hb create --service=$((SSVC + 2)) --type=reseller --username="hbx${RUN}" --password="Sup3rPass-$RUN" --email="$SMAIL"
check "an email that is already taken is a readable CONFLICT" "$([ "$RC" = 1 ] && [[ "$(json error)" == *"already taken"* ]] && echo 1)" "$LAST"
hb terminate --service=$SSVC --type=reseller

echo "== secrets"
check "the API key appears in no output of the script" "$(grep -qF -- "$KEY" "$LOG" && echo 0 || echo 1)"
check "the key was not on the command line" "$(grep -F 'hostbill' "$LOG" | grep -qF -- "$KEY" && echo 0 || echo 1)"
check "config.php and the state file are not readable by others" "$([ -z "$(find "$WORK/config.php" "$WORK/data" -perm -o=r 2>/dev/null)" ] && echo 1)"
php -l "$SCRIPT" >/dev/null && ok "php -l is clean" || bad "php -l"

echo
if [ "$FAIL" = 0 ]; then echo "ALL OK"; else echo "$FAIL FAILED"; fi
exit "$([ "$FAIL" = 0 ] && echo 0 || echo 1)"
