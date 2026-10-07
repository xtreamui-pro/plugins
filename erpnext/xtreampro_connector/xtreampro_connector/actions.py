"""Whitelisted methods behind the buttons of the forms.

Every method checks the caller's role first, loads the record from the database by its name
(never trusting field values sent by the browser) and checks the document permission. A
failure of the panel is shown as the readable message; nothing here puts a password in a
message.

Roles: Sales Manager / System Manager run the actions, Sales User may only e-mail the
credentials to the customer on file. The settings (API address and key) are System Manager
only, so nobody else can point the stored key at another host.
"""

import frappe
from frappe import _

from xtreampro_connector.panel import XtreamProError
from xtreampro_connector.utils import (
    LINE, MANAGER_ROLES, RESELLER, SETTINGS, STAFF_ROLES, TRANSFER, get_provisioner, throw_failure,
)
from xtreampro_connector.xtream_ui_pro.doctype.xtream_ui_pro_credit_transfer.xtream_ui_pro_credit_transfer import new_transfer
from xtreampro_connector.xtream_ui_pro.doctype.xtream_ui_pro_package.xtream_ui_pro_package import sync


def _load(doctype, name, roles=MANAGER_ROLES, ptype="write"):
    frappe.only_for(roles)
    doc = frappe.get_doc(doctype, name)
    doc.check_permission(ptype)
    return doc


def _run(doing, fn):
    """Run a panel operation; turn a failure into a message for the user."""
    try:
        return fn()
    except XtreamProError as exc:
        frappe.throw(exc.message, title=_("Xtream UI Pro"))
    except frappe.ValidationError:
        raise
    except Exception as exc:  # noqa: BLE001 - logged scrubbed, shown generically
        throw_failure(exc, doing)


def _done(message):
    return {"message": message}


# --- settings -------------------------------------------------------------------

@frappe.whitelist()
def test_connection():
    frappe.only_for("System Manager")
    info = _run("testing the connection", lambda: get_provisioner().test_connection())
    text = _("Connected as {0}. Credits on the panel: {1}.").format(info.get("username") or "?", info["credits"])
    frappe.db.set_single_value(SETTINGS, "last_result", text)
    return _done(text)


@frappe.whitelist()
def sync_packages():
    frappe.only_for("System Manager")
    rows = _run("syncing the packages", lambda: get_provisioner().packages())
    created, updated, switched_off = sync(rows)
    text = _("Packages synced: {0} created, {1} updated, {2} no longer offered.").format(created, updated, switched_off)
    frappe.db.set_single_value(SETTINGS, "last_result", text)
    return _done(text)


# --- lines --------------------------------------------------------------------------

@frappe.whitelist()
def line_renew(name):
    doc = _load(LINE, name)
    charged, balance = _run("renewing the line", doc.renew)
    doc.add_comment("Info", "Line %s renewed on the panel (credits charged: %s, balance: %s)." % (doc.username, charged, balance))
    return _done(_("Line renewed. Credits charged: {0}.").format(charged))


@frappe.whitelist()
def line_suspend(name):
    doc = _load(LINE, name)
    _run("suspending the line", lambda: doc.set_enabled(False))
    doc.add_comment("Info", "Line %s suspended." % doc.username)
    return _done(_("Line suspended."))


@frappe.whitelist()
def line_unsuspend(name):
    doc = _load(LINE, name)
    _run("reactivating the line", lambda: doc.set_enabled(True))
    doc.add_comment("Info", "Line %s reactivated." % doc.username)
    return _done(_("Line reactivated."))


@frappe.whitelist()
def line_refresh(name):
    doc = _load(LINE, name)
    _run("refreshing the line", doc.refresh_from_panel)
    return _done(_("Line refreshed."))


@frappe.whitelist()
def line_terminate(name):
    doc = _load(LINE, name)
    _run("terminating the line", doc.terminate)
    return _done(_("Line terminated."))


@frappe.whitelist()
def line_retry(name):
    doc = _load(LINE, name)
    _run("creating the line", doc.retry)
    doc.add_comment("Info", "Line %s created on the panel." % doc.username)
    return _done(_("Line created."))


@frappe.whitelist()
def line_send_credentials(name):
    doc = _load(LINE, name, STAFF_ROLES, "read")
    email = _run("sending the credentials", doc.send_credentials)
    return _done(_("Credentials e-mailed to {0}.").format(email))


@frappe.whitelist()
def line_links(name):
    """Play links with the password in them: managers only."""
    doc = _load(LINE, name, MANAGER_ROLES, "read")
    return _run("reading the play links", doc.links)


# --- sub-resellers ----------------------------------------------------------------------

@frappe.whitelist()
def reseller_suspend(name):
    doc = _load(RESELLER, name)
    _run("suspending the account", lambda: doc.set_enabled(False))
    doc.add_comment("Info", "Sub-reseller %s suspended." % doc.username)
    return _done(_("Account suspended."))


@frappe.whitelist()
def reseller_unsuspend(name):
    doc = _load(RESELLER, name)
    _run("reactivating the account", lambda: doc.set_enabled(True))
    doc.add_comment("Info", "Sub-reseller %s reactivated." % doc.username)
    return _done(_("Account reactivated."))


@frappe.whitelist()
def reseller_refresh(name):
    doc = _load(RESELLER, name)
    _run("refreshing the account", doc.refresh_from_panel)
    return _done(_("Account refreshed."))


@frappe.whitelist()
def reseller_terminate(name):
    doc = _load(RESELLER, name)
    _run("terminating the account", doc.terminate)
    return _done(_("Account disabled and closed."))


@frappe.whitelist()
def reseller_retry(name):
    doc = _load(RESELLER, name)
    _run("creating the account", doc.retry)
    doc.add_comment("Info", "Sub-reseller account %s created on the panel." % doc.username)
    return _done(_("Account created."))


@frappe.whitelist()
def reseller_send_credentials(name):
    doc = _load(RESELLER, name, STAFF_ROLES, "read")
    email = _run("sending the credentials", doc.send_credentials)
    return _done(_("Credentials e-mailed to {0}.").format(email))


def _manual_credits(name, credits, note, sign):
    doc = _load(RESELLER, name)
    credits = int(credits or 0)
    if credits <= 0:
        frappe.throw(_("Enter a number of credits above zero."))
    transfer = new_transfer(doc.name, sign * credits, (note or "").strip() or "Manual transfer from ERPNext")
    _run("moving the credits", transfer.run)
    return transfer


@frappe.whitelist()
def reseller_add_credits(name, credits, note=None):
    transfer = _manual_credits(name, credits, note, 1)
    return _done(_("{0} credits handed over. Balance: {1}.").format(transfer.credits, transfer.balance_after))


@frappe.whitelist()
def reseller_take_credits(name, credits, note=None):
    transfer = _manual_credits(name, credits, note, -1)
    return _done(_("{0} credits taken back. Balance: {1}.").format(-transfer.credits, transfer.balance_after))


@frappe.whitelist()
def transfer_retry(name):
    doc = _load(TRANSFER, name)
    _run("moving the credits", doc.run)
    return _done(_("Credit transfer done."))
