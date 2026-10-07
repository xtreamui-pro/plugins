"""Scheduler jobs (hooks.py -> scheduler_events). All of them work in small batches and stop
at the first error that makes the rest pointless (wrong key, panel down), so a broken panel
never produces hundreds of failing calls."""

import frappe
from frappe.utils import add_days, nowdate

from xtreampro_connector.invoices import provision_invoice
from xtreampro_connector.panel import XtreamProError
from xtreampro_connector.utils import (
    KIND_LINE, KIND_RESELLER, LINE, RESELLER, get_provisioner, is_configured, scrub,
)
from xtreampro_connector.xtream_ui_pro.doctype.xtream_ui_pro_package.xtream_ui_pro_package import sync

BATCH = 20          # records per commit
MAX_RECORDS = 200   # records per run (the least recently refreshed first)
CATCH_UP_DAYS = 30
CATCH_UP_LIMIT = 25


def _log(title):
    frappe.log_error(title=title, message=scrub(frappe.get_traceback()))


def refresh_all():
    """Every 6 hours: status, expiry and credit balance of lines and sub-reseller accounts."""
    if not is_configured():
        return
    _refresh_lines()
    _refresh_resellers()


def _refresh_lines():
    rows = frappe.get_all(LINE, filters={"status": ["in", ["Active", "Expired"]], "panel_line_id": [">", 0]},
                          fields=["name", "panel_line_id"], order_by="last_sync asc, name asc", limit=MAX_RECORDS)
    prov = get_provisioner()
    for start in range(0, len(rows), BATCH):
        results, stopped = prov.refresh_lines([(r.name, r.panel_line_id) for r in rows[start:start + BATCH]])
        for name, fields, error in results:
            try:
                doc = frappe.get_doc(LINE, name)
                if fields:
                    doc.apply_fields(fields)
                    doc.save(ignore_permissions=True)
                else:
                    doc.set_error(error)
            except Exception:  # noqa: BLE001
                _log("Xtream UI Pro: refreshing line %s failed" % name)
        frappe.db.commit()
        if stopped:
            frappe.logger("xtreampro").warning("line refresh stopped: %s", stopped)
            break


def _refresh_resellers():
    rows = frappe.get_all(RESELLER, filters={"status": ["in", ["Active", "Suspended"]], "panel_user_id": ["!=", ""]},
                          fields=["name", "panel_user_id"], order_by="last_sync asc, name asc", limit=MAX_RECORDS)
    if not rows:
        return
    # one pass over the panel's list of accounts refreshes all of them
    results, stopped = get_provisioner().refresh_resellers([(r.name, r.panel_user_id) for r in rows])
    for start in range(0, len(results), BATCH):
        for name, fields, error in results[start:start + BATCH]:
            try:
                doc = frappe.get_doc(RESELLER, name)
                if fields:
                    doc.apply_fields(fields)
                    doc.save(ignore_permissions=True)
                else:
                    doc.set_error(error)
            except Exception:  # noqa: BLE001
                _log("Xtream UI Pro: refreshing sub-reseller %s failed" % name)
        frappe.db.commit()
    if stopped:
        frappe.logger("xtreampro").warning("sub-reseller refresh stopped: %s", stopped)


def provision_missed_invoices():
    """Hourly safety net: paid invoices with Xtream UI Pro items that were never provisioned
    (settled by a Journal Entry, an event lost with the job queue, ...). Invoices that were
    tried and failed are not retried here: use Provision again on the invoice."""
    if not is_configured():
        return
    names = frappe.db.sql(
        """select distinct si.name, si.posting_date
           from `tabSales Invoice` si
           join `tabSales Invoice Item` sii on sii.parent = si.name
           join `tabItem` it on it.name = sii.item_code
           where si.docstatus = 1 and si.is_return = 0 and si.outstanding_amount <= 0
             and ifnull(si.xp_provision_status, '') = '' and si.posting_date >= %s
             and (it.xp_kind = %s or (it.xp_kind = %s and ifnull(it.xp_package, '') != ''))
           order by si.posting_date desc limit %s""",
        (add_days(nowdate(), -CATCH_UP_DAYS), KIND_RESELLER, KIND_LINE, CATCH_UP_LIMIT))
    for name, _posting_date in names:
        try:
            provision_invoice(name)
        except Exception:  # noqa: BLE001
            _log("Xtream UI Pro: catch-up provisioning of %s failed" % name)
        frappe.db.commit()


def sync_packages():
    """Daily: keep the Package list (and so the Item dropdown) in step with the panel."""
    if not is_configured():
        return
    try:
        sync(get_provisioner().packages())
    except XtreamProError as exc:
        frappe.logger("xtreampro").warning("package sync failed: %s", exc.code)
