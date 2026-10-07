# Changelog - Odoo addon

## 1.1.1 (manifest 17.0.1.1.1)

- Packages carry *Sold as a line* (from `sells` of the panel's `packages` / `pricing`). The product's
  package picker and **Change package** offer only packages that sell as a plain line; a sale of a
  box-only package fails before the panel is asked, with a readable reason. A panel that sends no
  `sells` is treated as before. Resync the packages after upgrading.
- **Change package** shows, before the button is pressed, what the panel says it will do
  (`package_compatibility`): time left kept or the new period starts today, the time lost, the price.
  The answer is also written to the line's chatter. A panel too old to know the action gets a note, and
  the change is decided when it is sold.
- Webhook receiver de-duplicates on the event `id` (model `xtreampro.webhook.event`, ids kept 24 hours);
  the 5-minute timestamp window stays.
- New settings button **Register webhook (with sub-resellers' lines)** (`include_sub_resellers`, default
  off) and the read-only *Events sent*. Events carry `owner_id` / `owner_username`; one for a line this
  database did not sell is ignored, except that the time of an event of a line owned by a sub-reseller
  record is noted on it (*Last panel activity of its lines*).
- `REQUEST_ID_SPENT` text says what to do (archive the record; a new sale makes a new line). The line
  request ids are unchanged: a unit that already has its record is never sent again.

## 1.1.0 (manifest 17.0.1.1.0)

- Sends `X-Connector: odoo/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what an order costs (`pricing`) before creating lines or a sub-reseller account,
  and before a renewal, a package change or a credit hand-over: a package that is not on sale, a
  group that is not allowed or too few credits now fail before anything reaches the panel. The order
  is still confirmed; its lines / accounts stay in *Error* with the reason and the amounts, and Retry
  works as before.
- The line form shows the links the panel returns (server, M3U, HLS, XMLTV guide, web player) instead
  of assembling them; lines made by 1.0.0 keep the assembled M3U and web player links until refreshed.
- New **Change package** button on a line (upgrade / downgrade, `change_package`, charged).
- New product field **On cancellation**: *Disable* (default) or *Delete permanently*. Cancelling a
  confirmed sale order now acts on the panel: it suspends, or deletes for good, the lines and the
  sub-reseller account the order created. Deleting is final on the panel and the field says so.
  (Cancelling an order did nothing on the panel before.)
- New **Delete on panel** button on a sub-reseller (`delete_user`, with a confirmation).
- New product field **Sub-reseller group** (groups synced from the panel's `pricing`); settings gained
  **Panel address** (sign-in link on the sub-reseller form and in the credentials e-mail of new
  installations).
- Webhook receiver `/xtreampro/webhook`: HMAC-SHA256 signature (constant time) and a 5-minute
  timestamp window; `line.expired` / `renewed` / `enabled` / `disabled` update the line, `line.deleted`
  archives it. Settings gained **Register webhook**, **Send test event** and **Remove webhook**.
- Readable messages for `REQUEST_ID_SPENT`, `READ_ONLY_KEY` and `TOO_MANY_WEBHOOKS`.

## 1.0.0

First release.
