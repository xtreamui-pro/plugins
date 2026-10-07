# Xtream UI Pro Connector for Odoo

Version 1.1.1 (changes: [CHANGELOG.md](CHANGELOG.md)).

Sell IPTV subscriptions from Odoo Sales. Confirming a sale order creates the
subscriber lines on your Xtream UI Pro panel through its Reseller API; you can
then renew, suspend, refresh and e-mail the credentials from Odoo.

Target: Odoo 17 (works on 16; see "Other Odoo versions"). Only the Python
package `requests` is needed (already part of every Odoo install).

## Install

1. Extract the zip into your Odoo addons path so you get
   `<addons-path>/xtreampro_connector/__manifest__.py`.
2. Restart Odoo, enable developer mode, **Apps > Update Apps List**.
3. Install **Xtream UI Pro Connector** (it depends on `sale_management`).

## Set up

1. In the panel, sign in with a **reseller** account and generate an API key on
   the *API key* page. Only reseller accounts work; creating and renewing lines
   is charged to that reseller's credits.
2. In Odoo open **Sales > Configuration > Xtream UI Pro Settings**, enter the
   public API address of the panel (for example `https://api.example.com`) and
   the key, then **Save**.
3. Press **Test connection** (shows the reseller name and credits) and
   **Sync packages**. Packages (and the sub-reseller groups, see below) are also
   synced daily; ones removed on the panel are archived, never deleted. Every
   call carries the header `X-Connector: odoo/1.1.1`, so the panel's API call log
   (API key page) shows which calls came from this addon.
4. Open a product (type *Service*), and in the **Xtream UI Pro** group of the
   General Information tab pick the *Panel package*. Tick *Create as trial* to
   create trial lines. The picker only offers packages the panel sells as a plain
   line (`sells` contains `line`, column *Sold as a line* of **Sales > Configuration > Xtream UI Pro
   Packages**); a package for MAG or Enigma boxes only is left out, here and in
   **Change package**, and a sale of one is refused before the panel is asked.

## Credits are checked before anything is sold

Before confirming creates lines or an account, and before a renewal or a package
change, the addon asks the panel what it costs this reseller (`pricing`). When a
package is not on sale for the reseller, the reseller's group may not create
sub-resellers (or the group chosen on the product is not allowed), or the balance
(a balance of 0 sells nothing, even a free package) does not cover the whole order,
**nothing is sent to the panel**: the order is still confirmed, its lines and
accounts are kept in state *Error* with the reason and the amounts, the order's
chatter says "nothing was provisioned", and **Retry** works as for any other
failure. The check is a look, not a reservation: the panel charges again,
atomically, when it creates each line, so two sales racing for the last credits can
still be refused by the panel. A panel too old to know `pricing` skips the check.

## Daily use

- Confirm a quotation: one panel line is created per unit of quantity (integer
  part) for every order line whose product has a package. The credentials are
  those returned by the panel. The order's **IPTV lines** smart button opens them.
- A provisioning failure never blocks the confirmation. The error is written to
  the order chatter and the line is kept in state *Error*; press **Retry** on it
  after fixing the cause (for example insufficient credits). Re-confirming never
  creates duplicates, and the request id sent to the panel makes retries safe.
- Line buttons: **Renew** (costs credits), **Suspend** / **Unsuspend**,
  **Change package**, **Refresh status**, **Send credentials** (e-mail to the customer),
  **Terminate on panel** (deletes the line on the panel, then archives the record).
- **Change package** (upgrade / downgrade, managers): pick another package that is sold
  as an official period. The panel moves the line to it and charges that package's
  official price to your credits (`change_package`); the time left is kept when the two
  packages are compatible, otherwise the new period starts now. The dialog asks the panel
  first (`package_compatibility`) and shows, before you press the button, whether the time
  left is kept (and about how many days) or the new period starts today and the time left is
  lost, and what the change costs; if the panel says the package cannot be sold on that line,
  or the reseller cannot pay, the dialog says so instead. A panel too old to know the action
  gets a note that it decides when the change is made. The line's chatter records the answer. A retry of the same
  change is not charged twice (the request id carries a counter that moves on only after
  the panel accepted a change). Odoo has no standard hook that turns a sale order change
  into an upgrade, so this is a button, not automatic.
- **On cancellation**: cancelling a confirmed sale order now acts on the panel. The
  product's field *On cancellation* (General Information tab, Xtream UI Pro group) says
  what: *Disable (default)* suspends the lines the order created and the sub-reseller account
  it created (an account that only received credits from this order is left alone, and credits
  already handed over are not taken back); *Delete permanently* deletes them on the panel.
  **Deleting is final**: the line and everything recorded about its customer are erased and
  cannot be restored; a deleted sub-reseller account hands its credits, lines and sub-accounts
  to your reseller account. A failure is written to the order's chatter and never blocks the
  cancellation. Before 1.1.0 cancelling an order did nothing on the panel.
- Deleting a line record in Odoo does **not** delete it on the panel.
- Two scheduled actions run: package sync (daily) and line status refresh
  (every 6 hours, at most 200 lines per run, oldest refresh first).
- The line form shows the links the panel returned with the line (`links`): server,
  M3U playlist, HLS playlist, XMLTV guide and web player, built on the host the API
  was called on or on the reseller's own play address. A line made by 1.0.0 (or by a panel
  that sends no `links`) has none stored until its next refresh and shows the M3U and web
  player links assembled from the credentials (`<base>/get.php?username=U&password=P&type=m3u_plus&output=ts`,
  web player `<base>/player/`). The links carry the password, so only sales managers see them.
- **Webhooks**: the panel can push line events to Odoo, see below.

## Selling sub-reseller accounts and credits

A product can also sell a **sub-reseller account** of the panel (a reseller
account owned by your API key's reseller) and top-ups of its credits.

1. On the product (type *Service*), in the **Xtream UI Pro** group, set
   *Panel product* to **Sub-reseller account** and fill *Credits per unit*
   (0 only creates the account). Package and trial apply to IPTV lines only.
2. Your reseller's **group on the panel must allow creating sub-resellers**,
   otherwise the panel answers "not allowed" and the record stays in *Error*.
3. Optionally pick the **Sub-reseller group** on the product (the groups your reseller
   group allows, loaded by *Sync packages*); empty = the first allowed group. Confirming the order finds the customer's sub-reseller record (one active
   record per customer and company) or creates it: the username is made from
   the customer name plus a random suffix (3-32 characters), the password is
   random (14 characters), the e-mail is the customer's e-mail (required; with
   none the record goes to *Error* without calling the panel). Both are stored
   before the call, so a retry sends the same values. Then *Credits per unit x
   quantity* are handed over from your own balance as a credit transfer.
4. Cost: creating the account is charged at your group's sub-reseller price,
   **plus** the credits you hand over. Both come from your credits on the panel.
5. Under **Sales > Xtream UI Pro > Sub-resellers** (managers): **Retry**
   (creation and the order's transfers), **Suspend** / **Unsuspend**,
   **Refresh**, **Add credits**, **Take back credits**, **Send credentials**,
   **Delete on panel** (final, with a confirmation: credits, lines and sub-accounts go to your
   reseller account and the account's personal data is erased).
   Every credit movement is a *credit transfer* with its own request id, so
   retries never apply twice. Failed transfers show a **Retry** button in the
   *Credit transfers* tab.
6. Archiving the Odoo record does not call the API and the account keeps working;
   suspend it first if needed, or use **Delete on panel**. The *Panel address* setting
   (Settings, optional) gives the account a sign-in link, `address/login`, shown on the
   form (the credentials e-mail of a new installation carries it too; the template of an
   existing installation is not rewritten on upgrade, add `object.login_url` to it yourself).
7. Credits can be taken back only while the sub-reseller has not spent them
   (neither balance may go below zero).
8. The 6-hourly **refresh sub-reseller status and credits** job reads the
   account list from the panel (up to 500 per call) and updates active and
   suspended records, at most 200 per run.

## Webhooks from the panel

The panel can push events (line renewed, enabled, disabled, deleted, expired) to an https
address. The addon receives them at `<web.base.url>/xtreampro/webhook` (public route,
POST only, JSON as the panel sends it).

1. **Settings > Xtream UI Pro Settings > Webhook from the panel**: press **Register webhook**.
   The addon asks the panel (`create_webhook`) to post the five events above to that address
   and keeps the signing secret the panel shows only once (in the system parameter
   `xtreampro.webhook_secret`, readable by Settings users only). The panel accepts https
   addresses only: set the system parameter `web.base.url` to your public https address first.
   **Send test event** makes the panel post a ping; **Remove webhook** deletes it on the panel
   and here. You can also paste the secret of a webhook you created on the panel.
2. Every call is verified before anything in it is read: `X-Xtream-Signature` must be `sha256=`
   + HMAC-SHA256 of `<X-Xtream-Timestamp>.<body>` with the secret (constant-time comparison)
   and the timestamp must be within 5 minutes of the server clock (the panel stamps every
   delivery attempt anew; the window is this addon's choice, the panel defines none). An
   unsigned or wrongly signed call gets 401, a stale one 400, and nothing changes. With no
   secret set every call is refused (503). The panel delivers at least once and keeps the
   event `id` the same on every retry, so an id is applied once: it is stored (model
   `xtreampro.webhook.event`, the id only) in the same transaction as the event's effect, a second
   delivery is answered 200 `duplicate`, and ids older than 24 hours are dropped whenever a new one
   is stored. An event without an id (an older panel) is simply applied; handling is idempotent
   anyway.
3. What an event does: `line.expired` sets the line to *Expired* (with a chatter note),
   `line.renewed` / `line.enabled` to *Active*, `line.disabled` to *Suspended* (the expiry follows
   the event), `line.deleted` archives it (state *Suspended*, a chatter note). Events of lines
   this database did not sell are acknowledged and ignored. By default the panel only reports
   lines the reseller owns itself. Because this addon also sells sub-reseller accounts, a second
   button, **Register webhook (with sub-resellers' lines)**, registers it with
   `include_sub_resellers`: the panel then also sends the events of lines owned by accounts below
   the reseller, each carrying `owner_id` / `owner_username`. A line of this database is handled as
   above; any other is ignored, except that when `owner_id` is the panel account of a sub-reseller
   record, only the time of the event is noted on it (*Last panel activity of its lines*; nothing
   about the line or its customer is kept). **Events sent** in the settings says which of the two
   registrations is in force. Default is off.
4. On a server that hosts several databases the route needs the database to be chosen by
   `dbfilter` (or `-d`), like any public route.

## Access rights

- Salesmen: read lines, sub-resellers and packages, send credentials. They cannot see the password.
- Sales managers: full access, see passwords, run all line buttons.
- Settings (API address / key) require Settings access. The API key is stored
  in `ir.config_parameter` and is never written to logs or chatter; line
  passwords are never written to logs or chatter either (the credentials e-mail
  is sent as an unlinked mail so it does not appear in the chatter).

## Notes and limits

- The API address and key are global (not per company). Lines carry the company
  of the order and are protected by a multi-company record rule.
- The panel stores line passwords in clear and so does this module (needed for
  the playlist link and the credentials e-mail). The webhook secret is stored in clear
  in `ir.config_parameter`, like the API key.
- TLS verification is always on. Use an `https://` address in production.
- Requests use a (5 s connect, 20 s read) timeout and do not follow redirects
  (so the key is never sent elsewhere); if the panel redirects http to https,
  enter the https address.

## What was verified, and what is a guess

Run (`plugins/e2e/run.sh odoo`, 2026-10-05, again for 1.1.1): the addon installed in a real Odoo 17 (Docker), with
its scenario in `odoo shell` against a real panel API (cmd/api of this repository on a
throwaway database): confirming orders, renew, suspend, refresh, change package with its
charge, links from the API, the credit checks (package not on sale, sub-reseller too expensive,
retry refused), the chosen group and the sign-in link, cancelling an order with *Disable* and
with *Delete permanently* (lines and a sub-reseller account), the webhook receiver with signed,
tampered, unsigned, stale and unknown-line events, the settings buttons (register / test /
remove webhook), access rights, and the `X-Connector` header in the panel's call log. 1.1.1 adds:
the package list and pickers by `sells` (a box-only package is left out, refused as a line, and
still allowed for a renewal), `package_compatibility` in the dialog and the chatter (and a panel
that does not know it, simulated), `REQUEST_ID_SPENT` readable and a Retry that cannot make a second
line, an order provisioned again after its line was deleted (sends no request id twice), event
de-duplication by id (also over HTTP with `curl`), the retention, events with `owner_id`, and
registering with and without the sub-resellers' lines. The
webhook route (`/xtreampro/webhook`) was also called over HTTP on a real Odoo 17 server with
`curl`.

Not run, so guesses: the form and settings screens clicked in a browser (their views compile,
and the settings buttons are called from the scenario), a multi-database server (see the
webhook section), Odoo 16 and 18, `_action_cancel` as the cancellation hook on other Odoo
versions than 17 (the scenario cancels through the same `action_cancel` the confirmation wizard
calls), and real webhook deliveries from the panel's worker to a public https address (the
tests sign events with the same formula as the panel). The 24-hour retention of event ids, the
"note only the time" handling of a sub-reseller's line events and the wording of the dialog's note
are this addon's decisions; the panel defines none of them. The compute that fills the dialog's
note runs when the form opens and when the package changes; how that feels in a browser was not
checked.

## Other Odoo versions

The addon is written for and tested on **Odoo 17**.

- **Odoo 16**: change the manifest version to `16.0.1.1.1`, convert the
  `invisible="..."` expressions of the views to `attrs`, and replace the
  `<app>` / `<block>` / `<setting>` markup of the settings view by the Odoo 16
  `<div class="app_settings_block">` markup.
- **Odoo 18**: change `version` to `18.0.1.1.1`, replace `<tree` / `</tree>` and
  `view_mode` `tree,form` by `list` in the views and actions, and remove the
  `numbercall` and `doall` fields from `data/ir_cron.xml`.
- Test on a copy of your database first.
