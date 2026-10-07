{
    "name": "Xtream UI Pro Connector",
    "version": "17.0.1.1.1",
    "category": "Sales/Sales",
    "summary": "Sell IPTV subscriptions from Odoo Sales and provision them on the Xtream UI Pro panel",
    "description": """
Connects Odoo Sales to the Xtream UI Pro IPTV panel through its Reseller API.
Confirming a sale order creates the subscriber lines on the panel, and the
resulting credentials can be renewed, suspended, moved to another package and
e-mailed from Odoo. Changes of each release: CHANGELOG.md (1.0.0 was the first release).
""",
    "author": "Xtream UI Pro",
    "license": "Other OSI approved licence",
    "depends": ["sale_management"],
    "external_dependencies": {"python": ["requests"]},
    "data": [
        "security/xtreampro_security.xml",
        "security/ir.model.access.csv",
        "views/xtreampro_package_views.xml",
        "views/xtreampro_line_views.xml",
        "views/xtreampro_reseller_views.xml",
        "views/xtreampro_credit_wizard_views.xml",
        "views/xtreampro_line_package_wizard_views.xml",
        "views/product_template_views.xml",
        "views/sale_order_views.xml",
        "views/res_config_settings_views.xml",
        "views/menus.xml",
        "data/ir_cron.xml",
        "data/mail_template.xml",
    ],
    "application": False,
    "installable": True,
}
