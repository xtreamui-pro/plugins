import logging

from markupsafe import Markup

from odoo import _, api, fields, models
from odoo.exceptions import UserError, ValidationError

from .xtreampro_api import XtreamProError
from .xtreampro_reseller import _to_int, error_text

_logger = logging.getLogger(__name__)


class XtreamProCreditTransfer(models.Model):
    """Idempotency record of one adjust_credits call (its request id is what the panel de-duplicates on)."""

    _name = "xtreampro.credit.transfer"
    _description = "Xtream UI Pro credit transfer"
    _order = "id desc"

    reseller_id = fields.Many2one("xtreampro.reseller", required=True, index=True, ondelete="cascade")
    company_id = fields.Many2one(related="reseller_id.company_id", store=True, index=True)
    sale_line_id = fields.Many2one("sale.order.line", string="Sale order line", index=True, ondelete="set null", copy=False)
    sale_order_id = fields.Many2one(related="sale_line_id.order_id", store=True, index=True)
    credits = fields.Integer(help="Positive: handed to the account. Negative: taken back from it.")
    state = fields.Selection([("draft", "Draft"), ("done", "Done"), ("error", "Error")],
                             default="draft", required=True, copy=False, index=True)
    request_id = fields.Char(copy=False, readonly=True)
    note = fields.Char()
    last_error = fields.Text(copy=False, readonly=True)
    date = fields.Datetime(default=fields.Datetime.now, copy=False)

    _sql_constraints = [
        ("request_id_uniq", "unique(request_id)", "A credit transfer with this request id already exists."),
    ]

    @api.constrains("credits")
    def _check_credits(self):
        if any(rec.credits == 0 for rec in self):
            raise ValidationError(_("The credits of a transfer cannot be zero."))

    def _run(self):
        """Send the transfer to the panel. Raises XtreamProError / UserError, changes nothing on failure."""
        self.ensure_one()
        reseller = self.reseller_id
        if not reseller.panel_user_id:
            raise UserError(_("The sub-reseller account has not been created on the panel yet."))
        if self.credits > 0 and not self.env.context.get("xtreampro_prechecked"):
            self.env["xtreampro.api"]._check_needs([{"kind": "reseller", "create": False, "credits": self.credits}])
        data = self.env["xtreampro.api"]._adjust_credits(
            reseller.panel_user_id, self.credits, self.note, self.request_id)
        target = data.get("target_balance") if isinstance(data, dict) else None
        self.write({"state": "done", "last_error": False, "date": fields.Datetime.now()})
        if target is not None:
            reseller.write({"credits": _to_int(target), "last_sync": fields.Datetime.now()})
        return data

    def _log_done(self):
        for rec in self:
            verb = "handed over" if rec.credits > 0 else "taken back"
            rec.reseller_id._log_message("Xtream UI Pro: %s credits %s for sub-reseller <b>%s</b> (balance now: %s).",
                                         abs(rec.credits), verb, rec.reseller_id.username or "", rec.reseller_id.credits)

    def _run_for_order(self, order, product_name):
        """Run a transfer created by an order; failures are kept on the record and noted on the order."""
        self.ensure_one()
        try:
            with self.env.cr.savepoint():
                self._run()
        except Exception as e:
            message = error_text(e)
            if not message:
                _logger.exception("Xtream UI Pro credit transfer failed for order %s", order.id)
                message = _("Unexpected error while handing over credits.")
            self.write({"state": "error", "last_error": message})
            order.message_post(body=Markup(
                "Xtream UI Pro: could not hand %s credits to the sub-reseller for <b>%s</b>: %s "
                "Open the sub-reseller and press Retry on the credit transfer once the problem is fixed.")
                % (self.credits, product_name, message))
            return False
        self._log_done()
        return True

    def action_retry(self):
        self.env["xtreampro.api"]._require_manager()
        for rec in self.filtered(lambda t: t.state in ("draft", "error")):
            try:
                rec._run()
            except XtreamProError as e:
                raise UserError(e.message) from None
            rec._log_done()
        return True
