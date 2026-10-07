# Changelog - Dolibarr module

## 1.1.0

- Sends `X-Connector: dolibarr/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating or renewing a line, creating a sub-reseller
  account or handing it credits: a package that is not on sale, a package for MAG / Enigma boxes only (`sells`
  without `line`) or too few credits now fail at once with the amounts and are recorded as the line's error;
  nothing is created.
- The package list on the setup page (after "Test connection") leaves out packages for MAG / Enigma boxes only.
- The product field is now **delete the line permanently on terminate** and **unticked by default** (also for a
  product that never stored a value: it used to delete); deleting is final on the panel. Products where the box
  was saved ticked keep deleting. The extra field's default is empty for installs that enable the module now;
  an existing install keeps the default it created (edit the product field if you want that changed).
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: Terminate it
  here, then Retry), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- The line card and the credentials email already used the links the panel returns (`get_line`); unchanged.
- Not done: upgrade / downgrade (Dolibarr has no package-change event for a sold service; nothing is faked) and a
  webhook receiver (a Dolibarr module can add a public page, but a signed receiver with a table for the secret and the
  event ids and a setup screen is not a cheap addition).

## 1.0.0

First release.
