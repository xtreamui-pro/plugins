# Changelog - Blesta module

## 1.1.0

- Sends `X-Connector: blesta/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating or renewing a line, creating a sub-reseller
  account or handing it credits: a package that is not on sale or too few credits now fail at once with the
  amounts, and nothing is created.
- The **Panel package** list and the sale use `sells` of the panel's `packages` / `pricing`: a package for MAG /
  Enigma boxes only is no longer offered and is refused with a readable reason. A panel that sends no `sells`
  is treated as before.
- Upgrade / downgrade: `changeServicePackage` sells the new package on the line (`change_package`), after
  asking `package_compatibility` (the answer - time left kept or lost, price - goes to the module log; too
  few credits refuse the change). A panel that does not know the action is handled as before.
- **Delete on cancel** is now **Delete permanently on cancel** and off by default (new packages); deleting is
  final on the panel and the help text says so. Packages saved earlier keep their value.
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: cancel the
  service and order a new one), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- The client tab already showed the links the panel returns (`get_line`); unchanged.
- No webhook receiver: Blesta has no hook that gives a module an HTTP endpoint of its own.

## 1.0.0

First release.
