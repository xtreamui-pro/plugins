"""Run the real platform independent core of the ERPNext connector against a panel API.

ERPNext / Frappe cannot be started here, so this loads the two files the app
keeps free of Frappe (panel/client.py, panel/provisioner.py) from the app folder
itself (not copies) and drives them the way the Frappe layer does: for an IPTV
line and for a sub-reseller account it creates, replays the create, suspends,
unsuspends, renews (twice with the same request id: no double charge),
terminates, creates again, and tries a wrong key and an unknown package. The
panel is checked through the API after each step, and no API key or password may
appear in anything the core logs or says. The Frappe layer itself (doctypes,
hooks, scheduler) is not run here; it is only checked for syntax and valid JSON.

    API_PORT_NUM=18095 API_KEY=... python3 plugins/e2e/erpnext-harness.py
    (API_URL overrides http://127.0.0.1:API_PORT_NUM, API_KEY_FILE reads the key from a file;
     needs `requests`, which Frappe ships)
"""

import ast
import http.server
import json
import logging
import os
import pathlib
import random
import re
import sys
import threading

import requests

ROOT = pathlib.Path(__file__).resolve().parent.parent / "erpnext" / "xtreampro_connector"
sys.path.insert(0, str(ROOT))

from xtreampro_connector.panel import provisioner as prov_mod  # noqa: E402
from xtreampro_connector import __version__ as CONNECTOR_VERSION  # noqa: E402
from xtreampro_connector.panel.client import PanelClient, XtreamProError, mask_secrets, sells_line  # noqa: E402
from xtreampro_connector.panel.provisioner import Provisioner  # noqa: E402

API_BASE = os.environ.get("API_URL") or "http://127.0.0.1:%s" % os.environ.get("API_PORT_NUM", "18095")
if os.environ.get("API_KEY_FILE"):
    API_KEY = pathlib.Path(os.environ["API_KEY_FILE"]).read_text().strip()
else:
    API_KEY = os.environ.get("API_KEY", "").strip()
if not API_KEY:
    sys.exit("set API_KEY or API_KEY_FILE")

RUN = "%d%d" % (os.getpid() % 1000, random.randint(100, 999))
INSTALL = "t%s" % RUN[:7]

fail = 0


def check(label, ok, detail=""):
    global fail
    if isinstance(detail, (dict, list)):
        detail = json.dumps(detail, default=str)[:400]
    print(("  ok   " if ok else "  FAIL ") + label + ("" if ok else " -> %s" % (detail,)))
    if not ok:
        fail += 1


# ---- everything the core logs is captured and searched for secrets --------------------
class Capture(logging.Handler):
    def __init__(self):
        super().__init__(logging.DEBUG)
        self.lines = []

    def emit(self, record):
        self.lines.append(record.getMessage())


capture = Capture()
logging.getLogger("xtreampro_connector").addHandler(capture)
logging.getLogger("xtreampro_connector").setLevel(logging.DEBUG)
logging.getLogger("xtreampro_connector").propagate = False

secrets_seen = [API_KEY]
spoken = []          # every error text the core produced


# ---- independent view on the panel (not the code under test) ---------------------------
def panel(action, params=None, post=False):
    params = dict(params or {}, action=action)
    url = API_BASE + "/reseller/v1"
    h = {"X-API-Key": API_KEY}
    r = requests.post(url, data=params, headers=h, timeout=20) if post else requests.get(url, params=params, headers=h, timeout=20)
    return r.json()


def balance():
    return int(panel("user_info")["data"]["credits"])


def line_of(line_id):
    r = panel("get_line", {"id": line_id})
    return r["data"] if r.get("status") == "STATUS_SUCCESS" else None


def sub_of(username):
    for u in panel("get_users", {"search": username, "start": 0, "limit": 50}).get("data") or []:
        if u.get("username") == username:
            return u
    return None


def fails_with(label, code, fn):
    try:
        fn()
    except XtreamProError as e:
        spoken.append(e.message)
        check(label, e.code == code and bool(e.message), "%s %s" % (e.code, e.message))
        return e
    except Exception as e:  # noqa: BLE001
        check(label, False, "%s: %s" % (type(e).__name__, e))
        return None
    check(label, False, "no error raised")
    return None


good = Provisioner(PanelClient(API_BASE, API_KEY), INSTALL)
cleanup_lines, cleanup_users = [], []

# ===========================================================================
print("== pure helpers")
ids = {good.rid_line("ACC-SINV-2026-00001", "abc123", 1, 0), good.rid_line("ACC-SINV-2026-00001", "abc123", 2, 0),
       good.rid_line("ACC-SINV-2026-00001", "abc123", 1, 1), good.rid_line("ACC-SINV-2026-00002", "abc123", 1, 0),
       good.rid_renew_invoice("ACC-SINV-2026-00001", "abc123"), good.rid_reseller("Ann Berg", 0), good.rid_reseller("Ann_Berg", 0),
       good.rid_credit("ACC-SINV-2026-00001", "abc123", 0), good.rid_credit_back("ACC-SINV-2026-00001", "abc123", 0)}
check("request ids differ per unit, generation, invoice and kind", len(ids) == 9, sorted(ids))
check("a request id is at most 64 characters, even for a long invoice name",
      all(len(i) <= 64 for i in ids) and len(good.rid_line("X" * 140 + " é", "r", 1, 0)) <= 64)
check("two installations never share a request id",
      Provisioner(None, "aaa").rid_line("INV-1", "r", 1, 0) != Provisioner(None, "bbb").rid_line("INV-1", "r", 1, 0))
check("a generated username fits the panel rule", all(re.match(r"^[A-Za-z0-9._-]{3,32}$", prov_mod.make_username(n, e, "9"))
      for n, e in (("Ann Berg", None), ("Zoë Ünal", "z@x.test"), ("", ""), ("A" * 80, None), ("日本語", "ab@c.test"))))
check("a generated password has 14 characters and differs each time", len(prov_mod.make_password()) == 14 and prov_mod.make_password() != prov_mod.make_password())
check("tag_email", prov_mod.tag_email("a@x.test", 0) == "a@x.test" and prov_mod.tag_email("a@x.test", 1) == "a+g1@x.test"
      and prov_mod.tag_email("a+g1@x.test", 2) == "a+g2@x.test", prov_mod.tag_email("a@x.test", 1))
check("missing_units", prov_mod.missing_units([1, 3], 4) == [2, 4] and prov_mod.missing_units([], 0) == [] and prov_mod.missing_units([1, 2], 2) == [])
check("credits_for", prov_mod.credits_for(30, 2) == 60 and prov_mod.credits_for(-5, 2) == 0 and prov_mod.credits_for("x", 2) == 0)
check("mask_secrets hides json passwords and password= in links",
      "s3cret" not in mask_secrets('{"password":"s3cret","u":"http://h/get.php?username=u&password=s3cret&type=m3u"}'))
src = (ROOT / "xtreampro_connector" / "panel")
check("the core never imports frappe", not any(re.search(r"^\s*(import|from)\s+frappe", p.read_text(), re.M) for p in src.glob("*.py")))

# ===========================================================================
print("== connection and packages")
info = good.test_connection()
check("test connection returns the reseller and its credits", bool(info["username"]) and info["credits"] > 0, info)
pkgs = good.packages()
official = [p for p in pkgs if p["is_official"]]
check("packages are listed as plain rows", len(pkgs) >= 1 and all(k in pkgs[0] for k in ("panel_id", "package_name", "official_credits")), pkgs[:1])
PKG = official[0]["panel_id"]

# ===========================================================================
print("== 1.1.0: sells, pricing, connector name, spent request id")
raw_pk = panel("packages")["data"]
box = next((p for p in raw_pk if isinstance(p.get("sells"), list) and "line" not in p["sells"]), None)
check("the panel marks a box-only package with sells without 'line'", box is not None, raw_pk)
check("a panel that sends no sells counts as selling lines", sells_line({"id": 1}) and not sells_line({"sells": ["mag"]}))
check("the package rows leave box-only packages out and keep the others", box is not None and box["id"] not in [p["panel_id"] for p in pkgs]
      and len(pkgs) == len([p for p in raw_pk if sells_line(p)]), [p["panel_id"] for p in pkgs])
bal_box = balance()
e = fails_with("a box-only package is refused before anything is sold", "INVALID_PACKAGE", lambda: good.create_line(box["id"], False, good.rid_line("ACC-SINV-%s-9" % RUN, "box", 1, 0)))
check("with a readable reason, and nothing was charged", e is not None and "boxes only" in e.message and balance() == bal_box, e and e.message)
names = [row.get("connector") for row in (panel("api_logs", {"limit": 50}).get("data") or [])]
check("the panel call log names the connector", "erpnext/1.1.0" in names, sorted(set(n for n in names if n)))
sp_rid = good.rid_line("ACC-SINV-%s-8" % RUN, "spent", 1, 0)
sp_line = good.create_line(PKG, False, sp_rid)
secrets_seen.append(sp_line.get("password", ""))
panel("delete_line", {"id": sp_line["panel_line_id"]}, post=True)
e = fails_with("selling again under the request id of a line deleted on the panel", "REQUEST_ID_SPENT", lambda: good.create_line(PKG, False, sp_rid))
check("gives a readable message", e is not None and "already made" in e.message and "REQUEST_ID" not in e.message, e and e.message)
install_src = (ROOT / "xtreampro_connector" / "install.py").read_text()
check("the Item field 'Delete permanently on terminate' is off by default", re.search(r'fieldname="xp_delete_on_terminate".*?default="0"', install_src, re.S) is not None)

# ===========================================================================
print("== line")
inv = "ACC-SINV-%s-1" % RUN
rid = good.rid_line(inv, "row1", 1, 0)
before = balance()
line = good.create_line(PKG, False, rid)
lid = line["panel_line_id"]
cleanup_lines.append(lid)
secrets_seen.append(line.get("password", ""))
pl = line_of(lid)
check("create: the panel has an active line with the package", pl and pl["status"] == "active" and pl["package_id"] == PKG, pl)
check("create returns username, password, expiry and connections",
      bool(line.get("username")) and bool(line.get("password")) and line.get("expiry", 0) > 0 and line.get("max_connections", 0) >= 1, {k: v for k, v in line.items() if k != "password"})
check("the credentials returned are the ones the panel holds", line["username"] == pl["username"] and line["password"] == pl["password"])
charged = before - balance()
check("create charged the reseller once", charged > 0, charged)
again = good.create_line(PKG, False, rid)
check("replay of create returns the same line and charges nothing", again["panel_line_id"] == lid and balance() == before - charged, again)

fields = good.set_line_enabled(lid, False)
check("suspend", fields.get("status") == "disabled" and line_of(lid)["status"] == "disabled", fields)
fields = good.set_line_enabled(lid, True)
check("unsuspend", fields.get("status") == "active" and line_of(lid)["status"] == "active", fields)

e0, c0 = line_of(lid)["exp_date"], balance()
ren = good.renew_line(lid, good.rid_renew_invoice("ACC-SINV-%s-2" % RUN, "row1"))
e1, c1 = line_of(lid)["exp_date"], balance()
check("renew extends the line and is charged", e1 > e0 and c1 < c0 and ren.get("credits_charged"), (e0, e1, c0, c1, ren.get("credits_charged")))
good.renew_line(lid, good.rid_renew_invoice("ACC-SINV-%s-2" % RUN, "row1"))
check("renew repeated with the same request id does not extend or charge twice", line_of(lid)["exp_date"] == e1 and balance() == c1)
good.renew_line(lid, good.rid_renew_manual("XPL-0001-%s" % RUN, "20261004"))
check("a renewal with another request id extends again", line_of(lid)["exp_date"] > e1 and balance() < c1)
check("read_line", good.read_line(lid)["username"] == pl["username"])

res = good.terminate_line(lid, delete=True)
check("terminate deletes the line", res["deleted"] and line_of(lid) is None, res)
res = good.terminate_line(lid, delete=True)
check("terminate again counts as done", res["already_gone"] is True, res)

line2 = good.create_line(PKG, False, good.rid_line(inv, "row1", 1, 1))
cleanup_lines.append(line2["panel_line_id"])
secrets_seen.append(line2.get("password", ""))
check("create again (generation 1) sells a NEW line", line2["panel_line_id"] != lid and line_of(line2["panel_line_id"])["status"] == "active", line2["panel_line_id"])

only_disable = good.create_line(PKG, False, good.rid_line(inv, "row2", 1, 0))
cleanup_lines.append(only_disable["panel_line_id"])
secrets_seen.append(only_disable.get("password", ""))
res = good.terminate_line(only_disable["panel_line_id"], delete=False)
check("terminate without delete only disables", line_of(only_disable["panel_line_id"])["status"] == "disabled" and not res["already_gone"], res)

fails_with("unknown package", "INVALID_PACKAGE", lambda: good.create_line(999999, False, good.rid_line(inv, "bad", 1, 0)))
fails_with("no package", "INVALID_PACKAGE", lambda: good.create_line(0, False, good.rid_line(inv, "bad", 1, 0)))
fails_with("suspend of a line that does not exist", "RESOURCE_NOT_FOUND", lambda: good.set_line_enabled(987654321, False))

print("== batch refresh of lines")
results, stopped = good.refresh_lines([("a", line2["panel_line_id"]), ("gone", 987654321), ("b", only_disable["panel_line_id"])])
by = {k: (f, err) for k, f, err in results}
check("a batch refreshes what exists and reports what does not", stopped is None and by["a"][0]["status"] == "active"
      and by["b"][0]["status"] == "disabled" and by["gone"][0] is None and "does not exist" in by["gone"][1], results)
bad_client = Provisioner(PanelClient(API_BASE, "xk_wrong_key_" + RUN), INSTALL)
results, stopped = bad_client.refresh_lines([("a", line2["panel_line_id"]), ("b", only_disable["panel_line_id"])])
check("a wrong key stops the batch after the first call", stopped == "INVALID_API_KEY" and results == [], (stopped, results))

# ===========================================================================
print("== sub-reseller")
email = "erpnext-%s-sub@example.test" % RUN
cust = "Ann Berg %s" % RUN
username = prov_mod.make_username(cust, email, RUN)
password = prov_mod.make_password()
secrets_seen.append(password)
rrid = good.rid_reseller(cust, 0)
before = balance()
acct = good.create_reseller(username, password, email, cust, rrid, 0)
uid = acct["panel_user_id"]
cleanup_users.append(uid)
pu = sub_of(username)
check("create: the panel has an active account with the customer e-mail and no credits",
      pu and pu["id"] == uid and pu["email"] == email and pu["status"] == "active" and int(pu["credits"]) == 0, pu)
check("create returns id, username, status and the password to show the customer", acct["status"] == "active" and acct["username"] == username and acct["password"] == password, {k: v for k, v in acct.items() if k != "password"})
price = before - balance()
check("create charged the reseller the sub-reseller price", price > 0, price)
again = good.create_reseller(username, password, email, cust, rrid, 0)
check("replay of create returns the same account and charges nothing", again["panel_user_id"] == uid and balance() == before - price, again)

r1 = good.adjust_credits(uid, 50, "Invoice A", good.rid_credit("INV-A-%s" % RUN, "row1", 0))
check("hand over 50 credits", r1["balance"] == 50 and int(sub_of(username)["credits"]) == 50, r1)
mid = balance()
good.adjust_credits(uid, 50, "Invoice A", good.rid_credit("INV-A-%s" % RUN, "row1", 0))
check("the same credit transfer again does not give credits twice", int(sub_of(username)["credits"]) == 50 and balance() == mid)
r2 = good.adjust_credits(uid, 20, "Invoice B", good.rid_credit("INV-B-%s" % RUN, "row1", 0))
check("a later invoice tops the same account up", r2["balance"] == 70 and int(sub_of(username)["credits"]) == 70, r2)

good.set_reseller_enabled(uid, False)
check("suspend", sub_of(username)["status"] == "disabled")
good.set_reseller_enabled(uid, True)
check("unsuspend", sub_of(username)["status"] == "active")
check("read_reseller", good.read_reseller(uid, username)["credits"] == 70)

r3 = good.adjust_credits(uid, -30, "Invoice B refunded", good.rid_credit_back("INV-B-%s" % RUN, "row1", 0))
check("take 30 credits back", r3["balance"] == 40 and int(sub_of(username)["credits"]) == 40, r3)
good.adjust_credits(uid, -30, "Invoice B refunded", good.rid_credit_back("INV-B-%s" % RUN, "row1", 0))
check("the same take-back again does nothing", int(sub_of(username)["credits"]) == 40)
fails_with("taking back more than the balance", "INSUFFICIENT_CREDITS", lambda: good.adjust_credits(uid, -5000, "too much", good.rid_credit_back("INV-C-%s" % RUN, "row1", 0)))
fails_with("zero credits", "INVALID_REQUEST", lambda: good.adjust_credits(uid, 0, "", "x"))
fails_with("more credits than the reseller owns", "INSUFFICIENT_CREDITS", lambda: good.adjust_credits(uid, 999999999, "x", good.rid_credit("INV-D-%s" % RUN, "row1", 0)))

print("== batch refresh of sub-resellers")
results, stopped = good.refresh_resellers([("a", uid), ("gone", "00000000-0000-0000-0000-000000000000")])
by = {k: (f, err) for k, f, err in results}
check("a batch refresh reads the balance and flags a missing account", stopped is None and by["a"][0]["credits"] == 40 and by["gone"][0] is None, results)
results, stopped = bad_client.refresh_resellers([("a", uid)])
check("a wrong key stops the account refresh", stopped == "INVALID_API_KEY" and results == [], (stopped, results))

res = good.terminate_reseller(uid)
check("terminate disables the account (the panel cannot delete it)", sub_of(username)["status"] == "disabled" and res["already_gone"] is False, res)
res = good.terminate_reseller(uid)
check("terminate again is still success", True)

username2 = prov_mod.make_username(cust, email, RUN)
password2 = prov_mod.make_password()
secrets_seen.append(password2)
acct2 = good.create_reseller(username2, password2, email, cust, good.rid_reseller(cust, 1), 1)
cleanup_users.append(acct2["panel_user_id"])
pu2 = sub_of(username2)
check("create again (generation 1) makes a NEW account with a tagged e-mail",
      acct2["panel_user_id"] != uid and pu2 and pu2["email"] == "erpnext-%s-sub+g1@example.test" % RUN and username2 != username, pu2)
fails_with("no e-mail address", "INVALID_REQUEST", lambda: good.create_reseller("noemail_" + RUN, "Passw0rdOk1", "", "x", good.rid_reseller("noemail", 0)))
fails_with("bad username", "INVALID_REQUEST", lambda: good.create_reseller("a b", "Passw0rdOk1", email, "x", good.rid_reseller("badname", 0)))
fails_with("short password", "INVALID_REQUEST", lambda: good.create_reseller("shortpw_" + RUN, "abc", email, "x", good.rid_reseller("shortpw", 0)))
fails_with("same username, other request (conflict)", "CONFLICT", lambda: good.create_reseller(username, password, "other-%s@example.test" % RUN, "x", good.rid_reseller("clash", 0)))

# ===========================================================================
print("== wrong key and unreachable panel")
e = fails_with("wrong key, line", "INVALID_API_KEY", lambda: bad_client.create_line(PKG, False, good.rid_line(inv, "wk", 1, 0)))
fails_with("wrong key, sub-reseller", "INVALID_API_KEY", lambda: bad_client.create_reseller("wk_" + RUN, "Passw0rdOk1", email, "x", good.rid_reseller("wk", 0)))
fails_with("wrong key, test connection", "INVALID_API_KEY", lambda: bad_client.test_connection())
fails_with("no key at all", "NOT_CONFIGURED", lambda: Provisioner(PanelClient(API_BASE, ""), INSTALL).test_connection())
fails_with("not an http address", "BAD_URL", lambda: Provisioner(PanelClient("ftp://example.test", "k"), INSTALL).test_connection())
fails_with("dead port", "NETWORK", lambda: Provisioner(PanelClient("http://127.0.0.1:1", "k"), INSTALL).test_connection())
check("a pasted /reseller/v1 suffix is ignored", PanelClient(API_BASE + "/reseller/v1/", API_KEY).base == API_BASE.rstrip("/"))

hits = []


class Redirector(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        hits.append(self.path)
        if self.path.startswith("/reseller/v1"):
            self.send_response(302)
            self.send_header("Location", "/elsewhere")
            self.end_headers()
        else:
            self.send_response(200)
            self.end_headers()

    do_POST = do_GET

    def log_message(self, *a):
        pass


srv = http.server.HTTPServer(("127.0.0.1", 0), Redirector)
threading.Thread(target=srv.serve_forever, daemon=True).start()
fails_with("a redirect is refused", "REDIRECT", lambda: Provisioner(PanelClient("http://127.0.0.1:%d" % srv.server_port, "k"), INSTALL).test_connection())
srv.shutdown()
check("a redirect is never followed", not any(h.startswith("/elsewhere") for h in hits), hits)

# ===========================================================================
print("== secrets")
log_text = "\n".join(capture.lines)
check("the leak check had real secrets to look for", len({s for s in secrets_seen if s}) >= 6, len(secrets_seen))
check("the core logged its calls", len(capture.lines) >= 5, len(capture.lines))
leak = next((("API key" if s == API_KEY else "a password") for s in set(secrets_seen) if s and (s in log_text or any(s in m for m in spoken))), "")
check("neither the API key nor any password reached a log or an error text", leak == "", leak)
check("no clear password= anywhere in the log", not re.search(r"password=(?!\*)[^&\s]", log_text))
check("the client passes the key only in the header", "X-API-Key" not in log_text)

# ===========================================================================
print("== app files")
bad_py = []
for p in ROOT.rglob("*.py"):
    try:
        ast.parse(p.read_text(), str(p))
    except SyntaxError as exc:
        bad_py.append("%s: %s" % (p, exc))
check("every Python file parses", not bad_py, bad_py)
bad_json = []
doctypes = list(ROOT.rglob("doctype/*/*.json"))
for p in doctypes:
    try:
        d = json.loads(p.read_text())
        assert d["doctype"] == "DocType" and d["name"] and d["module"] == "Xtream UI Pro"
        names = [f["fieldname"] for f in d["fields"]]
        assert len(names) == len(set(names)), "duplicate fieldname"
        assert set(d["field_order"]) == set(names), "field_order differs from fields"
        assert p.parent.name == re.sub(r"[^a-z0-9]+", "_", d["name"].lower()), "folder name"
    except Exception as exc:  # noqa: BLE001
        bad_json.append("%s: %s" % (p.name, exc))
check("five doctype definitions, all valid", len(doctypes) == 5 and not bad_json, bad_json or len(doctypes))
check("modules.txt names the module", (ROOT / "xtreampro_connector" / "modules.txt").read_text().strip() == "Xtream UI Pro")
check("version 1.1.0 in the app entry files and in the API client (X-Connector)", "1.1.0" in (ROOT / "xtreampro_connector" / "__init__.py").read_text()
      and "1.1.0" in (ROOT / "xtreampro_connector" / "hooks.py").read_text() and CONNECTOR_VERSION == "1.1.0")
check("hooks wire invoices, payments and the scheduler", all(s in (ROOT / "xtreampro_connector" / "hooks.py").read_text() for s in ("on_submit", "on_cancel", "Payment Entry", "scheduler_events")))

# ---- leave the shared panel as found ------------------------------------------------------
for i in set(cleanup_lines):
    if i:
        panel("delete_line", {"id": i}, post=True)
for i in set(cleanup_users):
    if i:
        panel("disable_user", {"id": i}, post=True)

print("\n%d FAILED" % fail if fail else "\nALL OK")
sys.exit(1 if fail else 0)
