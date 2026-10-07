# End-to-end run of the addon inside a real Odoo 17 (odoo shell).
import hashlib, hmac, json, os, random, time, traceback
from unittest import mock
from odoo.addons.xtreampro_connector.models.xtreampro_api import XtreamProError
fail = 0
def check(label, ok, detail=""):
    global fail
    print(("  ok   " if ok else "  FAIL ") + label + ("" if ok else " -> %s" % (detail,)))
    if not ok:
        fail += 1
def attempt(label, fn):
    try:
        return fn()
    except Exception as e:
        check(label, False, "%s: %s" % (type(e).__name__, e))
        traceback.print_exc()
        env.cr.rollback()
        return None

run = str(random.randint(10000, 99999))
ICP = env["ir.config_parameter"].sudo()
ICP.set_param("xtreampro.api_url", os.environ["API_URL"])
ICP.set_param("xtreampro.api_key", os.environ["API_KEY"])
env.cr.commit()

print("== settings and packages")
settings = env["res.config.settings"].create({})
r = attempt("test connection", lambda: settings.action_xtreampro_test_connection())
check("test connection returns a notification", isinstance(r, dict) and r.get("type") == "ir.actions.client", r)
attempt("sync packages", lambda: settings.action_xtreampro_sync_packages())
pkgs = env["xtreampro.package"].search([])
check("packages synced from the panel", len(pkgs) >= 1, pkgs.mapped("name"))
attempt("cron: sync packages", lambda: env["xtreampro.package"]._cron_sync_packages())
for model in ("xtreampro.line", "xtreampro.reseller", "xtreampro.package", "xtreampro.credit.transfer"):
    for vt in ("form", "tree", "search"):
        try:
            env[model].get_view(view_type=vt)
        except Exception as e:
            if not (model == "xtreampro.credit.transfer" and vt != "form"):
                check("%s %s view" % (model, vt), False, e)
check("views compile", True)
attempt("settings form view", lambda: env["res.config.settings"].get_view(view_type="form"))
attempt("product form view", lambda: env["product.template"].get_view(view_type="form"))
attempt("sale order form view", lambda: env["sale.order"].get_view(view_type="form"))
attempt("credit wizard form view", lambda: env["xtreampro.credit.wizard"].get_view(view_type="form"))
attempt("package wizard form view", lambda: env["xtreampro.line.package.wizard"].get_view(view_type="form"))

print("== lines")
# A package sold as an official period on a plain line: the panel says so itself (`sells`); the box
# packages (the sample catalogue's MAG / Enigma2 ones, and the one the e2e setup adds) are not offered.
officials = pkgs.filtered(lambda p: p.is_official and p.sells_line)
boxes = pkgs.filtered(lambda p: p.is_official and not p.sells_line)
check("the panel offers an official package that sells as a line", len(officials) >= 1, pkgs.mapped("name"))
check("packages for boxes only are told apart by `sells`, and the e2e box package is among them", "E2E box only" in boxes.mapped("name") and not (boxes & officials), (boxes.mapped("name"), officials.mapped("name")))
check("the pickers (product and package change) offer only packages that sell as a line", env["xtreampro.package"].search([("sells_line", "=", True)]) >= officials and not (env["xtreampro.package"].search([("sells_line", "=", True)]) & boxes))
box = boxes.filtered(lambda p: p.name == "E2E box only")[:1] or boxes[:1]
pkg = officials[0]
groups = env["xtreampro.group"].search([])
check("sub-reseller groups were synced from pricing", len(groups) >= 1, groups.mapped("name"))
api = env["xtreampro.api"]
connectors = [row.get("connector") for row in api._call("api_logs", {"limit": 50})]
check("the panel call log names the connector", "odoo/1.1.1" in connectors, set(connectors))
prod = env["product.product"].create({"name": "IPTV 12 months " + run, "type": "service", "list_price": 10,
                                      "xtreampro_kind": "line", "xtreampro_package_id": pkg.id})
partner = env["res.partner"].create({"name": "Ann Berg " + run, "email": "ann%s@example.test" % run})
so = env["sale.order"].create({"partner_id": partner.id, "order_line": [(0, 0, {"product_id": prod.id, "product_uom_qty": 2})]})
attempt("confirm sale order", lambda: so.action_confirm())
lines = env["xtreampro.line"].with_context(active_test=False).search([("sale_order_id", "=", so.id)])
check("confirming creates one line per unit, active", len(lines) == 2 and set(lines.mapped("state")) == {"active"}, [(l.state, l.last_error) for l in lines])
check("lines have credentials and an expiry", all(l.username and l.password and l.expiry for l in lines), [(l.username, bool(l.password), l.expiry) for l in lines])
check("smart button counts them", so.xtreampro_line_count == 2, so.xtreampro_line_count)
attempt("re-confirm does not duplicate", lambda: so._xtreampro_provision())
check("still two lines", env["xtreampro.line"].with_context(active_test=False).search_count([("sale_order_id", "=", so.id)]) == 2)
msgs = " ".join(so.message_ids.mapped("body")) + " ".join(lines.mapped("message_ids.body"))
check("no password in the chatter", all((l.password or "#") not in msgs for l in lines))
l = lines[0]
exp = l.expiry
attempt("renew", lambda: l.action_renew())
check("renew extends the expiry", l.expiry and l.expiry > exp, (exp, l.expiry, l.last_error))
attempt("suspend", lambda: l.action_suspend())
check("suspended", l.state == "disabled", l.state)
attempt("unsuspend", lambda: l.action_unsuspend())
check("active again", l.state == "active", l.state)
attempt("refresh", lambda: l.action_refresh())
attempt("cron: refresh lines", lambda: env["xtreampro.line"]._cron_refresh_lines())
check("playlist url", "get.php" in (l.playlist_url or ""), l.playlist_url)
check("the links come from the panel (HLS playlist, guide, web player)", "output=m3u8" in (l.hls_url or "") and "xmltv.php" in (l.guide_url or "") and (l.player_url or "").endswith("/player/") and bool(l.links_json), (l.hls_url, l.guide_url, l.player_url))
legacy = lines[1]
legacy.write({"links_json": False})
legacy.invalidate_recordset()
check("a line without stored links still shows assembled M3U and web player links", "get.php" in (legacy.playlist_url or "") and not legacy.hls_url and (legacy.player_url or "").endswith("/player/"), (legacy.playlist_url, legacy.hls_url))
attempt("refresh stores the links again", lambda: legacy.action_refresh())
check("refresh restored the links of the panel", bool(legacy.links_json) and "output=m3u8" in (legacy.hls_url or ""), legacy.links_json)

other_pkg = (officials - pkg)[:1] or pkg
before_balance = int(env["xtreampro.api"]._user_info().get("credits") or 0)
wiz = env["xtreampro.line.package.wizard"].create({"line_id": l.id, "package_id": other_pkg.id})
check("the wizard shows, before anything is sold, what the panel will do (time kept or restarted, price)", "On the panel" in (wiz.compat_note or "") and "credits" in (wiz.compat_note or ""), wiz.compat_note)
compat = api._package_compatibility(l.panel_line_id, other_pkg.panel_id)
check("package_compatibility answers keeps_time_left, reason and price", isinstance(compat, dict) and isinstance(compat.get("keeps_time_left"), bool) and compat.get("reason") and "price" in compat and "can_afford" in compat, compat)
if box:
    bw = env["xtreampro.line.package.wizard"].create({"line_id": l.id, "package_id": box.id})
    check("for a package of boxes only the wizard says why instead of failing at sale time", "available" in (bw.compat_note or "").lower() or "cannot" in (bw.compat_note or "").lower(), bw.compat_note)
    try:
        with env.cr.savepoint():
            bw.action_apply()
        check("changing to a box-only package is refused", False, "no error raised")
    except Exception as e:
        check("changing to a box-only package is refused with a readable message and nothing changes", ("boxes only" in str(e) or "not available" in str(e)) and l.package_changes == 0, e)
    try:
        api._check_needs([{"kind": "line", "package_id": box.panel_id, "trial": False, "units": 1}])
        check("a sale of a box-only package as a line is refused before the panel is asked", False, "no error raised")
    except Exception as e:
        check("a sale of a box-only package as a line is refused before the panel is asked", getattr(e, "code", "") == "INVALID_PACKAGE" and "boxes only" in str(e), e)
    check("a renewal of a line is not refused for that reason", api._check_needs([{"kind": "line", "package_id": box.panel_id, "trial": False, "units": 1, "renewal": True}]) is None)
with mock.patch.object(type(api), "_call", side_effect=XtreamProError("UNKNOWN_ACTION")):
    check("a panel that does not know package_compatibility gives None, not an error", api._package_compatibility(1, 1) is None)
    wiz_old = env["xtreampro.line.package.wizard"].create({"line_id": l.id, "package_id": other_pkg.id})
    check("and the wizard says the panel decides when the change is made", "too old" in (wiz_old.compat_note or ""), wiz_old.compat_note)
attempt("change package (upgrade / downgrade)", lambda: wiz.action_apply())
check("the line has the new package" + ("" if other_pkg != pkg else " (only one official package exists here, so the same one)"), l.package_id == other_pkg and l.package_changes == 1, (l.package_id.name, l.package_changes))
check("and the change was charged", int(env["xtreampro.api"]._user_info().get("credits") or 0) < before_balance, before_balance)
check("the change is in the chatter, with what it did to the time left", "moved to package" in " ".join(l.message_ids.mapped("body")) and "On the panel" in " ".join(l.message_ids.mapped("body")))
trial_only = env["xtreampro.package"].search([("is_official", "=", False)], limit=1)
if trial_only:
    try:
        with env.cr.savepoint():
            env["xtreampro.line.package.wizard"].create({"line_id": l.id, "package_id": trial_only.id}).action_apply()
        check("a package that is not sold as an official period is refused", False, "no error raised")
    except Exception as e:
        check("a package that is not sold as an official period is refused with a clear message", "official" in str(e).lower(), e)
attempt("send credentials (mail is queued)", lambda: l.action_send_credentials())
attempt("terminate", lambda: lines[1].action_terminate())
check("terminated line is archived", not lines[1].active, lines[1].active)

env.cr.commit()
print("== access rights")
salesman = env["res.users"].create({"name": "Sales " + run, "login": "sales" + run, "groups_id": [(6, 0, [env.ref("base.group_user").id, env.ref("sales_team.group_sale_salesman").id])]})
as_sales = l.with_user(salesman)
check("a salesman reads the line", attempt("salesman read", lambda: as_sales.read(["username", "state"])) is not None)
try:
    data = as_sales.read(["password"])
    check("a salesman cannot read the password", not data or not data[0].get("password"), data)
except Exception:
    check("a salesman cannot read the password", True)
try:
    as_sales.action_suspend()
    check("a salesman cannot suspend", False, "no error raised")
except Exception:
    env.cr.rollback()
    check("a salesman cannot suspend", True)

env.cr.commit()
print("== sub-reseller")
rprod = env["product.product"].create({"name": "Reseller starter " + run, "type": "service", "list_price": 50,
                                       "xtreampro_kind": "reseller", "xtreampro_credits": 30})
so2 = env["sale.order"].create({"partner_id": partner.id, "order_line": [(0, 0, {"product_id": rprod.id, "product_uom_qty": 1})]})
attempt("confirm reseller order", lambda: so2.action_confirm())
res = env["xtreampro.reseller"].search([("partner_id", "=", partner.id)])
check("order creates the reseller account, active with 30 credits", len(res) == 1 and res.state == "active" and res.credits == 30 and bool(res.panel_user_id), [(r.state, r.credits, r.last_error) for r in res] + [so2.message_ids.mapped("body")])
check("no IPTV line for a reseller product", env["xtreampro.line"].with_context(active_test=False).search_count([("sale_order_id", "=", so2.id)]) == 0)
so3 = env["sale.order"].create({"partner_id": partner.id, "order_line": [(0, 0, {"product_id": rprod.id, "product_uom_qty": 2})]})
attempt("confirm top-up order", lambda: so3.action_confirm())
check("a later order tops the same account up to 90", len(env["xtreampro.reseller"].search([("partner_id", "=", partner.id)])) == 1 and res.credits == 90, (res.credits, res.transfer_ids.mapped(lambda t: (t.credits, t.state, t.last_error))))
attempt("re-provision does not credit twice", lambda: so3._xtreampro_provision())
attempt("refresh reseller", lambda: res.action_refresh())
check("still 90 after re-provision and refresh", res.credits == 90, res.credits)
wiz = env["xtreampro.credit.wizard"].create({"reseller_id": res.id, "direction": "add", "credits": 10, "note": "bonus"})
attempt("wizard: add credits", lambda: wiz.action_apply())
wiz = env["xtreampro.credit.wizard"].create({"reseller_id": res.id, "direction": "take", "credits": 40})
attempt("wizard: take credits back", lambda: wiz.action_apply())
check("balance after +10 and -40 is 60", res.credits == 60, res.credits)
env.cr.commit()
wiz = env["xtreampro.credit.wizard"].create({"reseller_id": res.id, "direction": "take", "credits": 5000})
try:
    wiz.action_apply()
    check("taking more than the balance is refused", False, res.credits)
except Exception as e:
    env.cr.rollback()
    check("taking more than the balance is refused with a readable error", "credit" in str(e).lower(), e)
attempt("suspend reseller", lambda: res.action_suspend())
check("reseller disabled", res.state == "disabled", res.state)
attempt("unsuspend reseller", lambda: res.action_unsuspend())
check("reseller active", res.state == "active", res.state)
attempt("cron: refresh resellers", lambda: env["xtreampro.reseller"]._cron_refresh_resellers())
attempt("send reseller credentials", lambda: res.action_send_credentials())
rmsgs = " ".join(res.message_ids.mapped("body")) + " ".join(so2.message_ids.mapped("body"))
check("no reseller password in the chatter", (res.password or "#") not in rmsgs)
nomail = env["res.partner"].create({"name": "No Mail " + run})
so4 = env["sale.order"].create({"partner_id": nomail.id, "order_line": [(0, 0, {"product_id": rprod.id, "product_uom_qty": 1})]})
attempt("confirm for a partner without email", lambda: so4.action_confirm())
r4 = env["xtreampro.reseller"].search([("partner_id", "=", nomail.id)])
check("order still confirmed, account in error with a reason", so4.state == "sale" and len(r4) == 1 and r4.state == "error" and bool(r4.last_error), (so4.state, r4.mapped("state"), r4.mapped("last_error")))

env.cr.commit()
print("== failure does not block a sale")
ICP.set_param("xtreampro.api_key", "xk_wrong")
so5 = env["sale.order"].create({"partner_id": partner.id, "order_line": [(0, 0, {"product_id": prod.id, "product_uom_qty": 1})]})
attempt("confirm with a wrong key", lambda: so5.action_confirm())
bad = env["xtreampro.line"].with_context(active_test=False).search([("sale_order_id", "=", so5.id)])
check("order confirmed, line in error with a readable reason", so5.state == "sale" and len(bad) == 1 and bad.state == "error" and "key" in (bad.last_error or "").lower(), (so5.state, bad.mapped("state"), bad.mapped("last_error")))
ICP.set_param("xtreampro.api_key", os.environ["API_KEY"])
attempt("retry", lambda: bad.action_retry())
check("retry creates the line", bad.state == "active" and bool(bad.username), (bad.state, bad.last_error))
env.cr.commit()
print("== credits are checked before anything is bought")
ICP.set_param("xtreampro.api_key", os.environ["API_KEY"])
credits_before = int(api._user_info().get("credits") or 0)
buyer = env["res.partner"].create({"name": "Buyer " + run, "email": "buyer%s@example.test" % run})
try:
    api._check_needs([{"kind": "line", "package_id": pkg.panel_id, "trial": False, "units": 10 ** 7}])
    check("units the credits cannot pay for are refused before anything is created", False, "no error raised")
except Exception as e:
    check("units the credits cannot pay for are refused before anything is created, with the amounts", getattr(e, "code", "") == "INSUFFICIENT_CREDITS" and "needs" in str(e), e)
check("a check with nothing to buy passes", api._check_needs([]) is None)
check("and no credit was spent", int(api._user_info().get("credits") or 0) == credits_before)
env.cr.commit()
ghost = env["xtreampro.package"].create({"panel_id": 999999, "name": "Ghost " + run, "is_official": True})
gprod = env["product.product"].create({"name": "Ghost " + run, "type": "service", "list_price": 1, "xtreampro_kind": "line", "xtreampro_package_id": ghost.id})
so7 = env["sale.order"].create({"partner_id": buyer.id, "order_line": [(0, 0, {"product_id": gprod.id, "product_uom_qty": 1})]})
attempt("confirm an order for a package the reseller may not sell", lambda: so7.action_confirm())
gl = env["xtreampro.line"].with_context(active_test=False).search([("sale_order_id", "=", so7.id)])
check("the order says nothing was provisioned", "nothing was provisioned" in " ".join(so7.message_ids.mapped("body")))
check("it is refused with a clear reason, nothing created on the panel", len(gl) == 1 and gl.state == "error" and "not in the list" in (gl.last_error or "") and not gl.panel_line_id, gl.mapped("last_error"))
greedy = env["product.product"].create({"name": "Greedy " + run, "type": "service", "list_price": 1, "xtreampro_kind": "reseller", "xtreampro_credits": 99999999})
gp = env["res.partner"].create({"name": "Greedy " + run, "email": "greedy%s@example.test" % run})
so8 = env["sale.order"].create({"partner_id": gp.id, "order_line": [(0, 0, {"product_id": greedy.id, "product_uom_qty": 1})]})
attempt("confirm a sub-reseller order that cannot be paid", lambda: so8.action_confirm())
gr = env["xtreampro.reseller"].with_context(active_test=False).search([("partner_id", "=", gp.id)])
check("the account stays in error and was not created on the panel", len(gr) == 1 and gr.state == "error" and not gr.panel_user_id and "credits" in (gr.last_error or "").lower(), (gr.mapped("state"), gr.mapped("last_error")))
try:
    with env.cr.savepoint():
        gl.action_retry()
    check("retrying a line that cannot be sold is refused with a readable error", False, "no error raised")
except Exception as e:
    check("retrying a line that cannot be sold is refused with a readable error", "not in the list" in str(e), e)
env.cr.commit()

print("== chosen group, panel address, sign-in link")
check("the reseller product offers the synced groups", bool(groups[:1]))
gprod2 = env["product.product"].create({"name": "Grouped " + run, "type": "service", "list_price": 1, "xtreampro_kind": "reseller", "xtreampro_credits": 0, "xtreampro_group_id": groups[0].id})
gp2 = env["res.partner"].create({"name": "Grouped " + run, "email": "grouped%s@example.test" % run})
ICP.set_param("xtreampro.panel_url", "https://panel.example.test/")
so9 = env["sale.order"].create({"partner_id": gp2.id, "order_line": [(0, 0, {"product_id": gprod2.id, "product_uom_qty": 1})]})
attempt("confirm a sub-reseller order in a chosen group", lambda: so9.action_confirm())
g2 = env["xtreampro.reseller"].search([("partner_id", "=", gp2.id)])
check("the account was created in that group", len(g2) == 1 and g2.state == "active" and g2.group_id == groups[0], (g2.mapped("state"), g2.mapped("last_error")))
check("it has the panel sign-in link", g2.login_url == "https://panel.example.test/login", g2.login_url)
ICP.set_param("xtreampro.panel_url", "javascript:alert(1)")
g2.invalidate_recordset()
check("an address that is not http(s) gives no link", not g2.login_url)
env.cr.commit()

print("== on cancellation: disable (default) or delete permanently")
cprod = env["product.product"].create({"name": "Cancel default " + run, "type": "service", "list_price": 1, "xtreampro_kind": "line", "xtreampro_package_id": pkg.id})
check("the default on cancellation is disable", cprod.xtreampro_on_cancel == "disable")
dprod = env["product.product"].create({"name": "Cancel delete " + run, "type": "service", "list_price": 1, "xtreampro_kind": "line", "xtreampro_package_id": pkg.id, "xtreampro_on_cancel": "delete"})
cpartner = env["res.partner"].create({"name": "Cancel " + run, "email": "cancel%s@example.test" % run})
so10 = env["sale.order"].create({"partner_id": cpartner.id, "order_line": [(0, 0, {"product_id": cprod.id, "product_uom_qty": 1}), (0, 0, {"product_id": dprod.id, "product_uom_qty": 1})]})
attempt("confirm", lambda: so10.action_confirm())
cl = env["xtreampro.line"].search([("sale_order_id", "=", so10.id)])
check("both lines are active", len(cl) == 2 and set(cl.mapped("state")) == {"active"}, [(x.state, x.last_error) for x in cl])
# Odoo asks for a confirmation of the cancellation of a confirmed order (a wizard that then calls this with the warning off)
attempt("cancel the order", lambda: so10.with_context(disable_cancel_warning=True).action_cancel())
check("the order is cancelled", so10.state == "cancel", so10.state)
dis = cl.filtered(lambda x: x.sale_line_id.product_id == cprod)
dele = cl.with_context(active_test=False).filtered(lambda x: x.sale_line_id.product_id == dprod)
check("the line of the default product is suspended on the panel (not deleted)", dis.state == "disabled" and dis.active and api._get_line(dis.panel_line_id).get("status") == "disabled", (dis.state, dis.active))
try:
    api._get_line(dele.panel_line_id)
    gone_ok = False
except Exception as e:
    gone_ok = getattr(e, "code", "") == "RESOURCE_NOT_FOUND"
check("the line of the 'delete permanently' product is gone from the panel and archived", gone_ok and not dele.active, (gone_ok, dele.active))
n_lines = env["xtreampro.line"].with_context(active_test=False).search_count([("sale_order_id", "=", so10.id)])
attempt("provisioning the order again after its line was deleted", lambda: so10._xtreampro_provision())
check("sells nothing again: the unit already has its record, so no request id is sent twice", env["xtreampro.line"].with_context(active_test=False).search_count([("sale_order_id", "=", so10.id)]) == n_lines == 2, n_lines)

print("== a request id whose line was deleted (REQUEST_ID_SPENT)")
rid = "odoo-spent-" + run
first = api._create_line(pkg.panel_id, False, rid)
check("the first sale under the request id creates a line", bool((first.get("line") or {}).get("id")), first)
api._delete_line(first["line"]["id"])
try:
    api._create_line(pkg.panel_id, False, rid)
    check("selling again under the spent request id is refused", False, "no error raised")
except XtreamProError as e:
    check("selling again under the spent request id is refused with a readable message", e.code == "REQUEST_ID_SPENT" and "already made" in e.message and "Archive" in e.message, e.message)
srec = env["xtreampro.line"].create({"partner_id": partner.id, "package_id": pkg.id, "request_id": rid, "state": "error"})
try:
    with env.cr.savepoint():
        srec.action_retry()
    check("Retry of a line whose sale was deleted on the panel is refused", False, "no error raised")
except Exception as e:
    check("Retry of a line whose sale was deleted on the panel shows a readable message", "already made" in str(e), e)
check("and no second line was made under the key", srec.state == "error" and not srec.panel_line_id, (srec.state, srec.panel_line_id))
srec.write({"active": False})
rp = env["res.partner"].create({"name": "Cancel reseller " + run, "email": "cancelr%s@example.test" % run})
rdel = env["product.product"].create({"name": "Reseller delete " + run, "type": "service", "list_price": 1, "xtreampro_kind": "reseller", "xtreampro_credits": 5, "xtreampro_on_cancel": "delete"})
so11 = env["sale.order"].create({"partner_id": rp.id, "order_line": [(0, 0, {"product_id": rdel.id, "product_uom_qty": 1})]})
attempt("confirm a sub-reseller order", lambda: so11.action_confirm())
rr = env["xtreampro.reseller"].search([("partner_id", "=", rp.id)])
check("the account exists with 5 credits", len(rr) == 1 and rr.state == "active" and rr.credits == 5, (rr.mapped("state"), rr.mapped("last_error")))
panel_id = rr.panel_user_id
attempt("cancel it", lambda: so11.with_context(disable_cancel_warning=True).action_cancel())
rr = rr.with_context(active_test=False)
check("the account is deleted for good on the panel and archived", not rr.active and all(str(u.get("id")) != panel_id for u in api._sub_users(search=rr.username)), (rr.active, rr.state))
check("cancelling a second time is harmless", so11.state == "cancel")
env.cr.commit()

print("== webhooks from the panel")
SECRET = "whsec_e2e_" + run
ICP.set_param("xtreampro.webhook_secret", SECRET)
Hook = env["xtreampro.webhook"]
counter = [0]
def post(event_type, data, ts=None, secret=None, tamper=False, unsigned=False, event_id=None):
    ts = str(int(time.time()) if ts is None else ts)
    counter[0] += 1
    # every event has its own id (the receiver applies an id once)
    body = json.dumps({"id": event_id or "evt_e2e_%s_%d" % (run, counter[0]), "type": event_type, "created": int(time.time()), "data": data}).encode()
    sig = "sha256=" + hmac.new((secret or SECRET).encode(), ts.encode() + b"." + body, hashlib.sha256).hexdigest()
    if tamper:
        body += b" "
    return Hook._receive(body, ts, "" if unsigned else sig)
hl = lines[0]
check("a signed ping is accepted", post("ping", {}) == (200, "pong"))
check("a signed line.expired is applied", post("line.expired", {"line_id": hl.panel_line_id, "exp_date": 1700000000}) == (200, "recorded") and hl.state == "expired" and hl.expiry.year == 2023, (hl.state, hl.expiry))
check("the line's chatter says so", "expired on the panel" in " ".join(hl.message_ids.mapped("body")))
check("a signed line.renewed moves it back to active", post("line.renewed", {"line_id": hl.panel_line_id, "exp_date": 1900000000})[0] == 200 and hl.state == "active")
check("a signed line.disabled suspends it", post("line.disabled", {"line_id": hl.panel_line_id})[0] == 200 and hl.state == "disabled")
check("a tampered body is refused (401)", post("line.expired", {"line_id": hl.panel_line_id}, tamper=True)[0] == 401 and hl.state == "disabled")
check("an unsigned call is refused (401)", post("line.expired", {"line_id": hl.panel_line_id}, unsigned=True)[0] == 401)
check("a wrong secret is refused (401)", post("line.expired", {"line_id": hl.panel_line_id}, secret="whsec_other")[0] == 401)
check("a replay outside the time window is refused (400) although the signature is right", post("line.expired", {"line_id": hl.panel_line_id}, ts=int(time.time()) - 3600)[0] == 400 and hl.state == "disabled")
check("an event of an unknown line is acknowledged and ignored", post("line.deleted", {"line_id": 987654321}) == (200, "unknown line"))
check("a signed line.deleted archives the line", post("line.deleted", {"line_id": hl.panel_line_id})[0] == 200 and not hl.with_context(active_test=False).active)
check("replaying it is harmless", post("line.deleted", {"line_id": hl.panel_line_id})[0] == 200)
# The panel delivers at least once and keeps the event id the same on every retry: an id is applied once.
dup = "evt_dup_" + run
Event = env["xtreampro.webhook.event"]
check("a signed event of an unknown line is acknowledged", post("line.expired", {"line_id": 987654321}, event_id=dup) == (200, "unknown line"))
check("its id is remembered once it was dealt with", Event._seen(dup))
hl2 = bad
r1 = post("line.disabled", {"line_id": hl2.panel_line_id}, event_id="evt_dupline_" + run)
check("an event of a line is applied", r1 == (200, "recorded") and hl2.state == "disabled", (r1, hl2.state))
hl2.write({"state": "active"})
r2 = post("line.disabled", {"line_id": hl2.panel_line_id}, event_id="evt_dupline_" + run)
check("the same event id again is acknowledged (200) and not applied twice", r2 == (200, "duplicate") and hl2.state == "active", (r2, hl2.state))
r3 = post("line.disabled", {"line_id": hl2.panel_line_id}, event_id="evt_dupline2_" + run)
check("another event id of the same kind is applied", r3 == (200, "recorded") and hl2.state == "disabled", (r3, hl2.state))
body = json.dumps({"type": "line.enabled", "created": int(time.time()), "data": {"line_id": hl2.panel_line_id}}).encode()
ts = str(int(time.time()))
sig = "sha256=" + hmac.new(SECRET.encode(), ts.encode() + b"." + body, hashlib.sha256).hexdigest()
check("an event without an id (older panel) is applied every time", Hook._receive(body, ts, sig) == (200, "recorded") and Hook._receive(body, ts, sig) == (200, "recorded"))
from odoo.addons.xtreampro_connector.models.xtreampro_webhook import event_id_of
check("an id of an unexpected shape is not used for de-duplication", event_id_of({"id": "x" * 65}) == "" and event_id_of({"id": ["x"]}) == "" and event_id_of({"id": "evt_9f2c41d07b3a5e6c81d2f4a0"}) == "evt_9f2c41d07b3a5e6c81d2f4a0")
env.cr.execute("UPDATE xtreampro_webhook_event SET create_date = now() - interval '2 days' WHERE event_id = %s", (dup,))
Event.invalidate_model()
Event._remember("evt_fresh_" + run)
check("an id older than the retention is forgotten when a new one is stored, a fresh one is kept", not Event._seen(dup) and Event._seen("evt_fresh_" + run) and Event._seen("evt_dupline_" + run))
Event._remember("evt_fresh_" + run)
check("storing the same id twice is harmless", Event.search_count([("event_id", "=", "evt_fresh_" + run)]) == 1)
# Events of lines this database does not know, with the owner of the line (webhook registered with the sub-resellers' lines).
owner_before = res.last_event_at
check("a line of an unknown owner is acknowledged and ignored", post("line.expired", {"line_id": 987654322, "owner_id": "0197a1c2-5f3e-7d10-8a41-6b2c9e0d4f55", "owner_username": "reseller_a"}) == (200, "unknown line") and not res.last_event_at)
check("a line of a sub-reseller account of this database is noted on the account, nothing else is kept", post("line.expired", {"line_id": 987654323, "username": "enduser", "owner_id": res.panel_user_id, "owner_username": res.username}) == (200, "recorded (line of a sub-reseller account)") and bool(res.last_event_at), res.last_event_at)
ICP.set_param("xtreampro.webhook_secret", False)
check("with no secret set every call is refused (503)", post("ping", {})[0] == 503)
hook = api._create_webhook("https://odoo.example.test/xtreampro/webhook", "line.deleted,line.expired")
check("the panel registers the webhook and returns its id and secret", bool(hook.get("id")) and str(hook.get("secret", "")).startswith("whsec_"), list(hook))
attempt("the panel pings it", lambda: api._test_webhook(hook["id"]))
attempt("and deletes it", lambda: api._delete_webhook(hook["id"]))
try:
    api._create_webhook("http://odoo.example.test/hook", "line.deleted")
    check("an http address is refused by the panel (https only)", False, "no error raised")
except Exception as e:
    check("an http address is refused by the panel (https only)", getattr(e, "code", "") == "INVALID_REQUEST", e)
base_url = env["ir.config_parameter"].sudo().get_param("web.base.url")
ICP.set_param("web.base.url", "https://odoo.example.test")
settings = env["res.config.settings"].create({})
res_ = attempt("settings: Register webhook", lambda: settings.action_xtreampro_register_webhook())
check("it stores the secret and the id the panel returned", (ICP.get_param("xtreampro.webhook_secret") or "").startswith("whsec_") and bool(ICP.get_param("xtreampro.webhook_id")), res_)
def mine():
    return [h for h in (api._call("get_webhooks") or []) if "odoo.example.test" in str(h.get("url"))]
check("the default registration does not ask for the events of sub-resellers' lines", len(mine()) == 1 and not mine()[0].get("include_sub_resellers") and env["res.config.settings"].create({}).xtreampro_webhook_scope.endswith("account only."), mine())
attempt("settings: Send test event", lambda: settings.action_xtreampro_test_webhook())
attempt("settings: Remove webhook", lambda: settings.action_xtreampro_remove_webhook())
check("removing clears both", not ICP.get_param("xtreampro.webhook_secret") and not ICP.get_param("xtreampro.webhook_id"))
attempt("settings: Register webhook (with sub-resellers' lines)", lambda: env["res.config.settings"].create({}).action_xtreampro_register_webhook_subs())
check("the panel holds it with include_sub_resellers and the settings say so", len(mine()) == 1 and bool(mine()[0].get("include_sub_resellers")) and "sub-reseller" in env["res.config.settings"].create({}).xtreampro_webhook_scope, mine())
attempt("settings: Remove it again", lambda: env["res.config.settings"].create({}).action_xtreampro_remove_webhook())
check("nothing of it is left on the panel", not mine())
ICP.set_param("web.base.url", base_url or False)
# left for the HTTP check of run.sh: a secret to sign with
ICP.set_param("xtreampro.webhook_secret", "whsec_e2e_http")
env.cr.commit()
print("\n%d FAILED" % fail if fail else "\nALL OK")
