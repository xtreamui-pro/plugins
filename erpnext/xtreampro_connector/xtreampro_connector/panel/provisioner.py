"""Business logic of the connector, free of any Frappe class.

The Frappe layer (doctypes, hooks, scheduler) reads plain values from its
records, calls one method here and stores what comes back. Nothing in this file
knows about invoices or DocTypes: it knows lines, sub-reseller accounts, request
ids and error texts.

Naming used throughout:

* "line"      an IPTV subscriber line of the reseller.
* "reseller"  a sub-reseller account below the reseller whose API key is used.
* request id  what makes create / renew / credit transfers idempotent on the
              panel: the same request id always returns the first answer and
              never charges twice. Built from stable ids of the shop, from a
              per-installation id (two shops with the same invoice number must
              not replay each other) and from a "generation" counter (so that
              creating a terminated service again sells a NEW line).
"""

import hashlib
import logging
import re
import secrets
import unicodedata

from .client import FATAL_CODES, XtreamProError, sells_line

LOG = logging.getLogger("xtreampro_connector")

REQUEST_ID_MAX = 64            # the panel refuses longer request ids
USERNAME_RE = re.compile(r"^[A-Za-z0-9._-]{3,32}$")
USERNAME_MAX = 32
USERNAME_SUFFIX = 4            # random hex characters appended to a generated username
PASSWORD_LENGTH = 14
PASSWORD_MIN = 8
# No 0/O, 1/l/I: the password is read from a screen and typed into a TV box.
PASSWORD_ALPHABET = "abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789"

LINE_STATUSES = {"active", "expired", "disabled", "banned"}
RESELLER_STATUSES = {"active", "disabled"}


# ---------------------------------------------------------------------------
# Small pure helpers
# ---------------------------------------------------------------------------

def to_int(value, default=0):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def make_request_id(install_id, kind, *parts):
    """Request id (at most 64 characters) for one business event.

    Readable when it is short and made of safe characters, otherwise a hash of
    the parts, so two different events can never end up with the same id.
    """
    raw = "-".join(str(p) for p in parts)
    readable = "erp-%s-%s-%s" % (install_id, kind, raw)
    if len(readable) <= REQUEST_ID_MAX and re.fullmatch(r"[A-Za-z0-9_.:-]+", readable):
        return readable
    digest = hashlib.sha256(("%s|%s|%s" % (install_id, kind, raw)).encode("utf-8")).hexdigest()[:40]
    return "erp-%s-%s-%s" % (install_id, kind, digest)


def make_username(name, email=None, seed="x"):
    """Panel-safe username (3-32 characters) from a name or e-mail plus a random suffix."""
    source = name or (email or "").split("@")[0]
    ascii_name = unicodedata.normalize("NFKD", source or "").encode("ascii", "ignore").decode()
    base = re.sub(r"[^A-Za-z0-9_.-]", "", ascii_name)
    base = base[: USERNAME_MAX - USERNAME_SUFFIX - 1] or ("r%s" % re.sub(r"[^A-Za-z0-9]", "", str(seed))[:8])
    suffix = secrets.token_hex(USERNAME_SUFFIX // 2)
    return ("%s_%s" % (base, suffix))[:USERNAME_MAX]


def make_password(length=PASSWORD_LENGTH):
    """Random password from the operating system's CSPRNG."""
    return "".join(secrets.choice(PASSWORD_ALPHABET) for _i in range(length))


def tag_email(email, generation):
    """The panel keeps e-mail addresses unique. A sub-reseller created again
    (generation 1, 2, ...) gets a tagged address, user+g1@example.com, which
    still reaches the same mailbox."""
    email = (email or "").strip()
    if not generation or "@" not in email:
        return email
    local, _at, domain = email.rpartition("@")
    return "%s+g%d@%s" % (local.split("+g")[0], generation, domain)


def missing_units(done_indexes, quantity):
    """Unit numbers (1-based) of an ordered quantity that have no line yet."""
    done = set(done_indexes)
    return [n for n in range(1, max(0, to_int(quantity)) + 1) if n not in done]


def credits_for(per_unit, quantity):
    """Credits to hand over for a quantity of an item (whole number, never negative)."""
    return max(0, to_int(per_unit)) * max(0, to_int(quantity))


def explain(exc):
    """Readable text of an expected failure, None for anything unexpected."""
    return exc.message if isinstance(exc, XtreamProError) else None


def line_fields(line, password=None):
    """Plain values of a panel line. A key is only present when the panel said it,
    so a refresh never wipes a stored value."""
    out = {}
    if line.get("id") is not None:
        out["panel_line_id"] = to_int(line["id"])
    if line.get("username"):
        out["username"] = str(line["username"])
    pw = line.get("password") or password
    if pw:
        out["password"] = str(pw)
    if line.get("status") in LINE_STATUSES:
        out["status"] = line["status"]
    if "exp_date" in line:
        out["expiry"] = to_int(line["exp_date"], 0) or None   # None = never expires
    if line.get("max_connections") is not None:
        out["max_connections"] = to_int(line["max_connections"])
    if "is_trial" in line:
        out["is_trial"] = bool(line["is_trial"])
    if line.get("package_id") is not None:
        out["package_id"] = to_int(line["package_id"], -1)
    if isinstance(line.get("links"), dict):
        out["links"] = line["links"]          # carry the password: never log or store
    return out


def reseller_fields(user):
    out = {}
    if user.get("id") is not None:
        out["panel_user_id"] = str(user["id"])
    if user.get("username"):
        out["username"] = str(user["username"])
    if user.get("status") in RESELLER_STATUSES:
        out["status"] = user["status"]
    if user.get("credits") is not None:
        out["credits"] = to_int(user["credits"])
    return out


# ---------------------------------------------------------------------------
# The provisioner
# ---------------------------------------------------------------------------

class Provisioner:
    """Every panel operation the connector needs, on plain values."""

    def __init__(self, client, install_id):
        self.client = client
        self.install_id = install_id or "x"

    # --- request ids (one place, so the glue cannot get them wrong) --------
    def rid_line(self, invoice, row, unit, generation):
        return make_request_id(self.install_id, "line", invoice, row, unit, "g%d" % generation)

    def rid_renew_invoice(self, invoice, row):
        return make_request_id(self.install_id, "renew", invoice, row)

    def rid_renew_manual(self, line_name, day):
        return make_request_id(self.install_id, "renewm", line_name, day)

    def rid_reseller(self, customer, generation):
        return make_request_id(self.install_id, "sub", customer, "g%d" % generation)

    def rid_credit(self, invoice, row, generation):
        return make_request_id(self.install_id, "subc", invoice, row, "g%d" % generation)

    def rid_credit_back(self, document, row, generation):
        return make_request_id(self.install_id, "subx", document, row, "g%d" % generation)

    def rid_credit_manual(self, transfer_name):
        return make_request_id(self.install_id, "subm", transfer_name)

    # --- connection --------------------------------------------------------
    def test_connection(self):
        """The reseller behind the key: {username, credits, ...}."""
        info = self.client.user_info()
        return {"username": info.get("username"), "credits": to_int(info.get("credits")),
                "status": info.get("status")}

    def packages(self):
        """Packages of the reseller as plain rows for the Package doctype."""
        rows = []
        for p in self.client.packages():
            # A package for MAG / Enigma boxes only (`sells` without "line") cannot be sold as a line.
            if not isinstance(p, dict) or p.get("id") is None or to_int(p["id"], -1) < 0 or not sells_line(p):
                continue
            rows.append({
                "panel_id": to_int(p["id"]),
                "package_name": p.get("name") or "Package %s" % p["id"],
                "is_trial": 1 if p.get("is_trial") else 0,
                "is_official": 1 if p.get("is_official") else 0,
                "duration": to_int(p.get("official_duration")),
                "duration_in": p.get("official_duration_in") or "",
                "trial_duration": to_int(p.get("trial_duration")),
                "trial_duration_in": p.get("trial_duration_in") or "",
                "trial_credits": float(p.get("trial_credits") or 0),
                "official_credits": float(p.get("official_credits") or 0),
                "max_connections": to_int(p.get("max_connections")),
            })
        return rows

    # --- pricing: ask before selling ------------------------------------------
    def assert_can_sell_package(self, package_id, trial, plain_line=True):
        """Fail before anything is sold when one official (or trial) period of the package is not on
        sale for this reseller or costs more than the balance. The answer is only a look: the panel
        charges again, atomically, when the line is created. `plain_line`: the package is sold as a
        plain line (create); a renewal of an existing line passes False."""
        pricing = self.client.pricing()
        if pricing is None:
            return  # a panel too old to know `pricing`: the sale itself is still refused by the panel
        package = None
        for row in pricing.get("packages") or []:
            if isinstance(row, dict) and to_int(row.get("id"), -1) == to_int(package_id):
                package = row
                break
        if package is None:
            raise XtreamProError("INVALID_PACKAGE", 400, detail=(
                "Package #%d is not in the list of packages this reseller may sell." % to_int(package_id)))
        if plain_line and not sells_line(package):
            raise XtreamProError("INVALID_PACKAGE", 400, detail=(
                "The package is for MAG / Enigma boxes only and cannot be sold as an IPTV line."))
        if not (package.get("is_trial") if trial else package.get("is_official")):
            raise XtreamProError("INVALID_PACKAGE", 400, detail=(
                "The package is not offered as %s." % ("a trial" if trial else "an official period")))
        cost = to_int(package.get("trial_credits") if trial else package.get("official_credits"))
        self._assert_balance(pricing, cost)

    def assert_can_create_sub_user(self, credits_on_create=0):
        """Fail before an account is created when the reseller's group may not create sub-resellers
        or the balance does not cover the account price plus the credits handed over at once."""
        pricing = self.client.pricing()
        if pricing is None:
            return
        sub = pricing.get("sub_reseller") if isinstance(pricing.get("sub_reseller"), dict) else {}
        if not sub.get("can_create"):
            raise XtreamProError("FORBIDDEN")
        self._assert_balance(pricing, to_int(sub.get("price")) + to_int(credits_on_create))

    def assert_can_give_credits(self, credits):
        """Fail when the balance cannot give `credits` to a sub-reseller."""
        pricing = self.client.pricing()
        if pricing is not None:
            self._assert_balance(pricing, to_int(credits))

    @staticmethod
    def _assert_balance(pricing, cost):
        # A balance of 0 sells nothing, not even a free package (the panel refuses it).
        balance = to_int(pricing.get("credits"))
        if balance <= 0 or balance < cost:
            raise XtreamProError("INSUFFICIENT_CREDITS", 402, detail=(
                "This needs %d credits, the reseller account has %d." % (cost, balance)))

    # --- lines ---------------------------------------------------------------
    def create_line(self, package_id, trial, request_id):
        """Create (or, for a repeated request id, fetch again) a line."""
        if to_int(package_id) <= 0:
            raise XtreamProError("INVALID_PACKAGE")
        # Ask the panel first: too few credits, a package that is not on sale or one for boxes only fail
        # here with the amounts and nothing is created.
        self.assert_can_sell_package(package_id, trial, plain_line=True)
        data = self.client.create_line(package_id, trial, request_id)
        line = data.get("line") if isinstance(data, dict) else None
        if not isinstance(line, dict) or line.get("id") is None:
            raise XtreamProError("BAD_RESPONSE")
        fields = line_fields(line, password=data.get("password"))
        if isinstance(data.get("links"), dict):
            fields.setdefault("links", data["links"])
        return fields

    def read_line(self, panel_line_id):
        data = self.client.get_line(panel_line_id)
        if not isinstance(data, dict) or not data:
            raise XtreamProError("BAD_RESPONSE")
        return line_fields(data)

    def renew_line(self, panel_line_id, request_id):
        """Renew a line (charged). The same request id never charges twice."""
        # Fails with the amounts when the balance cannot pay the period; nothing is sold then.
        current = self.client.get_line(panel_line_id)
        if isinstance(current, dict) and to_int(current.get("package_id")) > 0:
            self.assert_can_sell_package(current["package_id"], False, plain_line=False)
        data = self.client.renew_line(panel_line_id, request_id)
        line = data.get("line") if isinstance(data, dict) else None
        fields = line_fields(line) if isinstance(line, dict) and line else self._read_quietly(panel_line_id, {})
        fields["credits_charged"] = data.get("credits_charged") if isinstance(data, dict) else None
        fields["credits_balance"] = data.get("credits_balance") if isinstance(data, dict) else None
        return fields

    def set_line_enabled(self, panel_line_id, enabled):
        """Suspend (disable) or unsuspend (enable) a line; returns the fresh state."""
        self.client.line_action("enable_line" if enabled else "disable_line", panel_line_id)
        return self._read_quietly(panel_line_id, {"status": "active" if enabled else "disabled"})

    def terminate_line(self, panel_line_id, delete):
        """Delete the line (final) or only disable it. A line already gone counts as done."""
        gone = False
        try:
            self.client.line_action("delete_line" if delete else "disable_line", panel_line_id)
        except XtreamProError as e:
            if e.code != "RESOURCE_NOT_FOUND":
                raise
            gone = True
        return {"deleted": bool(delete) or gone, "already_gone": gone}

    def refresh_lines(self, items):
        """Refresh many lines. items: [(key, panel_line_id)].

        Returns (results, stopped): results is [(key, fields, error_message)];
        stopped is the code of a fatal error (wrong key, panel down, ...) after
        which the rest of the batch was not tried.
        """
        results, stopped = [], None
        for key, panel_line_id in items:
            try:
                results.append((key, self.read_line(panel_line_id), None))
            except XtreamProError as e:
                if e.code in FATAL_CODES:
                    LOG.warning("line refresh stopped: %s", e.code)
                    return results, e.code
                results.append((key, None, e.message))
        return results, stopped

    def _read_quietly(self, panel_line_id, fallback):
        """The action itself already worked; a failing read must not undo that."""
        try:
            return self.read_line(panel_line_id)
        except XtreamProError:
            return dict(fallback)

    # --- sub-reseller accounts -------------------------------------------------
    def create_reseller(self, username, password, email, fullname, request_id, generation=0):
        """Create a sub-reseller account (charged: the group's sub-reseller price).

        Username and password must be stored by the caller BEFORE this call and
        passed again on every retry: the panel keeps only a hash of the password.
        """
        if not USERNAME_RE.match(username or ""):
            raise XtreamProError("INVALID_REQUEST", message=(
                "The username is not valid for a sub-reseller: use 3 to 32 letters, digits, '_', '.' or '-'."))
        if len(password or "") < PASSWORD_MIN:
            raise XtreamProError("INVALID_REQUEST", message="The password of a sub-reseller needs at least 8 characters.")
        if not (email or "").strip():
            raise XtreamProError("INVALID_REQUEST", message=(
                "The customer has no e-mail address; the panel needs one to create the sub-reseller account."))
        # The reseller's group must be allowed to create sub-resellers and the balance must cover the price.
        self.assert_can_create_sub_user()
        data = self.client.create_sub_user(username, password, tag_email(email, generation),
                                           (fullname or "")[:128], request_id)
        user = data.get("user") if isinstance(data, dict) else None
        if not isinstance(user, dict) or user.get("id") is None:
            raise XtreamProError("BAD_RESPONSE")
        fields = reseller_fields(user)
        fields.setdefault("username", username)
        # A replayed request may answer with other credentials than the ones sent now.
        fields["password"] = str(data.get("password") or password)
        return fields

    def read_reseller(self, panel_user_id, username):
        return reseller_fields(self.client.find_sub_user(panel_user_id, username))

    def adjust_credits(self, panel_user_id, credits, note, request_id):
        """Hand credits to the account (> 0) or take them back (< 0). Returns {balance}."""
        if to_int(credits) == 0:
            raise XtreamProError("INVALID_REQUEST", message="The number of credits cannot be zero.")
        if to_int(credits) > 0:
            self.assert_can_give_credits(credits)
        data = self.client.adjust_credits(panel_user_id, to_int(credits), note, request_id)
        balance = data.get("target_balance") if isinstance(data, dict) else None
        return {"balance": to_int(balance) if balance is not None else None}

    def set_reseller_enabled(self, panel_user_id, enabled):
        self.client.sub_user_action("enable_user" if enabled else "disable_user", panel_user_id)

    def terminate_reseller(self, panel_user_id):
        """The panel cannot delete accounts: they are disabled. Already gone counts as done."""
        try:
            self.client.sub_user_action("disable_user", panel_user_id)
        except XtreamProError as e:
            if e.code != "RESOURCE_NOT_FOUND":
                raise
            return {"already_gone": True}
        return {"already_gone": False}

    def list_resellers(self, page_size=500, max_pages=20):
        """All accounts below the key's reseller: ({id: fields}, complete)."""
        found, complete = {}, False
        for page in range(max_pages):
            users = self.client.sub_users(start=page * page_size, limit=page_size)
            for user in users:
                fields = reseller_fields(user)
                if "panel_user_id" in fields:
                    found[fields["panel_user_id"]] = fields
            if len(users) < page_size:
                complete = True
                break
        return found, complete

    def refresh_resellers(self, items):
        """Refresh many accounts with one pass over the panel's list.

        items: [(key, panel_user_id)]. Returns (results, stopped) like refresh_lines;
        an account missing from a complete list gets the error "not found".
        """
        try:
            panel, complete = self.list_resellers()
        except XtreamProError as e:
            if e.code in FATAL_CODES:
                LOG.warning("sub-reseller refresh stopped: %s", e.code)
                return [], e.code
            return [(key, None, e.message) for key, _id in items], None
        results = []
        for key, panel_user_id in items:
            fields = panel.get(str(panel_user_id))
            if fields:
                results.append((key, fields, None))
            elif complete:
                results.append((key, None, XtreamProError("RESOURCE_NOT_FOUND").message))
        return results, None
