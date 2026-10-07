import logging

from markupsafe import Markup

from odoo import _, fields, models

from .xtreampro_api import XtreamProError
from .xtreampro_reseller import error_text, make_password, make_username

_logger = logging.getLogger(__name__)


class SaleOrder(models.Model):
    _inherit = "sale.order"

    xtreampro_line_count = fields.Integer(compute="_compute_xtreampro_line_count")
    xtreampro_reseller_count = fields.Integer(compute="_compute_xtreampro_reseller_count")

    def _xtreampro_resellers(self):
        """Sub-reseller accounts created by, or credited from, this order."""
        self.ensure_one()
        Reseller = self.env["xtreampro.reseller"].sudo().with_context(active_test=False)
        Transfer = self.env["xtreampro.credit.transfer"].sudo()
        return Reseller.search([("sale_order_id", "=", self.id)]) | \
            Transfer.search([("sale_order_id", "=", self.id)]).reseller_id

    def _compute_xtreampro_reseller_count(self):
        for order in self:
            order.xtreampro_reseller_count = len(order._xtreampro_resellers()) if order.id else 0

    def action_view_xtreampro_resellers(self):
        self.ensure_one()
        resellers = self._xtreampro_resellers()
        action = {
            "type": "ir.actions.act_window",
            "name": _("Sub-resellers"),
            "res_model": "xtreampro.reseller",
            "view_mode": "tree,form",
            "domain": [("id", "in", resellers.ids)],
            "context": {"active_test": False},
        }
        if len(resellers) == 1:
            action.update(view_mode="form", res_id=resellers.id)
        return action

    def _compute_xtreampro_line_count(self):
        Line = self.env["xtreampro.line"].sudo().with_context(active_test=False)
        for order in self:
            order.xtreampro_line_count = Line.search_count([("sale_order_id", "=", order.id)]) if order.id else 0

    def action_view_xtreampro_lines(self):
        self.ensure_one()
        action = {
            "type": "ir.actions.act_window",
            "name": _("IPTV lines"),
            "res_model": "xtreampro.line",
            "view_mode": "tree,form",
            "domain": [("sale_order_id", "=", self.id)],
            "context": {"active_test": False},
        }
        lines = self.env["xtreampro.line"].with_context(active_test=False).search([("sale_order_id", "=", self.id)])
        if len(lines) == 1:
            action.update(view_mode="form", res_id=lines.id)
        return action

    def action_confirm(self):
        res = super().action_confirm()
        for order in self.filtered(lambda o: o.state in ("sale", "done")):
            try:
                order._xtreampro_provision()
            except Exception:  # never block the confirmation
                _logger.exception("Xtream UI Pro provisioning crashed for order %s", order.id)
        return res

    def _action_cancel(self):
        res = super()._action_cancel()
        for order in self:
            try:
                order._xtreampro_revoke()
            except Exception:  # never block the cancellation
                _logger.exception("Xtream UI Pro revoking crashed for order %s", order.id)
        return res

    def _xtreampro_revoke(self):
        """Cancelling the order: its lines and sub-reseller account are disabled on the panel, or
        deleted for good when the product's "On cancellation" says so. A failure is written to the
        order's chatter; the cancellation itself always goes through."""
        self.ensure_one()
        Line = self.env["xtreampro.line"].sudo().with_context(active_test=False)
        Reseller = self.env["xtreampro.reseller"].sudo().with_context(active_test=False)
        product_of = lambda sol: sol.product_id.product_tmpl_id if sol and sol.product_id else self.env["product.template"]
        for line in Line.search([("sale_order_id", "=", self.id), ("state", "in", ("active", "expired", "disabled")),
                                 ("panel_line_id", "!=", 0)]):
            delete = product_of(line.sale_line_id).xtreampro_on_cancel == "delete"
            if not delete and line.state == "disabled" and line.active:
                continue  # already suspended
            if not line.active:
                continue  # already terminated
            try:
                with self.env.cr.savepoint():
                    line._terminate_on_panel() if delete else line._suspend_on_panel()
            except Exception as e:
                self.message_post(body=Markup("Xtream UI Pro: could not %s line <b>%s</b>: %s") % (
                    "delete" if delete else "suspend", line.username or line.id,
                    error_text(e) or _("Unexpected error.")))
        # an account this order created (a customer's later orders only topped it up)
        for reseller in Reseller.search([("sale_order_id", "=", self.id), ("panel_user_id", "!=", False), ("active", "=", True)]):
            sol = self.order_line.filtered(lambda l: product_of(l).xtreampro_kind == "reseller")[:1]
            delete = product_of(sol).xtreampro_on_cancel == "delete"
            if not delete and reseller.state == "disabled":
                continue
            try:
                with self.env.cr.savepoint():
                    reseller._delete_on_panel() if delete else reseller._suspend_on_panel()
            except Exception as e:
                self.message_post(body=Markup("Xtream UI Pro: could not %s sub-reseller <b>%s</b>: %s") % (
                    "delete" if delete else "suspend", reseller.username or reseller.id,
                    error_text(e) or _("Unexpected error.")))

    def _xtreampro_needs(self):
        """What provisioning this order would still buy, for xtreampro.api._check_needs()."""
        self.ensure_one()
        Line = self.env["xtreampro.line"].sudo().with_context(active_test=False)
        Reseller = self.env["xtreampro.reseller"].sudo().with_context(active_test=False)
        needs = []
        partner = self.partner_id.commercial_partner_id
        for sol in self.order_line:
            if sol.display_type or not sol.product_id:
                continue
            tmpl = sol.product_id.product_tmpl_id
            if tmpl.xtreampro_kind == "reseller":
                reseller = Reseller.search([("partner_id", "=", partner.id), ("company_id", "=", self.company_id.id)], limit=1)
                needs.append({
                    "kind": "reseller", "create": not (reseller and reseller.panel_user_id),
                    "group_id": tmpl.xtreampro_group_id.panel_id,
                    "credits": 0 if self.env["xtreampro.credit.transfer"].sudo().search_count([("sale_line_id", "=", sol.id)])
                    else tmpl.xtreampro_credits * int(sol.product_uom_qty)})
            elif tmpl.xtreampro_kind == "line" and tmpl.xtreampro_package_id:
                made = Line.search_count([("sale_line_id", "=", sol.id)])
                left = int(sol.product_uom_qty) - made
                if left > 0:
                    needs.append({"kind": "line", "package_id": tmpl.xtreampro_package_id.panel_id,
                                  "trial": tmpl.xtreampro_trial, "units": left})
        return needs

    def _xtreampro_provision(self):
        self.ensure_one()
        Line = self.env["xtreampro.line"].sudo().with_context(active_test=False)
        # Ask what the order costs before buying any of it, so a short balance (or a package that is
        # not on sale) leaves the panel untouched. The records are still made, in state Error with
        # the reason, so that Retry works as for any other failure.
        blocked = False
        try:
            self.env["xtreampro.api"]._check_needs(self._xtreampro_needs())
        except XtreamProError as e:
            blocked = e.message
            self.message_post(body=Markup(
                "Xtream UI Pro: nothing was provisioned. %s Open the lines / sub-resellers and press Retry once the problem is fixed.")
                % blocked)
        for sol in self.order_line:
            if sol.display_type or not sol.product_id:
                continue
            tmpl = sol.product_id.product_tmpl_id
            if tmpl.xtreampro_kind == "reseller":
                try:
                    self._xtreampro_provision_reseller(sol, tmpl, blocked)
                except Exception:  # never block the confirmation
                    _logger.exception("Xtream UI Pro sub-reseller provisioning crashed for order %s line %s",
                                      self.id, sol.id)
                continue
            package = tmpl.xtreampro_package_id
            if tmpl.xtreampro_kind != "line" or not package:
                continue
            for n in range(1, int(sol.product_uom_qty) + 1):
                if Line.search_count([("sale_line_id", "=", sol.id), ("unit_index", "=", n)]):
                    continue
                line = Line.create({
                    "company_id": self.company_id.id,
                    "partner_id": self.partner_id.id,
                    "sale_order_id": self.id,
                    "sale_line_id": sol.id,
                    "unit_index": n,
                    "package_id": package.id,
                    "is_trial": tmpl.xtreampro_trial,
                    "request_id": "odoo-so%s-l%s-%s" % (self.id, sol.id, n),
                    "state": "draft",
                })
                if blocked:
                    line.write({"state": "error", "last_error": blocked})
                    continue
                try:
                    with self.env.cr.savepoint():
                        line.with_context(xtreampro_prechecked=True)._create_on_panel()
                except Exception as e:
                    message = getattr(e, "message", None)
                    if not message:
                        _logger.exception("Xtream UI Pro provisioning failed for order %s line %s", self.id, sol.id)
                        message = _("Unexpected error while creating the line.")
                    line.write({"state": "error", "last_error": message})
                    self.message_post(body=Markup(
                        "Xtream UI Pro: could not create IPTV line %s of %s for <b>%s</b>: %s "
                        "Open the line and press Retry once the problem is fixed.")
                        % (n, int(sol.product_uom_qty), sol.product_id.display_name, message))
                else:
                    line._log_line_message("Xtream UI Pro: line <b>%s</b> created on the panel.", line.username or "")

    def _xtreampro_provision_reseller(self, sol, tmpl, blocked=False):
        self.ensure_one()
        Reseller = self.env["xtreampro.reseller"].sudo()
        Transfer = self.env["xtreampro.credit.transfer"].sudo()
        partner = self.partner_id.commercial_partner_id
        name = sol.product_id.display_name
        reseller = Reseller.search([("partner_id", "=", partner.id), ("company_id", "=", self.company_id.id)], limit=1)
        if not reseller:
            email = partner.email or self.partner_id.email
            # username and password are stored first so a retry sends the same values
            reseller = Reseller.create({
                "company_id": self.company_id.id,
                "partner_id": partner.id,
                "sale_order_id": self.id,
                "username": make_username(partner, email),
                "password": make_password(),
                "request_id": "odoo-sub-so%s-l%s" % (self.id, sol.id),
                "group_id": tmpl.xtreampro_group_id.id,
                "state": "draft",
            })
        if blocked:
            if reseller.state in ("draft", "error") and not reseller.panel_user_id:
                reseller.write({"state": "error", "last_error": blocked})
        elif not reseller.panel_user_id and reseller.state in ("draft", "error"):
            reseller.with_context(xtreampro_prechecked=True)._try_create_for_order(self, name)

        credits = tmpl.xtreampro_credits * int(sol.product_uom_qty)
        if credits > 0 and not Transfer.search_count([("sale_line_id", "=", sol.id)]):
            transfer = Transfer.create({
                "reseller_id": reseller.id,
                "sale_line_id": sol.id,
                "credits": credits,
                "request_id": "odoo-subc-so%s-l%s" % (self.id, sol.id),
                "note": (_("Sale order %s") % self.name)[:200],
                "state": "draft",
            })
            if reseller.panel_user_id:
                transfer.with_context(xtreampro_prechecked=True)._run_for_order(self, name)
            else:
                transfer.write({"state": "error",
                                "last_error": _("The sub-reseller account has not been created on the panel yet.")})
