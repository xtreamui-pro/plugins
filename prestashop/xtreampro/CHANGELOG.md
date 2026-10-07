# Changelog - PrestaShop module

## 1.1.0

Written first and not yet run in PrestaShop: the changes below were run for real only in the platform independent
core (`src/`) against a panel. The glue that was touched (`classes/Config.php`, the version in `xtreampro.php` and
`config.xml`) was only syntax-checked.

- Sends `X-Connector: prestashop/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating or renewing a line, creating a sub-reseller
  account or handing it credits: a package that is not on sale, a package for MAG / Enigma boxes only (`sells`
  without `line`) or too few credits now fail at once with the amounts, and nothing is created (for a
  sub-reseller: the price of the account plus the starting credits, before the account exists).
- The **Package** list of the product page leaves out packages for MAG / Enigma boxes only (the panel list is cached
  ten minutes, so an older cached list may still show one until it expires).
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: cancel the
  order and place a new one), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- The order pages and the credentials mail already used the links the panel returns (`get_line`); unchanged.
- Cancellation stays "disable, never delete" (no product option to delete: deleting is final on the panel; use the
  panel for that). Not done: upgrade / downgrade (no hook for a package change of a sold product) and a webhook
  receiver (a module front controller could take one, but it is not a cheap addition and could not be run here).

## 1.0.0

First release.
