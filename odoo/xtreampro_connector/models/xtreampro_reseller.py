import logging
import re
import secrets
import string
import threading
import unicodedata

from markupsafe import Markup

from odoo import _, api, fields, models
from odoo.exceptions import UserError, ValidationError

from .xtreampro_api import FATAL_CODES, XtreamProError

_logger = logging.getLogger(__name__)

STATES = [
    ("draft", "Draft"),
    ("active", "Active"),
    ("disabled", "Suspended"),
    ("error", "Error"),
]
PANEL_STATUSES = {"active", "disabled"}
USERNAME_MAX = 32  # the panel accepts 3-32 characters of letters, digits and _ . -
USERNAME_SUFFIX = 4
PASSWORD_LENGTH = 14


def _to_int(value, default=0):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def error_text(exc):
    """Readable text of an expected failure, or None for an unexpected one."""
    if isinstance(exc, XtreamProError):
        return exc.message
    if isinstance(exc, UserError) and exc.args:
        return str(exc.args[0])
    return None


def make_username(partner, email=None):
    """Panel-safe username (3-32 chars) from the partner name/e-mail plus a short random suffix."""
    source = partner.name or (email or partner.email or "").split("@")[0]
    ascii_name = unicodedata.normalize("NFKD", source or "").encode("ascii", "ignore").decode()
    base = re.sub(r"[^A-Za-z0-9_.-]", "", ascii_name)
    base = base[: USERNAME_MAX - USERNAME_SUFFIX - 1] or ("r%s" % partner.id)
    suffix = secrets.token_hex(USERNAME_SUFFIX // 2)
    return ("%s_%s" % (base, suffix))[:USERNAME_MAX]


def make_password():
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _i in range(PASSWORD_LENGTH))


class XtreamProReseller(models.Model):
    _name = "xtreampro.reseller"
    _description = "Xtream UI Pro sub-reseller account"
    _inherit = ["mail.thread"]
    _order = "id desc"
    _mail_post_access = "read"

    name = fields.Char(compute="_compute_name", store=True)
    active = fields.Boolean(
        default=True,
        help="Archiving only hides this record in Odoo; the account keeps working on the panel (suspend "
             "it first if it must stop working). To remove it from the panel for good use "
             "'Delete on panel'.")
    company_id = fields.Many2one("res.company", default=lambda self: self.env.company, index=True)
    panel_user_id = fields.Char(string="Panel account ID", copy=False, index=True, readonly=True)
    username = fields.Char(copy=False, index=True)
    password = fields.Char(copy=False, groups="sales_team.group_sale_manager")
    partner_id = fields.Many2one("res.partner", string="Customer", index=True, required=True)
    sale_order_id = fields.Many2one("sale.order", string="Sale order", index=True, ondelete="set null", copy=False)
    state = fields.Selection(STATES, default="draft", required=True, copy=False, tracking=True, index=True)
    group_id = fields.Many2one("xtreampro.group", string="Panel group", copy=False,
                               help="Group the account is created in (from the product). Empty = the first group the panel allows.")
    login_url = fields.Char(compute="_compute_login_url")
    credits = fields.Integer(string="Credits", copy=False, readonly=True, help="Last known balance of the account on the panel.")
    last_sync = fields.Datetime(copy=False, readonly=True)
    last_event_at = fields.Datetime(
        string="Last panel activity of its lines", copy=False, readonly=True,
        help="When the panel last told this Odoo about a line of this account. Only filled when the webhook was "
             "registered with the sub-resellers' lines; nothing about the line is kept.")
    last_error = fields.Text(copy=False, readonly=True)
    request_id = fields.Char(copy=False, readonly=True)
    transfer_ids = fields.One2many("xtreampro.credit.transfer", "reseller_id", string="Credit transfers")

    _sql_constraints = [
        ("request_id_uniq", "unique(request_id)", "A sub-reseller with this request id already exists."),
    ]

    @api.constrains("partner_id", "company_id", "active")
    def _check_one_per_partner(self):
        for rec in self.filtered("active"):
            if self.with_context(active_test=True).search_count([
                ("id", "!=", rec.id), ("partner_id", "=", rec.partner_id.id),
                ("company_id", "=", rec.company_id.id), ("active", "=", True),
            ]):
                raise ValidationError(_("This customer already has a sub-reseller account in this company."))

    def _compute_login_url(self):
        url = (self.env["ir.config_parameter"].sudo().get_param("xtreampro.panel_url") or "").strip().rstrip("/")
        ok = url.lower().startswith(("http://", "https://"))
        for rec in self:
            rec.login_url = url + "/login" if ok else False

    @api.depends("username", "partner_id.name")
    def _compute_name(self):
        for rec in self:
            rec.name = rec.username or rec.partner_id.name or _("Sub-reseller")

    # --- helpers -------------------------------------------------------
    @staticmethod
    def _html(text, *args):
        return Markup(text) % args if args else Markup("%s") % text

    def _check_manager(self):
        self.env["xtreampro.api"]._require_manager()

    def _log_message(self, text, *args):
        """Chatter note on the account and on its order. Never put secrets in here."""
        body = self._html(text, *args)
        for rec in self:
            rec.message_post(body=body)
            if rec.sale_order_id:
                rec.sale_order_id.message_post(body=body)

    def _vals_from_panel(self, user):
        vals = {"last_sync": fields.Datetime.now(), "last_error": False}
        if user.get("id") is not None:
            vals["panel_user_id"] = str(user["id"])
        if user.get("username"):
            vals["username"] = str(user["username"])
        if user.get("status") in PANEL_STATUSES:
            vals["state"] = user["status"]
        if user.get("credits") is not None:
            vals["credits"] = _to_int(user["credits"])
        return vals

    # --- panel operations (raise XtreamProError / UserError) ------------
    def _create_on_panel(self):
        self.ensure_one()
        if not self.username or not self.password:
            raise XtreamProError("INVALID_REQUEST")
        partner = self.partner_id
        email = partner.email or ""
        if not email:
            raise UserError(_("The customer has no e-mail address; the panel needs one to create the account."))
        if not self.env.context.get("xtreampro_prechecked"):
            # a retry; the batch of an order is checked once by the order
            self.env["xtreampro.api"]._check_needs([{
                "kind": "reseller", "create": True, "credits": 0, "group_id": self.group_id.panel_id}])
        data = self.env["xtreampro.api"]._create_sub_user(
            self.username, self.password, email, (partner.name or "")[:128], self.request_id,
            group_id=self.group_id.panel_id or None)
        user = data.get("user") if isinstance(data, dict) else None
        if not isinstance(user, dict) or user.get("id") is None:
            raise XtreamProError("BAD_RESPONSE")
        self.write(self._vals_from_panel(user))
        if self.state in ("draft", "error"):
            self.state = "active"
        return data

    def _refresh_from_panel(self):
        self.ensure_one()
        if not self.panel_user_id:
            raise XtreamProError("RESOURCE_NOT_FOUND")
        user = self.env["xtreampro.api"]._find_sub_user(self.panel_user_id, self.username)
        self.write(self._vals_from_panel(user))

    def _safe_refresh(self):
        for rec in self:
            try:
                with self.env.cr.savepoint():
                    rec._refresh_from_panel()
            except Exception:  # the action itself already succeeded on the panel
                _logger.info("Xtream UI Pro refresh after action failed for sub-reseller %s", rec.id)

    def _try_create_for_order(self, order, product_name):
        """Create the account on the panel; on failure keep the record in error and note the order."""
        self.ensure_one()
        try:
            with self.env.cr.savepoint():
                self._create_on_panel()
        except Exception as e:
            message = error_text(e)
            if not message:
                _logger.exception("Xtream UI Pro sub-reseller creation failed for order %s", order.id)
                message = _("Unexpected error while creating the sub-reseller account.")
            self.write({"state": "error", "last_error": message})
            order.message_post(body=Markup(
                "Xtream UI Pro: could not create the sub-reseller account for <b>%s</b>: %s "
                "Open the sub-reseller and press Retry once the problem is fixed.")
                % (product_name, message))
            return False
        self._log_message("Xtream UI Pro: sub-reseller account <b>%s</b> created on the panel.", self.username or "")
        return True

    # --- buttons -------------------------------------------------------
    def _add_credits_action(self, direction):
        self._check_manager()
        self.ensure_one()
        if not self.panel_user_id:
            raise UserError(_("This account has not been created on the panel yet."))
        return {
            "type": "ir.actions.act_window",
            "name": _("Add credits") if direction == "add" else _("Take back credits"),
            "res_model": "xtreampro.credit.wizard",
            "view_mode": "form",
            "target": "new",
            "context": {"default_reseller_id": self.id, "default_direction": direction},
        }

    def action_add_credits(self):
        return self._add_credits_action("add")

    def action_take_credits(self):
        return self._add_credits_action("take")

    def action_retry(self):
        self._check_manager()
        for rec in self.filtered(lambda r: r.state == "error" and not r.panel_user_id):
            try:
                rec._create_on_panel()
            except XtreamProError as e:
                raise UserError(e.message) from None
            rec._log_message("Xtream UI Pro: sub-reseller account <b>%s</b> created on the panel.", rec.username or "")
            pending = rec.transfer_ids.filtered(lambda t: t.sale_line_id and t.state in ("draft", "error"))
            for transfer in pending:
                try:
                    with self.env.cr.savepoint():
                        transfer._run()
                except Exception as e:
                    transfer.write({"state": "error", "last_error": error_text(e) or _("Unexpected error.")})
                else:
                    transfer._log_done()
        return True

    def _suspend_on_panel(self):
        """Disable the accounts on the panel (raises XtreamProError); no access check."""
        for rec in self:
            self.env["xtreampro.api"]._set_sub_user_state(rec.panel_user_id, False)
            rec.state = "disabled"
            rec._safe_refresh()
            rec._log_message("Xtream UI Pro: sub-reseller <b>%s</b> suspended.", rec.username or "")

    def _delete_on_panel(self):
        """Delete the accounts on the panel for good (raises XtreamProError); no access check.
        Final: the account's credits, lines and sub-accounts go to the reseller and its personal data is
        erased. An account already gone counts as deleted."""
        for rec in self:
            try:
                if rec.panel_user_id:
                    self.env["xtreampro.api"]._delete_sub_user(rec.panel_user_id)
            except XtreamProError as e:
                if e.code != "RESOURCE_NOT_FOUND":
                    raise
            rec._log_message("Xtream UI Pro: sub-reseller <b>%s</b> deleted on the panel.", rec.username or "")
            rec.write({"state": "disabled", "active": False})

    def action_suspend(self):
        self._check_manager()
        try:
            self._suspend_on_panel()
        except XtreamProError as e:
            raise UserError(e.message) from None
        return True

    def action_delete_on_panel(self):
        self._check_manager()
        try:
            self._delete_on_panel()
        except XtreamProError as e:
            raise UserError(e.message) from None
        return True

    def action_unsuspend(self):
        self._check_manager()
        for rec in self:
            try:
                self.env["xtreampro.api"]._set_sub_user_state(rec.panel_user_id, True)
            except XtreamProError as e:
                raise UserError(e.message) from None
            rec.state = "active"
            rec._safe_refresh()
            rec._log_message("Xtream UI Pro: sub-reseller <b>%s</b> reactivated.", rec.username or "")
        return True

    def action_refresh(self):
        self._check_manager()
        for rec in self:
            try:
                rec._refresh_from_panel()
            except XtreamProError as e:
                raise UserError(e.message) from None
        return True

    def action_send_credentials(self):
        self.env["xtreampro.api"]._require_group("sales_team.group_sale_salesman")
        template = self.env.ref("xtreampro_connector.mail_template_xtreampro_reseller_credentials").sudo()
        for rec in self:
            acct = rec.sudo()
            if not acct.username or not acct.password:
                raise UserError(_("This account has no credentials yet."))
            if not acct.partner_id.email:
                raise UserError(_("The customer has no e-mail address."))
            # Rendered by hand and sent as an unlinked mail so the password never lands in the chatter.
            values = {"auto_delete": True, "author_id": self.env.user.partner_id.id}
            for field in ("subject", "body_html", "email_from", "email_to"):
                values[field] = template._render_field(field, [acct.id])[acct.id]
            self.env["mail.mail"].sudo().create(values).send()
            rec.message_post(body=self._html("Credentials e-mailed to %s.", acct.partner_id.email))
        return True

    # --- cron ----------------------------------------------------------
    @api.model
    def _commit(self):
        if not getattr(threading.current_thread(), "testing", False):
            self.env.cr.commit()

    @api.model
    def _cron_refresh_resellers(self, limit=200, batch_size=20, page_size=500, max_pages=20):
        records = self.search([("state", "in", ("active", "disabled")), ("panel_user_id", "!=", False)],
                              order="last_sync asc, id asc", limit=limit)
        if not records:
            return
        # One list of the accounts below the API key's reseller refreshes many records at once.
        panel = {}
        complete = False
        try:
            for page in range(max_pages):
                users = self.env["xtreampro.api"]._sub_users(start=page * page_size, limit=page_size)
                panel.update({str(u.get("id")): u for u in users})
                if len(users) < page_size:
                    complete = True
                    break
        except XtreamProError as e:
            _logger.warning("Xtream UI Pro sub-reseller refresh stopped: %s", e.code)
            if e.code not in FATAL_CODES:
                records.write({"last_sync": fields.Datetime.now(), "last_error": e.message})
                self._commit()
            return
        for start in range(0, len(records), batch_size):
            for rec in records[start:start + batch_size]:
                try:
                    with self.env.cr.savepoint():
                        user = panel.get(rec.panel_user_id)
                        if user:
                            rec.write(rec._vals_from_panel(user))
                        elif complete:
                            rec.write({"last_sync": fields.Datetime.now(),
                                       "last_error": _("The account was not found on the panel.")})
                except Exception:
                    _logger.exception("Xtream UI Pro sub-reseller refresh failed for %s", rec.id)
            self._commit()
