"""Small client of the Xtream UI Pro Reseller API.

Platform independent: this file (and provisioner.py next to it) never imports
Frappe, so it can be run with plain Python. The only dependency is `requests`,
which Frappe ships anyway.

Talks to {base}/reseller/v1 with the reseller's API key in the X-API-Key header.
Reads are GET with a query string, mutations are POST with a form body.
"""

import logging
import re

import requests

from xtreampro_connector import __version__ as CONNECTOR_VERSION

LOG = logging.getLogger("xtreampro_connector")

API_PATH = "/reseller/v1"
TIMEOUT = (5, 20)  # connect, read (seconds)

# Readable text for every error code (API codes and the ones raised here).
ERROR_MESSAGES = {
    "NOT_CONFIGURED": "The Xtream UI Pro API address or API key is not set. Fill them in under Xtream UI Pro Settings.",
    "BAD_URL": "The Xtream UI Pro API address must start with http:// or https://.",
    "INVALID_API_KEY": "The panel rejected the API key. Generate a new key in the panel (API key page) and update the settings.",
    "FORBIDDEN": "The panel account is not allowed to do this. Only reseller accounts can use the Reseller API, and creating sub-resellers needs a reseller group that is allowed to create sub-resellers.",
    "RESOURCE_NOT_FOUND": "The line or sub-reseller account does not exist on the panel (it may have been deleted there).",
    "INVALID_REQUEST": "The panel refused the request as invalid. Check the item, the package and the customer data (a sub-reseller needs an e-mail address).",
    "INVALID_PACKAGE": "The package does not exist, is not available to this reseller, or cannot be sold this way (for example a package for MAG / Enigma boxes only sold as a line). Sync the packages and check the item.",
    "INSUFFICIENT_CREDITS": "Not enough credits on the panel: the reseller account (or, when taking credits back, the sub-reseller) does not have the credits needed.",
    "CONFLICT": "The username or e-mail address is already used on the panel, or the request id was already used for another operation.",
    "REQUEST_ID_SPENT": "This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. Terminate the line here and create it again.",
    "READ_ONLY_KEY": "The API key is read-only. Create a key that may change things on the panel's API key page.",
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

# Errors after which every further call of a batch is pointless.
FATAL_CODES = frozenset({
    "NOT_CONFIGURED", "BAD_URL", "INVALID_API_KEY", "FORBIDDEN", "RATE_LIMITED",
    "TIMEOUT", "NETWORK", "TLS", "REDIRECT", "SERVER_ERROR",
})


class XtreamProError(Exception):
    """A failed Reseller API call. `message` is readable English and never holds the API key."""

    def __init__(self, code, http_status=None, message=None, detail=""):
        self.code = code or "SERVER_ERROR"
        self.http_status = http_status
        text = message or ERROR_MESSAGES.get(self.code, "The panel returned an error (%s)." % self.code)
        # `detail` is a sentence appended to the readable text, e.g. the amounts of a refused sale.
        self.message = "%s %s" % (text, detail) if detail else text
        super().__init__(self.message)


def sells_line(package):
    """Whether a package of `packages` / `pricing` can be sold as a plain IPTV line.

    The panel lists what a package can be sold as in `sells` (a subset of line, mag, enigma). A panel
    too old to send it leaves the key out; the package then counts as sellable (the sale itself is
    still checked by the panel)."""
    sells = package.get("sells") if isinstance(package, dict) else None
    if not isinstance(sells, list):
        return True
    return "line" in sells


def normalize_base(url):
    """Panel base address without trailing slash and without a pasted /reseller/v1."""
    url = (url or "").strip().rstrip("/")
    if url.lower().endswith(API_PATH):
        url = url[: -len(API_PATH)].rstrip("/")
    return url


_PASSWORD_JSON = re.compile(r'("password"\s*:\s*)"(?:[^"\\]|\\.)*"')
_PASSWORD_QUERY = re.compile(r"(password=)[^&\"\\\s]+")


def mask_secrets(text):
    """Hide line passwords in a text: JSON members and `password=` inside play links."""
    text = _PASSWORD_JSON.sub(r'\1"********"', text or "")
    return _PASSWORD_QUERY.sub(r"\1********", text)


def mask_params(params):
    """Copy of request parameters that is safe to log (no passwords)."""
    return {k: ("********" if k == "password" else v) for k, v in params.items()}


class PanelClient:
    """One connection to the panel's Reseller API."""

    def __init__(self, base_url, api_key, session=None):
        self.base = normalize_base(base_url)
        self.api_key = (api_key or "").strip()
        self.session = session or requests.Session()

    # --- transport -----------------------------------------------------
    def call(self, action, params=None, post=False):
        """Run one API action and return the `data` member of the answer."""
        if not self.base or not self.api_key:
            raise XtreamProError("NOT_CONFIGURED")
        if not self.base.lower().startswith(("http://", "https://")):
            raise XtreamProError("BAD_URL")
        payload = {k: v for k, v in (params or {}).items() if v is not None}
        payload["action"] = action
        # X-Connector names this connector in the panel's API call log (never parameters, never the key).
        headers = {"X-API-Key": self.api_key, "Accept": "application/json",
                   "X-Connector": "erpnext/%s" % CONNECTOR_VERSION}
        url = self.base + API_PATH
        try:
            # TLS verification stays on and redirects are never followed: a
            # redirect could carry the API key to another host.
            if post:
                resp = self.session.post(url, data=payload, headers=headers, timeout=TIMEOUT,
                                         verify=True, allow_redirects=False)
            else:
                resp = self.session.get(url, params=payload, headers=headers, timeout=TIMEOUT,
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
            LOG.debug("panel %s ok %s", action, mask_params(payload))
            return body.get("data")
        code = body.get("error")
        if not code or not isinstance(code, str):
            code = "SERVER_ERROR" if resp.status_code >= 500 else "INVALID_REQUEST"
        LOG.info("panel %s failed: %s (HTTP %s) %s", action, code, resp.status_code, mask_params(payload))
        raise XtreamProError(code, resp.status_code)

    # --- reads and lines -------------------------------------------------
    def user_info(self):
        return self.call("user_info") or {}

    def packages(self):
        data = self.call("packages")
        return data if isinstance(data, list) else []

    def pricing(self):
        """What the reseller can sell and afford: credits, packages[] (the reseller's prices, `sells`),
        sub_reseller and the line rules. None when the panel is too old to know the action; the sale
        is then still decided by the panel."""
        try:
            data = self.call("pricing")
        except XtreamProError as e:
            if e.code == "UNKNOWN_ACTION":
                return None
            raise
        return data if isinstance(data, dict) else None

    def get_line(self, panel_line_id):
        return self.call("get_line", {"id": int(panel_line_id)}) or {}

    def create_line(self, package_id, trial, request_id):
        # Username and password are left to the panel: the reseller's group may
        # ignore chosen credentials anyway, so the answer is what counts.
        return self.call("create_line", {
            "package_id": int(package_id),
            "trial": 1 if trial else 0,
            "request_id": (request_id or "")[:64] or None,
        }, post=True) or {}

    def renew_line(self, panel_line_id, request_id):
        return self.call("renew_line", {
            "id": int(panel_line_id),
            "request_id": (request_id or "")[:64] or None,
        }, post=True) or {}

    def line_action(self, action, panel_line_id):
        """action: enable_line | disable_line | delete_line (delete cannot be undone)."""
        return self.call(action, {"id": int(panel_line_id)}, post=True)

    # --- sub-reseller accounts ---------------------------------------------
    def sub_users(self, search=None, start=0, limit=500):
        """One page of the accounts below the key's reseller (list of dicts)."""
        data = self.call("get_users", {
            "search": search or None,
            "start": int(start or 0),
            "limit": max(1, min(int(limit or 500), 500)),
        })
        if isinstance(data, dict):
            data = data.get("data")
        return [u for u in (data or []) if isinstance(u, dict)]

    def find_sub_user(self, panel_user_id, username):
        """The panel has no get-one action for accounts: search by username, match the id."""
        for user in self.sub_users(search=username):
            if str(user.get("id")) == str(panel_user_id):
                return user
        raise XtreamProError("RESOURCE_NOT_FOUND")

    def create_sub_user(self, username, password, email, fullname, request_id):
        # With a request id the panel needs both username and password.
        return self.call("create_user", {
            "username": username,
            "password": password,
            "email": email,
            "fullname": fullname or None,
            "request_id": (request_id or "")[:64] or None,
        }, post=True) or {}

    def adjust_credits(self, panel_user_id, credits, note, request_id):
        return self.call("adjust_credits", {
            "id": str(panel_user_id),
            "credits": int(credits),
            "note": (note or "")[:200] or None,
            "request_id": (request_id or "")[:64] or None,
        }, post=True) or {}

    def sub_user_action(self, action, panel_user_id):
        """action: enable_user | disable_user."""
        return self.call(action, {"id": str(panel_user_id)}, post=True)
