# Xtream UI Pro - Dolibarr module

> **This connector has not yet run inside a real Dolibarr.** It was written from Dolibarr's
> developer documentation and the conventions of the standard module template. The part that holds
> all the rules (the Reseller API client and the provisioner, `xtreampro/lib/core/`) was run for
> real against a panel (see "Tested"); the Dolibarr glue (descriptor, triggers, pages, tables) was
> only syntax-checked. Places where Dolibarr's behaviour was guessed are listed at the end.

Sells IPTV lines and sub-reseller accounts from Dolibarr. A product is either an **IPTV line**
(package, optional trial, delete or only disable on terminate) or a **sub-reseller account**
(credits per unit). When an invoice is paid the module provisions it through the panel's Reseller
API with your reseller's API key. Lines and credits are charged to that reseller's credits in the
panel.

## Requirements

- Dolibarr 18 or newer (uses `isModEnabled`, `hasRight`, `dolEncrypt`), PHP 7.4 or newer with the
  `curl` and `json` extensions. Modules: Third parties and Invoices (Products/Services for the
  product fields).
- An Xtream UI Pro panel whose API address (`cmd/api`, e.g. `https://api.example.com`) is reachable
  from the Dolibarr server. Use a valid TLS certificate; certificates are always verified.
- A **reseller** account in the panel with enough credits. For sub-reseller products its group must
  be allowed to create sub-resellers.

## Install

1. Copy the folder `xtreampro/` of this package into `<dolibarr>/htdocs/custom/`, so that you have
   `htdocs/custom/xtreampro/core/modules/modXtreampro.class.php`. (`custom` must be enabled: see
   `$dolibarr_main_url_root_alt` in `conf/conf.php`.)
2. **Home - Setup - Modules**, find **Xtream UI Pro** (family "Interface") and enable it. Enabling
   creates three tables (`llx_xtreampro_line`, `llx_xtreampro_reseller`, `llx_xtreampro_credit`) and
   five product extra fields. Disabling keeps both.
3. Give the permission **Read** (and **Manage** for staff who may renew, suspend, terminate and email
   credentials) in **Users & Groups**.

## Get the API key and set up

1. In the panel sign in as the reseller that will own the lines, open `/api-key` and copy the key.
   Admin keys are refused with `FORBIDDEN`.
2. In Dolibarr open the module's setup (the gear on the module row): enter the **Panel API URL**
   and the **API key**, click **Save**, then **Test connection**. The test shows the reseller's
   credits and the panel's package ids.
3. The key is stored encrypted (`dolEncrypt`) in the constant `XTREAMPRO_API_KEY`, is never written
   back into the page (the field stays empty; an empty field keeps the saved key) and is never
   logged. Every form is protected by Dolibarr's CSRF token (`newToken()`) and the module's own
   check.

## Set up a product or service

Edit the product: the module adds these extra fields.

| Field | Meaning |
| --- | --- |
| Xtream UI Pro: what it sells | empty = not an Xtream UI Pro product; **IPTV line** or **Sub-reseller account** |
| package id | line: the panel package to sell (ids are listed on the setup page after "Test connection"; packages for MAG / Enigma boxes only are not listed and are refused at sale) |
| trial line | line: create it as a trial |
| credits per unit | sub-reseller: credits given for each unit sold (0 = account only) |
| delete the line permanently on terminate | line: unticked (**default**) = only disabled, it can be enabled again; ticked = deleted in the panel (final: nothing can be brought back) |

## What happens

| Dolibarr event | Panel call | Notes |
| --- | --- | --- |
| Invoice **paid** (`BILL_PAYED`) | line: `pricing`, then `create_line`, once per **unit** (quantity); sub-reseller: `pricing`, `create_user` once per **third party**, then `pricing` and `adjust_credits` for `credits x quantity` | `pricing` runs first: too few credits, a package that is not on sale or one for boxes only fail at once with the amounts, are recorded as the line's error and nothing is created. Request ids make a repeat of the event safe: nothing is sold or credited twice. A later invoice of the same customer only adds credits to the existing account. Credit notes and deposit invoices sell nothing. |
| Invoice **set unpaid** (`BILL_UNPAYED`), **abandoned** (`BILL_CANCEL`), or a **credit note that covers the whole invoice** | `disable_line` for its lines; `adjust_credits` with a negative amount; `disable_user` for an account that this invoice created | Never deletes. Paying the invoice again enables the lines/account again and gives the credits again. If the customer already spent the credits the panel refuses and the error is recorded (the account is still disabled). |
| Card page: **Renew** | `pricing`, then `renew_line` | Charged; refused with the amounts when the balance cannot pay. A failed renewal is retried with the same request id, a renewal after a success is a new one. Dolibarr has no renewal event of its own: recurring invoices of the same product sell a new line. |
| **Suspend / Unsuspend** | `disable_*` / `enable_*` | Free. |
| **Terminate** (asks for confirmation) | `disable_line` (default) or `delete_line` (final, when the product says so), `disable_user` | Sub-reseller accounts are only disabled, never deleted. Afterwards only **Retry** sells it again (a new line / new account). |
| **Retry provisioning** | as the first time | For a failed line or account; also retries credits that were not given. The **Xtream UI Pro** button on an invoice card opens the page that does it for the whole invoice. |
| **Email the credentials** | none | Sends username, password and play links to the third party's email with Dolibarr's mail class (`CMailFile`). Nothing is written to the agenda / event log. Needs the sender address of Home - Setup - Emails. |

Where to look: the tab **Xtream UI Pro** on a third party (lists, cards, buttons), the top menu
**Xtream UI Pro** (all lines and accounts, filter by status) and the invoice card button.
Errors are recorded on the line or account (column "Last error") and in Dolibarr's syslog; a
provisioning problem never blocks the payment.

## Security notes

- The API key is never echoed or logged; passwords of lines (also inside `password=` in play
  links) are masked in every log line. TLS verification is on, 5 s connect / 20 s total timeouts,
  redirects are never followed.
- Passwords of lines and sub-reseller accounts are stored encrypted (`dolEncrypt`), only shown on the
  card to users with the Manage right, and only emailed on request.
- Provisioning runs inside the request that marks the invoice paid (also from payment webhooks).
  Each unit is one API call of up to 20 s; for very large quantities provision the invoice with the
  invoice-card button instead (turn off "Provision when an invoice is paid" in the setup).

## Verified / guess (1.1.0)

Ran against a real panel (core only, `plugins/e2e/run.sh dolibarr`): `X-Connector` (read back from the panel's API call log),
`pricing` before create / renewal, the refusal of a box-only package with its reason, the default of
**delete permanently on terminate**, the `REQUEST_ID_SPENT` text. **Not run in Dolibarr**: the glue that reads the product
field (`xtreamproservice.class.php`), the setup page filter, the new extra-field default and language text. The 1.0.0
list of guessed Dolibarr behaviour (end of this file) is unchanged.

## Tested

`plugins/e2e/dolibarr-harness.php` loads the real files of `xtreampro/lib/core/` and runs them
against a panel, with an in-memory stand-in for the tables:

```sh
API_PORT_NUM=18095 API_KEY=<reseller key> php plugins/e2e/dolibarr-harness.php   # prints ALL OK
```

For a line and a sub-reseller: create, replay of create (also after a lost response), suspend,
unsuspend, renew, repeated renew without a second charge, revoke and pay again, terminate, create
again, wrong key, unknown package, no password or key in any log line or stored error.
Dolibarr itself (triggers, pages, tables, extra fields, mail) was **not** run; those files were only
checked with `php -l`.

## Not included

- Customer-facing pages: Dolibarr's customer portal does not show module data; customers get their
  credentials by email (button) only.
- Automatic renewals when a recurring invoice is paid (a recurring invoice sells a new line).
- Editing a line's package, username or password, devices (MAG / Enigma2) and deleting a
  sub-reseller account. `change_package` exists in the panel's API, but Dolibarr has no package-change event for a
  sold service, so nothing is faked here.
- A receiver for the panel's webhooks (not a cheap addition).
- Multi-company: records are scoped by `entity`, but the API key and URL are one pair per entity
  that saves them; this was not tried.

## Troubleshooting

| Message | Cause |
| --- | --- |
| The panel rejected the API key | Wrong key, or the key was regenerated. |
| The API key does not belong to a reseller account ... | Admin key, or the reseller's group may not do this. |
| The reseller account has not enough credits | Top up the reseller in the panel; use **Retry**. |
| This sale was already made and its line has since been deleted on the panel (`REQUEST_ID_SPENT`) | **Terminate** the line here, then **Retry** (a new line is sold). |
| The package is for MAG / Enigma boxes only | Choose another package on the product. |
| username / email already taken | The third party's email already belongs to another panel account. |
| The third party has no valid email address | Sub-reseller accounts need the third party's email. |
| Nothing happens when an invoice is paid | Module not enabled, setup empty, "Provision when paid" off, or the product's "what it sells" empty. |

## Places where Dolibarr's behaviour was guessed

- Module number 504760 (free for unregistered modules) and right ids `numero + 1/2`.
- Extra field types `select`, `int`, `boolean` on element `product` and the `addExtraField` argument
  order; the value keys `options_xtreampro_*` read from `array_options`.
- The tab definition string in `$this->tabs` and the `invoicecard` hook `addMoreActionsButtons`
  with `dolGetButtonAction`.
- `dolEncrypt` / `dolDecrypt` (Dolibarr 18) for the API key and passwords; `dolDecrypt` handing back
  a value that was never encrypted.
- Trigger names and objects: `BILL_PAYED`, `BILL_UNPAYED`, `BILL_CANCEL`, `BILL_VALIDATE` (credit note
  with `fk_facture_source` and negative `total_ttc`), `Facture::STATUS_CLOSED` as "paid".
- CSRF: which session token `newToken()` / `currentToken()` / `$_SESSION['token']` holds; the check
  accepts any of them.
- `CMailFile` constructor arguments and that it creates no event by itself; whether `MAIN_MAIL_DEBUG`
  would put the body into the log.
- `formconfirm` posting `action=confirm_terminate&confirm=yes` with the token, and `GETPOST` filters.
- The status words and CSS classes used in the pages.
