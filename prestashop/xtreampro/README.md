# Xtream UI Pro - PrestaShop module

Sells IPTV lines and sub-reseller accounts from a [PrestaShop](https://prestashop.com) shop. When an
order reaches a "paid" status the module creates one line per unit bought (or hands credits to the
customer's sub-reseller account) through the panel's Reseller API, with your own reseller API key. The
customer finds the credentials on the order page, in the order confirmation, in "My IPTV" in the
account, and (when you add a placeholder to the mail template) in the order e-mail.

> **Not tested inside PrestaShop.** This module has never run inside a real PrestaShop. It was written
> from the PrestaShop developer documentation (devdocs.prestashop-project.org: hooks list, module
> structure, configuration pages, `HelperForm`, front and admin controllers) and from the conventions of
> well-known modules. Every PHP file passes `php -l`, and the **platform independent core** (`src/`:
> API client and provisioner) and the presenter (`classes/Presenter.php`) were run end to end against a real
> panel by `plugins/e2e/prestashop-harness.php` (80 checks). The **PrestaShop glue** (the module class,
> the hooks, the database store, the controllers and the Smarty templates) was never executed. The places
> where PrestaShop's behaviour had to be guessed are listed at the end under "What is unverified". Install
> it on a test shop first and try every action once.

## Requirements

- PrestaShop 8.x (also written for 1.7.7 and 1.7.8), PHP 7.1 or newer with the `curl` and `json`
  extensions, MySQL 5.7+ / MariaDB 10.2+.
- An Xtream UI Pro panel whose API address (`cmd/api`, for example `https://api.example.com`) is reachable
  from the shop server. Use a valid TLS certificate; certificates are always verified.
- A **reseller** account in the panel with enough credits. Creating and renewing a line, and creating a
  sub-reseller account, is charged to this reseller's credits.
- Customers must be able to see their orders in the account (the credentials are shown there).

## Install

1. Upload the ZIP in **Module Manager > Upload a module** (the ZIP contains the single folder `xtreampro/`),
   or copy the folder `xtreampro/` to `<shop>/modules/xtreampro/`.
2. Click **Install**. The module creates three tables (`<prefix>xtreampro_product`, `_unit`, `_account`),
   a hidden back office controller, registers its hooks and sets the defaults: *paid* = "Payment accepted",
   *revoked* = "Canceled" and "Refunded".
3. Nothing else: no Composer, no cron job.

The three tables are **kept on uninstall** on purpose: they hold the credentials customers were given. To
remove them: `DROP TABLE <prefix>xtreampro_product, <prefix>xtreampro_unit, <prefix>xtreampro_account;`.
Uninstalling removes the settings, the API key included.

## Get the API key

1. Sign in to the panel as the reseller account that will own the lines.
2. Open `/api-key` in the dashboard and generate (or copy) the API key.
3. Only reseller accounts work. Admin keys are refused with `FORBIDDEN`.

## Module settings

**Module Manager > Xtream UI Pro > Configure**

| Field | Meaning |
| --- | --- |
| **API URL** | The panel's API address, `http://` or `https://`, for example `https://api.example.com`. |
| **API key** | The reseller key. It is stored in the shop's configuration table and **never shown again** (the field is always empty): type a key only to replace it. |
| **Panel address** | Optional: the dashboard address. Customers who buy a sub-reseller account get a `<address>/login` link. |
| **Paid order statuses** | Statuses that provision (default: Payment accepted). You may choose several. |
| **Revoked order statuses** | Statuses that take back (default: Canceled, Refunded). A status cannot be in both lists. |

**Save and test connection** saves the form, calls `user_info` and answers with the reseller name and credit
balance, or a readable error (wrong key, host unreachable, ...). It also refreshes the package list.

## Product settings

Edit a product, open the **Modules** tab (the module's own panel **Xtream UI Pro**; on the older product page it is
under **Modules** / **Extra** too):

| Field | Meaning |
| --- | --- |
| **Sold as** | *Not an Xtream UI Pro product* (default: the module ignores the product), *IPTV line* or *Sub-reseller account (credits)*. |
| **Package** | Loaded from the panel (`name (credits, duration)`), cached for ten minutes; packages for MAG / Enigma boxes only (`sells` without `line`) are not listed. Only for IPTV lines. |
| **Trial line** | Create the line as a trial (the package must allow it). Only for IPTV lines. |
| **Credits** | Whole number, 0 or more: credits handed to the customer's account for **every unit bought**. Only for sub-reseller products. |

Make the product **virtual** (or use an order status flow that reaches your *paid* status without shipping),
because provisioning follows the order status, not the delivery.

A customer cannot choose a username or password: the panel generates a line's credentials (and the
module reads back what the panel actually used); the module generates the sub-reseller's username (from the
e-mail address plus random letters) and a random 14 character password. Those secrets come from a
cryptographically secure generator.

## What each event does

A **unit** is one item bought: order detail + unit number (quantity 3 = units 1, 2, 3). Every call that costs
credits carries a request id built from stable ids (a random token of this shop, the order detail id, the unit
number and counters), so a retry, a double click or a repeated hook never charges twice.

| Event | Panel call | Notes |
| --- | --- | --- |
| Order gets a *paid* status | `pricing`, then `create_line` per unit, **or** `pricing`, `create_user` (once per customer) + `adjust_credits` per unit | Charged. `pricing` runs first: too few credits, a package that is not on sale or one for boxes only fail at once with the amounts and nothing is created. Request ids `xp<token>-line-<detail>-<unit>`, `...-sub-<customer>`, `...-subc-<detail>-<unit>-<n>`. A unit that is done is skipped. A unit that failed is retried with the same request id on the next paid event or with **Provision again**. |
| Order gets a *revoked* status | `disable_line` per line; sub-reseller: `adjust_credits` with a negative amount, `disable_user` | Lines are **disabled, never deleted** (deleting is final). Credits that are already spent cannot be taken back: the unit keeps its status and shows the message, and revoking again retries it. The sub-reseller account is disabled only when the revoked order created it. |
| Paid again after a revocation | `enable_line` / `enable_user`, then a new credit transfer | The customer gets the same line back; credits are handed over again (counter `n` + 1). |
| Admin: **Renew** (order page) | `get_line`, `pricing`, then `renew_line` | **Charged**; refused with the amounts when the balance cannot pay. Lines only. Request id `...-renew-<detail>-<unit>-<number>`; the number increases only after a success, so a retry after a timeout does not charge twice. |
| Admin: **Suspend** / **Resume** | `disable_line` / `enable_line`; sub-reseller: `disable_user` / `enable_user` | Free. |
| Admin: **Provision again** | as for a paid status | Only while the order has a paid status. |

Sub-reseller details: every customer has **one** account, created by the first order that contains a
sub-reseller product; later orders only add credits. The account gets the customer's e-mail address and name
(the panel keeps e-mail addresses unique, so the same address cannot be used for a second account: a
conflict is reported on the order). **Guest orders** are refused for sub-reseller products ("needs a
registered customer"): ask the customer to order while signed in. A customer who changed the password in the
panel sees the original one only on the order that created the account.

## Where the customer sees it

- **Order confirmation page** and **order detail page** (`displayOrderConfirmation`, `displayOrderDetail`): server
  URL, username, password, playlist link and web player link of every line (all links come from the panel);
  username, password, credits and sign-in link of the reseller account. Only the customer the order belongs to.
- **My IPTV** (`Your account > My IPTV`, link shown once the customer has something): all lines and the account,
  with fresh status, expiry date, max connections and credit balance from the panel (up to 25 calls; if the panel
  does not answer the stored data is shown).
- A unit that failed shows the customer only "could not be set up yet, contact us". The error text is only
  in the back office.

### Credentials in the order e-mail

PrestaShop does not let a module add text to a mail by itself. The module provides two template variables for
the **order confirmation** (`order_conf`) and **payment** (`payment`) mails:

- `{xtreampro_credentials}` - HTML block (tables), for the `.html` template
- `{xtreampro_credentials_txt}` - plain text block, for the `.txt` template

Add the variable where you want it in **Design > E-mail Theme** (or edit `mails/<language>/order_conf.html` /
`.txt`). Without the placeholder nothing is added. The values are empty for orders without Xtream UI Pro items.

## Back office

The order page shows an **Xtream UI Pro** panel (hook `displayAdminOrderMain`): every unit with status, username,
panel id and the last error, and the buttons **Renew**, **Suspend**, **Resume** and **Provision again**. The
buttons post to the module's hidden controller `AdminXtreamproOrders`, which checks the back office token and a
second form token (compared in constant time), accepts POST only, and requires the employee to be allowed to
edit orders. Failures are also written to **Advanced Parameters > Logs** (never with the API key or a password).

## Troubleshooting

| Code | Meaning | What to do |
| --- | --- | --- |
| `INVALID_API_KEY` (401) | Key unknown or revoked | Type the key again in the module settings. |
| `FORBIDDEN` (403) | Not a reseller account, missing permission, or (sub-resellers) the group may not create sub-resellers / hierarchy too deep | Use a reseller account; check its group permissions. |
| `RESOURCE_NOT_FOUND` (404) | Line or account not in the panel | It was deleted there. |
| `INVALID_REQUEST` (400) | e.g. invalid e-mail, credentials shorter than the group's minimum | Fix the customer's data or the group setting, then **Provision again**. |
| `INVALID_PACKAGE` (400) | Package missing, not allowed, or for MAG / Enigma boxes only | Choose the package again on the product. |
| `REQUEST_ID_SPENT` (409) | The line this item's request id paid for was deleted on the panel, so the same id cannot sell another | Cancel the order and place a new one. |
| `INSUFFICIENT_CREDITS` (402) | Reseller balance too low | Add credits in the panel, then **Provision again**. |
| `CONFLICT` (409) | Username or (sub-resellers) e-mail taken | The panel keeps e-mail addresses unique. |
| `RATE_LIMITED` (429) | Too many requests | Wait and retry. |
| Could not connect | Network, port, TLS | Check the API URL and the certificate. |

Nothing happens when an order gets a paid status: check that the status is in the *paid* list, that the product
has **Sold as** set, and that the module is configured (a not-configured module logs "was not provisioned" in
the PrestaShop logs).

## Not included

- No renewal at checkout: a customer who orders a line product again gets a **new** line. Renewing an existing line is an
  admin action on the order (or in the panel). The Reseller API's `delete_*` actions (the module only ever disables),
  password / package changes (`change_package` exists, but PrestaShop has no hook for a package change of a sold
  product) and per-renewal credits are not used. There is no receiver for the panel's webhooks.
- No return/partial refund handling: only whole-order statuses revoke. Quantity is taken from the order detail as bought.
- The module does not top up the reseller's own credits.
- Multistore is not handled (one set of settings, products are global). No logo is shipped.
- Customer-facing texts use PrestaShop's translation system (`Modules.Xtreampro.Shop` / `.Admin`, English source
  strings); the error texts of the core (`src/`) are English only.
- The sub-reseller password is kept in clear in the module's table (the customer is shown it, as in the other
  connectors); line passwords are kept in clear because the panel also keeps them in clear.

## Layout of the source

```
xtreampro/                      zip root = the module folder
  xtreampro.php                 module class: install, settings page, hooks (thin)
  config.xml
  src/                          PLATFORM INDEPENDENT core (no PrestaShop class)
    ApiClient.php               Reseller API client, error texts, secret masking
    Provisioner.php             all business rules: create / replay / suspend / renew / revoke, request ids
    Store.php                   what the provisioner needs saved (interface)
  classes/                      PrestaShop glue
    Config.php                  settings, factories, package cache
    DbStore.php                 the three tables, implements Store
    OrderService.php            order -> jobs, locks, what the pages show
    Presenter.php               unit rows -> cards / mail text (no PrestaShop class, tested by the harness)
  controllers/admin/AdminXtreamproOrdersController.php   the order page buttons
  controllers/front/iptv.php                               "My IPTV"
  views/templates/{admin,hook,front}/*.tpl                Smarty templates (every value escaped)
```

## Security notes

TLS verification is on, timeouts are 5 s (connect) / 20 s (total), redirects are never followed, the API key is
never logged or shown, passwords (also inside `password=` of play links) are masked in everything logged, all
output is escaped (Smarty `escape` / `htmlspecialchars`), links from the panel are shown as links only when they
are http(s), SQL values are escaped with `pSQL` or cast.

## Test

```sh
plugins/e2e/run.sh prestashop     # once the caller has wired it, see plugins/e2e/README.md
# or by hand, against a running panel API:
MODULE_DIR="$PWD/plugins/prestashop/xtreampro" \
  API_PORT_NUM=18092 API_KEY=<reseller key> php plugins/e2e/prestashop-harness.php
```

The harness loads the real `src/*.php` and `classes/Presenter.php` (not copies) with an in-memory store and, for
a line and for a sub-reseller account, checks against the panel after every step: create, replay of create (also
after the record is lost), suspend, resume, renew and repeated renew, revoke, paid again, wrong key, unknown
package, credit transfer that fails and resumes, credits that cannot be taken back, guest refusal; and that no API
key or password reaches a log or an error text. It does **not** cover PrestaShop itself.

## Verified / unrun (1.1.0)

**Ran for real against a panel** (core only, `plugins/e2e/run.sh prestashop`): `X-Connector` (read back from the panel's
API call log), `pricing` before create / renewal and the sub-reseller account (including the early refusal with no account
left behind), the refusal of a box-only package, the `REQUEST_ID_SPENT` text. **Never run in PrestaShop:** the product page
filter (`Config::packages`), everything else of the glue. The 1.0.0 list below is unchanged.

## What is unverified (guessed from the documentation)

1. **Hook parameters.** `actionOrderStatusPostUpdate` gets `newOrderStatus` (an `OrderState`) and `id_order`;
   `displayAdminOrderMain` gets `id_order`; `displayOrderConfirmation` / `displayOrderDetail` get `order`;
   `displayAdminProductsExtra` gets `id_product`; the product save hooks get `id_product`.
2. **Order of events at checkout.** Assumed: when an order is created with an already paid status, the order
   details exist before `actionOrderStatusPostUpdate` runs, and the confirmation mail is sent after it, so the
   mail variables already find the lines. If your payment module changes the status later, the lines appear on
   the order pages at that time and the mail variables need the later `payment` mail.
3. **Product page.** The fields are plain inputs in the "Modules" tab and are read with `Tools::getValue()` in the
   save hooks; a hidden field `xtreampro_form` tells the module its form was part of the request. Whether the new
   product page of PrestaShop 8.1+ posts such fields (it submits the page by script) is not documented; if not,
   the settings of a product do not save.
4. **Admin controller.** A hidden tab (`id_parent = -1`) receives the order page forms; the redirect back uses
   `getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => ...])`; the permission test uses
   `Profile::getProfileAccess()` for the Orders tab; the "done" message travels in the employee cookie.
5. **Settings form.** `HelperForm` with `multiple` selects named `KEY[]` and an extra submit button in `buttons`.
6. **Mail variables.** `actionGetExtraMailTemplateVars` fills `extra_template_vars` by reference with the keys
   `{xtreampro_credentials}` / `{xtreampro_credentials_txt}`; the order is found from `{id_order}` (payment mail) or
   `{order_name}` (order_conf mail, via `Order::getByReference`) and checked against `{email}` when present.
7. **Templates.** `{extends file='customer/page.tpl'}`, `module:xtreampro/...` includes and `{l s='..' d='Modules.Xtreampro.Shop'}`
   work as in the classic theme; the account link uses the classic theme's markup (`link-item`, Material icon).
8. **Database.** `INSERT ... ON DUPLICATE KEY UPDATE`, `REPLACE` and `GET_LOCK()` (a lock per order so two status
   changes at the same moment do not run in parallel) are used directly on MySQL / MariaDB.
9. **Units.** Quantity of the order detail = number of units; the order detail id is stable. A product bought
   several times in one order is one detail with a quantity.
10. **PHP 7.1.** Only checked by reading (the code avoids newer syntax); the tests ran on PHP 8.4.
