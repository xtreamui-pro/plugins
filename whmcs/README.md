# Xtream UI Pro - WHMCS provisioning module

Version 1.1.1 (changes: [modules/servers/xtreampro/CHANGELOG.md](modules/servers/xtreampro/CHANGELOG.md)).

Sells IPTV lines from WHMCS. Each WHMCS service is one line in your Xtream UI Pro panel,
created and managed through the panel's Reseller API.

## Requirements

- WHMCS 7.10 or newer (8.x recommended), PHP 7.4 or newer with the `curl` and `json` extensions.
- An Xtream UI Pro panel whose API address (`cmd/api`, for example `https://api.example.com`)
  is reachable from the WHMCS server. Use a valid TLS certificate; certificates are always verified.
- A **reseller** account in the panel with enough credits. Creating and renewing a line is charged
  to this reseller's credits.

## Install

1. Upload the `modules/` folder of this package into your WHMCS root directory, so that you get
   `<whmcs>/modules/servers/xtreampro/xtreampro.php`.
2. Nothing else to install: no Composer, no database step. The module creates its own small table
   `mod_xtreampro_lines` (service id to line id) the first time it is used.

## Get the API key

1. Sign in to the panel as the reseller account that will own the lines.
2. Open `/api-key` in the dashboard and generate (or copy) the API key.
3. Only reseller accounts work. Admin keys are refused with `FORBIDDEN`.

## Set up the server

1. In WHMCS go to **Setup > Products/Services > Servers > Add New Server**.
2. Name: anything. **Hostname**: the panel's API host, e.g. `api.example.com`.
3. Tick **Secure** if the API is served over https (recommended). Set **Port** only if it is not
   80/443.
4. **Type**: Xtream UI Pro. **Password**: the API key. WHMCS stores it encrypted.
   (The Access Hash field is accepted as a fallback.)
5. Click **Test Connection**. It calls `user_info` and fails with a readable message if the host,
   port or key is wrong.
6. Put the server into a server group (also required for the product).

## Set up the product

1. **Setup > Products/Services > Create a New Product**, type "Other" (or any type).
2. **Module Settings**: module **Xtream UI Pro**, pick the server group.
3. Options:
   - **Package**: list loaded live from the panel (`id => name (credits, duration)`). Only packages
     the panel sells as a plain line are listed (`sells` contains `line`): a package for MAG or
     Enigma boxes only is left out, so it cannot be picked and then refused at sale time.
   - **Trial line**: create the line as a trial.
   - **Delete on terminate**: off (the default) = the line is only disabled when the service is
     terminated; on = it is **deleted permanently**. Deleting is final on the panel: the line and
     everything recorded about the customer are erased and cannot be restored (a returning customer
     gets a new line).
4. Choose when to provision (for example "Automatically setup the product as soon as the first
   payment is received").

Before it creates or renews a line, or changes its package, the module asks the panel what the
package costs this reseller (`pricing`). When the package is not on sale for the reseller, or the
balance (a balance of 0 sells nothing, even a free package) does not cover it, the action fails at
once with a readable message that names the amounts, and nothing is created. The check is a look,
not a reservation: the panel charges again, atomically, when the line is created, so two sales
racing for the last credits can still be refused by the panel.

If the order has a username/password the module sends them. The panel may ignore custom
credentials (reseller group setting) or generate them when blank; the module always saves the
username and password the panel actually used on the service.

## What each action does

| WHMCS action | Panel call | Notes |
| --- | --- | --- |
| Create | `create_line` | Charged. `request_id = whmcs-create-<serviceid>-<n>` (n counts terminations of the service), so a retry never charges twice and creating a terminated service again sells a new line. |
| Suspend | `disable_line` | Free. |
| Unsuspend | `enable_line` | Free. |
| Terminate | `disable_line` (default) or `delete_line` | Depends on **Delete on terminate**; deleting is final. A line already gone from the panel counts as success. |
| Renew | `renew_line` | **Charged.** Extends the line by the package's official duration. Request id includes the due date, so same-day retries are not charged twice. |
| Upgrade / downgrade | `change_package` | **Charged** at the new package's official price (see below). |

Every call carries the header `X-Connector: whmcs/1.1.1`, so the panel's API call log (API key
page) shows which calls came from this module.

The client area shows username, password, status, expiry, max connections and the links the panel
returned with the line (`links` of `get_line`): server URL, M3U playlist, HLS playlist, XMLTV guide
and web player. They are built on the host the API was called on, or on the reseller's own play
address when one is set (`set_play_base`), so the module does not assemble them. A panel old enough
to return no `links` gets the M3U and web player links assembled here as before (the HLS and guide
rows are then left out). The admin service page shows line id, status, expiry and max connections,
fetched live, and the last status the panel pushed by webhook.

### Upgrade and downgrade

WHMCS calls `ChangePackage` when an upgrade or downgrade of the service is processed. The line is
moved to the package selected in the product's **Package** option and the panel charges that
package's official price to the reseller's credits. The remaining time is kept when the two
packages are compatible, otherwise the new period starts now (the panel decides; see its API
documentation, `change_package`). Before selling, the module asks the panel what the change would
do (`package_compatibility`: time kept or restarted, the time lost, the price) and refuses it
at once when the reseller cannot pay the price. WHMCS has no screen in its upgrade flow for a
module's remarks, so the answer is written to **Activity Log** once the change went through
("... the time left (about N days) is kept ..." or "... the new period starts today and the time
left is lost ...", plus the credits) and the Module Log holds the call. A panel too old to know the
action is not asked again: the change is then decided, and logged without that sentence, when it is sold. WHMCS' own prorated invoice is a separate thing: price the
products so that the two agree. Retrying the same change does not charge twice (the request id
carries a per-service counter that moves on only after the panel accepted a change). A sub-reseller
service has no package: its upgrade does nothing on the panel.

### Webhooks from the panel

The panel can push events (line created, renewed, enabled, disabled, deleted, expired) to an https
address. The module receives them at `https://<your WHMCS>/modules/servers/xtreampro/webhook.php`.

1. Open any service of this module in the admin area and press **Register panel webhook**. The
   module asks the panel (`create_webhook`) to post `line.renewed`, `line.enabled`, `line.disabled`,
   `line.deleted` and `line.expired` to that address, and keeps the signing secret the panel shows
   only once, encrypted with WHMCS' own encryption (one webhook per server record). The address is
   built from the System URL of WHMCS; the panel accepts https addresses only. **Send webhook test**
   makes the panel post a ping, **Remove panel webhook** deletes it on the panel and here.
2. Every call is verified before anything in it is read: the header `X-Xtream-Signature` must be
   `sha256=` + HMAC-SHA256 of `<X-Xtream-Timestamp>.<body>` with the stored secret, compared in
   constant time, and the timestamp must be within 5 minutes of the WHMCS clock (the panel stamps
   every delivery attempt anew; the window is this module's choice, the panel defines none). An
   unsigned or wrongly signed call is answered 401, a stale one 400, and nothing is changed.
   Handling an event is idempotent, so a replay inside the window repeats a harmless update.
3. The panel delivers at least once and keeps the event `id` the same on every retry, so the module
   applies an id once: it is stored in `mod_xtreampro_webhook_events` after the event was applied
   (a failed one is applied again on the retry), a second delivery of it is answered 200
   `duplicate`, and ids older than 24 hours are dropped whenever a new one is stored. An event
   without an id (an older panel) is simply applied.
4. What an event does: `line.deleted` marks the service **Terminated** and forgets its line;
   `line.expired` is recorded (admin service page, activity log) but does **not** suspend the
   service, because WHMCS has no "expired" status and its own due dates decide when a service is
   suspended; `line.renewed`, `line.enabled` and `line.disabled` update the recorded status. Events
   of lines this WHMCS does not know are acknowledged and ignored. By default the panel only
   reports lines the reseller owns itself. Because this module also provisions sub-reseller
   accounts, a second button, **Register panel webhook (with sub-resellers' lines)**, registers it
   with `include_sub_resellers`: the panel then also sends the events of lines owned by accounts
   below the reseller, each carrying `owner_id` / `owner_username`. A line this WHMCS did not sell is
   still ignored, except that when `owner_id` is the panel id of a sub-reseller service of this
   module, only the time of the event is noted on that service (admin service page, *Last panel
   activity of the account's lines*); nothing about the line or its customer is kept. Default is off.

Renewals cost credits: keep the reseller's balance topped up, otherwise renewals fail with
"The reseller account has not enough credits" and WHMCS reports the module error on the service.

## Selling sub-reseller accounts

A product can sell a **sub-reseller account** instead of an IPTV line: a reseller account owned by
the reseller whose API key the server uses.

Product setup (Module Settings):

- **Service type**: Sub-reseller account (the default, IPTV line, keeps the behaviour above).
- **Credits on creation**: whole number, 0 or more. Credits handed to the new account at creation.
- **Credits per renewal**: whole number, 0 or more. Credits handed over at every WHMCS renewal
  (0 = renewals do nothing on the panel).
- **Sub-reseller on cancellation**: *Disable the account* (the default) or *Delete the account
  permanently*. Deleting is final: the account's credits, lines and sub-accounts go to your
  reseller account, its personal data is erased and nothing can be restored. Your panel group must
  be allowed to delete accounts, otherwise termination reports that you are not allowed.
- **Panel address**: optional, e.g. `https://panel.example.com`. The client area then shows
  `<address>/login` as the sign-in link (only http and https addresses are used).
- **Sub-reseller group**: the group a new account is put in, loaded from the panel's `pricing`
  (the groups your reseller group allows). *Default* lets the panel take the first allowed group.
- Package, Trial line and Delete on terminate are ignored for sub-reseller accounts.

The account gets the WHMCS service username/password (a username of `r<serviceid>` plus random
letters and a random 14 character password are generated when blank), the client's email and
first + last name. Usernames are 3 to 32 letters, digits, `_`, `.` or `-` (longer ones are cut to
32); passwords at least 8 characters.

| WHMCS action | Panel call | Notes |
| --- | --- | --- |
| Create | `create_user`, then `adjust_credits` | Charged: the reseller pays its group's sub-reseller price **plus** the credits handed over. `request_id = whmcs-sub-<serviceid>-<n>` and `whmcs-subc-<serviceid>-<n>`. If the credit transfer fails the error is shown but the account stays mapped; run Create again to retry the transfer without a second charge. |
| Suspend | `disable_user` | Free. |
| Unsuspend | `enable_user` | Free. |
| Terminate | `disable_user` (default) or `delete_user` | Depends on **Sub-reseller on cancellation**; the mapping is closed. An account already gone counts as success. |
| Renew | `adjust_credits` | Gives **Credits per renewal** credits from the reseller to the account. Request id includes the due date, so same-day retries do not pay twice. Nothing happens when it is 0. |

The reseller's group must be allowed to create sub-resellers (otherwise `FORBIDDEN`). Before the
account is created the module checks `pricing`: the group may create sub-resellers, the chosen
group is one it may use, and the balance covers the account price **plus** the credits to hand
over, so a short balance leaves no half-made account. The client
area and admin service page show username, status and credit balance (the client area also the
password); no playlist, server URL or web player. The panel id of the account (a UUID) is kept in
the `user_id` column of `mod_xtreampro_lines`, added automatically to existing installs.

## Not included

- Password change is not available through the Reseller API. Do it in the panel. (Changing the
  line's password in the panel does not update the WHMCS service.)
- The module does not top up credits.
- Renewal extends by the package's duration on the panel, not by the WHMCS billing cycle: choose
  the two so that they match.

## Updating from 1.0.0 or 1.1.0

Copy the new files over the old ones. The module adds columns to its own table
`mod_xtreampro_lines` (and one to `mod_xtreampro_webhooks`) and creates `mod_xtreampro_webhook_events`
by itself. Existing products keep what
they stored: a **Delete on terminate** that was ticked still deletes (new products now start with it
off), and the three new sub-reseller options are empty, which means *disable*, no panel address and
the default group.

## What was verified, and what is a guess

Run (`plugins/e2e/run.sh whmcs`, 2026-10-05, again for 1.1.1): the real module and API client against a real panel
API (cmd/api of this repository on a throwaway database), with a stand-in for WHMCS' own classes
(its database layer, `logModuleCall`, `localAPI`, `logActivity`). It covers create and replay,
suspend, unsuspend, renew without double charge, terminate (disable and delete), create again,
upgrade / downgrade with its charge, the credit checks (package not on sale, credits short, group
not allowed, no half-made sub-reseller), links from the API, the `X-Connector` header in the
panel's call log, registering / testing / removing the webhook, signature checks (valid, wrong
secret, changed body, unsigned, stale, no secret), the events above, deleting a sub-reseller on
termination, and that neither the API key nor a password reaches the module log. 1.1.1 adds: the
package list leaves out the box-only package (`sells`), a sale or change to it is refused before
anything is created, `package_compatibility` is asked before a change (and a panel that does not
know it gives `null`), the activity-log line, event de-duplication by id (twice the same id, an
event without id, an id of a wrong shape, ids past the retention dropped), events with `owner_id`,
registering with and without the sub-resellers' lines (the panel's `get_webhooks` shows the flag),
and `REQUEST_ID_SPENT` after a line was deleted on the panel followed by Terminate and Create. The
refusal when the reseller cannot pay the change (`can_afford` false) was written but not run.

Not run, so guesses:

- Nothing ran inside a licensed WHMCS. Not checked there: that `ChangePackage` is called with the
  parameters of the **new** product, the new config option screens and the group dropdown loader,
  the admin buttons, the client area templates, the `webhook.php` bootstrap (`init.php` three
  folders up) and the System URL lookup.
- `localAPI('EncryptPassword' / 'DecryptPassword')` for the webhook secret, and
  `UpdateClientProduct` with `status` = `Terminated` (assumed not to call the module's Terminate).
  The stand-in implements them, WHMCS may behave differently.
- The panel's real webhook deliveries (cmd/worker posting to `webhook.php`) were not run end to end:
  the test signs events itself with the same formula as the panel
  (`SignWebhook`, `internal/app/usecase/webhooks.go`).
- The 5-minute time window and "do not suspend on `line.expired`" are this module's decisions.
- The 24-hour retention of event ids and the "note only the time" handling of a sub-reseller's
  line events are this module's decisions; the panel defines neither.
- That the Activity Log is the place an admin looks at after an upgrade is a guess: a licensed
  WHMCS may offer a better one.

## Troubleshooting

Module calls are visible under **Configuration > System Logs > Module Log** (enable Module
Logging). The API key and line passwords are masked there.

| Code | Meaning | What to do |
| --- | --- | --- |
| `INVALID_API_KEY` (401) | Key unknown or revoked | Re-copy the key into the server's Password field. |
| `FORBIDDEN` (403) | Not a reseller account, missing permission, or (sub-resellers) the group may not create sub-resellers / hierarchy too deep | Use a reseller account; check its group permissions. |
| `RESOURCE_NOT_FOUND` (404) | Line or sub-reseller not in the panel | It was deleted in the panel; terminate the service. |
| `INVALID_REQUEST` (400) | e.g. username/password shorter than the group's minimum | Adjust the credentials or the group setting. |
| `INVALID_PACKAGE` (400) | Package missing or not allowed | Re-select the package on the product. |
| `INSUFFICIENT_CREDITS` (402) | Reseller balance too low (sub-resellers: the price plus the credits to hand over, or a renewal top-up) | Add credits in the panel, then retry the action. |
| `CONFLICT` (409) | Username (or, for sub-resellers, email) taken, or request id reused for another operation | Choose another username or email. |
| `POST_REQUIRED` (405) | Request was not a POST | Usually a proxy rewriting methods; check it. |
| `REQUEST_ID_SPENT` (409) | The same request id was sent again after its line had been deleted (for example Create pressed on a service whose line was deleted on the panel) | Terminate the service and create it again: Terminate moves the service's counter on, so the next Create sends a new request id. |
| `READ_ONLY_KEY` (403) | The API key is read-only | Use a key that may change things (API key page). |
| `TOO_MANY_WEBHOOKS` (409) | The account has the maximum number of webhook endpoints | Remove one on the panel's API key page. |
| `RATE_LIMITED` (429) | Too many requests | Wait and retry. |
| `UNKNOWN_ACTION` (400) | Panel too old for this module | Update the panel. |
| `SERVER_ERROR` (500) | Internal panel error | Check the panel logs. |
| Could not connect | Network, port, TLS | Check hostname, port, the Secure box and the certificate. |
