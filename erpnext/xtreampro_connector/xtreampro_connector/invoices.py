"""Sales Invoice events -> Xtream UI Pro lines and sub-reseller accounts.

An invoice plays the role of the order of an order-based shop (compare the WooCommerce
connector):

* when a Sales Invoice with Xtream UI Pro items is PAID (outstanding amount 0) one line is
  created per unit of an IPTV line item; one sub-reseller account is created per customer and
  later invoices only top its credits up;
* provisioning is idempotent: running it again (a second payment event, "Provision again",
  the hourly catch-up) creates nothing twice, and the panel de-duplicates on the request ids;
* cancelling the invoice, or submitting a credit note against it, disables the lines and takes
  unspent credits back (lines are never deleted by a refund);
* the panel calls run in a background job (frappe.enqueue) so a slow panel never blocks
  posting an invoice; failures are kept on the line / account / transfer and as a comment on
  the invoice, and can be retried. Comments and errors never contain a password.
"""

import frappe
from frappe import _
from frappe.utils import flt

from xtreampro_connector.panel import XtreamProError
from xtreampro_connector.panel.client import FATAL_CODES
from xtreampro_connector.panel.provisioner import credits_for, make_password, make_username, missing_units
from xtreampro_connector.utils import (
    KIND_RESELLER, LINE, MANAGER_ROLES, RESELLER, TRANSFER, customer_email,
    failure_text, get_provisioner, is_configured, item_kind, scrub, send_credentials, settings,
)
from xtreampro_connector.xtream_ui_pro.doctype.xtream_ui_pro_credit_transfer.xtream_ui_pro_credit_transfer import new_transfer

INVOICE = "Sales Invoice"


# ---------------------------------------------------------------------------
# Hooks (hooks.py -> doc_events). They must never block posting an invoice.
# ---------------------------------------------------------------------------

def _has_xtream_items(doc):
    return any(item_kind(row.item_code) for row in doc.get("items") or [])


def _enqueue(method, **kwargs):
    frappe.enqueue("xtreampro_connector.invoices." + method, queue="default", timeout=900,
                   enqueue_after_commit=True, **kwargs)


def _guard(fn):
    def wrapper(doc, method=None):
        try:
            fn(doc)
        except Exception:  # noqa: BLE001 - never block posting an invoice
            frappe.log_error(title="Xtream UI Pro: %s failed for %s" % (fn.__name__, doc.name),
                             message=scrub(frappe.get_traceback()))
    wrapper.__name__ = fn.__name__
    return wrapper


@_guard
def _on_submit(doc):
    if not _has_xtream_items(doc):
        return
    if doc.is_return:
        _enqueue("revoke_return", return_name=doc.name)
    elif flt(doc.outstanding_amount) <= 0:          # paid at once (POS, advance, free item)
        _enqueue("provision_invoice", invoice_name=doc.name)


@_guard
def _on_cancel(doc):
    # A cancelled credit note is not undone: nothing was taken back that could be restored.
    if not doc.is_return and _has_xtream_items(doc):
        _enqueue("revoke_invoice", invoice_name=doc.name)


@_guard
def _on_payment(doc):
    # Runs after ERPNext updated the outstanding amount of the invoices in this entry.
    for ref in doc.get("references") or []:
        if ref.reference_doctype == INVOICE and _is_paid(ref.reference_name):
            invoice = frappe.get_doc(INVOICE, ref.reference_name)
            if _has_xtream_items(invoice):
                _enqueue("provision_invoice", invoice_name=invoice.name)


on_invoice_submit = _on_submit
on_invoice_cancel = _on_cancel
on_payment_submit = _on_payment


def _is_paid(name):
    row = frappe.db.get_value(INVOICE, name, ["docstatus", "is_return", "outstanding_amount"], as_dict=True)
    return bool(row) and row.docstatus == 1 and not row.is_return and flt(row.outstanding_amount) <= 0


@frappe.whitelist()
def provision_now(invoice):
    """Button on the invoice: provision (or retry) the Xtream UI Pro items of a paid invoice."""
    frappe.only_for(MANAGER_ROLES)
    doc = frappe.get_doc(INVOICE, invoice)
    doc.check_permission("write")
    if not _is_paid(invoice):
        frappe.throw(_("The invoice must be submitted and paid first."))
    if not _has_xtream_items(doc):
        frappe.throw(_("No item of this invoice is an Xtream UI Pro item."))
    _enqueue("provision_invoice", invoice_name=invoice)
    return {"message": _("Provisioning started. Reload the invoice in a moment.")}


# ---------------------------------------------------------------------------
# Provisioning
# ---------------------------------------------------------------------------

class Report:
    """What one run did, for the comment on the invoice. Never holds a password."""

    def __init__(self):
        self.done = []       # sentences
        self.errors = []     # sentences
        self.new_lines = []  # Line names created in this run (credentials are e-mailed)
        self.new_accounts = []

    def fail(self, text):
        self.errors.append(text)


def provision_invoice(invoice_name):
    """Background job. Safe to run any number of times for the same invoice."""
    inv = frappe.get_doc(INVOICE, invoice_name)
    if not _is_paid(invoice_name):
        return
    work = [(row, cfg) for row in inv.items if (cfg := item_kind(row.item_code))]
    if not work:
        return
    try:
        inv.lock()
    except frappe.DocumentLockedError:
        return          # another job is on it; the hourly catch-up covers a lost event
    report = Report()
    try:
        if not is_configured():
            report.fail(_("Xtream UI Pro is not configured: set the API address and key in Xtream UI Pro Settings."))
        else:
            fatal = None
            for row, cfg in work:
                try:
                    if cfg["kind"] == KIND_RESELLER:
                        fatal = _provision_reseller_row(inv, row, cfg, report, fatal)
                    else:
                        fatal = _provision_line_row(inv, row, cfg, report, fatal)
                except Exception as exc:  # noqa: BLE001 - one row must not stop the others
                    report.fail(_("{0}: {1}").format(row.item_code, failure_text(exc, "provisioning")))
        _finish(inv, report, "provisioning")
    finally:
        inv.unlock()


def _provision_line_row(inv, row, cfg, report, fatal):
    prov = get_provisioner()
    if row.get("xp_renew_line") and not cfg["trial"]:
        return _renew_row(inv, row, row.xp_renew_line, report, prov, fatal)

    quantity = max(1, int(flt(row.qty)))
    existing = frappe.get_all(LINE, filters={"sales_invoice": inv.name, "invoice_item": row.name}, pluck="unit_index")
    for unit in missing_units(existing, quantity):
        frappe.get_doc(dict(
            doctype=LINE, customer=inv.customer, sales_invoice=inv.name, invoice_item=row.name,
            item_code=row.item_code, unit_index=unit, generation=0, package=cfg["package"],
            is_trial=1 if cfg["trial"] else 0, request_id=prov.rid_line(inv.name, row.name, unit, 0),
            status="Draft")).insert(ignore_permissions=True)

    for name in frappe.get_all(LINE, filters={"sales_invoice": inv.name, "invoice_item": row.name,
                                              "status": ["in", ["Draft", "Error"]], "panel_line_id": 0},
                               order_by="unit_index asc", pluck="name"):
        line = frappe.get_doc(LINE, name)
        label = _("{0} (unit {1} of {2})").format(row.item_name or row.item_code, line.unit_index, quantity)
        if fatal:
            line.set_error(fatal[1], "Error")
            report.fail("%s: %s" % (label, fatal[1]))
            continue
        try:
            line.create_on_panel()
        except XtreamProError as exc:
            line.set_error(exc.message, "Error")
            report.fail("%s: %s" % (label, exc.message))
            if exc.code in FATAL_CODES:
                fatal = (exc.code, exc.message)
        else:
            report.done.append(_("line {0} created ({1})").format(line.username, line.name))
            report.new_lines.append(line.name)
        frappe.db.commit()      # keep what the panel did, whatever happens next
    return fatal


def _renew_row(inv, row, line_name, report, prov, fatal):
    label = row.item_name or row.item_code
    if fatal:
        report.fail("%s: %s" % (label, fatal[1]))
        return fatal
    line = frappe.get_doc(LINE, line_name) if frappe.db.exists(LINE, line_name) else None
    if not line or line.customer != inv.customer:
        report.fail(_("{0}: line {1} does not belong to the customer, nothing was renewed.").format(label, line_name))
        return fatal
    request_id = prov.rid_renew_invoice(inv.name, row.name)
    if line.last_renew_request == request_id:
        return fatal        # renewed by an earlier run
    try:
        charged, _balance = line.renew_with(request_id)
    except XtreamProError as exc:
        line.set_error(exc.message)
        report.fail("%s: %s" % (label, exc.message))
        return (exc.code, exc.message) if exc.code in FATAL_CODES else fatal
    report.done.append(_("line {0} renewed (credits charged: {1})").format(line.username, charged))
    frappe.db.commit()
    return fatal


def _next_generation(customer):
    value = frappe.db.sql("select max(generation) from `tabXtream UI Pro Reseller` where customer = %s", customer)[0][0]
    return 0 if value is None else int(value) + 1


def _account_for(inv, prov):
    """The customer's live sub-reseller record, created (with its username and password,
    stored before the panel is called) when there is none."""
    name = frappe.db.get_value(RESELLER, {"customer": inv.customer, "status": ["!=", "Terminated"]})
    if name:
        return frappe.get_doc(RESELLER, name)
    email = customer_email(inv.customer, inv)
    fullname = (inv.customer_name or inv.customer)[:128]
    generation = _next_generation(inv.customer)
    account = frappe.get_doc(dict(
        doctype=RESELLER, customer=inv.customer, sales_invoice=inv.name, email=email, fullname=fullname,
        username=make_username(fullname, email, inv.customer), password=make_password(),
        generation=generation, request_id=prov.rid_reseller(inv.customer, generation),
        status="Draft")).insert(ignore_permissions=True)
    frappe.db.commit()
    return account


def _provision_reseller_row(inv, row, cfg, report, fatal):
    prov = get_provisioner()
    label = row.item_name or row.item_code
    if fatal:
        report.fail("%s: %s" % (label, fatal[1]))
        return fatal
    account = _account_for(inv, prov)
    if not account.panel_user_id and account.status in ("Draft", "Error"):
        try:
            account.create_on_panel()
        except XtreamProError as exc:
            account.set_error(exc.message, "Error")
            report.fail("%s: %s" % (label, exc.message))
            return (exc.code, exc.message) if exc.code in FATAL_CODES else fatal
        report.done.append(_("sub-reseller account {0} created").format(account.username))
        report.new_accounts.append(account.name)
        frappe.db.commit()

    credits = credits_for(cfg["credits"], row.qty)
    if credits > 0 and account.panel_user_id:
        transfer_name = frappe.db.get_value(TRANSFER, {"sales_invoice": inv.name, "invoice_item": row.name,
                                                       "reverses": ["is", "not set"]})
        transfer = frappe.get_doc(TRANSFER, transfer_name) if transfer_name else new_transfer(
            account.name, credits, "Invoice %s" % inv.name,
            request_id=prov.rid_credit(inv.name, row.name, account.generation or 0),
            sales_invoice=inv.name, invoice_item=row.name, item_code=row.item_code)
        if transfer.status != "Done":
            try:
                transfer.run()
            except XtreamProError as exc:
                transfer.set_error(exc.message)
                report.fail(_("{0}: could not hand over {1} credits: {2}").format(label, credits, exc.message))
                return (exc.code, exc.message) if exc.code in FATAL_CODES else fatal
            report.done.append(_("{0} credits handed to {1}").format(credits, account.username))
            frappe.db.commit()
    return fatal


def _finish(inv, report, what):
    if report.errors:
        status = "Partly" if report.done else "Error"
    else:
        status = "Provisioned"
    frappe.db.set_value(INVOICE, inv.name, {"xp_provision_status": status,
                                            "xp_last_error": "\n".join(report.errors)[:1000]}, update_modified=False)
    if report.done or report.errors:
        parts = ["Xtream UI Pro %s: %s" % (what, "; ".join(report.done) or "nothing done")]
        if report.errors:
            parts.append("Problems: %s. Fix the cause and press Xtream UI Pro > Provision again on this invoice (or Retry on the line)." % " | ".join(report.errors))
        inv.add_comment("Comment", scrub(" ".join(parts)))
    if (report.new_lines or report.new_accounts) and settings().email_credentials:
        _email_new_credentials(inv, report)
    frappe.db.commit()


def _email_new_credentials(inv, report):
    """One e-mail with the credentials created in this run, to the customer only."""
    try:
        docs = [frappe.get_doc(LINE, n) for n in report.new_lines] + [frappe.get_doc(RESELLER, n) for n in report.new_accounts]
        entries = []
        for doc in docs:
            if doc.doctype == LINE:
                entries.append(doc.mail_entry())
            else:
                entries.append({"kind": "reseller", "username": doc.username,
                                "password": doc.get_password("password", raise_exception=False),
                                "credits": doc.credits, "panel_url": settings().panel_url})
        email = customer_email(inv.customer, inv)
        send_credentials(email, entries, INVOICE, inv.name)
        for doc in docs:
            doc.db_set("credentials_sent_on", frappe.utils.now_datetime(), update_modified=False)
        inv.add_comment("Comment", "Xtream UI Pro: credentials e-mailed to %s." % email)
    except Exception as exc:  # noqa: BLE001 - the lines exist; only the mail failed
        inv.add_comment("Comment", scrub("Xtream UI Pro: the credentials could not be e-mailed (%s). Use Send credentials on the line or account."
                                         % failure_text(exc, "sending the credentials")))


# ---------------------------------------------------------------------------
# Revoking: cancel and credit note
# ---------------------------------------------------------------------------

def revoke_invoice(invoice_name):
    """Background job: the invoice was cancelled."""
    _revoke(invoice_name, source=invoice_name, qty_by_row=None)


def revoke_return(return_name):
    """Background job: a credit note was submitted against an invoice."""
    ret = frappe.get_doc(INVOICE, return_name)
    if not ret.is_return or not ret.return_against:
        return
    original = frappe.get_doc(INVOICE, ret.return_against)
    qty_by_row = {}
    for r in ret.items:
        target = r.get("sales_invoice_item")
        if not target:      # older data: match by item code
            match = next((o.name for o in original.items if o.item_code == r.item_code), None)
            target = match
        if target:
            qty_by_row[target] = qty_by_row.get(target, 0) + abs(flt(r.qty))
    _revoke(original.name, source=return_name, qty_by_row=qty_by_row)


def _revoke(invoice_name, source, qty_by_row):
    """Disable the lines and take unspent credits back. qty_by_row None = everything (cancel),
    otherwise {invoice row name: returned quantity}."""
    if not is_configured():
        return
    inv = frappe.get_doc(INVOICE, invoice_name)
    prov = get_provisioner()
    report = Report()
    try:
        _revoke_lines(inv, qty_by_row, report)
        _revoke_credits(inv, source, qty_by_row, prov, report)
        _revoke_account(inv, qty_by_row, report)
    except Exception as exc:  # noqa: BLE001
        report.fail(failure_text(exc, "revoking"))
    if report.errors or report.done:
        text = "Xtream UI Pro (%s): %s." % (source, "; ".join(report.done) or "nothing changed")
        if report.errors:
            text += " Problems: %s." % " | ".join(report.errors)
        inv.add_comment("Comment", scrub(text))
    if qty_by_row is None:
        frappe.db.set_value(INVOICE, invoice_name, {"xp_provision_status": "Revoked",
                                                    "xp_last_error": "\n".join(report.errors)[:1000]}, update_modified=False)
    frappe.db.commit()


def _revoke_lines(inv, qty_by_row, report):
    rows = frappe.get_all(LINE, filters={"sales_invoice": inv.name, "status": ["in", ["Active", "Expired"]],
                                         "panel_line_id": [">", 0]},
                          fields=["name", "invoice_item", "unit_index"], order_by="unit_index desc")
    left = None if qty_by_row is None else dict(qty_by_row)
    for r in rows:
        if left is not None:
            if left.get(r.invoice_item, 0) <= 0:
                continue
            left[r.invoice_item] -= 1
        line = frappe.get_doc(LINE, r.name)
        try:
            line.set_enabled(False)
        except XtreamProError as exc:
            line.set_error(exc.message)
            report.fail(_("could not disable line {0}: {1}").format(line.username, exc.message))
        else:
            report.done.append(_("line {0} disabled").format(line.username))


def _reversed_credits(transfer_name):
    value = frappe.db.sql("select coalesce(sum(-credits), 0) from `tabXtream UI Pro Credit Transfer` "
                          "where reverses = %s and status = 'Done'", transfer_name)[0][0]
    return int(value or 0)


def _revoke_credits(inv, source, qty_by_row, prov, report):
    originals = frappe.get_all(TRANSFER, filters={"sales_invoice": inv.name, "reverses": ["is", "not set"],
                                                  "status": "Done", "credits": [">", 0]},
                               fields=["name", "reseller", "invoice_item", "item_code", "credits"])
    for t in originals:
        if qty_by_row is None:
            amount = t.credits
        else:
            ordered = flt(frappe.db.get_value("Sales Invoice Item", t.invoice_item, "qty")) or 1
            returned = min(qty_by_row.get(t.invoice_item, 0), ordered)
            amount = int(round(t.credits * returned / ordered))
        amount = min(amount, t.credits - _reversed_credits(t.name))
        if amount <= 0:
            continue
        reseller = frappe.get_doc(RESELLER, t.reseller)
        request_id = prov.rid_credit_back(source, t.invoice_item, reseller.generation or 0)
        existing = frappe.db.get_value(TRANSFER, {"request_id": request_id})
        back = frappe.get_doc(TRANSFER, existing) if existing else new_transfer(
            reseller.name, -amount, "%s refunded" % source, request_id=request_id,
            sales_invoice=source, invoice_item=t.invoice_item, item_code=t.item_code, reverses=t.name)
        if back.status == "Done":
            continue
        try:
            back.run()
        except XtreamProError as exc:
            back.set_error(exc.message)
            report.fail(_("could not take back {0} credits from {1} (they may be spent already): {2}").format(
                amount, reseller.username, exc.message))
        else:
            report.done.append(_("{0} credits taken back from {1}").format(amount, reseller.username))
        frappe.db.commit()


def _credits_used_by_others(account, invoice_name):
    """Credits of other invoices that are still on the account (so it must stay enabled)."""
    value = frappe.db.sql(
        "select count(*) from `tabXtream UI Pro Credit Transfer` t where t.reseller = %s and t.status = 'Done' "
        "and t.credits > 0 and ifnull(t.sales_invoice, '') != %s and t.reverses is null "
        "and t.credits > (select coalesce(sum(-r.credits), 0) from `tabXtream UI Pro Credit Transfer` r "
        "where r.reverses = t.name and r.status = 'Done')", (account, invoice_name))[0][0]
    return int(value or 0)


def _revoke_account(inv, qty_by_row, report):
    """Disable the sub-reseller account this invoice created once nothing of it is left."""
    for row in inv.items:
        cfg = item_kind(row.item_code)
        if cfg and cfg["kind"] == KIND_RESELLER and qty_by_row is not None and qty_by_row.get(row.name, 0) < flt(row.qty):
            return          # a partial return keeps the account
    for r in frappe.get_all(RESELLER, filters={"sales_invoice": inv.name, "status": "Active"}, pluck="name"):
        account = frappe.get_doc(RESELLER, r)
        own_left = sum(t.credits - _reversed_credits(t.name) for t in frappe.get_all(
            TRANSFER, filters={"sales_invoice": inv.name, "reseller": account.name, "reverses": ["is", "not set"],
                               "status": "Done", "credits": [">", 0]}, fields=["name", "credits"]))
        if own_left > 0 or _credits_used_by_others(account.name, inv.name):
            continue
        try:
            account.set_enabled(False)
        except XtreamProError as exc:
            account.set_error(exc.message)
            report.fail(_("could not disable account {0}: {1}").format(account.username, exc.message))
        else:
            report.done.append(_("sub-reseller account {0} disabled").format(account.username))
