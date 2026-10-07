# Changelog - ClientExec plugin

## 1.1.0

- Sends `X-Connector: clientexec/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating or renewing a line, creating a sub-reseller
  account or handing it credits: a package that is not on sale, a package for MAG / Enigma boxes only (`sells`
  without `line`) or too few credits now fail at once with the amounts, and nothing is created.
- Package change: `update()` with a package change now sells the new package on the line (`change_package`),
  after asking `package_compatibility` (time left kept or lost, price; too few credits refuse the change and the
  answer is written to ClientExec's log). A panel that does not know the action is handled as before. Sub-reseller
  accounts have no package on the panel, so only the ClientExec package changes. `plugin.ini` now says
  `changepackage = 1`.
- **Delete on terminate** is now **Delete permanently on terminate** and off by default for new packages; the
  help text says deleting is final on the panel.
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: terminate the
  package and create it again), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- The custom fields already received the links the panel returns (`get_line`); unchanged.
- Not done: a webhook receiver (the plugin has no HTTP endpoint of its own).

## 1.0.0

First release.
