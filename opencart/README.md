# Xtream UI Pro - OpenCart extension

Sells IPTV lines and sub-reseller accounts from an OpenCart shop. When an order reaches a paid
status the extension creates the lines (or the customer's sub-reseller account) in your Xtream UI Pro
panel through the panel's Reseller API, with your own reseller API key. When the order is cancelled
or refunded the lines are disabled and unspent credits are taken back. The customer finds the
credentials on the order page and on an account page.

> **This extension has not yet run inside a real OpenCart.** It was written from the OpenCart source
> (4.0.2.3 and 4.1.0.0, `github.com/opencart/opencart`) and the developer documentation, without
> starting a shop. What was run for real: the Reseller API client and the provisioning rules
> (`system/library`) against a real panel, and the order glue (`order_service.php`, `store.php`,
> the install model) against a real MariaDB with OpenCart's table layout, through
> `plugins/e2e/opencart-harness.php` (112 checks). Every PHP file passes `php -l`, every template
> parses with the Twig library OpenCart ships and renders escaped. What the harness cannot prove is
> how OpenCart itself calls the extension. Every place where that is a guess is listed under
> "What is unverified". Read it, then try every action once on a test product before selling anything.

## Requirements

- **OpenCart 4.0.2.x or 4.1.x.** 4.0.0.x / 4.0.1.x are not supported (they use another separator in
  event actions) and neither is OpenCart 3.x or 2.x: see "OpenCart 3.x" below.
- PHP 8.0 or newer with the `curl` extension (OpenCart 4 needs both anyway), MySQL or MariaDB.
- An Xtream UI Pro panel whose API address (`cmd/api`, for example `https://api.example.com`) is
  reachable from the shop server. Use a valid TLS certificate: certificates are always verified.
- A **reseller** account in the panel with enough credits. Creating a line, creating a sub-reseller and
  every credit transfer is charged to this reseller's credits.

## Package and install

The folder you are reading is the content of the extension archive. OpenCart takes the **extension code
from the file name**, so the archive must be called `xtreampro.ocmod.zip`, with `install.json` at the root:

```sh
cd plugins/opencart && zip -r ../xtreampro.ocmod.zip . -x README.md
```

1. **Extensions > Installer > Upload** `xtreampro.ocmod.zip`, then press **Install** on the uploaded
   extension. The files go to `extension/xtreampro/`; no core file is changed (the extension only
   listens to events).
2. **Extensions > Extensions**, choose **Modules**, find **Xtream UI Pro** and press the green **Install**
   button. This creates the two tables, registers the 8 events and gives your admin group the
   permission. Then press the blue **Edit** button.
3. Fill in the settings below and save.

Uninstalling (the red button) removes the events and the settings (API key included). The two tables
and the shop token stay, because they belong to the lines you sold; delete the extension from the
Installer afterwards. To drop the data too: `DROP TABLE oc_xtreampro_unit, oc_xtreampro_product;`
(use your table prefix).

## Get the API key

1. Sign in to the panel as the reseller account that will own the lines.
2. Open `/api-key` in the dashboard and generate (or copy) the API key.
3. Only reseller accounts work. Admin keys are refused with `FORBIDDEN`.

## Settings (Extensions > Modules > Xtream UI Pro)

| Field | Meaning |
| --- | --- |
| **Status** | Master switch. Off: orders are not provisioned and the customer pages show nothing. |
| **API URL** | The panel's API address, `http://` or `https://`, without `/reseller/v1`. |
| **API key** | The reseller key. It is stored, never shown again (the field stays empty); leave it empty to keep the saved key. |
| **Panel address** | Optional. The panel's dashboard, e.g. `https://panel.example.com`. Customers who bought a sub-reseller account get a "Sign in" link to `<address>/login`. |
| **Paid order statuses** | Orders that reach one of these statuses are provisioned. Before the first save: your store's Processing and Complete statuses. |
| **Revoked order statuses** | Orders that reach one of these are revoked. Before the first save: statuses named Canceled, Refunded, Reversed, Chargeback, Voided, Denied. A status cannot be in both lists. |
| **Test connection** | Calls the panel with the values in the form (an empty key field uses the saved key) and answers with the reseller name, credit balance and number of packages. When the values are the saved ones it also refreshes the package list used by the product form. |

## Product setup

Edit a product (**Catalog > Products**): the form has a new tab **Xtream UI Pro** (next to Report).

| Field | Meaning |
| --- | --- |
| **Buying this product** | *does nothing on the panel* (default), *IPTV line*, or *Sub-reseller account*. |
| **Package** | IPTV line only. The list comes from the panel (cached for one hour, refreshed by Test connection); packages for MAG / Enigma boxes only (`sells` without `line`) are not listed.  A package that is no longer in the list stays selectable as `#id`. |
| **Trial line** | IPTV line only. Creates trial lines with the package's trial settings and trial credits. |
| **Credits** | Sub-reseller account only. Credits per purchased unit, taken from your reseller balance. 0 creates the account without credits. |

A variant uses the settings of its master product when it has none of its own.

## What happens

| Event in the shop | What the extension does |
| --- | --- |
| Order reaches a **paid** status | Asks the panel first (`pricing`): too few credits, a package that is not on sale or one for boxes only fail at once with the amounts and nothing is created. Then one line per purchased unit (quantity 3 = three lines), or: the first sub-reseller order of a customer creates the account and hands over `credits x quantity`; later orders top up the same account. Done units are left alone, so the many history entries of an order cost nothing. |
| Order reaches a **revoked** status | Lines are **disabled** (never deleted). For sub-reseller items the credits this order handed over are taken back; the account is disabled when this order created it. Credits the customer already spent cannot be taken back: the unit records that. |
| A revoked order is **paid again** | The same lines are enabled again (no new charge). A line deleted in the panel meanwhile is replaced by a new one. Credits are handed over again. A disabled account that some other order would top up is refused with a clear message instead of receiving credits. |
| Admin: order page > tab **Xtream UI Pro** > **Provision again** | Retries what failed (wrong key, no credits, bad package) and completes what is missing. Only for orders in a paid status. |
| Customer: order page | A block with server URL, username, password, playlist / EPG / web player links from the panel and live status and expiry. For a sub-reseller order that created the account: username and password (shown on that order only). Only for the customer the order belongs to. |
| Customer: **My account** | A new link **My IPTV lines and reseller account** (shown once the customer has something) lists every line with live status, and the reseller account with its credit balance. |

Repeating any event is harmless: request ids sent to the panel are built from the shop token, order id,
order product id, unit number and a generation counter, so a retry never charges twice, and redoing
undone work (refund, then paid again) is a new, separate sale.

Results and errors are stored per order product id and unit number in `oc_xtreampro_unit` and shown in
the admin order tab, with a readable message (`INSUFFICIENT_CREDITS` becomes "The reseller account has
not enough credits." and so on). Nothing secret reaches OpenCart's error log: it gets ids, statuses and
error codes only, and an exception is logged without its message (database errors contain SQL).

Guest orders: a **sub-reseller** product is refused for a guest (the account needs a customer to belong
to) and the unit records why. A **line** bought as a guest is created, but a guest has no order page in
OpenCart 4, so only you can read the credentials (admin order tab); send them yourself or disable guest
checkout for these products.

## Data kept in the shop

- `oc_xtreampro_product`: per product: type, package, trial, credits.
- `oc_xtreampro_unit`: per bought unit: status, the panel id, username, password, credits handed
  over, generation and the last error. **Line passwords are kept in clear**, as the panel itself does
  (customers and apps need them); the sub-reseller's password is kept in clear as well so the order page
  can show it once. Protect database backups like you protect the panel.
- Settings in OpenCart's `setting` table: the API key is stored in clear there (OpenCart has no secret
  store) and is never output by the extension.
- `module_xtreampro_instance`: a random token of this shop, part of every request id, so two shops (or a
  reinstalled one) using the same reseller key never replay each other's orders.

## Troubleshooting

| Message | Cause |
| --- | --- |
| The panel rejected the API key | Wrong key, or the key was regenerated. |
| The API key does not belong to a reseller account ... | Admin key, or the reseller's group may not create sub-resellers / the hierarchy is too deep. |
| The reseller account has not enough credits | Top up the reseller in the panel, then **Provision again**. The message names the amounts. |
| The selected package does not exist ... or cannot be sold this way | The package was deleted in the panel, or it is for MAG / Enigma boxes only; choose another in the product tab. |
| Could not connect to the panel | Wrong URL, firewall, or a certificate that is not valid (verification cannot be switched off). |
| The customer's sub-reseller account is disabled | A refund disabled it, or you did. Enable it in the panel, then provision again. |
| This sale was already made and its line has since been deleted on the panel (`REQUEST_ID_SPENT`) | The earlier attempt did create the line but the shop never stored it, and the line was then deleted in the panel; the panel never sells a second line under the same request id. Cancel the order and place a new one. |
| The block does not appear on the product form / order page | See "What is unverified": the extension adds it by looking for the Report / Additional tab; the reason is written to `system/storage/logs/error.log`. |
| Nothing happens when an order is paid | Status switched off, or the order status is not in the paid list. Provision again tells you which. |

## OpenCart 3.x

Not supported, and not in this package: the extension layout (`admin/controller/extension/module`,
no `install.json`, OCMOD `install.xml` + `upload/`), the event registration (`addEvent` with other
trigger names and `|` separators), classes without namespaces and the templates (`.twig` only from 3.0,
`.tpl` before) all differ. A 3.x port would reuse `system/library` (the client, the provisioner and the
order glue do not depend on the OpenCart version) and rewrite the controllers, language files and
templates; it is a separate package, not a switch in this one.

## What is not included

- Renewal of an existing line from the cart ("renew my line"): the WordPress / WooCommerce connector has
  it, this one does not (it would need cart events).
- The credentials in the order e-mail: OpenCart sends its order mail before the extension provisions the
  order, so they cannot be in it. Customers read them on the order page.
- Other languages: only `en-gb` files are shipped; a missing translation shows the English text.
- Partial refunds, subscriptions / recurring products, product options, multi-store settings.
- Deleting lines or accounts in the panel (refunds only disable; there is no product option for a permanent delete, because deleting is final on the panel).
- Upgrade / downgrade (`change_package` exists in the API, but OpenCart has no hook for a package change of a sold product) and a receiver for the panel's webhooks.

## Verified / unrun (1.1.0)

**Ran for real** (core and database layer, `plugins/e2e/run.sh opencart`): `X-Connector` (read back from the panel's API
call log), `pricing` before create / the sub-reseller account (including the early refusal with no account left behind), the
`sells` filter of `packageOptions` and the refusal of a box-only package, the `REQUEST_ID_SPENT` text. **Never run in
OpenCart:** that the product form shows the filtered list, and everything in the next section (unchanged).

## What is unverified (guessed from the source, never run in OpenCart)

1. **Everything OpenCart does around the extension**: installer, extension list, permissions, event
   dispatch. Read in 4.0.2.3 and 4.1.0.0: events are registered by `addEvent`, fired as
   `model/checkout/order/addHistory/after` with `[&$route, &$args, &$output]`; view events as
   `view/<route>/before|after`. The handlers use only these arguments (the 4.1 `before` event passes one
   more, which is ignored).
2. **The tabs** are inserted into the *rendered* product form and order page by string search (the "Report"
   tab button and pane, the "Additional" tab button and pane), both checked against the 4.0.2.3 and
   4.1.0.0 templates. A theme or another extension that changes them makes the extension skip the
   tab and log why; nothing breaks.
3. **The customer blocks** are appended to the `content_bottom` position of the order page and the
   account page. A theme that does not print `content_bottom` there will not show them.
4. Admin changes of an order status go through the shop's own order API into the same
   `addHistory` model call, so the event fires for them too (read, not run).
5. The settings page, the product tab and the order tab use the alert box `#alert` of the admin and
   Bootstrap 5 classes of OpenCart 4; the test and provision buttons use `fetch`, not OpenCart's own
   ajax helper, so they do not depend on its version.
6. Language keys all start with `xp_` on purpose: OpenCart merges the loaded language into every
   template, so a short key could replace a string of the page the block is added to.
7. The order lock uses MySQL / MariaDB `GET_LOCK`; tested on MariaDB 11, not on MySQL 8.
8. The panel's play links are shown as returned by `get_line`; none is built by the extension.

## Files

```
install.json                      name / version / author / link read by OpenCart's installer
admin/controller/module/xtreampro.php      settings, install / uninstall, test connection, provision again (the main entry)
admin/controller/event/product.php         product form tab (view event) and its save (model events)
admin/controller/event/order.php           admin order tab (view event)
admin/model/module/xtreampro.php           tables, events, package cache
admin/view/template/...  admin/language/en-gb/...
catalog/controller/event/order.php         order history -> provision / revoke, customer order page, account link
catalog/controller/account/lines.php       customer page "My IPTV"
catalog/view/template/account/...  catalog/language/en-gb/...
system/library/client.php                  Reseller API client (platform independent)
system/library/provisioner.php             all provisioning rules (platform independent)
system/library/api_exception.php           readable error texts
system/library/order_service.php           order -> provisioner -> tables glue
system/library/store.php                   the two tables
system/library/credentials_view.php        data of the customer template
```

Test it without OpenCart: `API_KEY=<reseller key> API_PORT_NUM=<panel port> plugins/e2e/opencart-run.sh`
(starts a throwaway MariaDB with Docker, prints `ALL OK`).
