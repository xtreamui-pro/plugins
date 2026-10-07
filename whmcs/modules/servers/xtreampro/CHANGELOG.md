# Changelog - WHMCS module

## 1.1.1

- Package dropdown and sales use `sells` of the panel's `packages` / `pricing`: a package for MAG /
  Enigma boxes only is no longer offered, and creating a line or changing a package to it fails at
  once with a readable reason instead of `INVALID_PACKAGE` at sale time. A panel that sends no
  `sells` is treated as before.
- Upgrade / downgrade asks the panel first (`package_compatibility`) whether the time left is kept
  and what the change costs, refuses it at once when the reseller cannot pay, and writes the answer
  to the Activity Log. A panel that does not know the action is handled as before.
- Webhook receiver de-duplicates on the event `id` (table `mod_xtreampro_webhook_events`, ids kept 24
  hours); the 5-minute timestamp window stays.
- New admin button **Register panel webhook (with sub-resellers' lines)** (`include_sub_resellers`,
  default off). Events carry `owner_id` / `owner_username`; one for a line this WHMCS did not sell is
  ignored, except that the time of an event of a line owned by a sub-reseller service of this module
  is noted on that service.
- `REQUEST_ID_SPENT` text and the troubleshooting row say how to sell again (Terminate, then Create);
  the per-service counter in the request id is unchanged.

## 1.1.0

- Sends `X-Connector: whmcs/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what a sale costs (`pricing`) before creating or renewing a line, changing its
  package or creating a sub-reseller: a package that is not on sale, a group that is not allowed or
  too few credits now fail at once with the amounts, and nothing is created.
- Client area shows the links the panel returns with the line (M3U, HLS, XMLTV guide, web player,
  server) instead of assembling them; the old links remain as a fallback for a panel that sends none.
- Upgrade / downgrade: `ChangePackage` now sells the new package on the line (`change_package`).
- On cancellation, lines: **Delete on terminate** is now off by default (new products); deleting
  is final on the panel and the option says so. Sub-resellers: new **Sub-reseller on cancellation**
  option, *Disable* (default) or *Delete permanently* (`delete_user`).
- New product options **Panel address** (sign-in link in the client area) and **Sub-reseller group**
  (the group of a new account, loaded from the panel).
- Webhook receiver `modules/servers/xtreampro/webhook.php` with admin buttons to register, test and
  remove the webhook on the panel. Verifies the signature (constant time) and a 5-minute timestamp
  window; `line.deleted` marks the service Terminated, `line.expired` / `renewed` / `enabled` /
  `disabled` are recorded.
- Readable messages for `REQUEST_ID_SPENT`, `READ_ONLY_KEY` and `TOO_MANY_WEBHOOKS`.
- The module's table gains the columns `changes`, `panel_status`, `panel_event_at`; a table
  `mod_xtreampro_webhooks` holds the encrypted webhook secret. Both are created automatically.

## 1.0.0

First release.
