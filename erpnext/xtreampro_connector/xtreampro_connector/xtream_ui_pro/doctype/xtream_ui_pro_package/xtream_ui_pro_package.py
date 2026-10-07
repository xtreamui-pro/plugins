import frappe
from frappe.model.document import Document

PACKAGE = "Xtream UI Pro Package"


class XtreamUIProPackage(Document):
    pass


def sync(rows):
    """Upsert the panel's packages (rows from Provisioner.packages) and switch off the ones
    the panel no longer lists. Returns (created, updated, switched_off)."""
    existing = {r.panel_id: r for r in frappe.get_all(PACKAGE, fields=["name", "panel_id", "enabled"])}
    seen, created, updated = set(), 0, 0
    for row in rows:
        seen.add(row["panel_id"])
        values = dict(row, enabled=1)
        current = existing.get(row["panel_id"])
        if current:
            frappe.db.set_value(PACKAGE, current.name, values)
            updated += 1
        else:
            frappe.get_doc(dict(doctype=PACKAGE, **values)).insert(ignore_permissions=True)
            created += 1
    switched_off = 0
    for panel_id, current in existing.items():
        if panel_id not in seen and current.enabled:
            frappe.db.set_value(PACKAGE, current.name, "enabled", 0)
            switched_off += 1
    return created, updated, switched_off
