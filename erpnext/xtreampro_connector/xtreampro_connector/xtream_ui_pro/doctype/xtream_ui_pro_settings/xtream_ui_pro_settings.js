// Buttons of the settings page. The calls use the SAVED settings (never what is typed in the
// form), so save first.
frappe.ui.form.on("Xtream UI Pro Settings", {
	test_connection(frm) {
		xtreampro_settings_call(frm, "test_connection");
	},
	sync_packages(frm) {
		xtreampro_settings_call(frm, "sync_packages");
	},
});

function xtreampro_settings_call(frm, method) {
	if (frm.is_dirty()) {
		frappe.msgprint(__("Save the settings first."));
		return;
	}
	frappe.call({
		method: "xtreampro_connector.actions." + method,
		freeze: true,
		freeze_message: __("Talking to the panel..."),
		callback(r) {
			if (r.message) {
				frappe.show_alert({ message: r.message.message, indicator: "green" }, 8);
			}
			frm.reload_doc();
		},
	});
}
