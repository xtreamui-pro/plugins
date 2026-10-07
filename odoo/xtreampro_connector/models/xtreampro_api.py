import logging

import requests

from odoo import api, models
from odoo.exceptions import AccessError

_logger = logging.getLogger(__name__)

TIMEOUT = (5, 20)  # connect, read (seconds)
API_PATH = "/reseller/v1"
# Sent as "X-Connector: odoo/<version>" on every call (shown in the panel's API call log).
# Keep in step with the version of __manifest__.py (17.0.<this>).
CONNECTOR_VERSION = "1.1.1"

ERROR_MESSAGES = {
    "NOT_CONFIGURED": "The Xtream UI Pro API address or API key is not set. Fill them in under Sales > Configuration > Xtream UI Pro Settings.",
    "BAD_URL": "The Xtream UI Pro API address must start with http:// or https://.",
    "INVALID_API_KEY": "The panel rejected the API key. Generate a new key in the panel (API key page) and update the settings.",
    "FORBIDDEN": "The panel account is not allowed to do this. Only reseller accounts can use the Reseller API, and creating sub-resellers needs a reseller group that is allowed to create sub-resellers.",
    "RESOURCE_NOT_FOUND": "The line or sub-reseller account does not exist on the panel (it may have been deleted there).",
    "INVALID_REQUEST": "The panel refused the request as invalid. Check the product, package and customer data.",
    "INVALID_PACKAGE": "The package is not available to this reseller. Sync the packages and check the product.",
    "INSUFFICIENT_CREDITS": "Not enough credits on the panel: the reseller account (or, when taking credits back, the sub-reseller) does not have the credits needed.",
    "REQUEST_ID_SPENT": "This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. Archive this record; a new sale of the product makes a new line.",
    "READ_ONLY_KEY": "The API key is read-only. Use a key that may change things (panel, API key page).",
    "TOO_MANY_WEBHOOKS": "The reseller account already has the most webhook endpoints the panel allows. Remove one on the panel's API key page.",
    "CONFLICT": "The username or e-mail address is already used on the panel, or the request id was already used.",
    "POST_REQUIRED": "The panel expected a POST request for this operation.",
    "RATE_LIMITED": "The panel is rate limiting requests. Try again in a minute.",
    "UNKNOWN_ACTION": "The panel does not know this operation. Is the panel up to date?",
    "SERVER_ERROR": "The panel reported an internal error. Try again later.",
    "TIMEOUT": "The panel did not answer in time. Try again later.",
    "NETWORK": "The panel could not be reached. Check the API address and the network.",
    "TLS": "The TLS certificate of the panel could not be verified.",
    "REDIRECT": "The panel address redirects elsewhere. Use the final address (for example https://) in the settings.",
    "BAD_RESPONSE": "The panel answered with something that is not a valid Reseller API response.",
}

# Errors that make every further call of a batch pointless.
FATAL_CODES = {"NOT_CONFIGURED", "BAD_URL", "INVALID_API_KEY", "FORBIDDEN", "RATE_LIMITED",
               "TIMEOUT", "NETWORK", "TLS", "REDIRECT", "SERVER_ERROR"}


class XtreamProError(Exception):
    """Failure of a Reseller API call. Never contains the API key."""

    def __init__(self, code, http_status=None, detail=None):
        self.code = code or "SERVER_ERROR"
        self.http_status = http_status
        self.message = ERROR_MESSAGES.get(self.code, "The panel returned an error (%s)." % self.code)
        if detail:
            self.message = "%s %s" % (self.message, detail)
        super().__init__(self.message)


def describe_compatibility(compat):
    """One sentence for the user from a `package_compatibility` answer: is the time left kept, what does it cost."""
    def days(seconds):
        try:
            return int(round(max(0, int(seconds)) / 86400.0))
        except (TypeError, ValueError):
            return 0

    if compat.get("keeps_time_left"):
        if compat.get("time_left_seconds") is not None:
            time_text = "the time left (about %d days) is kept and the new period is added to it" % days(compat["time_left_seconds"])
        else:
            time_text = "the time left is kept and the new period is added to it"
    else:
        time_text = "the new period starts today"
        if int(compat.get("time_lost_seconds") or 0) > 0:
            time_text += " and the time left (about %d days) is lost" % days(compat["time_lost_seconds"])
    try:
        price = int(compat.get("price") or 0)
    except (TypeError, ValueError):
        price = 0
    return "On the panel %s; the change costs %d credits." % (time_text, price)


def sells_line(package):
    """Whether a package of `packages` / `pricing` can be sold as a plain IPTV line. The panel lists what a
    package can be sold as in `sells` (a subset of line, mag, enigma); a panel too old to send it leaves the
    key out and the package then counts as sellable (the sale itself is still checked by the panel)."""
    sells = package.get("sells") if isinstance(package, dict) else None
    return "line" in sells if isinstance(sells, list) else True


def normalize_base(url):
    url = (url or "").strip().rstrip("/")
    if url.lower().endswith(API_PATH):
        url = url[: -len(API_PATH)].rstrip("/")
    return url


class XtreamProApi(models.AbstractModel):
    """Thin client of the Xtream UI Pro Reseller API.

    Every method is private (leading underscore) so it cannot be reached over RPC.
    """

    _name = "xtreampro.api"
    _description = "Xtream UI Pro API client"

    # --- configuration -------------------------------------------------
    @api.model
    def _base_url(self):
        icp = self.env["ir.config_parameter"].sudo()
        return normalize_base(icp.get_param("xtreampro.api_url"))

    @api.model
    def _credentials(self):
        icp = self.env["ir.config_parameter"].sudo()
        return normalize_base(icp.get_param("xtreampro.api_url")), (icp.get_param("xtreampro.api_key") or "").strip()

    @api.model
    def _require_group(self, *xmlids):
        user = self.env.user
        if not any(user.has_group(x) for x in xmlids) and not user.has_group("base.group_system"):
            raise AccessError("You are not allowed to do this.")

    @api.model
    def _require_manager(self):
        self._require_group("sales_team.group_sale_manager")

    # --- transport -----------------------------------------------------
    @api.model
    def _call(self, action, params=None, post=False, creds=None):
        """Run one API action and return the `data` member of the answer."""
        base, key = creds or self._credentials()
        if not base or not key:
            raise XtreamProError("NOT_CONFIGURED")
        if not base.lower().startswith(("http://", "https://")):
            raise XtreamProError("BAD_URL")
        payload = {k: v for k, v in (params or {}).items() if v is not None}
        payload["action"] = action
        headers = {"X-API-Key": key, "Accept": "application/json",
                   "X-Connector": "odoo/%s" % CONNECTOR_VERSION}
        url = base + API_PATH
        try:
            if post:
                resp = requests.post(url, data=payload, headers=headers, timeout=TIMEOUT,
                                     verify=True, allow_redirects=False)
            else:
                resp = requests.get(url, params=payload, headers=headers, timeout=TIMEOUT,
                                    verify=True, allow_redirects=False)
        except requests.exceptions.SSLError:
            raise XtreamProError("TLS") from None
        except requests.exceptions.Timeout:
            raise XtreamProError("TIMEOUT") from None
        except requests.exceptions.RequestException:
            raise XtreamProError("NETWORK") from None

        if 300 <= resp.status_code < 400:
            raise XtreamProError("REDIRECT", resp.status_code)
        try:
            body = resp.json()
        except ValueError:
            raise XtreamProError("BAD_RESPONSE", resp.status_code) from None
        if not isinstance(body, dict):
            raise XtreamProError("BAD_RESPONSE", resp.status_code)
        if body.get("status") == "STATUS_SUCCESS":
            return body.get("data")
        code = body.get("error")
        if not code:
            code = "SERVER_ERROR" if resp.status_code >= 500 else "INVALID_REQUEST"
        _logger.info("Xtream UI Pro API action %s failed: %s (HTTP %s)", action, code, resp.status_code)
        raise XtreamProError(code, resp.status_code)

    # --- operations ----------------------------------------------------
    @api.model
    def _user_info(self, creds=None):
        return self._call("user_info", creds=creds) or {}

    @api.model
    def _packages(self, creds=None):
        return self._call("packages", creds=creds) or []

    @api.model
    def _pricing(self, creds=None):
        """What the reseller can sell and afford; None when the panel is too old to know `pricing`."""
        try:
            data = self._call("pricing", creds=creds)
        except XtreamProError as e:
            if e.code == "UNKNOWN_ACTION":
                return None
            raise
        return data if isinstance(data, dict) else None

    @api.model
    def _check_needs(self, needs):
        """Fail before anything is bought when it cannot be paid for or is not on sale.

        A need is {"kind": "line", "package_id": panel package id, "trial": bool, "units": int}
        (units = official periods or trial lines to pay for; a renewal or a package change is
        one official period; "renewal": True skips the check that the package can be sold as a plain
        line, because a renewal sells another period of a line that exists) or {"kind": "reseller", "create": bool, "credits": int,
        "group_id": panel group id or 0} (create = the account does not exist yet,
        credits = what is handed over). Raises XtreamProError INVALID_PACKAGE, FORBIDDEN,
        INVALID_REQUEST or INSUFFICIENT_CREDITS; returns quietly for an old panel without
        `pricing`. The answer is a look, not a reservation: the panel charges again,
        atomically, when it creates the line.
        """
        if not needs:
            return
        pricing = self._pricing()
        if pricing is None:
            return
        balance = int(pricing.get("credits") or 0)
        packages = {int(p["id"]): p for p in (pricing.get("packages") or []) if isinstance(p, dict) and "id" in p}
        sub = pricing.get("sub_reseller") or {}
        total = 0
        for need in needs:
            if need["kind"] == "reseller":
                if need.get("create"):
                    if not sub.get("can_create"):
                        raise XtreamProError("FORBIDDEN")
                    group_id = int(need.get("group_id") or 0)
                    if group_id and group_id not in {int(g["id"]) for g in (sub.get("groups") or []) if isinstance(g, dict) and "id" in g}:
                        raise XtreamProError("INVALID_REQUEST", detail="The sub-reseller group chosen on the product (#%s) is not one this reseller may use." % group_id)
                    total += int(sub.get("price") or 0)
                total += int(need.get("credits") or 0)
                continue
            package = packages.get(int(need["package_id"]))
            if package is None:
                raise XtreamProError("INVALID_PACKAGE", detail="Package #%s is not in the list of packages this reseller may sell." % need["package_id"])
            if not need.get("renewal") and not sells_line(package):
                raise XtreamProError("INVALID_PACKAGE", detail="The package is for MAG / Enigma boxes only and cannot be sold as an IPTV line.")
            if need.get("trial"):
                if not package.get("is_trial"):
                    raise XtreamProError("INVALID_PACKAGE", detail="The package is not offered as a trial.")
                price = int(package.get("trial_credits") or 0)
            else:
                if not package.get("is_official"):
                    raise XtreamProError("INVALID_PACKAGE", detail="The package is not offered as an official period.")
                price = int(package.get("official_credits") or 0)
            total += price * max(1, int(need.get("units") or 1))
        # A balance of 0 sells nothing, not even a free package: the panel refuses it.
        if balance <= 0 or balance < total:
            raise XtreamProError("INSUFFICIENT_CREDITS", 402,
                                 "This needs %d credits, the reseller account has %d." % (total, balance))

    @staticmethod
    def _links_of(data):
        """The play links of a get_line / create_line / renew_line / change_package answer
        (server, m3u, m3u_hls, xmltv, player_api, web_player), or None when it carried none
        (an older panel, or a line whose password is stored hashed)."""
        links = data.get("links") if isinstance(data, dict) else None
        if not isinstance(links, dict):
            return None
        clean = {k: str(v) for k, v in links.items()
                 if k in ("server", "m3u", "m3u_hls", "xmltv", "player_api", "web_player") and isinstance(v, str) and v}
        return clean or None

    @api.model
    def _get_line(self, panel_line_id):
        return self._call("get_line", {"id": panel_line_id}) or {}

    @api.model
    def _create_line(self, package_id, trial, request_id, username=None, password=None):
        return self._call("create_line", {
            "package_id": package_id,
            "trial": 1 if trial else 0,
            "username": username or None,
            "password": password or None,
            "request_id": (request_id or "")[:64] or None,
        }, post=True) or {}

    @api.model
    def _renew_line(self, panel_line_id, request_id):
        return self._call("renew_line", {
            "id": panel_line_id,
            "request_id": (request_id or "")[:64] or None,
        }, post=True) or {}

    @api.model
    def _change_package(self, panel_line_id, package_id, request_id):
        """Upgrade / downgrade: sells an official period of another package on the line."""
        return self._call("change_package", {
            "id": panel_line_id,
            "package_id": package_id,
            "request_id": (request_id or "")[:64] or None,
        }, post=True) or {}

    @api.model
    def _package_compatibility(self, panel_line_id, package_id):
        """What change_package would do on this line, asked without selling anything: keeps_time_left,
        reason, time_left_seconds, time_lost_seconds, exp_date, new_exp_date, price, can_afford.
        None when the panel is too old to know the action (the change is then decided when it is sold);
        raises XtreamProError INVALID_PACKAGE when the package cannot be sold on that line."""
        try:
            data = self._call("package_compatibility", {"id": panel_line_id, "package_id": package_id})
        except XtreamProError as e:
            if e.code == "UNKNOWN_ACTION":
                return None
            raise
        return data if isinstance(data, dict) and "keeps_time_left" in data else None

    @api.model
    def _enable_line(self, panel_line_id):
        return self._call("enable_line", {"id": panel_line_id}, post=True)

    @api.model
    def _disable_line(self, panel_line_id):
        return self._call("disable_line", {"id": panel_line_id}, post=True)

    @api.model
    def _delete_line(self, panel_line_id):
        return self._call("delete_line", {"id": panel_line_id}, post=True)

    # --- sub-reseller accounts -----------------------------------------
    @api.model
    def _sub_users(self, search=None, start=0, limit=500):
        """One page of the accounts below the API key's reseller (list of dicts)."""
        data = self._call("get_users", {
            "search": search or None,
            "start": int(start or 0),
            "limit": max(1, min(int(limit or 500), 500)),
        })
        if isinstance(data, dict):
            data = data.get("data")
        return [u for u in (data or []) if isinstance(u, dict)]

    @api.model
    def _find_sub_user(self, panel_id, username):
        """The panel has no get-one action: search by username and match the id."""
        for user in self._sub_users(search=username):
            if str(user.get("id")) == str(panel_id):
                return user
        raise XtreamProError("RESOURCE_NOT_FOUND")

    @api.model
    def _create_sub_user(self, username, password, email, fullname, request_id, group_id=None):
        return self._call("create_user", {
            "username": username,
            "password": password,
            "email": email,
            "fullname": fullname or None,
            "group_id": group_id or None,
            "request_id": (request_id or "")[:64] or None,
        }, post=True) or {}

    @api.model
    def _adjust_credits(self, panel_user_id, credits, note, request_id):
        return self._call("adjust_credits", {
            "id": panel_user_id,
            "credits": int(credits),
            "note": (note or "")[:200] or None,
            "request_id": (request_id or "")[:64] or None,
        }, post=True) or {}

    @api.model
    def _set_sub_user_state(self, panel_user_id, enabled):
        return self._call("enable_user" if enabled else "disable_user", {"id": panel_user_id}, post=True)

    @api.model
    def _delete_sub_user(self, panel_user_id):
        """Final: the account's credits, lines and sub-accounts go to the reseller, its personal data is erased."""
        return self._call("delete_user", {"id": panel_user_id}, post=True)

    # --- webhooks pushed by the panel ----------------------------------
    @api.model
    def _create_webhook(self, url, events, creds=None, include_sub_resellers=False):
        """Registers an https address; the answer holds the id and, this once, the signing `secret`.
        include_sub_resellers also sends the events of lines owned by accounts below the reseller
        (the panel's default, and this method's, is off)."""
        return self._call("create_webhook", {"url": url, "events": events,
                                             "include_sub_resellers": 1 if include_sub_resellers else None},
                          post=True, creds=creds) or {}

    @api.model
    def _test_webhook(self, webhook_id):
        return self._call("test_webhook", {"id": webhook_id}, post=True)

    @api.model
    def _delete_webhook(self, webhook_id):
        return self._call("delete_webhook", {"id": webhook_id}, post=True)
