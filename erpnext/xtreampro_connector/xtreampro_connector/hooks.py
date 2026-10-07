# Xtream UI Pro Connector 1.1.0
#
# Wiring of the app into Frappe / ERPNext. The panel logic itself lives in
# panel/ (no Frappe imports); invoices.py, tasks.py and the doctype controllers
# are the thin layer that turns ERPNext events into calls on it.

app_name = "xtreampro_connector"
app_title = "Xtream UI Pro Connector"
app_publisher = "Xtream UI Pro"
app_description = "Sell IPTV lines and sub-reseller accounts from ERPNext through the Xtream UI Pro Reseller API"
app_email = "support@example.com"
app_license = "LGPL-3.0"

required_apps = ["erpnext"]

# Custom fields on Item, Sales Invoice and Sales Invoice Item. Created on install and
# kept in step on every migrate; removed again when the app is uninstalled.
after_install = "xtreampro_connector.install.after_install"
after_migrate = "xtreampro_connector.install.after_migrate"
before_uninstall = "xtreampro_connector.install.before_uninstall"

doctype_js = {"Sales Invoice": "public/js/sales_invoice.js"}

# The signals that an invoice is paid (or no longer counts):
#  * Sales Invoice on_submit: paid at once (POS, advance allocated, free item) or a credit note;
#  * Payment Entry on_submit: runs after ERPNext updated the invoice's outstanding amount
#    (a plain db update, which does NOT fire on_update_after_submit on the invoice);
#  * Sales Invoice on_cancel: revoke.
# Anything else that settles an invoice (a Journal Entry, ...) is caught by the hourly job.
doc_events = {
    "Sales Invoice": {
        "on_submit": "xtreampro_connector.invoices.on_invoice_submit",
        "on_cancel": "xtreampro_connector.invoices.on_invoice_cancel",
    },
    "Payment Entry": {
        "on_submit": "xtreampro_connector.invoices.on_payment_submit",
    },
}

scheduler_events = {
    "hourly": [
        "xtreampro_connector.tasks.provision_missed_invoices",
    ],
    "daily": [
        "xtreampro_connector.tasks.sync_packages",
    ],
    "cron": {
        # every 6 hours: refresh status, expiry and credit balance in batches
        "0 */6 * * *": [
            "xtreampro_connector.tasks.refresh_all",
        ],
    },
}

# "My IPTV" page of the customer portal (www/iptv.html)
standard_portal_menu_items = [
    {"title": "My IPTV", "route": "/iptv", "role": "Customer"},
]
