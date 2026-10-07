import json
import logging
import threading
from datetime import datetime, timezone
from urllib.parse import quote, urlencode

from markupsafe import Markup

from odoo import _, api, fields, models
from odoo.exceptions import UserError

from .xtreampro_api import FATAL_CODES, XtreamProError, describe_compatibility

_logger = logging.getLogger(__name__)

STATES = [
    ("draft", "Draft"),
    ("active", "Active"),
    ("expired", "Expired"),
    ("disabled", "Suspended"),
    ("banned", "Banned"),
    ("error", "Error"),
]
PANEL_STATUSES = {"active", "expired", "disabled", "banned"}


def _to_int(value, default=0):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _from_unix(value):
    """Panel exp_date (unix seconds or null) -> naive UTC datetime as Odoo stores it."""
    if value in (None, "", 0, "0"):
        return False
    try:
        return datetime.fromtimestamp(int(value), tz=timezone.utc).replace(tzinfo=None)
    except (TypeError, ValueError, OverflowError, OSError):
        return False


class XtreamProLine(models.Model):
    _name = "xtreampro.line"
    _description = "Xtream UI Pro line"
    _inherit = ["mail.thread"]
    _order = "id desc"
    _mail_post_access = "read"

    name = fields.Char(compute="_compute_name", store=True)
    active = fields.Boolean(default=True)
    company_id = fields.Many2one("res.company", default=lambda self: self.env.company, index=True)
    panel_line_id = fields.Integer(string="Panel line ID", copy=False, index=True, readonly=True)
    username = fields.Char(copy=False, index=True, readonly=True)
    password = fields.Char(copy=False, readonly=True, groups="sales_team.group_sale_manager")
    partner_id = fields.Many2one("res.partner", string="Customer", index=True)
    sale_order_id = fields.Many2one("sale.order", string="Sale order", index=True, ondelete="set null", copy=False)
    sale_line_id = fields.Many2one("sale.order.line", string="Sale order line", index=True, ondelete="set null", copy=False)
    unit_index = fields.Integer(string="Unit #", copy=False)
    request_id = fields.Char(copy=False, readonly=True)
    package_id = fields.Many2one("xtreampro.package", string="Package")
    state = fields.Selection(STATES, default="draft", required=True, copy=False, tracking=True, index=True)
    expiry = fields.Datetime(copy=False, readonly=True)
    max_connections = fields.Integer(copy=False, readonly=True)
    is_trial = fields.Boolean(string="Trial")
    last_error = fields.Text(copy=False, readonly=True)
    last_sync = fields.Datetime(copy=False, readonly=True)
    # What the panel returned as `links` the last time it answered about this line (JSON). The
    # URLs carry the line password, so it is as private as the password field.
    links_json = fields.Text(copy=False, readonly=True, groups="sales_team.group_sale_manager")
    package_changes = fields.Integer(copy=False, readonly=True,
                                     help="Package changes the panel accepted; part of the request id of the next one.")
    playlist_url = fields.Char(compute="_compute_links", groups="sales_team.group_sale_manager")
    hls_url = fields.Char(string="Playlist URL (HLS)", compute="_compute_links", groups="sales_team.group_sale_manager")
    guide_url = fields.Char(string="Programme guide (XMLTV)", compute="_compute_links", groups="sales_team.group_sale_manager")
    server_url = fields.Char(compute="_compute_links")
    player_url = fields.Char(compute="_compute_links")

    _sql_constraints = [
        ("request_id_uniq", "unique(request_id)", "A line with this request id already exists."),
    ]

    # --- computes ------------------------------------------------------
    @api.depends("username", "sale_order_id.name", "unit_index")
    def _compute_name(self):
        for rec in self:
            rec.name = rec.username or "%s #%s" % (rec.sale_order_id.name or _("Line"), rec.unit_index or 1)

    @api.depends("username", "password", "links_json")
    def _compute_links(self):
        """The links the panel returned with the line (built on the host the API was called on,
        or on the reseller's own play address). Only a line the panel never described this way
        (made by version 1.0.0, or by a panel that sends no `links`) gets the M3U and web player
        links assembled here."""
        base = self.env["xtreampro.api"]._base_url()
        for rec in self:
            line = rec.sudo()
            try:
                links = json.loads(line.links_json) if line.links_json else None
            except ValueError:
                links = None
            if isinstance(links, dict) and links:
                rec.server_url = links.get("server") or base or False
                rec.player_url = links.get("web_player") or False
                rec.playlist_url = links.get("m3u") or False
                rec.hls_url = links.get("m3u_hls") or False
                rec.guide_url = links.get("xmltv") or False
                continue
            rec.hls_url = rec.guide_url = False
            rec.server_url = base or False
            rec.player_url = (base + "/player/") if base else False
            pw = line.password
            if base and rec.username and pw:
                query = urlencode({"username": rec.username, "password": pw,
                                   "type": "m3u_plus", "output": "ts"}, quote_via=quote)
                rec.playlist_url = "%s/get.php?%s" % (base, query)
            else:
                rec.playlist_url = False

    # --- helpers -------------------------------------------------------
    @staticmethod
    def _html(text, *args):
        return Markup(text) % args if args else Markup("%s") % text

    def _check_manager(self):
        self.env["xtreampro.api"]._require_manager()

    def _vals_from_panel(self, line, password=None, links=None):
        vals = {"last_sync": fields.Datetime.now(), "last_error": False}
        links = links or self.env["xtreampro.api"]._links_of(line)
        if links:
            vals["links_json"] = json.dumps(links)
        if line.get("id") is not None:
            vals["panel_line_id"] = _to_int(line["id"])
        if line.get("username"):
            vals["username"] = str(line["username"])
        pw = line.get("password") or password
        if pw:
            vals["password"] = str(pw)
        if line.get("status") in PANEL_STATUSES:
            vals["state"] = line["status"]
        if "exp_date" in line:
            vals["expiry"] = _from_unix(line["exp_date"])
        if line.get("max_connections") is not None:
            vals["max_connections"] = _to_int(line["max_connections"])
        if "is_trial" in line:
            vals["is_trial"] = bool(line["is_trial"])
        if line.get("package_id") is not None:
            pkg = self.env["xtreampro.package"].with_context(active_test=False).search(
                [("panel_id", "=", _to_int(line["package_id"], -1))], limit=1)
            if pkg:
                vals["package_id"] = pkg.id
        return vals

    def _log_line_message(self, text, *args):
        """Chatter note on the line and on its order. Never put secrets in here."""
        body = self._html(text, *args)
        for rec in self:
            rec.message_post(body=body)
            if rec.sale_order_id:
                rec.sale_order_id.message_post(body=body)

    # --- panel operations (raise XtreamProError) -----------------------
    def _create_on_panel(self):
        self.ensure_one()
        if not self.package_id:
            raise XtreamProError("INVALID_PACKAGE")
        if not self.env.context.get("xtreampro_prechecked"):
            # a retry of one line; the batch of an order is checked once by the order
            self.env["xtreampro.api"]._check_needs([{
                "kind": "line", "package_id": self.package_id.panel_id, "trial": self.is_trial, "units": 1}])
        data = self.env["xtreampro.api"]._create_line(self.package_id.panel_id, self.is_trial, self.request_id)
        line = data.get("line") if isinstance(data, dict) else None
        if not isinstance(line, dict) or line.get("id") is None:
            raise XtreamProError("BAD_RESPONSE")
        self.write(self._vals_from_panel(line, password=data.get("password"), links=self.env["xtreampro.api"]._links_of(data)))
        if self.state in ("draft", "error"):
            self.state = "active"

    def _refresh_from_panel(self):
        self.ensure_one()
        if not self.panel_line_id:
            raise XtreamProError("RESOURCE_NOT_FOUND")
        data = self.env["xtreampro.api"]._get_line(self.panel_line_id)
        if not isinstance(data, dict) or not data:
            raise XtreamProError("BAD_RESPONSE")
        self.write(self._vals_from_panel(data))

    def _safe_refresh(self):
        for rec in self:
            try:
                with self.env.cr.savepoint():
                    rec._refresh_from_panel()
            except Exception:  # the action itself already succeeded on the panel
                _logger.info("Xtream UI Pro refresh after action failed for line %s", rec.id)

    # --- buttons -------------------------------------------------------
    def action_retry(self):
        self._check_manager()
        for rec in self.filtered(lambda r: r.state == "error"):
            try:
                rec._create_on_panel()
            except XtreamProError as e:
                raise UserError(e.message) from None
            rec._log_line_message("Xtream UI Pro: line <b>%s</b> created on the panel.", rec.username or "")
        return True

    def action_renew(self):
        self._check_manager()
        today = fields.Date.today().strftime("%Y%m%d")
        for rec in self:
            if not rec.panel_line_id:
                raise UserError(_("This line has not been created on the panel yet."))
            try:
                if rec.package_id:
                    # renewing sells one official period of the line's package
                    self.env["xtreampro.api"]._check_needs([{
                        "kind": "line", "package_id": rec.package_id.panel_id, "trial": False, "units": 1, "renewal": True}])
                data = self.env["xtreampro.api"]._renew_line(rec.panel_line_id, "odoo-renew-%s-%s" % (rec.id, today))
            except XtreamProError as e:
                raise UserError(e.message) from None
            line = data.get("line") if isinstance(data, dict) else None
            if isinstance(line, dict) and line:
                rec.write(rec._vals_from_panel(line, links=self.env["xtreampro.api"]._links_of(data)))
            else:
                rec._safe_refresh()
            charged = data.get("credits_charged") if isinstance(data, dict) else None
            balance = data.get("credits_balance") if isinstance(data, dict) else None
            rec._log_line_message("Xtream UI Pro: line <b>%s</b> renewed on the panel (credits charged: %s, balance: %s).",
                                  rec.username or "", charged, balance)
        return True

    def _suspend_on_panel(self):
        """Disable the lines on the panel (raises XtreamProError); no access check."""
        for rec in self:
            self.env["xtreampro.api"]._disable_line(rec.panel_line_id)
            rec.state = "disabled"
            rec._safe_refresh()
            rec._log_line_message("Xtream UI Pro: line <b>%s</b> suspended.", rec.username or "")

    def _terminate_on_panel(self):
        """Delete the lines on the panel for good (raises XtreamProError); no access check.
        A line already gone counts as deleted."""
        for rec in self:
            try:
                if rec.panel_line_id:
                    self.env["xtreampro.api"]._delete_line(rec.panel_line_id)
            except XtreamProError as e:
                if e.code != "RESOURCE_NOT_FOUND":
                    raise
            rec._log_line_message("Xtream UI Pro: line <b>%s</b> terminated (deleted) on the panel.", rec.username or "")
            rec.write({"state": "disabled", "active": False})

    def action_suspend(self):
        self._check_manager()
        try:
            self._suspend_on_panel()
        except XtreamProError as e:
            raise UserError(e.message) from None
        return True

    def action_unsuspend(self):
        self._check_manager()
        for rec in self:
            try:
                self.env["xtreampro.api"]._enable_line(rec.panel_line_id)
            except XtreamProError as e:
                raise UserError(e.message) from None
            rec.state = "active"
            rec._safe_refresh()
            rec._log_line_message("Xtream UI Pro: line <b>%s</b> reactivated.", rec.username or "")
        return True

    def action_refresh(self):
        self._check_manager()
        for rec in self:
            try:
                rec._refresh_from_panel()
            except XtreamProError as e:
                raise UserError(e.message) from None
        return True

    def action_terminate(self):
        self._check_manager()
        try:
            self._terminate_on_panel()
        except XtreamProError as e:
            raise UserError(e.message) from None
        return True

    def action_change_package(self):
        """Open the upgrade / downgrade dialog."""
        self._check_manager()
        self.ensure_one()
        if not self.panel_line_id or self.state not in ("active", "expired", "disabled"):
            raise UserError(_("Only a line that exists on the panel can change package."))
        return {
            "type": "ir.actions.act_window",
            "name": _("Change package"),
            "res_model": "xtreampro.line.package.wizard",
            "view_mode": "form",
            "target": "new",
            "context": {"default_line_id": self.id, "default_package_id": self.package_id.id},
        }

    def _change_package_on_panel(self, package):
        """Sell an official period of `package` on the line (the panel's change_package): the line
        takes its limits and the reseller pays its price. A retry of the same change repeats its
        request id and is not charged twice; the counter moves on only after the panel accepted one."""
        self.ensure_one()
        api_ = self.env["xtreampro.api"]
        api_._check_needs([{"kind": "line", "package_id": package.panel_id, "trial": False, "units": 1}])
        # Ask, without selling, whether the time left is kept and what the change costs (None on a panel
        # too old to know the question: the change is then decided when it is sold).
        compat = api_._package_compatibility(self.panel_line_id, package.panel_id)
        if compat is not None and compat.get("can_afford") is False:
            raise XtreamProError("INSUFFICIENT_CREDITS", 402,
                                 "The change costs %d credits and the reseller account cannot pay it." % int(compat.get("price") or 0))
        data = api_._change_package(self.panel_line_id, package.panel_id,
                                    "odoo-chg-%s-%s" % (self.id, self.package_changes))
        line = data.get("line") if isinstance(data, dict) else None
        vals = self._vals_from_panel(line, links=api_._links_of(data)) if isinstance(line, dict) and line else {}
        vals["package_changes"] = self.package_changes + 1
        self.write(vals)
        if not (isinstance(line, dict) and line):
            self._safe_refresh()
        self.package_id = package
        charged = data.get("credits_charged") if isinstance(data, dict) else None
        self._log_line_message("Xtream UI Pro: line <b>%s</b> moved to package <b>%s</b> (credits charged: %s). %s",
                               self.username or "", package.name, charged,
                               describe_compatibility(compat) if compat is not None
                               else "This panel does not say whether the time left was kept.")

    def action_send_credentials(self):
        self.env["xtreampro.api"]._require_group("sales_team.group_sale_salesman")
        template = self.env.ref("xtreampro_connector.mail_template_xtreampro_credentials").sudo()
        for rec in self:
            line = rec.sudo()
            if not line.username or not line.password:
                raise UserError(_("This line has no credentials yet."))
            if not line.partner_id.email:
                raise UserError(_("The customer has no e-mail address."))
            # Rendered by hand and sent as an unlinked mail so the password never lands in the chatter.
            values = {"auto_delete": True, "author_id": self.env.user.partner_id.id}
            for field in ("subject", "body_html", "email_from", "email_to"):
                values[field] = template._render_field(field, [line.id])[line.id]
            self.env["mail.mail"].sudo().create(values).send()
            rec.message_post(body=self._html("Credentials e-mailed to %s.", line.partner_id.email))
        return True

    # --- cron ----------------------------------------------------------
    @api.model
    def _commit(self):
        if not getattr(threading.current_thread(), "testing", False):
            self.env.cr.commit()

    @api.model
    def _cron_refresh_lines(self, limit=200, batch_size=20):
        records = self.search([("state", "in", ("active", "expired")), ("panel_line_id", "!=", 0)],
                              order="last_sync asc, id asc", limit=limit)
        stop = False
        for start in range(0, len(records), batch_size):
            for rec in records[start:start + batch_size]:
                try:
                    with self.env.cr.savepoint():
                        rec._refresh_from_panel()
                except XtreamProError as e:
                    if e.code in FATAL_CODES:
                        _logger.warning("Xtream UI Pro line refresh stopped: %s", e.code)
                        stop = True
                        break
                    rec.write({"last_sync": fields.Datetime.now(), "last_error": e.message})
                except Exception:
                    _logger.exception("Xtream UI Pro line refresh failed for line %s", rec.id)
                    rec.write({"last_sync": fields.Datetime.now(), "last_error": _("Unexpected error while refreshing.")})
            self._commit()
            if stop:
                break
