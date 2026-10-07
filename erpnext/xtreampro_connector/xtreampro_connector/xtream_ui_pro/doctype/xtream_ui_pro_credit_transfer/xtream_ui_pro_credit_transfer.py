"""Record of one adjust_credits call. Its request id is what the panel de-duplicates on, so a
transfer that is retried never pays twice."""

import frappe
from frappe.model.document import Document
from frappe.utils import now_datetime

from xtreampro_connector.panel import XtreamProError
from xtreampro_connector.utils import RESELLER, TRANSFER, get_provisioner


class XtreamUIProCreditTransfer(Document):
    def set_error(self, text):
        self.db_set({"status": "Error", "last_error": text}, update_modified=False)

    def run(self):
        """Send the transfer to the panel. Raises XtreamProError and changes nothing on failure."""
        if self.status == "Done":
            return
        reseller = frappe.get_doc(RESELLER, self.reseller)
        if not reseller.panel_user_id:
            raise XtreamProError("RESOURCE_NOT_FOUND", message="The sub-reseller account has not been created on the panel yet.")
        result = get_provisioner().adjust_credits(reseller.panel_user_id, self.credits, self.note, self.request_id)
        self.status = "Done"
        self.last_error = ""
        self.transferred_on = now_datetime()
        if result["balance"] is not None:
            self.balance_after = result["balance"]
            reseller.db_set({"credits": result["balance"], "last_sync": now_datetime()}, update_modified=False)
        self.save(ignore_permissions=True)


def new_transfer(reseller, credits, note, request_id=None, **links):
    """Create a Draft transfer. Without a request id (a manual transfer) the id is derived
    from the new record's name."""
    doc = frappe.get_doc(dict(doctype=TRANSFER, reseller=reseller, credits=credits, note=(note or "")[:140],
                              request_id=request_id, status="Draft", **links))
    doc.insert(ignore_permissions=True)
    if not request_id:
        doc.request_id = get_provisioner().rid_credit_manual(doc.name)
        doc.db_set("request_id", doc.request_id, update_modified=False)
    return doc
