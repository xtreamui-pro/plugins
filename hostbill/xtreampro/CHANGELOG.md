# Changelog - HostBill provisioning script

## 1.1.0

- Sends `X-Connector: hostbill/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before `create`, `renew` and `change-package`: a package that is
  not on sale, a package for MAG / Enigma boxes only (`sells` without `line`) or too few credits now fail at once
  with the amounts (exit 1), and nothing is created. For a sub-reseller: the price of the account plus the
  starting credits, before the account exists.
- New action `change-package --service=<id> --package=<new panel package id>`: sells the new package on the line
  (`change_package`) after asking `package_compatibility`; the panel's answer (time left kept or lost, price) is
  in the `panel` field of the output. A panel that does not know the action is handled as before; a line already
  on the package is left alone. HostBill has to be set up to call it (see the README).
- **`terminate` now only disables the line by default**; deleting is final on the panel and needs the new
  `--delete-on-terminate` switch (`XTREAMPRO_DELETE_ON_TERMINATE`). `--keep-on-terminate` is still accepted.
  Existing HostBill products that relied on the old default (delete) must add `--delete-on-terminate`.
- Readable messages for `REQUEST_ID_SPENT` (the line of that request id was deleted on the panel: terminate the
  service and create it again), `READ_ONLY_KEY` and an extended `INVALID_PACKAGE`.
- `create` / `info` already printed the links the panel returns (`get_line`); unchanged.
- Not done: a webhook receiver (a command-line script has no HTTP endpoint).

## 1.0.0

First release.
