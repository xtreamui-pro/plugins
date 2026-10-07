# Changelog - OpenCart extension

## 1.1.0

Written first and not yet run in OpenCart: the changes below ran for real only in the platform independent
core (`system/library`) against a panel, and the order glue against MariaDB (`plugins/e2e/opencart-run.sh`).
The OpenCart-facing edit (the package list of the product form, through `Provisioner::packageOptions`) was
not run in OpenCart.

- Sends `X-Connector: opencart/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating a line, creating a sub-reseller account or handing
  it credits: a package that is not on sale, a package for MAG / Enigma boxes only (`sells` without `line`) or
  too few credits now fail at once with the amounts and nothing is created (before, a sub-reseller account was
  created and only the credit hand-over failed; the unit is now `failed` with no account).
- The **Package** list of the product tab leaves out packages for MAG / Enigma boxes only (the list is cached one
  hour; **Test connection** refreshes it).
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: cancel the
  order and place a new one; before, a retry of such a unit ended in an internal-error message), `READ_ONLY_KEY`
  and an extended `INVALID_PACKAGE`.
- The order page and account page already showed the links the panel returns (`get_line`); unchanged.
- Cancellation stays "disable, never delete" (no product option to delete: deleting is final on the panel; use the
  panel for that). Not done: renewal (the extension never had it), upgrade / downgrade (no hook for a package change
  of a sold product) and a webhook receiver (an extension catalog route could take one, but it could not be run
  here and is not a cheap addition).

## 1.0.0

First release.
