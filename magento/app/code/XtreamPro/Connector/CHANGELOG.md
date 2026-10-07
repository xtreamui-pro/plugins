# Changelog - Magento 2 module

## 1.1.0

Written first and not yet run in Magento: the changes below ran for real only in the platform independent
core (`Core/`) and the order logic (`Model/OrderService.php`, with stand-ins) against a panel. The PHP that
reads the package list for the product form (`Model/PackageList.php` calls `Core\Provisioner::packages()`, which
is filtered) was not run in Magento.

- Sends `X-Connector: magento/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating or renewing a line, creating a sub-reseller
  account or handing it credits: a package that is not on sale, a package for MAG / Enigma boxes only (`sells`
  without `line`) or too few credits now fail at once with the amounts, and nothing is created (for a
  sub-reseller: the price of the account plus the starting credits, before the account exists).
- The **package** dropdown of the product form leaves out packages for MAG / Enigma boxes only (cached ten minutes;
  an older cached list may still show one until it expires).
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: cancel the
  order and place a new one), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- `Core\Provisioner::terminateLine()` now disables by default (`$delete = false`); the shop never passed `true`.
- The order page and My IPTV already showed the links the panel returns (`get_line`); unchanged.
- Cancellation stays "disable, never delete" (no product option to delete: deleting is final on the panel; use the
  panel for that). Not done: upgrade / downgrade (no hook for a package change of a sold product), renewals (the
  module never had a caller for `renewLine`) and a webhook receiver (a frontend controller could take one, but it
  could not be run here and is not a cheap addition).

## 1.0.0

First release.
