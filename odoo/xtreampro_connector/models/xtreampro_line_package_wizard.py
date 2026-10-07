from odoo import _, api, fields, models
from odoo.exceptions import UserError

from .xtreampro_api import XtreamProError, describe_compatibility


class XtreamProLinePackageWizard(models.TransientModel):
    _name = "xtreampro.line.package.wizard"
    _description = "Upgrade or downgrade an IPTV line"

    line_id = fields.Many2one("xtreampro.line", required=True, readonly=True)
    current_package_id = fields.Many2one(related="line_id.package_id", string="Current package")
    package_id = fields.Many2one("xtreampro.package", string="New package", required=True,
                                 domain="[('is_official', '=', True), ('sells_line', '=', True)]")
    price = fields.Float(related="package_id.official_credits", string="Price (credits)")
    compat_note = fields.Text(
        string="What the panel will do", compute="_compute_compat_note",
        help="Asked from the panel without selling anything: whether the time left on the line is kept or "
             "the new period starts today, and what the change costs.")

    @api.depends("line_id", "package_id")
    def _compute_compat_note(self):
        for rec in self:
            rec.compat_note = rec._compat_text()

    def _compat_text(self):
        self.ensure_one()
        if not (self.line_id and self.package_id and self.line_id.panel_line_id):
            return False
        try:
            compat = self.env["xtreampro.api"]._package_compatibility(self.line_id.panel_line_id, self.package_id.panel_id)
        except XtreamProError as e:
            return e.message
        except Exception:  # a preview must never break the form
            return _("The panel could not be asked what this change would do.")
        if compat is None:
            return _("This panel is too old to say beforehand whether the time left is kept; it decides when "
                     "the change is made.")
        text = describe_compatibility(compat)
        if compat.get("can_afford") is False:
            text += " " + _("The reseller account cannot pay it.")
        return text

    def action_apply(self):
        self.env["xtreampro.api"]._require_manager()
        self.ensure_one()
        try:
            self.line_id._change_package_on_panel(self.package_id)
        except XtreamProError as e:
            raise UserError(e.message) from None
        return {"type": "ir.actions.act_window_close"}
