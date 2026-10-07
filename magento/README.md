# Xtream UI Pro - Magento 2 / Adobe Commerce Open Source module

Sells IPTV lines and sub-reseller accounts from a Magento 2 shop. The module `XtreamPro_Connector`
creates, disables and revokes them in your Xtream UI Pro panel through the panel's **Reseller API**,
with the API key of one reseller account. Version 1.1.0 (see `CHANGELOG.md`).

> **Status: this module has not yet run inside a real Magento.** It was written from the Adobe
> Commerce developer documentation and checked in two ways only:
>
> - the platform independent core (`Core/`) and the order logic (`Model/OrderService.php`) were run
>   for real against a panel API (`plugins/e2e/magento-harness.php`, `plugins/e2e/magento-orderflow-harness.php`,
>   the second one with stand-ins for Magento's order, product and comment classes and with the two
>   database tables kept in memory);
> - every PHP / PHTML file was linted (`php -l`), every XML and JSON file parsed.
>
> The SQL in `Model/Storage.php`, the observers, plugin, controllers, blocks, layouts, templates,
> `db_schema.xml`, the data patch and `system.xml` have **never been executed**. Expect to fix small
> things the first time you install it. The places where the behaviour of Magento had to be guessed are
> listed under "Guesses" at the end.

## Requirements

- Magento Open Source / Adobe Commerce 2.4.x, PHP 8.1 or newer with the `curl` and `json` extensions.
- Magento **cron** running (the retry job needs it).
- An Xtream UI Pro panel whose API address (`cmd/api`, for example `https://api.example.com`) is
  reachable from the Magento server. Use a valid TLS certificate; certificates are always verified.
- A **reseller** account in the panel with enough credits. Creating a line or an account is charged to
  this reseller's credits.

## Install

1. Extract the archive into the Magento root, so that you get
   `<magento>/app/code/XtreamPro/Connector/registration.php`.
2. Run, as the Magento file system owner:

   ```sh
   bin/magento module:enable XtreamPro_Connector
   bin/magento setup:upgrade          # creates the two tables and the four product attributes
   bin/magento setup:di:compile       # production mode only
   bin/magento setup:static-content:deploy -f   # production mode only
   bin/magento cache:flush
   ```

3. Nothing else to install: no Composer package, no third-party library. (`composer.json` is in the
   module folder in case you prefer a path repository.)

## Get the API key

1. Sign in to the panel as the reseller account that will own the lines.
2. Open `/api-key` in the dashboard and generate (or copy) the API key.
3. Only reseller accounts work. Admin keys are refused with `FORBIDDEN`.

## Configure

**Stores > Configuration > Services > Xtream UI Pro** (default scope only):

| Field | Meaning |
| --- | --- |
| API URL | The panel's API address, without `/reseller/v1`. |
| API key | The reseller's key. Stored encrypted (`Magento\Config\Model\Config\Backend\Encrypted`), never shown again, never logged. |
| Panel address (optional) | Dashboard address shown to customers who bought a sub-reseller account (`<address>/login`). Empty = no sign-in link. |
| Connection | **Test connection** calls `user_info` with the values typed in the form (a masked key means "the saved one") and shows the reseller name and balance or a readable error. |

Admin roles need the resource **Xtream UI Pro connector settings** (Stores > Settings > Configuration)
to see the page, and **Provision Xtream UI Pro lines again** (Sales > Operations > Orders > Actions)
for the order button.

## Set up a product

The module adds a group **Xtream UI Pro** to the product form of every attribute set (simple and
virtual products only):

| Field | Meaning |
| --- | --- |
| Xtream UI Pro product type | *Not an Xtream UI Pro product* (default), *IPTV line* or *Sub-reseller account / credits*. |
| Xtream UI Pro package | IPTV line only. Dropdown loaded from the panel's package list (packages for MAG / Enigma boxes only, `sells` without `line`, are not listed; cached 10 minutes; if the panel is down the last list is used so saving a product does not erase the choice). |
| Trial line | IPTV line only. Creates a trial line (package trial settings and trial credits). |
| Credits per unit | Sub-reseller only. Credits handed to the customer's account per purchased unit, taken from your reseller balance. 0 = account without credits. |

Quantity matters: buying 3 of an IPTV line product creates **3 lines** (units 1, 2, 3).

## What happens

| Magento event | Module action |
| --- | --- |
| `sales_order_invoice_pay` (invoice marked as paid) | Claims one unit per invoiced quantity of every IPTV item (database rows only, no panel call). |
| `sales_order_invoice_save_commit_after` (paid invoice committed) | Claims again if needed, then provisions the open units: line = `pricing`, then `create_line`; sub-reseller = `pricing`, `create_user` (once per customer) + `adjust_credits`. `pricing` runs first: too few credits, a package that is not on sale or one for boxes only fail at once with the amounts and nothing is created. |
| `sales_order_creditmemo_save_commit_after` (refund) | Units no longer paid for (highest unit numbers first) are revoked: line = `disable_line` (never deleted: deleting is final); sub-reseller = credits taken back (`adjust_credits` negative), and the account is disabled when the customer has no other paid unit. |
| `order_cancel_after` | Same revoking, for an order that ends up cancelled as a whole. (Magento only cancels what is not invoiced, so normally there is nothing to revoke.) |
| cron every 5 minutes | Tries again units that failed or never got their panel call and refunds that could not be passed on: at most 50 units per run, each at most 8 times, not more often than every 5 minutes. |
| Admin order view button **Provision Xtream UI Pro again** | Claims again, then works through the open units regardless of the attempt limit, and re-reads the product settings for units that were never sold (so fixing a missing package takes effect). Shown only when something is open. |

Idempotency: every panel call carries a request id built from the order item id and the unit number
(`mage-l-<item>-<unit>` for lines, `mage-s-<customer>` for the account, `mage-c-<item>-<unit>` and
`mage-x-<item>-<unit>` for credits given / taken back). A repeated event, a retry after a timeout or a
second click on the button never sells twice. A sub-reseller account's username and password are
chosen and stored **before** the panel is called, so a retry sends the same ones.

Rules: a sub-reseller product needs a registered customer (guest orders are refused and noted on the
order; lines work for guests). One panel account per customer: later orders top up the same account.
Failures are written as comments on the order (never with a password) and kept on the unit for retry.
A final error (no package selected, guest order) is not retried by cron; the button is the way forward.
Credits that are already spent cannot be taken back: that is noted on the order and the refund goes on.

Panel calls happen after the invoice is committed, in the request that paid the invoice (checkout or
admin). The panel call has a 5 s connect / 20 s total timeout; a slow or dead panel delays that request
by at most 20 seconds and the unit is retried by cron.

## What the customer sees

- **Order view page** (`Orders and Returns` for guests, `My Orders > View` for customers): a block
  *Your IPTV subscription* with the server URL, username, password, playlist URL and web player link of
  each line (the links come from the panel's `links`, nothing is built by the module), or the reseller
  account's username and password. The block only shows an order to its own customer.
- **My Account > My IPTV** (`/xtreampro/account`): all units of the signed-in customer, and for a
  sub-reseller account the current credit balance (read live from the panel). The password shown for a
  sub-reseller account is the one issued by the module; if the customer changes it in the panel the
  shop keeps showing the old one.
- Everything is escaped with `$escaper`. The customer never sees error texts or panel ids.

Credentials are stored in `xtreampro_connector_unit` / `xtreampro_connector_reseller` encrypted with
Magento's `crypt/key` (`app/etc/env.php`). Back that key up with your database: without it the stored
passwords cannot be read. The panel log is `var/log/xtreampro.log` (calls with the API key never
included and passwords masked, also inside `password=` of play links).

## Not included

- **Credentials in the order / invoice email.** Left out on purpose: Magento sends the order email
  before payment for most payment methods and the invoice email can go out before the provisioning
  runs, and the default templates have no place for extra HTML (the merchant would have to edit them).
  A half-working email that sometimes lacks the credentials is worse than none; the customer reads
  them on the order page and in My IPTV.
- **Renewals.** Magento has no recurring billing. `Core\Provisioner::renewLine()` exists and is tested
  (`get_line`, `pricing`, then `renew_line` with a request id that carries the period), but nothing in the shop calls it. A customer
  who wants more time buys the product again (a new line).
- Password change (not in the Reseller API) and package change (`change_package` exists in the API, but Magento has
  no hook for a package change of a sold product), a receiver for the panel's webhooks, deleting lines or accounts on
  refund (lines are disabled, accounts are disabled; there is no product option for a permanent delete), configurable / bundle / grouped / downloadable products,
  per-website API settings, Adobe Commerce split-database setups, GraphQL / REST endpoints, a "Support"
  or ticket integration.

## Troubleshooting

Calls are in `var/log/xtreampro.log`; the reason of a failed unit is a comment on the order and the
`last_error` column of `xtreampro_connector_unit`.

| Code | Meaning | What to do |
| --- | --- | --- |
| `INVALID_API_KEY` (401) | Key unknown or revoked | Paste the key again in the configuration. |
| `FORBIDDEN` (403) | Not a reseller account, missing permission, or (sub-resellers) the group may not create sub-resellers / hierarchy too deep | Use a reseller account; check its group permissions. |
| `RESOURCE_NOT_FOUND` (404) | Line, account or package not in the panel | It was deleted in the panel; fix the product or leave the unit. |
| `INVALID_REQUEST` (400) | e.g. username / password shorter than the group's minimum, bad email | Adjust the group setting or the customer's email. |
| `INVALID_PACKAGE` (400) | Package missing, not allowed, or for MAG / Enigma boxes only | Select the package on the product again, then press the order button. |
| `REQUEST_ID_SPENT` (409) | The line this unit's request id paid for was deleted on the panel, so the same id cannot sell another | Cancel the order and place a new one. |
| `INSUFFICIENT_CREDITS` (402) | Reseller balance too low (sub-resellers: the price plus the credits to hand over) | Add credits in the panel; cron or the button retries. |
| `CONFLICT` (409) | Username or (sub-resellers) email already taken, or request id reused for another operation | Change the customer's email or ask the panel admin. |
| `RATE_LIMITED` (429) | Too many requests | Wait; cron retries. |
| Could not connect | Network, port, TLS | Check the API URL and the certificate; use **Test connection**. |

## Verified / unrun (1.1.0)

**Ran for real** (core and order logic with stand-ins, `plugins/e2e/run.sh magento`): `X-Connector` (read back from the
panel's API call log), `pricing` before create / the sub-reseller account (including the early refusal), the `sells` filter of
`Core\Provisioner::packages()` and the refusal of a box-only package, the `REQUEST_ID_SPENT` text, the `terminateLine`
default. **Never run in Magento:** that the product form dropdown shows the filtered list, and everything in the next
section (unchanged).

## Guesses (not verified in a running Magento)

1. `sales_order_invoice_save_commit_after` and `sales_order_creditmemo_save_commit_after` exist and
   carry `invoice` / `creditmemo` (assumed from `AbstractModel::afterCommitCallback` and the models'
   `_eventPrefix`); the brief named `sales_order_creditmemo_save_after`, replaced here so that the
   panel is not called inside the refund transaction. If your Magento does not fire them, the cron job
   (units claimed in `sales_order_invoice_pay`) and the order button still provision.
2. With online capture at checkout `sales_order_invoice_pay` fires before the order items have ids;
   the claim then does nothing and `save_commit_after` claims.
3. `Order::addCommentToStatusHistory()` returns the history entry and
   `OrderStatusHistoryRepositoryInterface::save()` stores it (2.4).
4. `Magento\Framework\Lock\LockManagerInterface` (2.4) is available and its database lock works.
5. The `current_order` registry key is set on the customer and guest order view pages, and the layout
   handles are `sales_order_view` and `sales_guest_view` (the block shows an order of a registered
   customer only to that customer).
6. The customer account navigation takes the `SortLink` block and the page title is set by
   `page.main.title::setPageTitle`.
7. The admin button posts a form built by an inline `onclick` (uses `window.FORM_KEY`); the button
   widget prints `onclick` as given. The "Test connection" script is an inline `<script>` (admin CSP is
   report-only in 2.4).
8. The data patch's `group` creates the group "Xtream UI Pro" in every attribute set and `apply_to`
   limits the attributes to simple / virtual products; the package source model is built by the object
   manager with its dependencies.
9. `db_schema_whitelist.json` was written by hand. Regenerate it with
   `bin/magento setup:db-declaration:generate-whitelist --module-name=XtreamPro_Connector` before
   you change the schema.
10. `$escaper` is available in the `.phtml` files (2.3.5+).
11. The module's own admin route `xtreampro` (frontName) and frontend route `xtreampro` do not clash
    with another module.

## Testing

`plugins/e2e/magento-harness.php` (core against a panel) and `plugins/e2e/magento-orderflow-harness.php`
(order logic with Magento stand-ins) need only `php` and a running panel API, no Magento:

```sh
API_PORT_NUM=18092 API_KEY_FILE=/path/to/reseller.key php plugins/e2e/magento-harness.php
API_PORT_NUM=18092 API_KEY_FILE=/path/to/reseller.key php plugins/e2e/magento-orderflow-harness.php
```

Both print `ALL OK`. A real Magento test is still to do: install, create an IPTV product, buy it with
an offline payment method, create the invoice in the admin, check the order page and My IPTV, create a
credit memo and look at the panel.
