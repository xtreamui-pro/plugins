# Xtream UI Pro - FOSSBilling module

Sells IPTV lines and sub-reseller accounts from [FOSSBilling](https://fossbilling.org). Each FOSSBilling
order is one line (or one sub-reseller account) in your Xtream UI Pro panel, created and managed through
the panel's Reseller API with your own reseller API key. Nothing else in the panel is touched.

Version 1.1.0. Tested on FOSSBilling 0.8.8 (the `fossbilling/fossbilling` Docker image), PHP 8.5, against a real panel (`plugins/e2e/run.sh fossbilling`); the 1.1.0 additions ran there too. See `CHANGELOG.md`.

## Why a service module (and not a server manager)

FOSSBilling has two ways to talk to an outside system. A *server manager* (`library/Server/Manager`)
belongs to "hosting" products. It was looked at first and does not fit:

- it is not told which order it works for, so there is no stable id to make "create" and "renew"
  safe to repeat (a retry could charge the reseller twice);
- the only per-product data it gets are free-form plan values, and it has nowhere to remember the
  panel's line id or to show the customer a playlist link and credentials;
- **FOSSBilling never calls a server manager when a hosting order is renewed** (its renew step does
  nothing for the manager), so a paid renewal would never reach the panel.

A *service module* (a product type of its own, like "Hosting" or "License") is told about every order
event: create, activate, **renew**, suspend, unsuspend, cancel, uncancel, delete. That is what this
connector is: `modules/Servicextreampro`, product type **Xtreampro**.

## Requirements

- FOSSBilling 0.8.x (tested on 0.8.8; the service module interface is the same in earlier
  releases, but they were not tested), PHP 8.3 or newer with `curl` and `json`.
- FOSSBilling's cron job must run (it issues renewal invoices and suspends overdue orders).
- An Xtream UI Pro panel whose API address (`cmd/api`, for example `https://api.example.com`) is
  reachable from the FOSSBilling server. Use a valid TLS certificate; certificates are always verified.
- A **reseller** account in the panel with enough credits. Creating and renewing is charged to it.

## Install

1. Extract this package into your FOSSBilling root, so that you get
   `<fossbilling>/modules/Servicextreampro/Service.php`. No Composer, nothing else to copy.
2. Sign in to the FOSSBilling admin area, open **Extensions**, find **Xtream UI Pro** and click
   **Activate** (this also creates the table `service_xtreampro`).

## Get the API key

1. Sign in to the panel as the reseller account that will own the lines.
2. Open `/api-key` in the dashboard and generate (or copy) the API key.
3. Only reseller accounts work. Admin keys are refused with `FORBIDDEN`.

## Connect FOSSBilling to the panel

**Extensions > Xtream UI Pro** (`/admin/servicextreampro`):

- **Panel API URL**: scheme, host and port of the panel API, no path, e.g. `https://api.example.com`.
- **Reseller API key**: the key from above. FOSSBilling stores it encrypted; the page never shows it
  again (leave the field empty on later saves to keep it).
- **Save and test** calls `user_info` and `packages` and shows the reseller, its credits and the
  packages you can sell, or a readable error.

## Set up a product

1. **Products > New product**, product type **Xtreampro**. Set the price for the billing periods you
   sell (these are FOSSBilling's normal pricing fields) and **Activation** ("After payment is
   received" is the usual choice).
2. Open the product's **Configuration** tab:

| Field | Meaning |
| --- | --- |
| Sells | **IPTV line** or **Sub-reseller account**. |
| Package (IPTV line) | Dropdown loaded from the panel. Required for lines. Packages the panel says are for MAG / Enigma boxes only (`sells` without `line`) are not listed. |
| Trial line | Create the line as a trial (uses the reseller's trial quota). |
| When the order is cancelled (IPTV line) | **Only disable the line** (default: it can be enabled again) or **Delete the line permanently** (final on the panel: nothing can be brought back; a returning customer gets a new line). |
| Credits on creation (sub-reseller) | Whole number, 0 or more, handed to the new account. |
| Credits per renewal (sub-reseller) | Whole number, 0 or more, handed over at every renewal (0 = renewals do nothing on the panel). |

The values are copied onto each order when it is created, so changing a product later does not change
orders that already exist. If you prefer, the same values are the product's *Custom Parameters*
(`service_type`, `package_id`, `trial`, `delete_on_cancel`, `credits_on_creation`,
`credits_per_renewal`).

Lines get the username and password the panel generates (an order form with fields named `username` /
`password` is honoured, but the reseller's group may ignore custom credentials: the credentials the
panel actually used are always the ones shown). A sub-reseller account gets the generated username
`r<order id><random letters>`, a random 14-character password, the customer's email and name.

## What each action does

| FOSSBilling event | Line | Sub-reseller account |
| --- | --- | --- |
| Activate (order paid, or admin "Activate") | `pricing`, then `create_line`, **charged**. `pricing` runs first: too few credits, a package that is not on sale or one for boxes only fail at once with the amounts and nothing is created. `request_id = fb<install id>-c-<order>-<n>` (n counts deletions of the line), so a retry never charges twice. | `create_user` (charged: the group's sub-reseller price), then `adjust_credits` for *Credits on creation*. `request_id`s `...-u-...` and `...-v-...`. A failed credit transfer leaves the account mapped; activating again retries the transfer only. |
| Suspend (overdue, or admin) | `disable_line` | `disable_user` |
| Unsuspend | `enable_line` | `enable_user` |
| **Renew** (renewal invoice paid, or admin "Renew") | `pricing`, then `renew_line`, **charged**, extends the line by the package's duration. `request_id` carries the order's current expiry date, so a retry after a failure is not charged twice. A suspended order's line is enabled again. | `adjust_credits` with *Credits per renewal* (nothing when 0); enables the account if the order was suspended. |
| Cancel | `delete_line` (final) or `disable_line`, per the product setting. A line already gone from the panel counts as success. | `disable_user`. The panel can delete sub-resellers, but that hands their credits and lines to the reseller, so an ended order only disables the account. |
| Uncancel | The line is enabled again; if it was deleted, a **new** line is sold (and charged). | `enable_user` |
| Delete order | Ends the service like Cancel (unless already cancelled), then removes the order. | same |

### Renewal: how it reaches the panel

FOSSBilling calls the module's `renew` when a renewal invoice is paid (and when an admin renews an
order by hand). That is the signal this connector uses, and it is reliable: it was tested by paying
the renewal invoice. Things to know:

- The panel extends a line by the **package's own duration** (for example 1 year), not by the
  FOSSBilling billing period. Sell a package whose duration matches the period you charge for
  (a 1 month package with a monthly price). Credits cost the same either way.
- If the reseller has too few credits the order goes to *failed renew* with the readable message
  "The reseller account has not enough credits". Top up the credits in the panel and renew again.
- An overdue order is suspended by FOSSBilling's cron, which disables the line; paying the renewal
  invoice renews and enables it. If your FOSSBilling is set to cancel suspended orders after some days
  (**Settings > Order**), the cancel action runs, which by default only **disables** the line.
  Choose "Delete the line permanently" on the product only if you want it removed for good.

## What the customer sees

The order page (**Services > the order**) shows, live from the panel: username, password, status and
(lines) expiry, max connections, server URL, M3U / M3U-HLS playlist, XMLTV guide, player API and web
player links; for a sub-reseller account username, password, status and credit balance. The play
links are taken from the panel, not built here. Line passwords are not stored in FOSSBilling (they are
read from the panel); a sub-reseller password is stored encrypted with FOSSBilling's key because the
panel only keeps a hash. The admin order page shows the live account without any password.

## Security

TLS certificates are verified, connect timeout 5 s and total timeout 20 s, redirects are never
followed, the API key is never logged, displayed or sent anywhere but the panel, and generated
passwords come from `random_int`. Only the signed-in customer who owns an active order can read its
credentials. Activity entries and order notes carry no API key or password.

## Not included

- Changing a line's password (not in the Reseller API) or its package from FOSSBilling: `change_package`
  exists in the API, but FOSSBilling's service module contract has no hook for a package change, so
  nothing is faked here; do it in the panel. FOSSBilling's "upgrade" of an order does not change the panel package.
- A receiver for the panel's webhooks: it would need an endpoint, a table and an admin screen of its own.
- Topping up the reseller's credits.
- A line's connection limit, bouquets or MAG / Enigma2 devices: manage them in the panel.
- Uninstalling the module keeps the table `service_xtreampro`: it records which panel line belongs to
  which order.

## Troubleshooting

An action that fails shows the message on the order (status *failed setup* / *failed renew*, with the
text in the order's status history) and in the admin area. Fix the cause and use **Activate** / **Renew**
again.

| Code | Meaning | What to do |
| --- | --- | --- |
| `INVALID_API_KEY` (401) | Key unknown or revoked | Save the key again on the Xtream UI Pro page. |
| `FORBIDDEN` (403) | Not a reseller account, missing permission, or (sub-resellers) the group may not create sub-resellers / hierarchy too deep | Use a reseller account; check its group. |
| `RESOURCE_NOT_FOUND` (404) | Line or sub-reseller not in the panel | It was deleted there; cancel the order. |
| `INVALID_REQUEST` (400) | e.g. username/password shorter than the group's minimum, bad email | Adjust the credentials or the group setting. |
| `INVALID_PACKAGE` (400) | Package missing, not allowed, or for MAG / Enigma boxes only | Choose the package again on the product. |
| `REQUEST_ID_SPENT` (409) | The line this order's request id paid for was deleted on the panel, so the same id cannot sell another | Cancel the order and place a new one. |
| `INSUFFICIENT_CREDITS` (402) | Reseller balance too low (sub-resellers: price plus credits to hand over) | Add credits in the panel, then retry. |
| `CONFLICT` (409) | Username (or, for sub-resellers, email) taken, or request id reused for another operation | Another customer email, or contact the panel owner. |
| `RATE_LIMITED` (429) | Too many requests | Wait and retry. |
| `SERVER_ERROR` (500) | Internal panel error | Check the panel logs. |
| Could not connect | Network, port, TLS | Check the URL on the settings page and the certificate. |

Two FOSSBilling installs may share one reseller: every request id carries a random id of the install
(created when the settings are first saved), so order numbers cannot collide.
