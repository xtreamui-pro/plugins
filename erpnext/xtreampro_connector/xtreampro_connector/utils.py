"""Helpers shared by the Frappe layer: settings, the provisioner, error handling, e-mail.

Rules kept everywhere in this app:
* the API key and line / sub-reseller passwords are never put in a comment, a
  log line, an error text or the Error Log;
* a failure of the panel is kept as a readable text on the record, never lost.
"""

import html
import secrets
import traceback
from datetime import datetime, timezone

import frappe
from frappe import _
from frappe.utils.password import get_decrypted_password

from xtreampro_connector.panel import PanelClient, Provisioner, mask_secrets
from xtreampro_connector.panel.provisioner import explain

SETTINGS = "Xtream UI Pro Settings"
PACKAGE = "Xtream UI Pro Package"
LINE = "Xtream UI Pro Line"
RESELLER = "Xtream UI Pro Reseller"
TRANSFER = "Xtream UI Pro Credit Transfer"

KIND_LINE = "IPTV line"
KIND_RESELLER = "Sub-reseller account"

MANAGER_ROLES = ("Sales Manager", "System Manager")
STAFF_ROLES = ("Sales User", "Sales Manager", "System Manager")


# --- settings ----------------------------------------------------------------

def settings():
    return frappe.get_single(SETTINGS)


def api_key():
    return (get_decrypted_password(SETTINGS, SETTINGS, "api_key", raise_exception=False) or "").strip()


def install_id():
    """Short id of this ERPNext site, part of every request id (set once, never changes)."""
    value = frappe.db.get_single_value(SETTINGS, "install_id")
    if not value:
        value = secrets.token_hex(4)
        frappe.db.set_single_value(SETTINGS, "install_id", value)
    return value


def get_provisioner():
    """Provisioner for the saved settings. A missing address or key fails at the first call
    with the readable NOT_CONFIGURED text."""
    return Provisioner(PanelClient(frappe.db.get_single_value(SETTINGS, "api_url"), api_key()), install_id())


def is_configured():
    return bool((frappe.db.get_single_value(SETTINGS, "api_url") or "").strip() and api_key())


# --- errors ------------------------------------------------------------------

def scrub(text):
    """Remove the API key and any password from a text before it is logged."""
    text = mask_secrets(text or "")
    key = api_key()
    return text.replace(key, "********") if key else text


def failure_text(exc, doing):
    """Readable text for an exception. Unknown ones go to the Error Log (scrubbed)."""
    message = explain(exc)
    if message:
        return message
    frappe.log_error(
        title="Xtream UI Pro: unexpected error while %s" % doing,
        message=scrub("".join(traceback.format_exception(type(exc), exc, exc.__traceback__))),
    )
    return _("Unexpected error while {0}. See the Error Log.").format(doing)


def throw_failure(exc, doing):
    """Stop a whitelisted action with a readable message."""
    frappe.throw(failure_text(exc, doing), title=_("Xtream UI Pro"))


# --- small conversions ---------------------------------------------------------

def from_unix(value):
    """Panel expiry (unix seconds or None) as the naive UTC datetime the Expires (UTC) fields hold."""
    if not value:
        return None
    try:
        return datetime.fromtimestamp(int(value), tz=timezone.utc).replace(tzinfo=None)
    except (TypeError, ValueError, OverflowError, OSError):
        return None


def item_kind(item_code):
    """Xtream UI Pro settings of an Item: None when it is not an Xtream UI Pro item."""
    if not item_code:
        return None
    kind, package, trial, delete, credits = frappe.get_cached_value(
        "Item", item_code, ["xp_kind", "xp_package", "xp_trial", "xp_delete_on_terminate", "xp_credits"]) or (None,) * 5
    if kind == KIND_LINE and package:
        return {"kind": KIND_LINE, "package": package, "trial": bool(trial), "delete": bool(delete), "credits": 0}
    if kind == KIND_RESELLER:
        return {"kind": KIND_RESELLER, "package": None, "trial": False, "delete": False, "credits": max(0, int(credits or 0))}
    return None


def customer_email(customer, invoice=None):
    """Where the customer's credentials go: the invoice's contact, else the customer's own address."""
    email = (invoice.get("contact_email") if invoice else "") or ""
    return email.strip() or (frappe.db.get_value("Customer", customer, "email_id") or "").strip()


# --- e-mail ----------------------------------------------------------------------

def _row(label, value):
    return "<tr><th align='left'>%s</th><td>%s</td></tr>" % (html.escape(label), html.escape(str(value or "")))


def credentials_message(entries):
    """HTML body for the customer. entries: list of dicts with kind 'line' or 'reseller'.

    Everything is escaped. The body holds a password, so it is only ever sent to the
    customer's own address (never to a free-form recipient).
    """
    parts = []
    for e in entries:
        if e["kind"] == "line":
            links = e.get("links") or {}
            rows = [_row(_("Server URL"), links.get("server")), _row(_("Username"), e["username"]),
                    _row(_("Password"), e["password"]), _row(_("Playlist URL"), links.get("m3u")),
                    _row(_("Web player"), links.get("web_player"))]
            title = _("Your IPTV subscription")
        else:
            rows = [_row(_("Username"), e["username"]), _row(_("Password"), e["password"]),
                    _row(_("Credits"), e.get("credits")), _row(_("Sign in"), e.get("panel_url"))]
            title = _("Your reseller account")
        parts.append("<h3>%s</h3><table cellpadding='6'>%s</table>" % (html.escape(title), "".join(rows)))
    return "".join(parts)


def send_credentials(recipient, entries, reference_doctype=None, reference_name=None):
    """E-mail credentials to the customer. Raises frappe.ValidationError without a recipient."""
    if not recipient:
        frappe.throw(_("The customer has no e-mail address."))
    frappe.sendmail(
        recipients=[recipient],
        subject=_("Your Xtream UI Pro sign-in details"),
        message=credentials_message(entries),
        reference_doctype=reference_doctype,
        reference_name=reference_name,
    )
