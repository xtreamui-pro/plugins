// Buttons of an IPTV line. Each one calls a whitelisted method (xtreampro_connector.actions)
// that checks the role again on the server.
frappe.ui.form.on("Xtream UI Pro Line", {
	refresh(frm) {
		if (frm.is_new()) {
			return;
		}
		const run = (method, confirm_text) => () => {
			const go = () =>
				frappe.call({
					method: "xtreampro_connector.actions." + method,
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
			confirm_text ? frappe.confirm(confirm_text, go) : go();
		};
		const group = __("Xtream UI Pro");
		const live = frm.doc.panel_line_id > 0;
		frm.add_custom_button(__("Send credentials"), run("line_send_credentials"), group);
		if (!frappe.user.has_role("Sales Manager") && !frappe.user.has_role("System Manager")) {
			return;
		}
		if (live) {
			frm.add_custom_button(__("Refresh"), run("line_refresh"), group);
			frm.add_custom_button(
				__("Renew"),
				run("line_renew", __("Renew this line now? It is charged to your credits on the panel.")),
				group
			);
			if (frm.doc.status === "Suspended") {
				frm.add_custom_button(__("Unsuspend"), run("line_unsuspend"), group);
			} else {
				frm.add_custom_button(__("Suspend"), run("line_suspend"), group);
			}
			frm.add_custom_button(__("Play links"), () => {
				frappe.call({
					method: "xtreampro_connector.actions.line_links",
					args: { name: frm.doc.name },
					callback(r) {
						const links = r.message || {};
						const rows = Object.keys(links)
							.map((k) => `<tr><td>${frappe.utils.escape_html(k)}</td><td>${frappe.utils.escape_html(String(links[k]))}</td></tr>`)
							.join("");
						frappe.msgprint({ title: __("Play links"), message: `<table class="table table-bordered">${rows}</table>`, wide: true });
					},
				});
			}, group);
			frm.add_custom_button(
				__("Terminate"),
				run("line_terminate", __("Terminate this line? Depending on the item it is deleted on the panel, which cannot be undone.")),
				group
			);
		} else {
			frm.add_custom_button(__("Retry"), run("line_retry"), group);
		}
	},
});
