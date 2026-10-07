# Changelog - WordPress plugin

## 1.1.1

- Package pickers (WooCommerce product, EDD download, SureCart item list) and `[xtreampro_packages]`
  offer only packages the panel sells as a plain line (`sells` of `packages` / `pricing`). Settings
  lists every package with a new "Sold as" column. A product that still points at a box-only package
  is refused before the panel is asked, with an order note that says why; a renewal is not refused for
  that reason. A panel that sends no `sells` is treated as before.
- Webhook receiver de-duplicates on the event `id` (a 24-hour transient per id, stored after the event
  was applied); the 5-minute timestamp window stays. Events carry `owner_id` / `owner_username`: the
  plugin ignores them (a line it did not sell is acknowledged and ignored) and does not register with
  `include_sub_resellers`.
- `REQUEST_ID_SPENT` text says that a new order is a new sale. The request ids are unchanged: an order
  item that already holds a line is never sent again.

## 1.1.0

- Sends `X-Connector: wordpress/1.1.0` with every call (shown in the panel's API call log).
- Asks the panel what an order costs (`pricing`) before provisioning it, for WooCommerce, Easy
  Digital Downloads and the lines of SureCart: a package that is not on sale, a group that may not
  create sub-resellers or too few credits now fail at once with the amounts, and nothing is created.
- Order pages, emails and `[xtreampro_my_lines]` show the links the panel returns with the line
  (M3U, HLS, XMLTV guide, web player, server) instead of assembling them; lines of orders made by
  1.0.0, and panels that return none, keep the assembled M3U and web-player links.
- New product / download option "On refund or cancellation": disable (default) or delete
  permanently, for lines and for sub-reseller accounts. Deleting is final on the panel and the help
  text says so. (SureCart keeps disabling.)
- Webhook receiver `POST /wp-json/xtreampro/v1/webhook`: HMAC-SHA256 signature (constant time) and
  5-minute timestamp window; `line.deleted` removes the line from the customer's list, `line.expired`
  adds an order note, other line events update the recorded status. Settings gained the buttons to
  register, test and remove the webhook on the panel, and the Webhook secret field.
- Settings: the Panel address field (it was only readable from `XTREAMPRO_PANEL_URL` before; the
  option had no form field) and the `XTREAMPRO_WEBHOOK_SECRET` constant.
- Readable messages for `REQUEST_ID_SPENT`, `READ_ONLY_KEY` and `TOO_MANY_WEBHOOKS`.
- No upgrade / downgrade: the plugin has no subscription integration to hook the panel's
  `change_package` onto (said in readme.txt).

## 1.0.0

First release.
