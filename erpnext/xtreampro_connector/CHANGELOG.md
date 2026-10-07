# Changelog - ERPNext / Frappe connector

## 1.1.0

Written first and not yet run in ERPNext: the changes below ran for real only in the platform independent core
(`xtreampro_connector/panel/`) against a panel. The Frappe layer that was touched (the custom field default in
`install.py`, the fallback in the Line doctype's `terminate`, the version strings) was only syntax-checked.

- Sends `X-Connector: erpnext/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating or renewing a line, creating a sub-reseller
  account or handing it credits: a package that is not on sale, a package for MAG / Enigma boxes only (`sells`
  without `line`) or too few credits now fail at once with the amounts, and nothing is created.
- **Sync packages** leaves out packages for MAG / Enigma boxes only (a package synced earlier stays until you
  delete its row).
- **Delete on terminate** on the Item is now **Delete permanently on terminate** and **off by default** (new
  installs; an install keeps the default its custom field was created with, and every Item keeps its saved
  value). Terminate on a line whose item has no value only disables it. Deleting is final on the panel.
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: Terminate the
  record here, then create it again), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- The customer page `/iptv` and the e-mail already used the links the panel returns (`get_line`); unchanged.
- Not done: upgrade / downgrade (no hook for a package change of a sold item) and a webhook receiver (a Frappe
  whitelisted method could take one, but a signed receiver with a doctype for the secret and the event ids is not a
  cheap addition and could not be run here).

## 1.0.0

First release.
