# Xtream UI Pro — connectors

Source of the connectors that resellers of an [Xtream UI Pro](https://github.com/xtreamui-pro) panel install
in their own billing, shop or ERP system. Each connector sells IPTV lines through the panel's
**Reseller API** with the reseller's own API key.

| Folder         | Installs into                                                          |
| -------------- | ---------------------------------------------------------------------- |
| `whmcs/`       | WHMCS (`modules/servers/xtreampro/`)                                   |
| `blesta/`      | Blesta (`components/modules/xtreampro/`)                               |
| `wisecp/`      | WISECP (`coremio/modules/Product/XtreamPro/`)                          |
| `hostbill/`    | HostBill, with the Script Provisioning module                          |
| `fossbilling/` | FOSSBilling (`modules/Servicextreampro/`)                              |
| `paymenter/`   | Paymenter (`extensions/Servers/XtreamPro/`)                            |
| `clientexec/`  | ClientExec (`plugins/server/xtreampro/`)                               |
| `wordpress/`   | WordPress, with WooCommerce, Easy Digital Downloads or SureCart        |
| `prestashop/`  | PrestaShop (Module Manager → Upload)                                   |
| `opencart/`    | OpenCart (Extensions → Installer, `xtreampro.ocmod.zip`)                |
| `magento/`     | Magento 2 (`app/code/XtreamPro/Connector/`)                            |
| `odoo/`        | Odoo 17 addon (`xtreampro_connector/`)                                 |
| `dolibarr/`    | Dolibarr (`htdocs/custom/xtreampro/`)                                  |
| `erpnext/`     | ERPNext / Frappe app                                                   |
| `bridge/`      | small PHP app you host yourself: receives webhooks of Shopify, Upmind and Invoice Ninja |

Each folder has a README with installation steps, settings, and what has been tested and how.
The folder layout is the layout inside the zip: the panel offers each folder for download on its
**Connectors** page, and the `plugin-*` releases carry the same archives.

`e2e/` holds the test harnesses: most of them load the real connector files with a stand-in for the
host system and run them against a panel's Reseller API (`API_KEY`, `API_PORT_NUM`), so you can check a
change with your own panel and a reseller key. `e2e/run.sh` (the full run that starts a throwaway panel)
only works inside the panel's own repository.

`embed.go` lists the folders the panel builds into its binary; leave it alone unless you add a connector.

Want to fix or improve a connector? See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE). By opening a pull request you agree that your contribution is released under the same license.
