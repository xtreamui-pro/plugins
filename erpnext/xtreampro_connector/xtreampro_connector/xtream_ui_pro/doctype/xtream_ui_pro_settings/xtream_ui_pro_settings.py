import secrets

import frappe
from frappe import _
from frappe.model.document import Document

from xtreampro_connector.panel import normalize_base


class XtreamUIProSettings(Document):
    def validate(self):
        base = normalize_base(self.api_url)
        if base and not base.lower().startswith(("http://", "https://")):
            frappe.throw(_("The API address must start with http:// or https://."))
        self.api_url = base
        self.panel_url = (self.panel_url or "").strip().rstrip("/")
        if self.panel_url and not self.panel_url.lower().startswith(("http://", "https://")):
            frappe.throw(_("The panel address must start with http:// or https://."))
        if not self.install_id:
            self.install_id = secrets.token_hex(4)
