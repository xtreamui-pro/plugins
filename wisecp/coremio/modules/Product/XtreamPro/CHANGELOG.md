# Changelog - WISECP module

## 1.1.0

- Sends `X-Connector: wisecp/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating or renewing a line, creating a sub-reseller
  account or handing it credits: a package that is not on sale, a package for MAG / Enigma boxes only, or too
  few credits now fail at once with the amounts, and no account or line is created (before, a sub-reseller
  account could be created and only the credit hand-over fail).
- The **Panel package** list uses `sells` of the panel's `packages`: packages for MAG / Enigma boxes only are
  not offered. A panel that sends no `sells` is treated as before.
- **Delete on terminate** is now **Delete permanently on terminate** and off by default for new products;
  the help text says deleting is final on the panel. Products saved earlier keep their value.
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: cancel the
  service and create it again), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- The client area already showed the links the panel returns (`get_line`); unchanged.
- Not done: upgrade / downgrade (no documented WISECP hook for a package change of a service) and a webhook
  receiver (the module has no HTTP endpoint of its own).

## 1.0.0

First release.
