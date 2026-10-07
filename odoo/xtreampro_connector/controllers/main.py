from odoo import http
from odoo.http import request

from ..models.xtreampro_webhook import MAX_BODY


class XtreamProWebhookController(http.Controller):

    @http.route("/xtreampro/webhook", type="http", auth="public", methods=["POST"], csrf=False, save_session=False)
    def webhook(self, **_kwargs):
        """The panel is not an Odoo user: what lets a call in is the HMAC signature, checked
        before anything else happens (xtreampro.webhook._receive)."""
        headers = request.httprequest.headers
        body = request.httprequest.get_data(cache=False)[: MAX_BODY + 1]
        status, message = request.env["xtreampro.webhook"].sudo()._receive(
            body, headers.get("X-Xtream-Timestamp"), headers.get("X-Xtream-Signature"))
        return request.make_json_response({"ok": status == 200, "message": message}, status=status)
