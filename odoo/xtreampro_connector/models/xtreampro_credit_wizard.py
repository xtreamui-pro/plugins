from odoo import _, api, fields, models
from odoo.exceptions import UserError

from .xtreampro_api import XtreamProError

# Outcome of the call is unknown: keep the transfer so a retry reuses its request id.
UNCERTAIN_CODES = {"TIMEOUT", "NETWORK", "BAD_RESPONSE", "SERVER_ERROR"}


class XtreamProCreditWizard(models.TransientModel):
    _name = "xtreampro.credit.wizard"
    _description = "Add or take back sub-reseller credits"

    reseller_id = fields.Many2one("xtreampro.reseller", required=True, readonly=True)
    direction = fields.Selection([("add", "Add credits"), ("take", "Take back credits")],
                                 required=True, default="add", readonly=True)
    credits = fields.Integer(required=True, default=1)
    note = fields.Char(size=200)

    def action_apply(self):
        self.env["xtreampro.api"]._require_manager()
        self.ensure_one()
        reseller = self.reseller_id
        if self.credits <= 0:
            raise UserError(_("Enter a number of credits greater than zero."))
        if not reseller.panel_user_id:
            raise UserError(_("This account has not been created on the panel yet."))
        transfer = self.env["xtreampro.credit.transfer"].create({
            "reseller_id": reseller.id,
            "credits": self.credits if self.direction == "add" else -self.credits,
            "note": self.note or False,
        })
        transfer.request_id = "odoo-subm-%s" % transfer.id
        try:
            with self.env.cr.savepoint():
                transfer._run()
        except XtreamProError as e:
            if e.code not in UNCERTAIN_CODES:
                raise UserError(e.message) from None  # rolls the transfer back: nothing happened
            transfer.write({"state": "error", "last_error": e.message})
            return {
                "type": "ir.actions.client",
                "tag": "display_notification",
                "params": {
                    "title": _("Xtream UI Pro"),
                    "message": _("%s The transfer was kept; press Retry on it to send it again safely.", e.message),
                    "type": "warning", "sticky": True,
                    "next": {"type": "ir.actions.act_window_close"},
                },
            }
        transfer._log_done()
        return {"type": "ir.actions.act_window_close"}
