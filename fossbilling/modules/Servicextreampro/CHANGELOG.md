# Changelog - FOSSBilling module

## 1.1.0

- Sends `X-Connector: fossbilling/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before activating or renewing a line, creating a sub-reseller
  account or handing it credits: a package that is not on sale, a package for MAG / Enigma boxes only or too few
  credits now fail at once with the amounts, and nothing is created (the order goes to *failed setup* /
  *failed renew* with the reason).
- The package dropdown and the packages list of the admin page use `sells` of the panel's `packages`: packages
  for MAG / Enigma boxes only are no longer offered. A panel that sends no `sells` is treated as before.
- Cancellation: the product setting is now *Only disable the line* (default) or *Delete the line permanently*
  (final on the panel); products saved before 1.1.0 keep what they had stored, a product with no stored value
  now only disables (it used to delete).
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: cancel the
  order and place a new one), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- The customer page already showed the links the panel returns (`get_line`); unchanged.
- Not done: upgrade / downgrade (the service module contract has no hook for a package change) and a webhook
  receiver (needs an endpoint, a table and an admin screen of its own; not cheap in a service module).

## 1.0.0

First release.
