# Changelog - webhook bridge (Shopify, Upmind, Invoice Ninja, generic)

## 1.1.0

- Sends `X-Connector: bridge/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating or renewing a line, creating a sub-reseller account
  or handing it credits: a package that is not on sale, a package for MAG / Enigma boxes only (`sells` without
  `line`) or too few credits now fail at once with the amounts, and nothing is created. The event is stored as
  failed with that reason, and `retry` runs it again after the cause is fixed (for a sub-reseller: the price of the
  account plus the starting credits, before the account exists).
- `php bin/bridge.php check` marks packages that are for MAG / Enigma boxes only, so they are not mapped to a line
  product by mistake.
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: the order has to be
  placed again), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- The credentials mail already carried the links the panel returns (`get_line`); unchanged.
- Revocation stays "disable, never delete" (there is no per-product option to delete: deleting is final on the panel).
- Not done: upgrade / downgrade (none of the platforms sends a package-change event the bridge could map) and a receiver
  for the panel's own webhooks: the bridge already is an HTTP application, so this is the best candidate of all the
  connectors, but it needs a secret in `config.php`, a table for the event ids and a `register` command, and was left out
  rather than added without a real consumer (the bridge keeps no line state that a panel event would have to correct).

## 1.0.0

First release.
