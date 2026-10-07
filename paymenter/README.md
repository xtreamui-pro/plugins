# Xtream UI Pro - Paymenter server extension

Sells IPTV lines and sub-reseller accounts from [Paymenter](https://paymenter.org). Each Paymenter
service is one line (or one sub-reseller account) in your Xtream UI Pro panel, created and managed
through the panel's Reseller API.

Tested with Paymenter 1.5.9 (the official Docker image), version 1.1.0 of this extension, against a real panel (`plugins/e2e/run.sh paymenter`). See `CHANGELOG.md`.

## Requirements

- Paymenter 1.5 or newer (server extensions with `createServer` / `getActions`, PHP 8.2+, which Paymenter
  itself needs). The extension uses only what Paymenter ships: no Composer package, no migration.
- An Xtream UI Pro panel whose API address (`cmd/api`, for example `https://api.example.com`) is reachable
  from the Paymenter server. Use a valid TLS certificate; certificates are always verified.
- A **reseller** account in the panel with enough credits. Creating and renewing a line is charged to this
  reseller's credits.
- Paymenter's queue worker and its cron job running, as Paymenter itself requires: creating, suspending
  and terminating services are queued jobs, and the cron job suspends and terminates overdue services.

## Install

1. Copy the `extensions/` folder of this package into your Paymenter root, so that you get
   `<paymenter>/extensions/Servers/XtreamPro/XtreamPro.php`.
   - Docker: copy it into the folder you mount as `/app/extensions` (the `extensions` folder next to your
     `docker-compose.yml`). Keep `PAYMENTER_SKIP_DEFAULT` as it is: it only renews the bundled extensions.
   - Make the files readable by the web user (`chown -R www-data:www-data extensions/Servers/XtreamPro`,
     `nginx:nginx` in the Docker image).
2. If Paymenter caches its classes, run `php artisan optimize:clear`.
3. In the admin area open **Servers** (the extension list shows **Xtream UI Pro**) and create a server of
   that type.

## Get the API key

1. Sign in to the panel as the reseller account that will own the lines.
2. Open `/api-key` in the dashboard and generate (or copy) the API key.
3. Only reseller accounts work. Admin keys are refused with `FORBIDDEN`.

## Set up the server

In **Servers > Create**: extension **Xtream UI Pro**.

- **API URL**: the panel's API address, for example `https://api.example.com`.
- **Reseller API key**: the key. It is a password field and is stored encrypted by Paymenter.
- Save. Paymenter's test connection calls `user_info` and shows a readable message when the URL or the key
  is wrong.

## Set up a product

**Products > Create**, then the **Server** tab: pick the Xtream UI Pro server. The settings are

| Setting | Applies to | Meaning |
| --- | --- | --- |
| Service type | both | **IPTV line** or **Sub-reseller account**. The other fields switch on or off with it. |
| Package | line | List loaded live from the panel (`name (credits, duration)`); packages for MAG / Enigma boxes only (`sells` without `line`) are not listed. Required. |
| Trial line | line | Create the line as a trial. |
| Delete permanently on terminate | line | Off (**default**): the line is only disabled and can be enabled again. On: the line is deleted on the panel - final, nothing can be brought back (a returning customer gets a new line). |
| Credits on creation | sub-reseller | Whole number, 0 or more: credits handed to the new account. |
| Credits per renewal | sub-reseller | Whole number, 0 or more: credits handed over at every paid renewal invoice (0 = nothing). |

The packages dropdown shows the panel's error when it cannot be loaded (wrong key, panel down); use the
**Refresh** link next to the server field to load it again.

The username and password of a line come from the panel (it generates them, and the reseller's group may
ignore custom ones): the service always shows what the panel actually used. If the product has custom
properties with the keys `username` and `password`, they are sent to the panel as wishes.

## What each action does

| Paymenter | Panel call | Notes |
| --- | --- | --- |
| Create (first payment, or admin **Trigger Extension Action > Create server**) | `pricing`, then `create_line` | Charged. `pricing` runs first: too few credits, a package that is not on sale or one for boxes only fail at once with the amounts and nothing is created. Request id `pm-<installation>-create-<service id>-<n>`: a retry never charges twice, and creating a terminated service again (`n` counts terminations) sells a new line. |
| Suspend | `disable_line` | Free. |
| Unsuspend | `enable_line` | Free. |
| Terminate | `delete_line` or `disable_line` | Depends on **Delete permanently on terminate**. A line already gone from the panel counts as success. |
| Upgrade (customer upgrade, or admin **Trigger Extension Action > Upgrade server**) | `get_line`, `pricing`, `package_compatibility`, then `change_package` | **Charged** (the new package's official price), lines only. Sells the package of the service's product on the line; the panel's answer (time left kept or lost, price) goes to the log, too few credits refuse the change. A line already on that package is left alone (a repeat). Request id `pm-<installation>-chg-<service id>-<package>-<expiry now>`. |
| Paid renewal invoice | `pricing`, then `renew_line` | **Charged.** Extends the line by the package's official duration. Request id contains the invoice id: never renewed twice for one invoice. |

Sub-reseller accounts:

| Paymenter | Panel call | Notes |
| --- | --- | --- |
| Create | `create_user`, then `adjust_credits` | Charged: the reseller pays its group's sub-reseller price **plus** the credits handed over. Username `r<service id>` plus random letters, a random 14 character password, the customer's email and name. If the credit transfer fails, create again to retry only the transfer. |
| Suspend / Unsuspend | `disable_user` / `enable_user` | Free. |
| Terminate | `disable_user` | The account is only **disabled**, never deleted: `delete_user` is final and moves the account's credits and lines to the reseller. |
| Paid renewal invoice | `adjust_credits` | Hands over **Credits per renewal** credits. Nothing when it is 0. |

The client area of the service gets a tab **IPTV line** / **Account**: username, password, status, expiry,
max connections and the play links the panel builds (server, M3U, M3U HLS, XMLTV, player API, web player) for
a line; username, password, status and credit balance for a sub-reseller account. Passwords of
sub-reseller accounts are kept encrypted on the service (Paymenter would otherwise write them in clear to
its audit log).

Where the ids live: in the service's properties, `xtreampro_line_id`, `xtreampro_user_id`,
`xtreampro_username`, `xtreampro_secret` (encrypted), `xtreampro_generation`, plus
`xtreampro_renew_invoices` / `xtreampro_renew_error` while a renewal is pending. Nothing is written to files.

## How renewals work (and their limit)

Paymenter has **no renewal call for server extensions**: when a customer pays a renewal invoice it only
unsuspends a suspended service or creates a pending one. The extension therefore listens to Paymenter's
invoice events. Just before an invoice is saved as paid it notes the services on it that already have a
panel object (so the first payment, which creates the line, is never a renewal); once the invoice is paid it
renews them. A failure never blocks the payment: the invoice stays paid, the error is logged and shown in the
service's property `xtreampro_renew_error`, and the renewal waits.

To retry waiting renewals (for example after topping up the reseller's credits): in the admin area open the
service, **Trigger Extension Action > Upgrade server**. This action is the retry button; it also sells the product's
package on the line when the line is on another one (see the upgrade row above). Repeating it is safe, the panel
charges each invoice, and each change, once.

Not covered: a renewal that Paymenter does not bill as an invoice, that is, a service with a price of 0
(Paymenter renews it silently, no event fires), and an admin changing the due date by hand. Those do not
renew anything on the panel.

## Not included

- Password change is not available through the Reseller API. Do it in the panel. A package change made on the
  panel itself is not noticed until the next **Upgrade server** (which then sells the product's package again if
  it differs).
- A receiver for the panel's webhooks (not a cheap addition to a Paymenter extension).
  (Changing the line's password in the panel needs no change here: the client area reads it live.)
- The extension does not top up the reseller's credits.
- No admin-side tab: the admin sees the ids and the last error in the service's properties.
- A trial line is created with the `trial` flag as is; whether the chosen package may be a trial is the
  panel's decision (`INVALID_PACKAGE` otherwise).

## Troubleshooting

**Verified / guess (1.1.0).** Ran in a real Paymenter 1.5.9 against a real panel: `X-Connector`, `pricing` before
create / renewal, the `sells` filter of the dropdown (through Paymenter's own admin form) and the refusal of a
box-only package, `change_package` through `ExtensionHelper::upgradeServer` (charge, repeat guard), `REQUEST_ID_SPENT`
text, the cancellation default. Still a guess: that a **customer** upgrade in Paymenter changes the service's product
before it calls `upgradeServer`, so that `$settings` are the new product's (the scenario simulates it by changing the
service's product and calling the action, as the admin button does).

Errors of queued jobs show in Paymenter's failed jobs and in `storage/logs/laravel.log` (lines starting
with `Xtream UI Pro:`; the API key and passwords are never logged). Fix the cause, then re-run the action
from the service (**Trigger Extension Action**); create and renew are safe to repeat.

| Code | Meaning | What to do |
| --- | --- | --- |
| `INVALID_API_KEY` (401) | Key unknown or revoked | Re-enter the key in the server. |
| `FORBIDDEN` (403) | Not a reseller account, missing permission, or (sub-resellers) the group may not create sub-resellers / hierarchy too deep | Use a reseller account; check its group permissions. |
| `RESOURCE_NOT_FOUND` (404) | Line or sub-reseller not in the panel | It was deleted in the panel; terminate the service. |
| `INVALID_REQUEST` (400) | e.g. username/password shorter than the group's minimum, bad email | Adjust the credentials or the group setting. |
| `INVALID_PACKAGE` (400) | Package missing, not allowed, or for MAG / Enigma boxes only | Re-select the package on the product. |
| `REQUEST_ID_SPENT` (409) | The line this service's request id paid for was deleted on the panel, so the same id cannot sell another | Terminate the service and create it again. |
| `INSUFFICIENT_CREDITS` (402) | Reseller balance too low (sub-resellers: the price plus the credits to hand over, or a renewal top-up) | Add credits in the panel, then retry the action. |
| `CONFLICT` (409) | Username (or, for sub-resellers, email) taken, or request id reused for another operation | Choose another username or email. |
| `RATE_LIMITED` (429) | Too many requests | Wait and retry. |
| `UNKNOWN_ACTION` (400) | Panel too old for this extension | Update the panel. |
| `SERVER_ERROR` (500) | Internal panel error | Check the panel logs. |
| Could not connect | Network, port, TLS | Check the API URL and the certificate. |
