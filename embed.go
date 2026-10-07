// Package plugins holds the source of the connectors resellers install in
// their own systems (WHMCS, WordPress, Odoo, FOSSBilling, Paymenter, Blesta,
// WISECP, HostBill, ClientExec, PrestaShop, OpenCart, Magento, Dolibarr,
// ERPNext) and the webhook bridge they host for Shopify, Upmind and Invoice
// Ninja. Each folder is zipped as it is
// and offered for download by the dashboard at /connectors/{id}/download;
// `make package-plugins` writes the same archives to build/plugins.
//
// The folders are PHP and Python: nothing here is built or run by Go.
package plugins

import "embed"

// Sources is the tree the archives are built from. `all:` keeps files whose
// name starts with "_" (Odoo's __init__.py and __manifest__.py).
//
//go:embed all:whmcs all:wordpress all:odoo all:fossbilling all:paymenter all:blesta all:wisecp all:hostbill
//go:embed all:clientexec all:prestashop all:opencart all:magento all:dolibarr all:erpnext all:bridge
var Sources embed.FS
