from odoo import fields, models


class XtreamProGroup(models.Model):
    """A group a new sub-reseller account can be put in, as the panel lists them (`pricing`)."""

    _name = "xtreampro.group"
    _description = "Xtream UI Pro sub-reseller group"
    _order = "name, id"

    panel_id = fields.Integer(string="Panel ID", required=True, index=True, readonly=True)
    name = fields.Char(required=True)
    active = fields.Boolean(default=True)

    _sql_constraints = [
        ("panel_id_uniq", "unique(panel_id)", "A group with this panel ID already exists."),
    ]
