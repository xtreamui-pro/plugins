"""Custom fields of the app. Created on install, re-applied on every migrate (so an upgrade
adds new fields) and removed again when the app is uninstalled."""

import frappe
from frappe.custom.doctype.custom_field.custom_field import create_custom_fields

LINE_ONLY = "eval:doc.xp_kind=='IPTV line'"
RESELLER_ONLY = "eval:doc.xp_kind=='Sub-reseller account'"


def _fields():
    return {
        "Item": [
            dict(fieldname="xp_section", fieldtype="Section Break", label="Xtream UI Pro", collapsible=1,
                 insert_after="description"),
            dict(fieldname="xp_kind", fieldtype="Select", label="Xtream UI Pro type",
                 options="\nIPTV line\nSub-reseller account", insert_after="xp_section",
                 description="IPTV line: a paid invoice creates one line per unit with the package below. "
                             "Sub-reseller account: a paid invoice creates the customer's reseller account (once) and hands over the credits."),
            dict(fieldname="xp_package", fieldtype="Link", label="Xtream UI Pro package", options="Xtream UI Pro Package",
                 depends_on=LINE_ONLY, mandatory_depends_on=LINE_ONLY, insert_after="xp_kind",
                 description="Package of the line. Run Sync packages in Xtream UI Pro Settings if it is not listed."),
            dict(fieldname="xp_trial", fieldtype="Check", label="Trial line", depends_on=LINE_ONLY, insert_after="xp_package",
                 description="Create a trial line (uses the package's trial settings and trial credits)."),
            dict(fieldname="xp_delete_on_terminate", fieldtype="Check", label="Delete permanently on terminate", default="0",
                 depends_on=LINE_ONLY, insert_after="xp_trial",
                 description="Off (default): Terminate only disables the line on the panel; it can be enabled again. On: Terminate deletes the line on the panel - deleting is final, nothing can be brought back. A refund or cancelled invoice never deletes."),
            dict(fieldname="xp_credits", fieldtype="Int", label="Credits per unit", default="0", depends_on=RESELLER_ONLY,
                 insert_after="xp_delete_on_terminate",
                 description="Credits handed to the customer's reseller account per unit sold, taken from your balance on the panel. 0 creates the account without credits."),
        ],
        "Sales Invoice Item": [
            dict(fieldname="xp_renew_line", fieldtype="Link", label="Renew Xtream UI Pro line", options="Xtream UI Pro Line",
                 insert_after="description", no_copy=1,
                 description="Optional: renew this existing line of the customer instead of creating a new one when the invoice is paid."),
        ],
        "Sales Invoice": [
            dict(fieldname="xp_section", fieldtype="Section Break", label="Xtream UI Pro", collapsible=1,
                 insert_after=_after("Sales Invoice", ("remarks", "more_info", "amended_from"))),
            dict(fieldname="xp_provision_status", fieldtype="Select", label="Xtream UI Pro status",
                 options="\nProvisioned\nPartly\nError\nRevoked", read_only=1, no_copy=1, allow_on_submit=1,
                 insert_after="xp_section"),
            dict(fieldname="xp_last_error", fieldtype="Small Text", label="Xtream UI Pro last error", read_only=1,
                 no_copy=1, allow_on_submit=1, insert_after="xp_provision_status"),
        ],
    }


def _after(doctype, candidates):
    """First of the candidate fields that exists on the doctype (the layout differs a little between versions)."""
    meta = frappe.get_meta(doctype)
    for name in candidates:
        if meta.has_field(name):
            return name
    return meta.fields[-1].fieldname


def _apply():
    create_custom_fields(_fields(), ignore_validate=True, update=True)
    frappe.clear_cache()


def after_install():
    _apply()


def after_migrate():
    _apply()


def before_uninstall():
    for doctype, fields in _fields().items():
        for field in fields:
            name = frappe.db.get_value("Custom Field", {"dt": doctype, "fieldname": field["fieldname"]})
            if name:
                frappe.delete_doc("Custom Field", name, ignore_permissions=True)
    frappe.clear_cache()
