# End-to-end run of the connectors

`plugins/e2e/run.sh` drives the three connectors for real against a panel API
running on a throwaway database. Nothing here is shipped: the archives are
built from `plugins/whmcs`, `plugins/wordpress` and `plugins/odoo` only.

```sh
pnpm run build                  # once: cmd/api embeds dist/
plugins/e2e/run.sh              # everything (about 15 minutes, pulls Docker images the first time)
plugins/e2e/run.sh paymenter    # or: whmcs blesta wisecp hostbill clientexec wordpress odoo fossbilling
                                #     prestashop opencart magento dolibarr erpnext bridge
```

Needs `docker`, `go`, `curl`, `openssl`; `php` for whmcs / blesta / wisecp / hostbill; `python3` for hostbill.
Ports 55440 (Postgres), 18092 (API) and 18069 (the Odoo HTTP server of the webhook route check) can be
changed with `E2E_DB_PORT` / `E2E_API_PORT` / `E2E_ODOO_PORT`; check that they are free first. The panel
is seeded with `SEED_DEMO=1`: a plain seed creates no packages and the scenarios need some (the MAG and
Enigma2 box packages of that catalogue cannot be sold as a plain line; the three first-class scenarios pick packages by `sells`).
`plugins/e2e/setup` also adds a MAG-only package, `E2E box only` (listed last), to check that the package
pickers leave out what cannot be sold as a line. The
FOSSBilling scenario needs internet access (FOSSBilling checks the DNS of client email addresses).

| Connector | How it is run | Files |
| --------- | ------------- | ----- |
| WordPress | the plugin inside WordPress (Docker), with WooCommerce, then on a fresh site with Easy Digital Downloads (the two together on one site are not covered) | `wordpress-scenario.php`, `wordpress-admin.php`, `wordpress-edd-scenario.php` |
| Odoo | the addon installed in Odoo 17 (Docker), then a scenario in `odoo shell` | `odoo-scenario.py` |
| FOSSBilling | the service module inside FOSSBilling (Docker), through its own installer, admin API, cart, invoices and cron batch | `fossbilling-run.sh`, `fossbilling-scenario.php` |
| Paymenter | the server extension inside Paymenter (Docker), through its own forms, orders, invoices and jobs | `paymenter-run.sh`, `paymenter-scenario.php` |
| WHMCS | the real module and API client, with a stand-in for WHMCS itself (its database layer, `logModuleCall`, `localAPI`) | `whmcs-harness.php` |
| Blesta | the real module, views and API client, with a stand-in for Blesta's `Module`, `Input`, `ModuleFields`, `View`… | `blesta-harness.php` |
| WISECP | the real module and API client, with a stand-in for WISECP's product-module base class | `wisecp-harness.php` |
| HostBill | the provisioning script from the command line, as Script Provisioning would call it | `hostbill-scenario.sh` |
| Webhook bridge (Shopify, Upmind, Invoice Ninja, generic) | the real application on PHP's built-in server, receiving signed sample webhooks | `bridge-harness.php`, `bridge-samples/` |
| ClientExec | the real plugin and its core, with a stand-in for ClientExec's classes | `clientexec-harness.php` |
| PrestaShop | the core (API client, provisioner, presenter) with an in-memory store | `prestashop-harness.php` |
| OpenCart | the core, and the database layer on a throwaway MariaDB (Docker) | `opencart-run.sh`, `opencart-harness.php` |
| Magento 2 | the core, and the order service with stand-ins for Magento's order objects | `magento-harness.php`, `magento-orderflow-harness.php` |
| Dolibarr | the core with an in-memory stand-in for the module's tables | `dolibarr-harness.php` |
| ERPNext | the core (plain Python, in a virtualenv with `requests`) | `erpnext-harness.py` |
| SureCart (WordPress plugin) | the real integration class with stubs of WordPress and of SureCart's integration base class | `wordpress-surecart-harness.php` |

WHMCS, Blesta, WISECP, HostBill and ClientExec are commercial and need a licence, so they are not
started: their connectors have never run inside the real system. PrestaShop, OpenCart, Magento,
Dolibarr, ERPNext and SureCart were written code-first: only their platform-independent core runs
here, the part that plugs into the platform has not run inside it yet. Shopify, Upmind and Invoice
Ninja are hosted services: the bridge is exercised with sample webhooks, not with a real account. Every scenario covers, for a line and for
a sub-reseller account: create and its replay, suspend, unsuspend, renewal without double charge,
terminate, create again, readable errors, and that neither the API key nor a password reaches a log.
The WHMCS, WordPress and Odoo scenarios (connector version 1.1.0) also cover: the `X-Connector` header in
the panel's call log, the `pricing` check that stops a sale before anything is created, the links returned
by the API, deleting on cancellation, and the webhook receiver with signed, tampered, unsigned and stale
events (signed by the scenario itself with the panel's formula, not delivered by the panel's worker); WHMCS
and Odoo also upgrade / downgrade with `change_package` and the sub-reseller group; Odoo's receiver is
also called over HTTP on a real Odoo server.

The other twelve connectors (Blesta, WISECP, HostBill, ClientExec, FOSSBilling, Paymenter, PrestaShop, OpenCart, Magento,
Dolibarr, ERPNext and the bridge; version 1.1.0) cover, in their own harness or scenario, the same four checks of the
current contract that apply to them: the `X-Connector` header read back from the panel's `api_logs`, the `pricing`
check (a box-only package, `sells` without `line`, is refused with its reason and nothing is sold), the readable
`REQUEST_ID_SPENT` message (a line deleted on the panel, then the same request id sent again) and the cancellation
default (disable). Upgrade / downgrade with `change_package` runs for Blesta, ClientExec, Paymenter (through
`upgradeServer`) and HostBill (`change-package` command). None of them has a webhook receiver.

What it does not cover: licensed WHMCS / Blesta / WISECP / HostBill installations (how those
systems themselves call the connector, their product setup screens), checkout in a browser, the
web clients of Odoo, FOSSBilling and Paymenter, real payment gateways and mail delivery.
