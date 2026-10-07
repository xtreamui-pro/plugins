import hashlib
import hmac
import json
import logging
import re
import time
from datetime import timedelta

from psycopg2 import IntegrityError

from odoo import api, fields, models

from .xtreampro_line import _from_unix

_logger = logging.getLogger(__name__)

# Seconds a delivery may differ from this server's clock. The panel stamps every attempt anew.
TOLERANCE = 300
# Bytes of body accepted; the panel's events are a few hundred.
MAX_BODY = 65536
# How long an applied event id is remembered: a retry comes within minutes, a replay within the 5-minute window.
EVENT_RETENTION = 24 * 3600
# Events this addon asks the panel for.
EVENTS = "line.renewed,line.enabled,line.disabled,line.deleted,line.expired"

LINE_STATES = {
    "line.expired": "expired",
    "line.renewed": "active",
    "line.enabled": "active",
    "line.disabled": "disabled",
}


def verify_webhook(body, timestamp, signature, secret, now=None):
    """Check a delivery of the panel.

    The panel posts JSON {id, type, created, data} with
    X-Xtream-Timestamp: <unix seconds> and
    X-Xtream-Signature: sha256=<hex HMAC-SHA256(secret, timestamp + "." + body)>.
    Nothing in the body is looked at before the signature has been verified.

    Returns (status, message, event); the event only when the status is 200.
    """
    now = int(time.time()) if now is None else int(now)
    timestamp = timestamp or ""
    signature = signature or ""
    if not secret:
        return 503, "no webhook secret is set", None
    if len(body) > MAX_BODY:
        return 413, "body too large", None
    if not (timestamp.isascii() and timestamp.isdigit()) or len(timestamp) > 12:
        return 400, "missing or invalid timestamp", None
    if abs(now - int(timestamp)) > TOLERANCE:
        return 400, "timestamp outside the accepted window", None
    if not signature.startswith("sha256="):
        return 401, "missing signature", None
    expected = hmac.new(secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    # compare_digest compares in constant time.
    if not hmac.compare_digest(expected.encode(), signature[7:].encode()):
        return 401, "invalid signature", None
    try:
        event = json.loads(body)
    except ValueError:
        return 400, "unreadable event", None
    if not isinstance(event, dict) or not isinstance(event.get("type"), str) or not event["type"]:
        return 400, "unreadable event", None
    return 200, "ok", event


def event_id_of(event):
    """The event's own id ("evt_..."): the panel keeps it the same on every retry, so a receiver
    de-duplicates on it. "" when the event carries none (an older panel) or one of an unexpected shape."""
    value = event.get("id")
    return value if isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9_.:-]{1,64}", value) else ""


class XtreamProWebhookEvent(models.Model):
    """Ids of the webhook events already applied, so a retry of one (the panel delivers at least once)
    is acknowledged without being applied again. Rows older than EVENT_RETENTION are dropped whenever a
    new one is stored. Only the id is kept: nothing about the line or its customer."""

    _name = "xtreampro.webhook.event"
    _description = "Xtream UI Pro webhook event already applied"

    event_id = fields.Char(required=True, index=True)

    _sql_constraints = [
        ("event_id_uniq", "unique(event_id)", "This event id was already applied."),
    ]

    @api.model
    def _seen(self, event_id):
        return bool(self.sudo().search_count([("event_id", "=", event_id)]))

    @api.model
    def _remember(self, event_id):
        events = self.sudo()
        events.search([("create_date", "<", fields.Datetime.now() - timedelta(seconds=EVENT_RETENTION))],
                      limit=1000).unlink()
        try:
            with self.env.cr.savepoint():
                events.create({"event_id": event_id})
        except IntegrityError:
            pass  # a delivery of the same event raced this one and stored the id first


class XtreamProWebhook(models.AbstractModel):
    """Receiver of the webhooks the panel pushes (route /xtreampro/webhook).

    Every method is private (leading underscore) so it cannot be reached over RPC.
    """

    _name = "xtreampro.webhook"
    _description = "Xtream UI Pro webhook receiver"

    @api.model
    def _secret(self):
        return (self.env["ir.config_parameter"].sudo().get_param("xtreampro.webhook_secret") or "").strip()

    @api.model
    def _receive(self, body, timestamp, signature):
        """Verify, then apply, one delivery unless its event id was applied before.
        Returns (http status, message). The id is stored in the same transaction as the event's effect,
        so an event that failed to apply is applied on the retry."""
        status, message, event = verify_webhook(body, timestamp, signature, self._secret())
        if status == 200:
            event_id = event_id_of(event)
            seen = self.env["xtreampro.webhook.event"]
            if event_id and seen._seen(event_id):
                message = "duplicate"
            else:
                message = self._apply(event)
                if event_id:
                    seen._remember(event_id)
        return status, message

    @api.model
    def _apply(self, event):
        """Apply one verified event to the lines that hold it. Idempotent: the panel retries,
        and a replay inside the signature's time window repeats it.

        - line.deleted: the line is archived (state Suspended, as after "Terminate on panel").
        - line.expired / renewed / enabled / disabled: the state and the expiry follow.
        Events of lines this database did not sell are acknowledged and ignored. The exception: an event
        of a webhook registered with the sub-resellers' lines carries the panel id of the line's owner
        (owner_id); when that is a sub-reseller account of this database, only the time of the event is
        noted on it (nothing about the line or its customer is kept).
        """
        etype = event["type"]
        data = event.get("data") if isinstance(event.get("data"), dict) else {}
        if etype == "ping":
            return "pong"
        if etype != "line.deleted" and etype not in LINE_STATES:
            return "ignored"
        try:
            panel_line_id = int(data.get("line_id"))
        except (TypeError, ValueError):
            return "ignored"
        lines = self.env["xtreampro.line"].sudo().with_context(active_test=False).search(
            [("panel_line_id", "=", panel_line_id)])
        if not lines:
            owner_id = data.get("owner_id")
            account = self.env["xtreampro.reseller"].sudo().with_context(active_test=False).search(
                [("panel_user_id", "=", owner_id)], limit=1) if isinstance(owner_id, str) and owner_id else None
            if account:
                account.write({"last_event_at": fields.Datetime.now()})
                return "recorded (line of a sub-reseller account)"
            return "unknown line"
        for line in lines:
            if etype == "line.deleted":
                if line.active:
                    line.write({"state": "disabled", "active": False})
                    line._log_line_message("Xtream UI Pro: line <b>%s</b> was deleted on the panel.", line.username or "")
                continue
            vals = {"state": LINE_STATES[etype]}
            if "exp_date" in data:
                vals["expiry"] = _from_unix(data["exp_date"])
            changed = line.state != vals["state"]
            line.write(vals)
            if changed and etype == "line.expired":
                line._log_line_message("Xtream UI Pro: line <b>%s</b> expired on the panel.", line.username or "")
        return "forgotten" if etype == "line.deleted" else "recorded"
