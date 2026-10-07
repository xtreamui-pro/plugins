// Buttons on a paid Sales Invoice that sells Xtream UI Pro items.
frappe.ui.form.on("Sales Invoice", {
	refresh(frm) {
		if (frm.doc.docstatus !== 1 || frm.doc.is_return) {
			return;
		}
		if (!frappe.user.has_role("Sales Manager") && !frappe.user.has_role("System Manager")) {
			return;
		}
		const codes = (frm.doc.items || []).map((row) => row.item_code).filter(Boolean);
		if (!codes.length) {
			return;
		}
		frappe.db
			.get_list("Item", { filters: { name: ["in", codes], xp_kind: ["!=", ""] }, fields: ["name"], limit: 1 })
			.then((rows) => {
				if (!rows.length) {
					return;
				}
				const group = __("Xtream UI Pro");
				frm.add_custom_button(__("Show lines and accounts"), () => {
					frappe.set_route("List", "Xtream UI Pro Line", { sales_invoice: frm.doc.name });
				}, group);
				frm.add_custom_button(__("Provision again"), () => {
					frappe.call({
						method: "xtreampro_connector.invoices.provision_now",
						args: { invoice: frm.doc.name },
						freeze: true,
						callback(r) {
							if (r.message) {
								frappe.show_alert({ message: r.message.message, indicator: "green" }, 7);
							}
						},
					});
				}, group);
			});
	},
});
