from odoo import _, fields, models
from odoo.exceptions import UserError

from .xtreampro_api import XtreamProError, normalize_base
from .xtreampro_webhook import EVENTS


class ResConfigSettings(models.TransientModel):
    _inherit = "res.config.settings"

    xtreampro_api_url = fields.Char(
        string="Panel API address",
        config_parameter="xtreampro.api_url",
        help="Public address of the Xtream UI Pro API, for example https://api.example.com",
    )
    xtreampro_api_key = fields.Char(
        string="Reseller API key",
        config_parameter="xtreampro.api_key",
        help="Key of a reseller account, generated in the panel dashboard on the API key page.",
    )

    xtreampro_panel_url = fields.Char(
        string="Panel address",
        config_parameter="xtreampro.panel_url",
        help="Optional. Address of the panel dashboard, for example https://panel.example.com. "
             "Sub-resellers get address/login as their sign-in link.",
    )
    xtreampro_webhook_secret = fields.Char(
        string="Webhook secret",
        config_parameter="xtreampro.webhook_secret",
        help="Signs the events the panel pushes to /xtreampro/webhook. Use 'Register webhook' to fill it in, "
             "or paste the secret of a webhook you created on the panel. Without it every event is refused.",
    )
    xtreampro_webhook_url = fields.Char(string="Webhook address", compute="_compute_xtreampro_webhook_url")
    xtreampro_webhook_scope = fields.Char(string="Events sent", compute="_compute_xtreampro_webhook_scope")

    def _compute_xtreampro_webhook_url(self):
        base = (self.env["ir.config_parameter"].sudo().get_param("web.base.url") or "").rstrip("/")
        for rec in self:
            rec.xtreampro_webhook_url = base + "/xtreampro/webhook"

    def _compute_xtreampro_webhook_scope(self):
        icp = self.env["ir.config_parameter"].sudo()
        if not icp.get_param("xtreampro.webhook_id"):
            text = _("No webhook was registered from this Odoo.")
        elif icp.get_param("xtreampro.webhook_include_sub") == "1":
            text = _("Lines of the reseller account and of the sub-reseller accounts below it.")
        else:
            text = _("Lines of the reseller account only.")
        for rec in self:
            rec.xtreampro_webhook_scope = text

    def _xtreampro_creds(self):
        self.ensure_one()
        return normalize_base(self.xtreampro_api_url), (self.xtreampro_api_key or "").strip()

    def action_xtreampro_test_connection(self):
        self.ensure_one()
        self.env["xtreampro.api"]._require_group("base.group_system")
        try:
            info = self.env["xtreampro.api"].sudo()._user_info(creds=self._xtreampro_creds())
        except XtreamProError as e:
            raise UserError(e.message) from None
        who = info.get("fullname") or info.get("username") or _("unknown")
        message = _("Connected as %(who)s (%(username)s). Credits: %(credits)s",
                    who=who, username=info.get("username") or "-", credits=info.get("credits"))
        return {
            "type": "ir.actions.client",
            "tag": "display_notification",
            "params": {"title": _("Xtream UI Pro"), "message": message, "type": "success", "sticky": False},
        }

    def action_xtreampro_sync_packages(self):
        self.ensure_one()
        self.env["xtreampro.api"]._require_group("base.group_system")
        return self.env["xtreampro.package"].sudo()._sync_with_notification(creds=self._xtreampro_creds())

    # --- webhook from the panel ---------------------------------------
    def _xtreampro_notify(self, message, kind="success"):
        return {
            "type": "ir.actions.client",
            "tag": "display_notification",
            "params": {"title": _("Xtream UI Pro"), "message": message, "type": kind, "sticky": False,
                       # reload so that the form shows what was stored (saving a stale form would wipe it)
                       "next": {"type": "ir.actions.client", "tag": "reload"}},
        }

    def action_xtreampro_register_webhook(self):
        """Ask the panel to post line events to this Odoo and keep the secret it shows once."""
        return self._xtreampro_register_webhook(False)

    def action_xtreampro_register_webhook_subs(self):
        """Same, and the panel also sends the events of lines owned by the sub-reseller accounts below
        this reseller. Offered because this addon sells sub-reseller accounts; such an event is only
        noted on the account (xtreampro.webhook._apply)."""
        return self._xtreampro_register_webhook(True)

    def _xtreampro_register_webhook(self, include_sub):
        self.ensure_one()
        self.env["xtreampro.api"]._require_group("base.group_system")
        url = self.xtreampro_webhook_url
        try:
            data = self.env["xtreampro.api"].sudo()._create_webhook(
                url, EVENTS, creds=self._xtreampro_creds(), include_sub_resellers=include_sub)
        except XtreamProError as e:
            if e.code == "INVALID_REQUEST":
                raise UserError(_("The panel refused the address %s: it delivers to https addresses only "
                                  "(http only when the panel runs with WEBHOOK_ALLOW_PRIVATE). Check the "
                                  "system parameter web.base.url.", url)) from None
            raise UserError(e.message) from None
        if not data.get("id") or not data.get("secret"):
            raise UserError(XtreamProError("BAD_RESPONSE").message)
        icp = self.env["ir.config_parameter"].sudo()
        icp.set_param("xtreampro.webhook_secret", str(data["secret"]))
        icp.set_param("xtreampro.webhook_id", str(data["id"]))
        icp.set_param("xtreampro.webhook_include_sub", "1" if include_sub else False)
        return self._xtreampro_notify(_("Webhook registered: the panel will post line events to %s.", url))

    def action_xtreampro_test_webhook(self):
        self.ensure_one()
        self.env["xtreampro.api"]._require_group("base.group_system")
        webhook_id = self.env["ir.config_parameter"].sudo().get_param("xtreampro.webhook_id")
        if not webhook_id:
            raise UserError(_("No webhook was registered from this Odoo yet."))
        try:
            self.env["xtreampro.api"].sudo()._test_webhook(webhook_id)
        except XtreamProError as e:
            raise UserError(e.message) from None
        return self._xtreampro_notify(_("A test event was queued on the panel. It reaches Odoo within a few seconds."))

    def action_xtreampro_remove_webhook(self):
        self.ensure_one()
        self.env["xtreampro.api"]._require_group("base.group_system")
        icp = self.env["ir.config_parameter"].sudo()
        webhook_id = icp.get_param("xtreampro.webhook_id")
        if webhook_id:
            try:
                self.env["xtreampro.api"].sudo()._delete_webhook(webhook_id)
            except XtreamProError as e:
                if e.code != "RESOURCE_NOT_FOUND":
                    raise UserError(e.message) from None
        icp.set_param("xtreampro.webhook_id", False)
        icp.set_param("xtreampro.webhook_secret", False)
        icp.set_param("xtreampro.webhook_include_sub", False)
        return self._xtreampro_notify(_("Webhook removed."))
