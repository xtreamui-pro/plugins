# Xtream UI Pro - WISECP product module

Sells IPTV lines and sub-reseller accounts from WISECP. Each WISECP service is one line (or one
sub-reseller account) in your Xtream UI Pro panel, created and managed through the panel's
Reseller API with your own reseller API key.

> **Not tested inside a licensed WISECP.** WISECP is commercial and ionCube-encoded, so this module
> could not be started anywhere. It was written strictly from the WISECP developer documentation
> (dev.wisecp.com: "Writing a Product Module", "Product Module Client Management", "Module
> Configuration", "Module Anatomy", ...) and the published sample module
> (`github.com/wisecp/sample-product-module`). Every PHP file passes `php -l`, and the real module
> and API client were run end to end against a real panel through a stand-in for WISECP's base class
> (`plugins/e2e/wisecp-harness.php`). The places where the platform's behaviour had to be guessed are
> listed at the end under "What is unverified". Read them before selling anything with it, and try
> every action once on a test product first.

## Requirements

- WISECP 5.x (the module follows the v5 developer docs and also answers the method names of the
  older sample module), PHP 8.2 or newer (the WISECP requirement) with the `curl` and `json`
  extensions.
- An Xtream UI Pro panel whose API address (`cmd/api`, for example `https://api.example.com`) is
  reachable from the WISECP server. Use a valid TLS certificate; certificates are always verified.
- A **reseller** account in the panel with enough credits. Creating and renewing a line, and
  creating a sub-reseller, is charged to this reseller's credits.

## Install

1. Upload the package from **Modules > Product > All Modules > Upload Module** (ZIP), or extract it
   into the WISECP root so you get `coremio/modules/Product/XtreamPro/XtreamPro.php`. The folder
   name, the file name and the class name are all `XtreamPro` and must stay that way.
2. The web server user must be able to write `coremio/modules/Product/XtreamPro/config.php`: WISECP
   saves the module settings there (the API key is stored encrypted, never in clear).
3. Nothing else: no Composer, no database step.

## Get the API key

1. Sign in to the panel as the reseller account that will own the lines.
2. Open `/api-key` in the dashboard and generate (or copy) the API key.
3. Only reseller accounts work. Admin keys are refused with `FORBIDDEN`.

## Module settings

1. **Products > Product Group Modules > All Modules**, open **Xtream UI Pro**.
2. **API URL**: the panel's API address, `http://` or `https://`, for example
   `https://api.example.com`.
3. **API key**: the reseller key. It is never shown again; leave the field empty to keep the saved
   one.
4. Save, then press **Test connection**. It calls `user_info` and answers with the reseller name and
   credit balance, or a readable error (wrong key, host unreachable, ...). The test uses the
   *saved* settings: save first.
5. On **Initial**, enable the module for product groups (selecting it only makes it available).

## Product settings

Create a product in a *special* (or software) product group, open its **Automation** tab, choose
the module **Xtream UI Pro**, and fill **Module Configuration**:

| Field | Meaning |
| --- | --- |
| **Service type** | `IPTV line` or `Sub-reseller account`. Decides what one service sells. |
| **Panel package** | Loaded live from the panel (`name (credits, duration)`); packages for MAG / Enigma boxes only (`sells` without `line`) are not listed. Only for IPTV lines. If the panel cannot be reached while the product page is open, the field becomes a plain **Panel package id** box: type the numeric `id` of the package (shown in the panel's package list, or in the answer of `GET /reseller/v1?action=packages`). |
| **Trial line** | Create the line as a trial (the package must allow it). Only for IPTV lines. |
| **Delete permanently on terminate** | Off (**default**): cancelling the service only disables the line, which can be enabled again. On: cancelling deletes the line on the panel - final, nothing can be brought back (a returning customer gets a new line). Only for IPTV lines. |
| **Credits on creation** | Whole number, 0 or more: credits handed to the new sub-reseller account. Only for sub-reseller accounts. |
| **Credits per renewal** | Whole number, 0 or more: credits handed over at every renewal (0 = renewals do nothing on the panel). Only for sub-reseller accounts. |

Also set **Automatic Setup** as you like (for example "as soon as payment is complete").

A customer cannot choose a username or password by default: the panel generates the line's
credentials, and the module generates the sub-reseller's (`r<serviceid>` plus random letters, and a
random 14 character password). If you define product *requirements* named `username` and/or
`password`, their answers are sent instead (the panel's group may still ignore them). The
credentials the panel finally used are always read back and kept on the service, the password
encrypted.

## What each action does

| WISECP action | Panel call | Notes |
| --- | --- | --- |
| Create | `pricing`, then `create_line`, or `create_user` then `adjust_credits` | Charged. `pricing` runs first: too few credits, a package that is not on sale or one for boxes only fail at once with the amounts and nothing is created (sub-reseller: price of the account plus the starting credits). `request_id = wisecp-create-<service id>-<n>` (lines), `wisecp-sub-...` / `wisecp-subc-...` (sub-resellers); `n` counts cancellations of the service, so a retry never charges twice and creating a cancelled service again sells a new line. Running Create on an already provisioned service does nothing. |
| Suspend | `disable_line` / `disable_user` | Free. |
| Unsuspend | `enable_line` / `enable_user` | Free. |
| Cancel (terminate) | `delete_line` or `disable_line` (by **Delete on terminate**); sub-resellers: `disable_user` | A line already gone from the panel counts as success. Sub-reseller accounts are **disabled, never deleted** (deleting hands their lines and credits to the parent and cannot be undone: do it in the panel). The service is then closed so Create can sell a new line / account. |
| Renewal | `pricing`, then `renew_line`, or `adjust_credits` with **Credits per renewal** | **Charged.** The request id contains the service id and the service's due date (`wisecp-renew-<id>-<n>-<yyyymmdd>`), so WISECP running one renewal twice does not charge twice, and the next period charges again. |

Sub-reseller details: the account gets the customer's email and name. The panel keeps e-mail
addresses unique **even for disabled accounts**, so when a cancelled service is created again its
account is given a tagged address (`name+g1@example.com`). If the credit transfer after creating
the account fails (for example not enough credits), the error is shown, the account stays attached
to the service, and running Create again only completes the transfer. The reseller's group must be
allowed to create sub-resellers (otherwise `FORBIDDEN`).

The service page shows (customer: management tab and overview; admin: service detail): lines -
status, expiry, max connections, server URL, username, password, the M3U playlist link and the web
player link (all links come from the panel); sub-resellers - status, credit balance, username,
password. Everything printed is escaped.

Calls are written to the WISECP module action history (`save_log`). The API key and every password
(also inside `password=` of play links) are masked or never logged.

## Troubleshooting

| Code | Meaning | What to do |
| --- | --- | --- |
| `INVALID_API_KEY` (401) | Key unknown or revoked | Type the key again in the module settings. |
| `FORBIDDEN` (403) | Not a reseller account, missing permission, or (sub-resellers) the group may not create sub-resellers / hierarchy too deep | Use a reseller account; check its group permissions. |
| `RESOURCE_NOT_FOUND` (404) | Line or sub-reseller not in the panel | It was deleted there; cancel the service. |
| `INVALID_REQUEST` (400) | e.g. username/password shorter than the group's minimum, invalid email | Adjust the credentials or the group setting. |
| `INVALID_PACKAGE` (400) | Package missing, not allowed, or for MAG / Enigma boxes only | Pick the package again on the product. |
| `REQUEST_ID_SPENT` (409) | The line this service's request id paid for was deleted on the panel, so the same id cannot sell another | Cancel the service and create it again (cancelling moves the counter in the request id on). |
| `INSUFFICIENT_CREDITS` (402) | Reseller balance too low (sub-resellers: the price plus the credits to hand over, or the renewal top-up) | Add credits in the panel, then retry the action. |
| `CONFLICT` (409) | Username (or, for sub-resellers, email) taken, or request id reused for another operation | Choose another username / email. |
| `RATE_LIMITED` (429) | Too many requests | Wait and retry. |
| `SERVER_ERROR` (500) | Internal panel error | Check the panel logs. |
| Could not connect | Network, port, TLS | Check the API URL and the certificate. |

## Not included

- Password change is not available through the Reseller API: do it in the panel (a changed line password
  does not update the WISECP service). Package change (`change_package`) exists in the API but the module has
  no upgrade / downgrade: WISECP's developer documentation names no hook for it that the module could be
  sure of, and nothing here is faked. Add-ons and metered billing are not implemented.
- A receiver for the panel's webhooks: the module has no HTTP endpoint of its own.
- The module does not top up the reseller's credits.
- No logo (`logo.png`) is shipped; WISECP shows a default icon. The shipped Turkish language file is
  a plain translation of the English one.

## Verified / guess (1.1.0)

Run for real against a panel, with the stand-in for WISECP (`plugins/e2e/wisecp-harness.php`): `X-Connector`
(read back from the panel's API call log), `pricing` before create / renewal (including the refusal of a
sub-reseller account whose price plus starting credits exceed the balance, with no account left behind),
`sells` filtering and the refusal of a box-only package, `REQUEST_ID_SPENT` text, **Delete permanently on
terminate** off by default. Not run: anything inside a real WISECP (see the list below, unchanged by 1.1.0).
The "transfer fails after the account exists" resume path can no longer be provoked by a plain shortage of
credits (the early check refuses first); the harness covers the resume with an account that exists and a
transfer still to do, which is the same code path.

## What is unverified (guessed from the docs and the sample)

The module has not run inside WISECP. These are the points where the documentation was silent,
ambiguous or contradicted by the older sample module, and what the code assumes:

1. **Two generations of the module API.** The v5 docs use `create()`, `renew()`, `cancel()`, throw
   on failure and `save_log($action, $request, $response, $processed)`; the published sample uses
   `create($order_options)`, `renewal()`, `delete()`, `$this->error` + `false`, and
   `save_log('Product', name, function, request, response, trace)`. The module implements the v5
   names and adds `renewal()` / `delete()` wrappers that return `false` with `$this->error`; it
   picks the `save_log` form by counting its parameters with reflection. The class is global (no
   namespace) like the sample, which both loaders accept.
2. **Settings screen.** `page_configuration()`, `controller_save()` and
   `controller_test_connection()` follow the v5 docs (the controllers return an array and throw on
   error). `pages/configuration.php` reuses the form markup and JavaScript of the sample module
   (`module_controller` operation, `MioAjaxElement`); how v5 really routes the posted form to
   `use_controller()` and shows the returned message is not documented.
3. **Product fields.** `product_configuration()` returns descriptors in the shape of the sample's
   `config_options()` (`text`, `dropdown`, `approval`); doing an API call while that form renders,
   the field names (flat keys in the product's module data) and how a ticked/unticked `approval` is
   saved (assumed: absent = off) are guesses. `save_product_configuration()` normalises and validates
   them, and a product saved without it is read defensively (see `xpFlag`).
4. **Per-service state.** The panel line / account id, a generation counter and a "credits
   handed over" flag live in `$this->options['config']`, the (encrypted) login in
   `$this->options['login']`, written with `save_options()` and also returned from `create()` for
   the core to merge. Assumed: `save_options()` works inside `create()` and `cancel()`, and the core
   does not overwrite what `cancel()` saved afterwards. If it does, a service created again would
   replay the first request id (and return the old, deleted line).
5. **Renewal.** Assumed: `renew()` runs with the service bound and `$this->service['duedate']`
   holds a date string or timestamp; whether that is the old or the new due date at that moment is
   not documented. The request id is stable either way while the date does not change between a
   run and its repeat. When no date is found, today's date (UTC) is used. The docs warn that a
   renewal can run twice for one period; that is what the request id absorbs.
6. **Identity and customer data.** The service id is `$this->service['id']` (the order id as
   fallback); the customer's email and name are read from `$this->user['email']`,
   `['full_name']` or `['name']` + `['surname']`; product requirement answers from
   `$this->requirement_params['username'|'password']`.
7. **Client area.** Shipping `pages/dashboard.php` is assumed to open the management tab for
   active services and to show in the admin service detail; the `$module` variable, the CSS classes
   used there, and the `client_overview_data()` / `fetchRemoteStatus()` shapes follow the docs, but
   the rendering was only checked by calling `get_page()` in the stand-in. A suspended service
   hides the tab (documented).
8. **Secrets.** `encode_str()` / `decode_str()` are used for the API key and the service password
   as the docs instruct; the stand-in uses a reversible encoding, not WISECP's crypt.
9. **Module registration.** `config.php` copies the sample's keys (`created_at`, `group` =>
   `other`, `status` => false, `meta`); whether `group` must be `other` for a product module that
   is selectable in a special product group is not documented.

## Test

```sh
plugins/e2e/run.sh wisecp      # once the caller has wired it, see plugins/e2e/README.md
# or by hand, against a running panel API:
MODULE_DIR="$PWD/plugins/wisecp/coremio/modules/Product/XtreamPro" \
  API_PORT_NUM=18092 API_KEY=<reseller key> php plugins/e2e/wisecp-harness.php
```

The harness loads the real module and drives it like WISECP's core (create, client area,
suspend, unsuspend, renewal and repeated renewal, cancel, create again; for a line, a
disable-only line and a sub-reseller), checks the panel state through the API after every step,
and checks that no API key or password reaches a log or is stored in clear.
