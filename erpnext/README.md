# Xtream UI Pro - ERPNext / Frappe connector

Sells IPTV lines and sub-reseller accounts from ERPNext. When a Sales Invoice with an Xtream UI Pro
item is paid, the connector creates the lines (or the sub-reseller account) in your Xtream UI Pro
panel through the panel's **Reseller API**, shows the customer their credentials, and disables them
again when the invoice is cancelled or refunded.

> **Not yet run inside a real ERPNext.** This connector was written from the Frappe / ERPNext v15
> developer documentation and checked by reading. Its platform independent core (API client and
> provisioner) *was* run for real against a panel (`plugins/e2e/erpnext-harness.py`, see the end of
> this file), but the Frappe layer around it (doctypes, hooks, scheduler, forms, portal page) has
> never been started. Expect to fix small things on the first run, and test on a staging site first.
>
> Places where the behaviour of Frappe / ERPNext was **guessed**:
>
> - The install steps for an app that is a plain folder (not a git repository), see Install.
> - That a **Payment Entry's `on_submit`** hook sees the invoice's outstanding amount already updated
>   (ERPNext writes it with a plain database update that does not fire hooks on the invoice, which is
>   why the connector listens on the Payment Entry instead). Settlements by other documents (a Journal
>   Entry, ...) are caught by an hourly job, not by a hook.
> - `frappe.enqueue(..., enqueue_after_commit=True)` and `doc.lock()` as used in `invoices.py`.
> - How **Password** fields behave: saved encrypted by Frappe, read with `get_password`, hidden from
>   users without permission level 1, and read with `get_decrypted_password` on a Single doctype.
> - That `frappe.db.set_value(doctype, name, {dict})` and `doc.db_set({dict})` accept a dict.
> - The field `sales_invoice_item` on the rows of a credit note (used to find which row of the
>   original invoice is returned; without it the connector matches by item code).
> - The `insert_after` positions of the custom fields (the code falls back to the last field).
> - The customer portal page `/iptv`: that a portal user is linked to a Customer through a Contact
>   (`Contact.user`) and that the Jinja environment of web pages is rendered as written (all values
>   are escaped explicitly with `|e`).
> - `frappe.sendmail` keeps the message in the Email Queue, so an e-mailed password stays readable in
>   that queue (System Manager only) until Frappe clears old queue entries.
> - The hand-written DocType JSON files (module `Xtream UI Pro`, naming `format:XPL-{#####}`, the
>   `links` of the Reseller form) were written by hand, not exported from a running Desk.

## Requirements

- ERPNext / Frappe **v15** (`pyproject.toml` pins `>=15,<16`), Python 3.10 or newer. `requests` is
  installed with Frappe; nothing else is needed.
- A running **background worker and scheduler** (`bench start`, or the `worker` and `scheduler`
  services of a production bench). Provisioning runs in a background job so that a slow panel never
  blocks posting an invoice.
- An Xtream UI Pro panel whose API address (`cmd/api`, for example `https://api.example.com`) is
  reachable from the ERPNext server. Use a valid TLS certificate: certificates are always verified.
- A **reseller** account in the panel with enough credits. Creating lines, renewing them and
  creating sub-resellers are charged to this reseller's credits.

## Install

The package is the Frappe app folder `xtreampro_connector/` (it contains `pyproject.toml` and the
Python package `xtreampro_connector/`).

1. Unpack it into the `apps/` folder of your bench, so that you have
   `apps/xtreampro_connector/pyproject.toml`.
2. In the bench folder:

   ```sh
   ./env/bin/pip install -e apps/xtreampro_connector
   echo xtreampro_connector >> sites/apps.txt        # skip if the line is already there
   bench --site your.site install-app xtreampro_connector
   bench --site your.site migrate
   bench restart                                      # or restart the supervisor / systemd services
   ```

   If your bench insists on a git repository (`bench get-app`), run `git init && git add -A && git commit`
   inside the folder first and give that path to `bench get-app`. With Docker (`frappe_docker`) add the
   app to `apps.json` of your custom image.
3. Installing creates the custom fields below and the five doctypes. Migrating re-applies the fields
   (so an upgrade can add new ones). Uninstalling removes the custom fields; the doctypes and their
   records stay until you delete them.

Custom fields added to ERPNext:

| Doctype | Fields |
| --- | --- |
| Item | `xp_kind` (IPTV line / Sub-reseller account), `xp_package`, `xp_trial`, `xp_delete_on_terminate`, `xp_credits` |
| Sales Invoice Item | `xp_renew_line` (renew an existing line instead of creating one) |
| Sales Invoice | `xp_provision_status`, `xp_last_error` (read only) |

## Get the API key

1. Sign in to the panel as the reseller account that will own the lines.
2. Open `/api-key` in the dashboard and generate (or copy) the API key.
3. Only reseller accounts work. Admin keys are refused with `FORBIDDEN`.

## Settings

Open **Xtream UI Pro Settings** (search the awesomebar). Only the System Manager role sees it, so
nobody else can point the stored key at another host.

- **API address**: for example `https://api.example.com` (a pasted `/reseller/v1` is cut off).
- **Reseller API key**: stored as a Frappe Password field (encrypted). It is never written to a log,
  a comment or an error text.
- **Panel address (optional)**: where sub-resellers sign in; shown in their credentials.
- **E-mail the credentials to the customer**: send the sign-in details by e-mail as soon as a line or
  account is created.
- **Test connection** (calls `user_info` and shows the reseller and its credits) and **Sync packages**
  (loads the packages into the *Xtream UI Pro Package* list). Both use the *saved* settings, so save
  first. Packages are also synced daily.

## Roles

| Role | Can do |
| --- | --- |
| System Manager | Settings, everything below |
| Sales Manager | Everything on lines, sub-reseller accounts and credit transfers (read, write, delete), the buttons below, and sees the password field |
| Sales User | Reads lines, accounts, transfers and packages, **without** the password field; may press *Send credentials* (it goes to the customer's address on file) |
| Customer (portal) | Sees their own credentials on `/iptv` |

Deleting a Line / Reseller record in ERPNext does **not** touch the panel, and a later payment event
for the same invoice would create a new line. Terminate on the record instead.

## Set up an item

1. Open the Item (a non-stock service item is fine) and the section **Xtream UI Pro**.
2. **Xtream UI Pro type**:
   - *IPTV line*: pick the **package** (a dropdown filled by Sync packages; packages for MAG / Enigma
     boxes only are not listed), tick **Trial line** for a
     trial, tick **Delete permanently on terminate** to delete the line on the panel when you press
     Terminate (**off by default**: it is then only disabled and can be enabled again; deleting is
     final, nothing can be brought back).
   - *Sub-reseller account*: set **Credits per unit**. 0 creates the account without credits.
3. Sell the item on a Sales Invoice (or through a Sales Order > Sales Invoice, a POS invoice, a
   payment request, ...). A quantity of 3 of an IPTV line item creates 3 lines.

To renew a line the customer already has, put the line into **Renew Xtream UI Pro line** on the
invoice row (instead of creating a new one). The line must belong to the invoice's customer.

## What happens when

| Event in ERPNext | Result |
| --- | --- |
| Sales Invoice submitted and already paid, or a Payment Entry submitted that makes it paid (outstanding amount 0) | A background job creates one **Xtream UI Pro Line** per unit (`pricing`, then `create_line`), or the customer's **Reseller** account (`pricing`, then `create_user`, once) and hands over the credits (`pricing`, then `adjust_credits`), or renews the chosen line (`renew_line`). Each created line is committed at once. The result is a comment on the invoice and the field *Xtream UI Pro status*. |
| Customer has no e-mail address | The sub-reseller record is kept with the error; fill in the e-mail on the record and press Retry. |
| Panel unreachable, wrong key, not enough credits, ... | The record keeps the readable error text (`last_error`), the invoice gets the same text as a comment, nothing is lost. Fix the cause, then press **Retry** on the record or **Provision again** on the invoice. Already created lines are not created twice. |
| Sales Invoice cancelled | The lines are disabled (never deleted), the credits handed over by the invoice are taken back (if they are already spent the panel refuses and the transfer stays as an error you can retry), and the account the invoice created is disabled when none of its credits is left. |
| Credit note (return) submitted against an invoice | The same, for the returned quantity: that many lines are disabled, credits are taken back in proportion. A partial return keeps the account. |
| Every 6 hours | Status, expiry and balance of active lines and accounts are refreshed from the panel in batches of 20 (at most 200 per run, least recently refreshed first). A wrong key or an unreachable panel stops the run after the first failure. |
| Every hour | Paid invoices of the last 30 days with Xtream UI Pro items that were never provisioned (for example settled by a Journal Entry, or an event lost with the job queue) are provisioned. Invoices that were tried and failed are **not** retried automatically. |

Not undone: a cancelled payment (the invoice becomes unpaid again), a cancelled credit note, and the
renewal of a line by an invoice that is later cancelled (the panel cannot take a renewal back).

## Actions (buttons on the forms)

| Button | Panel call | Notes |
| --- | --- | --- |
| Line > Renew | `get_line`, `pricing`, then `renew_line` | **Charged**; refused with the amounts when the balance cannot pay. Request id contains the line and the day, so a double click does not charge twice. |
| Line > Suspend / Unsuspend | `disable_line` / `enable_line` | Free. |
| Line > Refresh | `get_line` | |
| Line > Terminate | `disable_line` (default) or `delete_line` | Per item (*Delete permanently on terminate*). Delete cannot be undone. A line already gone counts as success. The record is closed (status Terminated, generation + 1). |
| Line > Retry | `create_line` | For a record in Draft / Error, or a Terminated one: creates a **new** line. |
| Line > Play links | `get_line` | Shows the links the panel gives (they contain the password): Sales Manager only. |
| Line / Reseller > Send credentials | | E-mail to the customer's address on file. Sales User may press it. |
| Reseller > Suspend / Unsuspend | `disable_user` / `enable_user` | |
| Reseller > Add credits / Take back credits | `adjust_credits` | Creates an *Xtream UI Pro Credit Transfer*. Taking back more than the balance fails with `INSUFFICIENT_CREDITS`. |
| Reseller > Terminate | `disable_user` | The panel cannot delete accounts: it is disabled and the record closed. Creating it again (Retry) makes a new account with a new username and a tagged e-mail (`name+g1@example.com`). |
| Reseller / Credit transfer > Retry | `create_user` / `adjust_credits` | Same request id as before, so nothing is paid twice. |
| Invoice > Provision again | | Runs the provisioning of the invoice again in the background. |

Every action checks the role on the server, loads the record from the database (it does not trust
the values the browser sends) and shows the readable error from the panel when it fails.

## How double charging is prevented

`create_line`, `renew_line` and credit transfers carry a **request id**; the panel answers a repeated
request id with the first result and charges nothing. The ids are built from the stable ids of
ERPNext: `erp-<installation id>-<kind>-<invoice>-<row>-<unit>-g<generation>` (replaced by a hash when
longer than 64 characters). The *installation id* (Settings) keeps two ERPNext sites that share one
reseller key from replaying each other's invoice numbers; the *generation* counts terminations so that
creating a terminated line or account again sells a new one.

Credentials the customer chose may be ignored by the reseller's group, so the connector always reads
the final username and password back from the panel's answer.

## What the customer sees

- The credentials e-mail (when enabled in Settings): server URL, username, password, playlist URL and
  web player for a line; username, password, credits and sign-in address for a sub-reseller account.
  It goes to the invoice's contact e-mail, else the customer's own address.
- The portal page **My IPTV** (`/iptv`, also in the portal menu): the signed-in customer's lines and
  account with the links as the panel gives them. A portal user is matched to a Customer through
  their Contact.

The ERPNext print format of the invoice is not changed.

## Troubleshooting

Errors are on the record (field *Last error*), on the invoice (comment and the *Xtream UI Pro* section)
and, for unexpected errors, in **Error Log** (with the API key and passwords masked).

| Code | Meaning | What to do |
| --- | --- | --- |
| `INVALID_API_KEY` | Key unknown or revoked | Put the key in the settings again. |
| `FORBIDDEN` | Not a reseller account, missing permission, or the group may not create sub-resellers | Use a reseller account; check its group. |
| `RESOURCE_NOT_FOUND` | Line or account not in the panel | It was deleted in the panel; Terminate the record. |
| `INVALID_REQUEST` | e.g. no e-mail address for a sub-reseller, username or password too short | Fix the customer data and Retry. |
| `INVALID_PACKAGE` | Package missing, not allowed, or for MAG / Enigma boxes only | Sync packages and select the package on the item again. |
| `REQUEST_ID_SPENT` | The line this invoice row's request id paid for was deleted on the panel, so the same id cannot sell another | Terminate the record here, then create it again (Terminate moves the generation counter in the request id on). |
| `INSUFFICIENT_CREDITS` | Reseller balance too low (sub-resellers: the price plus the credits to hand over) | Add credits in the panel, then Retry. |
| `CONFLICT` | Username or e-mail taken, or request id reused | Change the e-mail on the record and Retry. |
| `RATE_LIMITED` | Too many requests | Wait and retry. |
| `SERVER_ERROR` | Internal panel error | Check the panel logs. |
| Timeout / could not be reached / TLS | Network, address or certificate | Check the API address and the certificate. A redirect is refused: use the final address. |

Nothing happens after payment: check that a worker is running (`bench doctor`), that Settings are
filled in, and the *Xtream UI Pro status* of the invoice. The hourly job provisions a paid invoice that
was missed, within 30 days.

## Not included

- Password change is not available through the Reseller API: do it in the panel (the stored password then differs
  until the next refresh, which reads it back). Package change (`change_package`) exists in the API, but ERPNext has
  no hook for a package change of a sold item, so nothing is faked here. There is no receiver for the panel's webhooks.
- No checkout page, price list or product sync: you sell the items with ERPNext's own tools.
- No automatic renewal of a line when a subscription renews; put the line on the invoice row, or
  press Renew.
- Sub-reseller *renewals* (credits per period) are not modelled: every paid invoice item hands over
  its credits again, so a recurring invoice tops the account up.
- Sales Orders and Delivery Notes are not watched, only Sales Invoices.

## Contents of the package

```
xtreampro_connector/                         the Frappe app (zip root)
  pyproject.toml
  xtreampro_connector/
    hooks.py                                 doc_events, scheduler_events, install hooks (1.1.0)
    panel/client.py, panel/provisioner.py    platform independent core: no `import frappe`
    invoices.py  tasks.py  actions.py        thin Frappe layer: events, scheduler, buttons
    utils.py  install.py                     settings, errors, e-mail; custom fields
    xtream_ui_pro/doctype/...                Settings (Single), Package, Line, Reseller, Credit Transfer
    public/js/sales_invoice.js  www/iptv.*   invoice buttons; customer portal page
```

## Verified / unrun (1.1.0)

**Ran for real** (core only, `plugins/e2e/run.sh erpnext`): `X-Connector` (read back from the panel's API call log),
`pricing` before create / renewal / credit transfers, the `sells` filter of the package rows and the refusal of a box-only
package, the `REQUEST_ID_SPENT` text. **Never run in ERPNext:** the custom field default of `xp_delete_on_terminate`, the
`terminate` fallback and everything listed at the top of this file (unchanged).

## Testing the core

`plugins/e2e/erpnext-harness.py` loads the two Frappe-free core files from this folder (not copies)
and runs them against a panel API: for a line and a sub-reseller account it creates, replays the
create, suspends, unsuspends, renews (twice with one request id: charged once), terminates, creates
again, takes credits back, refreshes in batches, and tries a wrong key, an unknown package, a dead
port and a redirect. It checks the panel through the API after each step and that neither the API
key nor any password appears in anything the core logs or says, and it checks that every Python file
parses and every doctype JSON is valid. It prints `ALL OK`.

```sh
python3 -m venv /tmp/erp-venv && /tmp/erp-venv/bin/pip install requests
API_PORT_NUM=18095 API_KEY_FILE=/path/to/key /tmp/erp-venv/bin/python plugins/e2e/erpnext-harness.py
```
