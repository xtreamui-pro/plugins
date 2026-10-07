# Xtream UI Pro - ClientExec server plugin

Sells IPTV lines and sub-reseller accounts of your Xtream UI Pro panel from ClientExec, through the
panel's Reseller API with your own reseller API key.

> **Status: this plugin has not yet run inside a real ClientExec.** ClientExec is commercial, so it
> was written from the open-source server plugins ClientExec publishes (`github.com/clientexec`:
> DirectAdmin, Virtualizor, Enhance) and tested with a stand-in for the ClientExec classes
> (`plugins/e2e/clientexec-harness.php`) against a real panel API. Every place where ClientExec's
> behaviour was guessed is listed under "Guesses" below. Test it on a staging ClientExec first.

## Requirements

- ClientExec (a version with server plugins and package settings, i.e. a current 6.x release),
  PHP with `curl` and `json`.
- An Xtream UI Pro panel whose API address (`cmd/api`, e.g. `https://api.example.com`) is reachable
  from the ClientExec server, with a valid TLS certificate (always verified).
- A **reseller** account in the panel with enough credits. Creating a line and every credit transfer
  is charged to this reseller.

## Install

Extract the archive into the ClientExec root folder. You get
`<clientexec>/plugins/server/xtreampro/PluginXtreampro.php` (plus `lib/` and `resource/plugin.ini`).
No Composer, no database step. Then enable the plugin in ClientExec under
**Settings > Plugins > Server** (the plugin list is rebuilt from `resource/plugin.ini`).

## API key

Sign in to the panel as the reseller, open `/api-key` in the dashboard and copy the key. Admin keys
are refused (`FORBIDDEN`).

## Add the server

**Settings > Plugins > Servers > Add Server**, plugin **Xtream UI Pro**:

| Field | Value |
| --- | --- |
| Hostname | the panel API host, e.g. `api.example.com` |
| API Key | the reseller API key (stored encrypted by ClientExec) |
| Use SSL | on (recommended) |
| Port | only when not 443 (SSL) / 80 |
| Playlist URL / Web Player URL / Server URL / Credit Balance Custom Field | optional: names of package custom fields that receive these values so the customer sees them (see below) |

Use **Test Connection**: it calls `user_info` and shows a readable error for a wrong host, port or key.

## Product (package) setup

In the package settings of the product, choose this server plugin; the **Xtream UI Pro Settings**
appear:

| Setting | Meaning |
| --- | --- |
| Service type | `IPTV line` or `Sub-reseller account` |
| Panel package id (line) | the numeric id of the panel package (the `id` in the Reseller API `packages` list; also shown in the panel's package list). ClientExec package settings cannot load a list from the panel, so the id is typed |
| Trial line (line) | create the line as a trial |
| Delete permanently on terminate (line) | no (**default**): only disable the line, it can be enabled again. yes: delete it on the panel - final, nothing can be brought back (a returning customer gets a new line) |
| Credits on creation (sub-reseller) | whole number: credits handed to the new account |
| Credits per renewal (sub-reseller) | whole number: credits handed over by the **Renew** action (0 = nothing) |

Provision the package as usual (e.g. when the first invoice is paid). The account gets the ClientExec
package's **User Name** and **Password** custom fields; if they are blank the panel (line) or the plugin
(sub-reseller: `r<id>` + random letters, random 14-character password) generates them. The panel may ignore
custom credentials (reseller group rule), so the values the panel really used are always written back to
**User Name** / **Password**.

## What each action does

| ClientExec action | Panel call | Notes |
| --- | --- | --- |
| Create | `pricing`, then `create_line`, or `create_user` then `adjust_credits` | Charged. `pricing` runs first: too few credits, a package that is not on sale or one for boxes only (`sells` without `line`) fail at once with the amounts and nothing is created. `request_id` = `clientexec-create-<package>-<n>` (line), `clientexec-sub-…` / `clientexec-subc-…` (sub-reseller); `n` counts terminations, so a retry never charges twice and creating a terminated package again sells a new line. If the credit transfer fails the account stays; run Create again to retry only the transfer |
| Suspend | `disable_line` / `disable_user` | Free |
| UnSuspend | `enable_line` / `enable_user` | Free |
| Delete (terminate) | `delete_line` or `disable_line`; sub-reseller: `disable_user` | The panel has no delete for sub-reseller accounts. Something already gone from the panel counts as done |
| Renew (custom action) | `pricing`, then `renew_line` (charged) / `adjust_credits` | See "Renewals" |
| Update, package change | `get_line`, `pricing`, `package_compatibility`, then `change_package` | **Charged** (the new package's official price). Lines only. The panel's answer to `package_compatibility` (time left kept or lost, price) goes to ClientExec's log; too few credits refuse the change. A panel that answers `UNKNOWN_ACTION` skips the check. `request_id = clientexec-chg-<package>-<n>-<new panel package>-<expiry now>`; a line already on that package is left alone |
| Update, password change | none | Refused with an explanation (not in the Reseller API) |

The panel id of the line (number) or sub-reseller (UUID) is kept in the package's
**Server Acct Properties** as `<id>|<generation>`. After a terminate it is `|<generation>`.

### Renewals

ClientExec does not tell server plugins when a renewal invoice is paid (the server plugin interface has
only create / suspend / unsuspend / delete / update and custom admin actions). So the plugin does **not**
renew automatically: renewing is the **Renew** button on the package in the admin area. Request ids contain
today's date, so clicking twice on the same day is not charged twice. (A real second renewal on the same
day is therefore not possible.) Customers cannot press it: it costs the reseller credits.

### What the customer sees

ClientExec shows the package's custom fields: **User Name** and **Password** always. Create custom fields
(Settings > Custom Fields > Packages, "show to customer") and type their names into the server settings to also
show, for a line, the M3U playlist link, the web player link and the server URL, and for a sub-reseller the credit
balance. They are written on create, unsuspend and renew. A **web player** button (direct link) opens
the line's web player. Sub-reseller accounts sign in at the panel dashboard (no link).

## Not included

- Password change (not in the Reseller API): do it in the panel.
- A receiver for the panel's webhooks: the plugin has no HTTP endpoint of its own.
- Automatic renewal and credit top-up (see Renewals); the plugin never tops up the reseller.
- A sub-reseller account cannot be deleted through the Reseller API, and its email address is unique in
  the panel: creating a sub-reseller account again for a customer whose earlier account was only disabled
  fails with "already taken" until the panel admin removes the old one.
- Customer-side actions (the customers cannot suspend or renew themselves).

## Verified / guess (1.1.0)

Run for real against a panel, with the stand-in for ClientExec (`plugins/e2e/clientexec-harness.php`):
`X-Connector` (read back from the panel's API call log), `pricing` before create / renewal, `sells` and the
refusal of a box-only package, `change_package` through `update()` with the charge, the repeat guard and the
`package_compatibility` line in the log, `REQUEST_ID_SPENT` text, **Delete permanently on terminate** off by
default. Not run: anything inside a real ClientExec.

## Guesses (not checked in a real ClientExec)

0. **New in 1.1.0:** that ClientExec calls `update($args)` with `$args['changes']['package']` on a package change
   and that `$args['package']` (so `variables['panel_package_id']`) then already describes the **new** package, as
   in ClientExec's own server plugins. If it still holds the old one the call finds the line already on that
   package and sells nothing (no error); `changepackage = 1` in `plugin.ini` is likewise assumed to enable the hook.

1. Server variables are read as `plugin_xtreampro_<Label_with_underscores>` (e.g. `plugin_xtreampro_API_Key`),
   as in the DirectAdmin / Virtualizor plugins; the hidden `Name` value is `Xtreampro`.
2. Package settings are declared with `package_vars` / `package_vars_values` (as in Virtualizor / CyberPanel)
   and arrive in `$args['package']['variables']` with yes/no as `1` / `0`.
3. `User Name`, `Password` and `Server Acct Properties` are standard package custom fields, writable with
   `UserPackage::setCustomField`. Whether ClientExec stores `Password` encrypted for such a write, and
   whether it html-encodes values (the plugin decodes them) is not verified.
4. Custom admin actions: `Actions` lists `Renew` and `doRenew` exists, like `doReboot` in Virtualizor.
5. The first-name / last-name of the customer comes from the `User` class (`getFirstName`, `getLastname`).
6. `getAvailableActions` returns the buttons by the panel's real state; the status `disabled` means suspended.
7. The direct link (`getDirectLink` + `dopanellogin`) follows the other plugins' shape.
8. Terminating on ClientExec clears nothing by itself; the plugin writes `|<generation>` to
   `Server Acct Properties`. If ClientExec blanks that field on termination, a re-created package replays the
   first request id (it then returns the first, deleted line); set Delete permanently on terminate = no or create a new package.

## Troubleshooting

ClientExec's log (Settings > Logs, level 4) shows one line per API call with the API key never present and
line passwords masked.

| Message | What to do |
| --- | --- |
| The panel rejected the API key | Re-enter the key in the server settings |
| The API key does not belong to a reseller account... | Use a reseller key; sub-resellers also need a group that may create them |
| The reseller account has not enough credits | Add credits in the panel and run the action again |
| The username ... is already taken | Pick another username/email, or remove the old account in the panel |
| The selected package does not exist ... or cannot be sold this way | Check the **Panel package id** of the product (a package for MAG / Enigma boxes only cannot be sold as a line) |
| This sale was already made and its line has since been deleted | Terminate the package and create it again |
| Could not connect to the panel | Check hostname, port and Use SSL; the certificate must be valid |

## Test it without ClientExec

```sh
PLUGIN_DIR=$PWD/plugins/clientexec/plugins/server/xtreampro API_PORT_NUM=<panel api port> API_KEY=<reseller key> \
  php plugins/e2e/clientexec-harness.php      # prints ALL OK
```
