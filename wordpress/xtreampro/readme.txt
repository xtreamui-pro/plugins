=== Xtream UI Pro Connector ===
Contributors: xtreampro
Tags: iptv, woocommerce, easy digital downloads, surecart, reseller, xtream
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Connects WordPress, WooCommerce, Easy Digital Downloads and SureCart to the Xtream UI Pro IPTV panel: package tables, "my lines" page and automatic line provisioning for shop orders.

== Description ==

* Settings page with connection test, cached package list and webhook registration.
* Shortcode `[xtreampro_packages]` - pricing table of packages.
* Shortcode `[xtreampro_my_lines]` - the customer's lines with live status, expiry and the playlist, guide and web-player links the panel returns.
* Shortcode `[xtreampro_my_reseller]` - the customer's sub-reseller account with status and live credit balance.
* WooCommerce: assign a package to a product; a completed order creates the line(s) through the panel's Reseller API and sends the credentials in the order email and on the order page. Renewals, refunds and retries are supported.
* Easy Digital Downloads 3.x: the same for downloads - lines or sub-reseller accounts / credits are created when an order completes, credentials are on the receipt and in the receipt email, refunds disable them.

* SureCart: an "Xtream UI Pro" integration you attach to a product; a purchase creates the lines or the reseller account / credits, the credentials are emailed to the buyer, refunds disable them.

Lines are created with the credits of the reseller account whose API key you enter.

== Installation ==

1. In WP Admin go to Plugins -> Add New -> Upload Plugin, choose the zip and activate it.
2. Open Settings -> Xtream UI Pro. Enter the API URL (the panel's public API address, e.g. `https://api.example.com`) and the API key of a reseller account (panel -> API key).
3. Click "Test connection". It shows the reseller name and credit balance.
4. Optional: define `XTREAMPRO_API_URL`, `XTREAMPRO_API_KEY`, `XTREAMPRO_PANEL_URL` and `XTREAMPRO_WEBHOOK_SECRET` in wp-config.php; they override the settings page.

Every call carries the header `X-Connector: wordpress/1.1.1`, so the panel's API call log (panel -> API key) shows which calls came from this plugin.

== Credits are checked before anything is sold ==

Before provisioning an order the plugin asks the panel what the order costs this reseller (`pricing`) and fails at once, with an order note that names the amounts, when a package is not on sale for the reseller, the reseller's group may not create sub-resellers, or the balance (a balance of 0 sells nothing, even a free package) does not cover the whole order. Nothing is created in that case, so an order is never left half provisioned for want of credits. The check is a look, not a reservation: the panel charges again, atomically, when it creates each line, so two sales racing for the last credits can still be refused by the panel (the order note then says so, and the lines made so far are kept). Panels too old to know `pricing` skip the check. WooCommerce, Easy Digital Downloads and SureCart lines use it; SureCart sub-reseller accounts are not pre-checked.

== Which packages are offered ==

The panel says what each package can be sold as (`sells` in `packages` and `pricing`: `line`, `mag`, `enigma`). The package pickers of WooCommerce products, EDD downloads and the SureCart item list, and `[xtreampro_packages]`, offer only packages that sell as a plain line; a package for MAG or Enigma boxes only is left out (Settings -> Xtream UI Pro lists every package with its "Sold as"). A product that still points at such a package is refused before the panel is asked, with an order note that says so, instead of `INVALID_PACKAGE` at sale time. A renewal is not refused for that reason. Panels that send no `sells` are treated as before.

== Play links ==

The playlist (M3U, also HLS), programme guide (XMLTV) and web-player links shown on order pages, in emails and by `[xtreampro_my_lines]` are the ones the panel returns with the line (`links`), built on the host the API was called on or on the reseller's own play address. Orders made by version 1.0.0 have none stored; their M3U and web-player links are assembled from the credentials as before, and so are those of a panel that returns no `links`. SureCart emails still assemble their links.

== Webhooks from the panel ==

The panel can push events to the site. Address: `https://your-site/wp-json/xtreampro/v1/webhook` (shown in Settings -> Xtream UI Pro).

1. In Settings -> Xtream UI Pro press "Register webhook": the plugin asks the panel to post `line.renewed`, `line.enabled`, `line.disabled`, `line.deleted` and `line.expired` to that address and keeps the secret the panel shows once. (The panel delivers to https addresses only.) Or paste the secret of a webhook you created on the panel into "Webhook secret". "Send test event" makes the panel post a ping; "Remove webhook" deletes it.
2. Every call is verified before anything in it is read: `X-Xtream-Signature` must be `sha256=` + HMAC-SHA256 of `<X-Xtream-Timestamp>.<body>` with the secret (constant-time comparison) and the timestamp must be within 5 minutes of the server clock (the panel stamps every delivery attempt anew; the window is this plugin's choice, the panel defines none). Unsigned or wrongly signed calls get 401, stale ones 400, and nothing changes. With no secret set, every call is refused (503). The panel delivers at least once and keeps the event `id` the same on every retry, so an id is applied once: after an event was applied its id is kept for 24 hours (a WordPress transient, which WordPress expires itself) and a second delivery is answered 200 `duplicate`. An event without an id (an older panel) is simply applied; handling is idempotent anyway.
3. What an event does: `line.deleted` removes the line from the customer's list (so it can no longer be renewed or shown) and adds an order note; `line.expired` adds an order note and records the status; `line.renewed`, `line.enabled` and `line.disabled` update the recorded status, which `[xtreampro_my_lines]` shows when the panel cannot be reached. Events of lines this site did not sell are acknowledged and ignored. By default the panel only reports lines the reseller owns itself; events now carry `owner_id` / `owner_username` (whose line it is), which this plugin does not use. It registers its webhook without `include_sub_resellers` and offers no option for it: it has no screen on which the activity of a sub-reseller's lines would show, so the lines of the sub-reseller accounts it sells would only be acknowledged and ignored.

== Setup with WooCommerce ==

1. Edit a product (simple, or a variable product - variations use the parent's package). In "Product data -> General" pick the "Xtream UI Pro package". Tick "Trial line" for a free trial product.
2. When the order becomes Completed (or Processing, if every item is virtual) one line is created per purchased unit.
3. Logged-in customers who already own a line from a product can choose "Renew my existing line" on the product page; the order then renews that line instead of creating a new one.
4. If the panel returns an error (for example not enough credits) an order note explains it. Re-complete the order, or use the order action "Provision Xtream UI Pro lines again", to retry. Lines are never created twice: every unit of an order item has its own request id (`wc-<order>-<item>-<n>`), and an item that already holds a line is not sent again, even if that line is later deleted on the panel. If the panel answers `REQUEST_ID_SPENT` (the sale was made, the answer was lost, and the line was deleted on the panel before the retry), the note says so; a new order is a new sale and makes a new line.
5. Refunded or cancelled orders disable their lines. A product can say "On refund or cancellation: Delete permanently" instead (Product data -> General); deleting is FINAL on the panel: the line and everything recorded about its customer are erased and cannot be restored.
6. There is no upgrade / downgrade of an existing line from WooCommerce: the plugin has no WooCommerce Subscriptions integration, so no switch hook exists to map onto the panel's `change_package`. The customer can renew a line with the same product ("Renew my existing line"); to move a line to another package, do it on the panel.

== Setup with Easy Digital Downloads ==

Loaded only when Easy Digital Downloads 3.x is active. It uses the same settings, the same shortcodes and the same panel account as the WooCommerce integration.

1. Edit a download. The "Xtream UI Pro" box lets you choose the product type (IPTV line or sub-reseller account / credits), the package, "Trial line" and the credits handed over per unit. They are stored under the same meta keys as WooCommerce products.
2. When the order becomes Complete (`edd_complete_purchase`, which EDD runs once per order) one line is created per purchased unit, or the customer's reseller account is created / topped up with the credits of the download. What was provisioned is stored in the order meta `_xtreampro_edd`; request ids are `edd-<order>-<download>-<n>`, `edd-sub-...` and `edd-subc-...`, so nothing is ever created or credited twice.
3. Credentials are shown on the purchase receipt (below the order table) and in the purchase receipt email. The email gets them through the email tag `{xtreampro_credentials}`; if the shop's receipt template does not contain the tag, the plugin appends it to the customer's receipt. Passwords are only written for the buyer: not on the receipt when someone else (for example a shop admin) looks at it, and not in the admin sale notice or any email that is not the customer's receipt.
4. If the panel returns an error (for example not enough credits) an order note explains it. Open the order and press "Provision Xtream UI Pro lines again" (box in the sidebar), or complete the order again after fixing the problem.
5. Refunded or revoked orders disable their lines, take the credits of sub-reseller downloads back when the account still has them, and disable the account that order created. A download can say "On refund or revoked order: Delete permanently" instead; deleting is FINAL on the panel (line and everything recorded about its customer erased, an account's credits, lines and sub-accounts go to your reseller account).
6. Reseller downloads need a WordPress account on the order: a guest order is not provisioned and an order note says so. Lines can be bought as a guest; the credentials are then only on the receipt and in the email.
7. `[xtreampro_my_lines]` and `[xtreampro_my_reseller]` show what was bought through EDD as well. Renewing an existing line from a new order is a WooCommerce-only feature.

== Setup with SureCart ==

Loaded only when SureCart is active. It has not yet run inside a real SureCart store (SureCart needs an account on its hosted backend); it was written against the SureCart plugin source and exercised with a stand-in for it. It uses the same settings, shortcodes and panel account as the other integrations.

1. In SureCart open a product -> Integrations -> Add integration -> "Xtream UI Pro", then choose the item: "IPTV line: <package>", "IPTV trial line: <package>" (packages that offer a trial) or "Sub-reseller account: N credits". Attach it to the product, or to one price / variant, as with any SureCart integration. The amounts offered for sub-reseller items come from the filter `xtreampro_surecart_credit_amounts` (default 10, 25, 50, 100, 250, 500, 1000): `add_filter( 'xtreampro_surecart_credit_amounts', function () { return array( 20, 100, 500 ); } );`
2. When SureCart creates the purchase (`surecart/purchase_created`) one line per purchased unit is created, or the buyer's reseller account is created (once) and the credits are handed over. SureCart only runs integrations for customers linked to a WordPress user. What was provisioned is stored in the option `xtreampro_sc_p_<purchase id>` (SureCart orders are not WordPress posts); request ids are `sc-<purchase>-<n>`, `sc-sub-<purchase>-<n>` and `sc-subc-<purchase>-<n>`, so nothing is ever created or credited twice, even when SureCart reports the purchase twice.
3. The credentials (password included) are sent with `wp_mail` to the email address of the buyer's WordPress account, and to nobody else. SureCart's own customer dashboard and emails are not changed: put `[xtreampro_my_lines]` / `[xtreampro_my_reseller]` on a page to show them.
4. A refund or cancelled subscription (`surecart/purchase_revoked`) disables the lines (never deletes them), takes the credits back when the account still has them and disables the account that purchase created. When SureCart invokes the purchase again (`surecart/purchase_invoked`, for example a restored subscription) the lines and the account are enabled and the credits given again.
5. Subscription renewals: SureCart fires `surecart/subscription_renewed` (webhook subscription.renewed). The lines of that purchase are renewed and the credits topped up, once per billing period (request ids `sc-ren-...`, `sc-subr-...`). Trial lines are not renewed. A renewal event within 15 minutes of creating the lines is treated as the first payment. Limitations: changing the quantity of an existing purchase does not add or remove lines, and a renewal that fails (for example for lack of credits) is listed under the problems below, but Retry does not repeat it: renew that line in the panel.
6. Failures (for example not enough credits) are listed under Settings -> Xtream UI Pro -> "Recent SureCart problems" with a Retry link. Retry re-reads the purchase from SureCart and provisions it (or revokes it when it is revoked); what already exists is skipped.

== Selling sub-reseller accounts and credits ==

1. Edit a product and set "Xtream UI Pro product type" to "Sub-reseller account / credits". Enter the "Credits" handed over per purchased unit. Package and Trial are ignored for these products.
2. The customer must have a WordPress account (guest orders are not provisioned; an order note says so). On the first such order the plugin creates one sub-reseller account for that customer (username from the WordPress login plus a random suffix, generated password, billing email and name) and hands over the credits. The account, its password (only on the order that created it) and the credits appear in the order email and on the order page.
3. Later orders of a credit product top up the same account. Each customer has exactly one reseller account.
4. Optional: enter the "Panel address" in the settings (or define `XTREAMPRO_PANEL_URL`). Customers then get `address/login` as their sign-in link.
5. Requirements and costs: the group of the reseller whose API key you use must allow creating sub-resellers. Creating an account costs the group's sub-reseller price, and the credits handed over come on top; both are taken from that reseller's balance. If the panel refuses (not enough credits, username or email already used) an order note explains it; use "Provision Xtream UI Pro lines again" to retry. Accounts and credits are never given twice.
6. Refunded or cancelled orders take the credits back when the sub-reseller still has them (otherwise an order note reports the failure) and disable the account that order created, or delete it for good when the product's "On refund or cancellation" is "Delete permanently" (the group of your reseller must be allowed to delete accounts).
7. The group of a new account is not chosen: the panel takes the first group your reseller group allows (the API accepts a `group_id` and lists the allowed groups in `pricing`, but this plugin does not offer the choice yet).

== Shortcodes ==

* `[xtreampro_packages]` - official packages.
* `[xtreampro_packages ids="1,2"]` - only these package ids.
* `[xtreampro_packages trial="1"]` - trial packages.
* `[xtreampro_my_lines]` - logged-in customer's lines. Guests see a login link.
* `[xtreampro_my_reseller]` - logged-in customer's reseller account: username, status, live credit balance (cached for one minute) and sign-in link. Customers without an account see a short message, guests a login link.

Credit prices are never shown to visitors. Set your selling price on the WooCommerce product or the EDD download.

== Frequently Asked Questions ==

= Which account do I need? =
A reseller account. The API key is generated in the panel at /api-key. Create and renew actions are charged to that reseller's credits.

= The customer received different credentials than expected =
The panel (or the reseller's group) may replace custom or blank credentials. The plugin always uses the credentials the panel returns.

= Is HPOS supported? =
Yes, the plugin declares High-Performance Order Storage compatibility.

= What happens on uninstall? =
The settings are removed. Order and user meta (purchase history) is kept.

== What was verified, and what is a guess ==

Run (`plugins/e2e/run.sh wordpress`, again for 1.1.1): the plugin inside real WordPress with WooCommerce, and inside real WordPress with Easy Digital Downloads, against a real panel API (cmd/api of this repository on a throwaway database): provisioning, renewal, refund, retries without double charge, the credit check, links from the API, the `X-Connector` header in the panel's call log, deletion on cancellation, and the webhook receiver through WordPress' own REST dispatcher with signed, tampered, unsigned and stale calls. 1.1.1 adds: the pickers and the shortcode by `sells` (the box-only package is left out, a WooCommerce order for it creates nothing and says why, a renewal is not refused), event de-duplication by id, events with `owner_id`, and `REQUEST_ID_SPENT` after a line was deleted (the message, and that provisioning an order again sends no request id twice). The SureCart integration runs through a stand-in for SureCart only.

Not run, so guesses: checkout in a browser, real payment gateways, WooCommerce Subscriptions (not integrated), the settings screen buttons (Register / Test / Remove webhook) clicked in a browser (their API calls are exercised), and real webhook deliveries from the panel's worker to a public https address (the tests sign events with the same formula as the panel). The 24-hour retention of event ids and the choice not to offer `include_sub_resellers` are this plugin's decisions; the panel defines neither. The EDD and SureCart pickers were run with the box-only package present (the SureCart one through its stand-in only); the refusal of an order for such a package was run for WooCommerce, and EDD and SureCart reach the same check (`check_credits`) without a run of their own.

== Changelog ==

See CHANGELOG.md inside the plugin folder.

= 1.1.1 =
* Package pickers and `[xtreampro_packages]` offer only packages the panel sells as a line (`sells`); a box-only package is refused before the panel is asked. The webhook receiver de-duplicates on the event id. Readable `REQUEST_ID_SPENT`.

= 1.1.0 =
* Sends X-Connector, checks credits with `pricing` before provisioning, shows the links the panel returns, adds "On refund or cancellation" (disable or delete permanently), a webhook receiver with signature check, and the Panel address and Webhook secret fields in the settings.

= 1.0.0 =
* First release.
