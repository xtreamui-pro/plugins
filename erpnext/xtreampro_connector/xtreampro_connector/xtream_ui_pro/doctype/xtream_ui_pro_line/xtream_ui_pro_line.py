"""One IPTV line sold through an invoice.

The methods below raise XtreamProError for a failure of the panel; the callers
(invoices.py, actions.py, tasks.py) decide whether to keep the text on the record or
to show it to the user.
"""

import frappe
from frappe.model.document import Document
from frappe.utils import now_datetime, nowdate

from xtreampro_connector.panel import XtreamProError
from xtreampro_connector.utils import (
    LINE, PACKAGE, customer_email, from_unix, get_provisioner, item_kind, send_credentials,
)

STATUS_FROM_PANEL = {"active": "Active", "expired": "Expired", "disabled": "Suspended", "banned": "Banned"}


class XtreamUIProLine(Document):
    # --- state --------------------------------------------------------------
    def apply_fields(self, fields):
        """Store what the provisioner returned. Only keys the panel sent are touched."""
        self.last_sync = now_datetime()
        self.last_error = ""
        if "panel_line_id" in fields:
            self.panel_line_id = fields["panel_line_id"]
        if "username" in fields:
            self.username = fields["username"]
        if "password" in fields:
            self.password = fields["password"]      # a Password field: stored encrypted
        if fields.get("status") in STATUS_FROM_PANEL:
            self.status = STATUS_FROM_PANEL[fields["status"]]
        if "expiry" in fields:
            self.expiry = from_unix(fields["expiry"])
        if "max_connections" in fields:
            self.max_connections = fields["max_connections"]
        if "is_trial" in fields:
            self.is_trial = 1 if fields["is_trial"] else 0
        if "package_id" in fields:
            package = frappe.db.get_value(PACKAGE, {"panel_id": fields["package_id"]})
            if package:
                self.package = package

    def set_error(self, text, status=None):
        """Keep a failure on the record (its own write: it survives a later rollback of the caller)."""
        values = {"last_error": text, "last_sync": now_datetime()}
        if status:
            values["status"] = status
        self.db_set(values, update_modified=False)

    def _need_panel_line(self):
        if not self.panel_line_id:
            raise XtreamProError("RESOURCE_NOT_FOUND", message="This line has not been created on the panel yet.")

    # --- panel operations ------------------------------------------------------
    def create_on_panel(self):
        if self.panel_line_id:
            return
        package_id = frappe.db.get_value(PACKAGE, self.package, "panel_id") if self.package else 0
        fields = get_provisioner().create_line(package_id, self.is_trial, self.request_id)
        self.apply_fields(fields)
        if self.status in ("Draft", "Error", "Terminated"):
            self.status = "Active"
        self.save(ignore_permissions=True)

    def refresh_from_panel(self):
        self._need_panel_line()
        self.apply_fields(get_provisioner().read_line(self.panel_line_id))
        self.save(ignore_permissions=True)

    def renew_with(self, request_id):
        """Renew (charged). A request id that was used before charges nothing again."""
        self._need_panel_line()
        fields = get_provisioner().renew_line(self.panel_line_id, request_id)
        charged, balance = fields.pop("credits_charged", None), fields.pop("credits_balance", None)
        self.apply_fields(fields)
        self.last_renew_request = request_id
        self.save(ignore_permissions=True)
        return charged, balance

    def renew(self):
        # One manual renewal per line and day: a double click must not charge twice.
        return self.renew_with(get_provisioner().rid_renew_manual(self.name, nowdate().replace("-", "")))

    def set_enabled(self, enabled):
        self._need_panel_line()
        fields = get_provisioner().set_line_enabled(self.panel_line_id, enabled)
        fields.setdefault("status", "active" if enabled else "disabled")
        self.apply_fields(fields)
        self.save(ignore_permissions=True)

    def terminate(self, delete=None):
        """Delete the line on the panel (final) or only disable it, then close the record:
        the next creation is a new generation, i.e. a new line."""
        if delete is None:
            info = item_kind(self.item_code)
            delete = info["delete"] if info else False
        if self.panel_line_id:
            get_provisioner().terminate_line(self.panel_line_id, delete)
        previous = self.panel_line_id
        self.generation = (self.generation or 0) + 1
        if self.sales_invoice and self.invoice_item:
            self.request_id = get_provisioner().rid_line(self.sales_invoice, self.invoice_item, self.unit_index, self.generation)
        self.panel_line_id = 0
        self.status = "Terminated"
        self.last_sync = now_datetime()
        self.last_error = ""
        self.save(ignore_permissions=True)
        self.add_comment("Info", "Line #%s %s on the panel." % (previous, "deleted" if delete else "disabled"))

    def retry(self):
        if self.panel_line_id:
            raise XtreamProError("CONFLICT", message="This line already exists on the panel.")
        self.create_on_panel()

    def links(self):
        """Play links as the panel gives them (they carry the password: for managers only)."""
        self._need_panel_line()
        return get_provisioner().read_line(self.panel_line_id).get("links") or {}

    def mail_entry(self):
        """Credentials as the e-mail needs them (never log this)."""
        try:
            links = self.links()
        except XtreamProError:
            links = {}
        return {"kind": "line", "username": self.username,
                "password": self.get_password("password", raise_exception=False), "links": links}

    def send_credentials(self):
        email = customer_email(self.customer)
        send_credentials(email, [self.mail_entry()], LINE, self.name)
        self.db_set("credentials_sent_on", now_datetime(), update_modified=False)
        self.add_comment("Info", "Credentials e-mailed to %s." % email)
        return email
