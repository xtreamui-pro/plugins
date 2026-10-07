from odoo import fields, models


class ProductTemplate(models.Model):
    _inherit = "product.template"

    xtreampro_kind = fields.Selection(
        [("line", "IPTV line"), ("reseller", "Sub-reseller account")],
        string="Panel product",
        default="line",
        required=True,
        help="IPTV line: confirming an order creates subscriber lines (needs a panel package). "
             "Sub-reseller account: confirming an order creates a reseller account below your "
             "panel reseller (once per customer) and hands it the credits set here.",
    )
    xtreampro_package_id = fields.Many2one(
        "xtreampro.package",
        string="Panel package",
        ondelete="restrict",
        domain="[('sells_line', '=', True)]",
        help="When set, confirming a sale order creates one IPTV line per unit on the Xtream UI Pro panel. "
             "Only packages the panel sells as a plain line are offered (not those for MAG / Enigma boxes only).",
    )
    xtreampro_trial = fields.Boolean(
        string="Create as trial",
        help="Create the lines as trial lines (charged at the package's trial price).",
    )
    xtreampro_credits = fields.Integer(
        string="Credits per unit",
        help="Credits handed to the customer's sub-reseller account for each unit sold "
             "(taken from your own credits on the panel). 0 only creates the account.",
    )

    xtreampro_group_id = fields.Many2one(
        "xtreampro.group",
        string="Sub-reseller group",
        ondelete="restrict",
        help="Group of the sub-reseller accounts this product creates. Empty = the first group your "
             "reseller group allows on the panel. Sync the packages to load the groups.",
    )
    xtreampro_on_cancel = fields.Selection(
        [("disable", "Disable (default)"), ("delete", "Delete permanently")],
        string="On cancellation",
        default="disable",
        required=True,
        help="What cancelling a sale order does on the panel to the line (or sub-reseller account) it "
             "created. Disable can be undone. Delete is FINAL: the line and everything recorded about its "
             "customer are erased on the panel and cannot be restored (a sub-reseller account's credits, "
             "lines and sub-accounts go to your reseller account).",
    )

    _sql_constraints = [
        ("xtreampro_credits_positive", "check(xtreampro_credits >= 0)", "The credits per unit cannot be negative."),
    ]
