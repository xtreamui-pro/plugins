"""Customer portal page /iptv: the signed-in customer's own lines and sub-reseller account.

Only records of the Customers the signed-in user is a contact of are shown, and each one is
looked up by that filter on the server; nothing is taken from the request.
"""

import frappe
from frappe import _

from xtreampro_connector.panel import XtreamProError
from xtreampro_connector.utils import LINE, RESELLER, get_provisioner, settings

no_cache = 1
MAX_LINES = 20


def _customers_of(user):
    contacts = frappe.get_all("Contact", filters={"user": user}, pluck="name")
    if not contacts:
        return []
    return frappe.get_all("Dynamic Link", filters={"parenttype": "Contact", "parent": ["in", contacts],
                                                   "link_doctype": "Customer"}, pluck="link_name")


def get_context(context):
    if frappe.session.user == "Guest":
        frappe.throw(_("Please sign in to see your IPTV subscriptions."), frappe.PermissionError)
    context.title = _("My IPTV")
    context.lines, context.accounts, context.error = [], [], ""
    customers = _customers_of(frappe.session.user)
    if not customers:
        return

    prov = get_provisioner()
    for row in frappe.get_all(LINE, filters={"customer": ["in", customers], "status": ["not in", ["Draft", "Error", "Terminated"]]},
                              fields=["name", "username", "status", "expiry", "max_connections"],
                              order_by="creation desc", limit=MAX_LINES):
        doc = frappe.get_doc(LINE, row.name)
        links = {}
        try:
            links = prov.read_line(doc.panel_line_id).get("links") or {}
        except XtreamProError as exc:
            context.error = exc.message
        context.lines.append({
            "username": row.username, "password": doc.get_password("password", raise_exception=False),
            "status": row.status, "expiry": row.expiry, "max_connections": row.max_connections,
            "server": links.get("server") or "", "playlist": links.get("m3u") or "", "player": links.get("web_player") or "",
        })

    panel_url = settings().panel_url
    for row in frappe.get_all(RESELLER, filters={"customer": ["in", customers], "status": ["in", ["Active", "Suspended"]]},
                              fields=["name", "username", "status", "credits"], limit=MAX_LINES):
        doc = frappe.get_doc(RESELLER, row.name)
        context.accounts.append({
            "username": row.username, "password": doc.get_password("password", raise_exception=False),
            "status": row.status, "credits": row.credits, "panel_url": panel_url,
        })
