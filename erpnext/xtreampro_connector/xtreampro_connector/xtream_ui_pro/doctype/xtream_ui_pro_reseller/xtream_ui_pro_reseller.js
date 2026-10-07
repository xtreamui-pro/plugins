// Buttons of a sub-reseller account. Each one calls a whitelisted method
// (xtreampro_connector.actions) that checks the role again on the server.
frappe.ui.form.on("Xtream UI Pro Reseller", {
	refresh(frm) {
		if (frm.is_new()) {
			return;
		}
		const call = (method, args, confirm_text) => {
			const go = () =>
				frappe.call({
					method: "xtreampro_connector.actions." + method,
					args: Object.assign({ name: frm.doc.name }, args || {}),
					freeze: true,
					freeze_message: __("Talking to the panel..."),
					callback(r) {
						if (r.message && r.message.message) {
							frappe.show_alert({ message: r.message.message, indicator: "green" }, 7);
						}
						frm.reload_doc();
					},
				});
			confirm_text ? frappe.confirm(confirm_text, go) : go();
		};
		const run = (method, confirm_text) => () => call(method, null, confirm_text);
		const credits = (method, title) => () =>
			frappe.prompt(
				[
					{ fieldname: "credits", fieldtype: "Int", label: __("Credits"), reqd: 1 },
					{ fieldname: "note", fieldtype: "Data", label: __("Note") },
				],
				(values) => call(method, values),
				title
			);
		const group = __("Xtream UI Pro");
		frm.add_custom_button(__("Send credentials"), run("reseller_send_credentials"), group);
		if (!frappe.user.has_role("Sales Manager") && !frappe.user.has_role("System Manager")) {
			return;
		}
		if (frm.doc.panel_user_id) {
			frm.add_custom_button(__("Refresh"), run("reseller_refresh"), group);
			if (frm.doc.status === "Suspended") {
				frm.add_custom_button(__("Unsuspend"), run("reseller_unsuspend"), group);
			} else {
				frm.add_custom_button(__("Suspend"), run("reseller_suspend"), group);
			}
			frm.add_custom_button(__("Add credits"), credits("reseller_add_credits", __("Add credits")), group);
			frm.add_custom_button(__("Take back credits"), credits("reseller_take_credits", __("Take back credits")), group);
			frm.add_custom_button(
				__("Terminate"),
				run("reseller_terminate", __("Disable this account on the panel and close the record? The panel cannot delete accounts.")),
				group
			);
		} else {
			frm.add_custom_button(__("Retry"), run("reseller_retry"), group);
		}
	},
});
