# Xtream UI Pro - HostBill provisioning script

Sells IPTV lines (or sub-reseller accounts) from HostBill. There is **no HostBill module to
install**: HostBill's built-in **Script Provisioning** module runs one command on account events,
and this package is that command. It is one self-contained PHP CLI script,
`xtreampro/xtreampro-provision.php` (version 1.1.0), that talks to the panel's Reseller API with the
reseller's own API key.

> **Not run inside HostBill.** HostBill is commercial software and no licence was available, so the
> script was only run from the command line (plugins/e2e/hostbill-scenario.sh, against a real panel
> API). The Script Provisioning settings below follow HostBill's documentation
> (hostbillapp.com/products-services/script-provisioning and the "Script Provisioning" page of the
> HostBill documentation); check them in your own HostBill before going live, with a test order.

Because the script is not tied to HostBill, **any system that can run a command on billing events
can use it** (another billing panel, a webhook handler, cron, a shell). It only needs the arguments
or `XTREAMPRO_*` environment variables listed below.

## Requirements

- PHP 7.4 or newer CLI with the `curl` and `json` extensions, on the HostBill server.
- An Xtream UI Pro panel whose API address (`cmd/api`, for example `https://api.example.com`) is
  reachable from that server. Use a valid TLS certificate: certificates are always verified.
- A **reseller** account in the panel with enough credits. Creating and renewing a line is charged
  to this reseller's credits.
- HostBill's Script Provisioning module needs PHP `proc_open` / `exec`, which `php.ini` normally
  disables; HostBill's documentation says to run it through the HostBill Queue module or to
  enable those functions, and to run HostBill under an isolated non-root user.

## Install

1. Copy the `xtreampro/` folder to the HostBill server, outside the web root, for example
   `/home/hostbill/xtreampro/`. It must be writable by the user HostBill runs scripts as (its
   `data/` folder is created next to it on first use).
2. Create the configuration and lock it down:

   ```sh
   cd /home/hostbill/xtreampro
   cp config.sample.php config.php
   chmod 600 config.php        # the script refuses to run if others can read the file
   nano config.php             # api_url and api_key
   ```

   Instead of `config.php` you may set `XTREAMPRO_API_URL` and `XTREAMPRO_API_KEY` in the
   environment of the process. The API key is never accepted on the command line (it would show
   in the process list).
3. HostBill's Script Provisioning lists the executable files of its scripts directory
   (`/home/hostbill/scripts`; on other installs set `$config['ScriptsDirectory']` in
   `includes/config.php`). Put a two-line wrapper there and make it executable
   (`chmod u+x`), because HostBill documents that scripts must be executable and should have a shebang:

   ```sh
   #!/bin/sh
   exec php /home/hostbill/xtreampro/xtreampro-provision.php "$@"
   ```

   Save it as `/home/hostbill/scripts/xtreampro.sh`.
4. Test it by hand as the HostBill user: `sh /home/hostbill/scripts/xtreampro.sh info --service=1 --username=nobody`
   must answer with a JSON line (an error such as "not found" is fine; "not configured" or "rejected
   the API key" means the configuration is wrong).

## Get the API key

1. Sign in to the panel as the reseller account that will own the lines.
2. Open `/api-key` in the dashboard and generate (or copy) the API key.
3. Only reseller accounts work. Admin keys are refused with `FORBIDDEN`.

## Set up the product in HostBill

1. **Settings > Modules**: activate **Script Provisioning**; it asks for a connection name in
   **Settings > Apps** (anything, it has no other fields).
2. **Settings > Products & Services**: create or open the product, choose the Script Provisioning
   module and app, then open the **Settings** tab of the module. For each event tick it, pick the
   script `xtreampro.sh`, and add the arguments **one per field** (HostBill escapes every argument
   before running the script). HostBill's variables are written `{$account.id}` and so on; the full list
   is on the **Available variables** tab of the same screen.

### Line product (IPTV line)

| Event (tick it) | Arguments, one per field |
| --- | --- |
| **Create** | `create` <br> `--service={$account.id}` <br> `--type=line` <br> `--package=<panel package id>` <br> `--username={$account.username}` <br> `--password={$account.password}` <br> `--no-secrets` <br> add `--trial` for a trial product |
| **Suspend** | `suspend` <br> `--service={$account.id}` <br> `--username={$account.username}` |
| **Unsuspend** | `unsuspend` <br> `--service={$account.id}` <br> `--username={$account.username}` |
| **Terminate** | `terminate` <br> `--service={$account.id}` <br> `--username={$account.username}` <br> by default the line is only **disabled**; add `--delete-on-terminate` to delete it on the panel (final: nothing can be brought back) |

`--username` is what lets the script find the line again if its data file is ever lost.

### Sub-reseller product

| Event | Arguments, one per field |
| --- | --- |
| **Create** | `create` <br> `--service={$account.id}` <br> `--type=reseller` <br> `--credits=<credits given on creation>` <br> `--username={$account.username}` <br> `--password={$account.password}` <br> `--email={$client.email}` <br> `--name={$client.firstname} {$client.lastname}` <br> `--no-secrets` |
| **Suspend** / **Unsuspend** / **Terminate** | as above, plus `--type=reseller` |

### Renewals

HostBill's Script Provisioning documents these events only: create, suspend, unsuspend, terminate,
package change, client form-input change and password change. **There is no "renewal" event**, so
the script cannot be called automatically when a renewal invoice is paid by this module alone. The
script itself supports renewals; wire them in one of these ways:

- `renew --service={$account.id} --username={$account.username} --due={$account.next_due}`
  (sub-reseller: add `--type=reseller --renew-credits=<credits per renewal>`) from whatever
  HostBill feature or external automation you use after a paid renewal invoice, or
- as a **Custom client-triggered action** of Script Provisioning (HostBill documents these: the client
  clicks a link in a "Client UI" function and the script of that action runs, with the action name
  available as `{$actname}`), or by hand.

`--due` is the service's next due date (any date format `strtotime` understands). It is part of
the request id, so repeating the same renewal never charges twice, while the next period is a new
renewal. Without `--due` the day's date is used.

**Upgrade / downgrade:** the script has a `change-package` action
(`change-package --service={$account.id} --username={$account.username} --package=<new panel package id>`),
which asks the panel first (`package_compatibility`) and then sells the package (`change_package`, charged). Wire it to
HostBill's *Change Package* event of Script Provisioning (the documentation lists that event; how the new package's
panel id reaches the command, for example from a product custom field, depends on your HostBill: this is not verified)
or run it by hand. HostBill's Change Password / Client Edit Form Inputs events are not used: the Reseller API has no
password change (do it in the panel).

## What the script does

```
php xtreampro-provision.php <create|suspend|unsuspend|terminate|renew|change-package|info> --service=<id>
    [--type=line|reseller] [--package=<id>] [--trial] [--delete-on-terminate]
    [--username=...] [--password=...] [--email=...] [--name=...]
    [--credits=<n>] [--renew-credits=<n>] [--due=<YYYY-MM-DD>] [--no-secrets]
```

Every option can also come from an environment variable `XTREAMPRO_<NAME>` (for example
`XTREAMPRO_SERVICE`, `XTREAMPRO_PACKAGE`, `XTREAMPRO_DELETE_ON_TERMINATE`, `XTREAMPRO_RENEW_CREDITS`,
`XTREAMPRO_NO_SECRETS`, `XTREAMPRO_DATA_DIR`); the command line wins. `XTREAMPRO_CONFIG` names another
config file. `--service` is the numeric id of the billing service.

| Action | Panel call | Notes |
| --- | --- | --- |
| `create` (line) | `pricing`, `create_line`, then `get_line` | Charged. `pricing` runs first: too few credits, a package that is not on sale or one for boxes only fail at once with the amounts and nothing is created. `request_id = hostbill-create-<service>-<n>` (n counts terminations of the service), so a retry never charges twice and creating a terminated service again sells a new line. Blank username / password are generated by the panel; the credentials the panel actually used are read back and printed. |
| `suspend` / `unsuspend` | `disable_line` / `enable_line` | Free. |
| `renew` (line) | `get_line`, `pricing`, then `renew_line` | **Charged**; refused with the amounts when the balance cannot pay. |
| `change-package` (line) | `get_line`, `pricing`, `package_compatibility`, then `change_package` | **Charged.** Prints what the change does to the time left in `panel`. `request_id = hostbill-chg-<service>-<n>-<package>-<expiry now>`; a line already on the package is left alone. |
| `terminate` | `disable_line` (default) or `delete_line` (final) with `--delete-on-terminate` | A line already gone from the panel counts as success. `--keep-on-terminate` is still accepted (it is the default now). |
| `renew` | `renew_line` | **Charged.** Request id `hostbill-renew-<service>-<due date>`. |
| `info` | `get_line` | Username, password, status, expiry, max connections and the panel's play links. |

Sub-reseller (`--type=reseller`):

| Action | Panel call | Notes |
| --- | --- | --- |
| `create` | `create_user`, then `adjust_credits` | Charged: the price of a sub-reseller plus `--credits`. Request ids `hostbill-sub-<service>-<n>` and `hostbill-subc-<service>-<n>`. Blank username (`r<service>` plus random letters) and password (14 random characters, CSPRNG) are generated; they are kept in the data file until the whole create succeeded so a retry sends the same values. Usernames: 3 to 32 letters, digits, `_ . -`; passwords at least 8 characters. |
| `suspend` / `unsuspend` | `disable_user` / `enable_user` | Free. |
| `terminate` | `disable_user` | The panel has **no delete** for accounts: it is only disabled. |
| `renew` | `adjust_credits` | `--renew-credits` credits (0 or missing = nothing). Request id `hostbill-subr-<service>-<due date>`. |
| `info` | `get_users` | Username, status, credit balance. The panel keeps only a hash of the password, so it is printed only if you pass `--password`. |

The panel's group may ignore the credentials the customer chose, so the script always reports the
final username and password it read back.

### Output and exit code

One line of JSON on stdout, exit code `0` for success and `1` for failure:

```json
{"ok":true,"type":"line","line_id":42,"username":"...","password":"...","status":"active","expiry":"...","max_connections":3,"links":{"server":"...","m3u":"...","m3u_hls":"...","xmltv":"...","player_api":"...","web_player":"..."}}
{"ok":true,"message":"suspended"}
{"ok":false,"error":"The reseller account has not enough credits."}
```

HostBill writes the script's output into the **account log** (documented), so the line password
would end up in it. **Use `--no-secrets`** (as in the tables above): the password is then left out
and the passwords inside the play links are replaced by `********`. Without it, `create` and `info`
print the real password and links. The API key is never printed; no secret is written to stderr.

## State

`data/state.json` (folder configurable with `data_dir` / `XTREAMPRO_DATA_DIR`, created `0700`, file `0600`,
guarded by `flock`) maps the service id to the panel's line id or account id and counts terminations.
If it is lost, the line or account is found again by its exact username (pass `--username`).

## Not included

- No automatic renewal event in HostBill's Script Provisioning (see above), no HostBill client-area
  template: HostBill shows the customer nothing from this script. Give customers their details from
  the panel's web player/portal, an e-mail template, or run `info` (without `--no-secrets`) in a
  place that is not logged.
- Password change is not possible through the Reseller API. Package change is: the `change-package` action, but this
  script cannot know whether your HostBill calls it (see Renewals / upgrade above).
- A receiver for the panel's webhooks: a command-line script has no HTTP endpoint.
- Passwords reach the script as command-line arguments (that is how Script Provisioning passes them), so other
  local users can see them in the process list while it runs: do not use this on a shared server.

## Troubleshooting

Check the account log of the service in HostBill, or run the same command by hand as the HostBill user.

| Message | What to do |
| --- | --- |
| Refusing to run: config.php is readable by other users | `chmod 600 config.php` |
| The script is not configured correctly | `api_url` / `api_key` missing in `config.php` or the environment. |
| The panel rejected the API key (`INVALID_API_KEY`) | Re-copy the key. |
| `FORBIDDEN` | Not a reseller key, missing permission, or the reseller's group may not create sub-resellers. |
| `RESOURCE_NOT_FOUND` | The line / account is not in the panel (deleted there), or the script cannot find it: pass `--username`. |
| `INVALID_REQUEST`, `INVALID_PACKAGE` | Credentials too short for the reseller group, bad email, or package not allowed / for MAG / Enigma boxes only. |
| `REQUEST_ID_SPENT` | The line this service's request id paid for was deleted on the panel, so the same id cannot sell another: terminate the service and create it again. |
| `INSUFFICIENT_CREDITS` | Add credits in the panel, then run the action again. |
| `CONFLICT` | Username (or, for sub-resellers, email) taken. |
| `RATE_LIMITED` / `SERVER_ERROR` | Wait and retry / check the panel logs. |
| Could not connect | Check `api_url`, port and certificate. |
| The data directory ... is not writable | Give the HostBill user write access to it. |

## Verified / guess (1.1.0)

Run from the command line against a real panel (`plugins/e2e/run.sh hostbill`): `X-Connector` (read back from the panel's
API call log), `pricing` before create / renew, the refusal of a box-only package, `change-package` (charge, repeat guard,
refusal of a box-only package), `REQUEST_ID_SPENT` text, the new terminate default. **Not run in HostBill**: how HostBill's
Change Package event reaches `change-package` and with which arguments is a guess; the README of 1.0.0 already says the rest
of the HostBill wiring was never run.

## Test

`plugins/e2e/hostbill-scenario.sh` runs the script from the command line against a real panel API (set
`XC_API_URL` and `XC_KEY_FILE`, the file holding the reseller key): create, info, suspend, unsuspend,
renew twice, terminate and create again for a line and for a sub-reseller, checks the panel through
the API after every step and that the API key never appears in any output.
