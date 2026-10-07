frappe.ui.form.on("Xtream UI Pro Credit Transfer", {
	refresh(frm) {
		if (frm.is_new() || frm.doc.status === "Done") {
			return;
		}
		if (!frappe.user.has_role("Sales Manager") && !frappe.user.has_role("System Manager")) {
			return;
		}
		frm.add_custom_button(__("Retry"), () => {
			frappe.call({
				method: "xtreampro_connector.actions.transfer_retry",
				args: { name: frm.doc.name },
				freeze: true,
				freeze_message: __("Talking to the panel..."),
				callback(r) {
					if (r.message && r.message.message) {
						frappe.show_alert({ message: r.message.message, indicator: "green" }, 7);
					}
					frm.reload_doc();
				},
			});
		});
	},
});
