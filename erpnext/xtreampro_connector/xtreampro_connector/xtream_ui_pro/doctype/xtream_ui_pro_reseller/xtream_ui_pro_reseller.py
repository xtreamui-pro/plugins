"""A sub-reseller account of a customer. One live account per customer: later invoices
top its credits up. Failures of the panel are raised as XtreamProError."""

import frappe
from frappe import _
from frappe.model.document import Document
from frappe.utils import now_datetime

from xtreampro_connector.panel import XtreamProError
from xtreampro_connector.panel.provisioner import make_password, make_username
from xtreampro_connector.utils import (
    RESELLER, customer_email, get_provisioner, send_credentials, settings,
)

STATUS_FROM_PANEL = {"active": "Active", "disabled": "Suspended"}


class XtreamUIProReseller(Document):
    def before_insert(self):
        if self.customer and frappe.db.exists(RESELLER, {"customer": self.customer, "status": ["!=", "Terminated"]}):
            frappe.throw(_("This customer already has a sub-reseller account."))

    # --- state --------------------------------------------------------------
    def apply_fields(self, fields):
        self.last_sync = now_datetime()
        self.last_error = ""
        if "panel_user_id" in fields:
            self.panel_user_id = fields["panel_user_id"]
        if "username" in fields:
            self.username = fields["username"]
        if "password" in fields:
            self.password = fields["password"]
        if fields.get("status") in STATUS_FROM_PANEL:
            self.status = STATUS_FROM_PANEL[fields["status"]]
        if "credits" in fields:
            self.credits = fields["credits"]

    def set_error(self, text, status=None):
        values = {"last_error": text, "last_sync": now_datetime()}
        if status:
            values["status"] = status
        self.db_set(values, update_modified=False)

    def _need_panel_user(self):
        if not self.panel_user_id:
            raise XtreamProError("RESOURCE_NOT_FOUND", message="This account has not been created on the panel yet.")

    # --- panel operations ------------------------------------------------------
    def create_on_panel(self):
        """Create the account with the username and password stored on this record (they were
        stored before the first call, so a retry sends the very same values)."""
        if self.panel_user_id:
            return
        fields = get_provisioner().create_reseller(
            self.username, self.get_password("password", raise_exception=False),
            self.email, self.fullname or self.customer, self.request_id, self.generation or 0)
        self.apply_fields(fields)
        if self.status in ("Draft", "Error", "Terminated"):
            self.status = "Active"
        self.save(ignore_permissions=True)

    def refresh_from_panel(self):
        self._need_panel_user()
        self.apply_fields(get_provisioner().read_reseller(self.panel_user_id, self.username))
        self.save(ignore_permissions=True)

    def set_enabled(self, enabled):
        self._need_panel_user()
        get_provisioner().set_reseller_enabled(self.panel_user_id, enabled)
        self.status = "Active" if enabled else "Suspended"
        self.last_sync = now_datetime()
        self.save(ignore_permissions=True)
        try:
            self.refresh_from_panel()
        except XtreamProError:
            pass        # the action itself worked

    def terminate(self):
        """The panel cannot delete accounts: this disables it and closes the record, so the
        next creation is a new generation (new username, tagged e-mail, new account)."""
        if self.panel_user_id:
            get_provisioner().terminate_reseller(self.panel_user_id)
        previous = self.username
        self.generation = (self.generation or 0) + 1
        self.request_id = get_provisioner().rid_reseller(self.customer, self.generation)
        self.username = make_username(self.fullname or self.customer, self.email, self.name)
        self.password = make_password()
        self.panel_user_id = ""
        self.status = "Terminated"
        self.last_error = ""
        self.last_sync = now_datetime()
        self.save(ignore_permissions=True)
        self.add_comment("Info", "Account %s disabled on the panel and closed." % previous)

    def retry(self):
        if self.panel_user_id:
            raise XtreamProError("CONFLICT", message="This account already exists on the panel.")
        self.create_on_panel()

    def send_credentials(self):
        email = customer_email(self.customer)
        entry = {"kind": "reseller", "username": self.username,
                 "password": self.get_password("password", raise_exception=False),
                 "credits": self.credits, "panel_url": settings().panel_url}
        send_credentials(email, [entry], RESELLER, self.name)
        self.db_set("credentials_sent_on", now_datetime(), update_modified=False)
        self.add_comment("Info", "Credentials e-mailed to %s." % email)
        return email
