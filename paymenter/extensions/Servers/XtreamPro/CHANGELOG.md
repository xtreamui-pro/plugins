# Changelog - Paymenter server extension

## 1.1.0

- Sends `X-Connector: paymenter/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating or renewing a line, creating a sub-reseller
  account or handing it credits: a package that is not on sale, a package for MAG / Enigma boxes only or too few
  credits now fail at once with the amounts, and nothing is created.
- The **Package** list uses `sells` of the panel's `packages`: packages for MAG / Enigma boxes only are not
  offered. A panel that sends no `sells` is treated as before.
- Upgrade / downgrade: `upgradeServer` (customer upgrade, or admin **Trigger Extension Action > Upgrade server**)
  now also sells the package of the service's product on the line (`change_package`), after asking
  `package_compatibility` (time left kept or lost, price; too few credits refuse the change, the answer is
  logged). A line already on that package is left alone, so the retry-renewals use of the action is unchanged. A
  panel that does not know the action is handled as before.
- **Delete on terminate** is now **Delete permanently on terminate** and off by default for new products; the
  help text says deleting is final on the panel. Products saved earlier keep their value.
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: terminate the
  service and create it again), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- The client area already showed the links the panel returns (`get_line`); unchanged.
- Not done: a webhook receiver (Paymenter extensions can add routes, but an endpoint, a table for the secret
  and the event ids and an admin screen to register it are not a cheap addition).

## 1.0.0

First release.
