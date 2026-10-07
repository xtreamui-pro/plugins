import logging

from odoo import _, api, fields, models
from odoo.exceptions import UserError

from .xtreampro_api import XtreamProError, sells_line

_logger = logging.getLogger(__name__)


def _to_int(value, default=0):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _to_float(value, default=0.0):
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


class XtreamProPackage(models.Model):
    _name = "xtreampro.package"
    _description = "Xtream UI Pro package"
    _order = "name, id"

    panel_id = fields.Integer(string="Panel ID", required=True, index=True, readonly=True)
    name = fields.Char(required=True)
    is_trial = fields.Boolean(string="Trial available")
    is_official = fields.Boolean(string="Official available")
    duration = fields.Integer(string="Official duration", help="Length added by an official period (new line or renewal).")
    duration_in = fields.Char(string="Official duration unit")
    trial_duration = fields.Integer()
    trial_duration_in = fields.Char(string="Trial duration unit")
    trial_credits = fields.Float()
    official_credits = fields.Float()
    max_connections = fields.Integer()
    sells_line = fields.Boolean(
        string="Sold as a line", default=True,
        help="The panel says this package can be sold as a plain IPTV line. A package for MAG / Enigma boxes "
             "only is not, and the product and package-change pickers leave it out.")
    active = fields.Boolean(default=True)

    _sql_constraints = [
        ("panel_id_uniq", "unique(panel_id)", "A package with this panel ID already exists."),
    ]

    @api.model
    def _sync_from_panel(self, creds=None):
        """Upsert the panel's packages; archive the ones the panel no longer lists."""
        rows = self.env["xtreampro.api"]._packages(creds=creds)
        if not isinstance(rows, list):
            raise XtreamProError("BAD_RESPONSE")
        existing = {p.panel_id: p for p in self.with_context(active_test=False).search([])}
        seen, created, updated = set(), 0, 0
        for row in rows:
            if not isinstance(row, dict) or row.get("id") is None:
                continue
            pid = _to_int(row.get("id"), -1)
            if pid < 0:
                continue
            seen.add(pid)
            vals = {
                "name": row.get("name") or _("Package %s", pid),
                "is_trial": bool(row.get("is_trial")),
                "is_official": bool(row.get("is_official")),
                "duration": _to_int(row.get("official_duration")),
                "duration_in": row.get("official_duration_in") or False,
                "trial_duration": _to_int(row.get("trial_duration")),
                "trial_duration_in": row.get("trial_duration_in") or False,
                "trial_credits": _to_float(row.get("trial_credits")),
                "official_credits": _to_float(row.get("official_credits")),
                "max_connections": _to_int(row.get("max_connections")),
                "sells_line": sells_line(row),
                "active": True,
            }
            rec = existing.get(pid)
            if rec:
                rec.write(vals)
                updated += 1
            else:
                vals["panel_id"] = pid
                self.create(vals)
                created += 1
        gone = self.browse([p.id for pid, p in existing.items() if pid not in seen and p.active])
        gone.write({"active": False})
        self._sync_groups(creds=creds)
        return created, updated, len(gone)

    @api.model
    def _sync_groups(self, creds=None):
        """The groups a sub-reseller can be put in, from `pricing`; archives the ones no longer listed.
        A panel too old to know `pricing` leaves the list alone."""
        pricing = self.env["xtreampro.api"]._pricing(creds=creds)
        if pricing is None:
            return
        Group = self.env["xtreampro.group"].with_context(active_test=False)
        existing = {g.panel_id: g for g in Group.search([])}
        seen = set()
        for row in (pricing.get("sub_reseller") or {}).get("groups") or []:
            if not isinstance(row, dict) or row.get("id") is None:
                continue
            gid = _to_int(row.get("id"), -1)
            if gid < 0:
                continue
            seen.add(gid)
            vals = {"name": row.get("name") or _("Group %s", gid), "active": True}
            if gid in existing:
                existing[gid].write(vals)
            else:
                Group.create(dict(vals, panel_id=gid))
        Group.browse([g.id for gid, g in existing.items() if gid not in seen and g.active]).write({"active": False})

    @api.model
    def _sync_with_notification(self, creds=None, reload=False):
        try:
            created, updated, archived = self._sync_from_panel(creds=creds)
        except XtreamProError as e:
            raise UserError(e.message) from None
        params = {
            "title": _("Xtream UI Pro"),
            "message": _("Packages synced: %(c)s created, %(u)s updated, %(a)s archived.",
                         c=created, u=updated, a=archived),
            "type": "success",
            "sticky": False,
        }
        if reload:
            params["next"] = {"type": "ir.actions.client", "tag": "reload"}
        return {"type": "ir.actions.client", "tag": "display_notification", "params": params}

    @api.model
    def action_sync_packages(self):
        self.env["xtreampro.api"]._require_manager()
        return self._sync_with_notification(reload=True)

    @api.model
    def _cron_sync_packages(self):
        try:
            self._sync_from_panel()
        except XtreamProError as e:
            if e.code == "NOT_CONFIGURED":
                return
            _logger.warning("Xtream UI Pro package sync failed: %s", e.code)
