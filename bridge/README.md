# Xtream UI Pro - webhook bridge (Shopify, Upmind, Invoice Ninja, generic)

A small PHP application you host on your own web server. Hosted platforms that cannot run a
module of ours but can send webhooks (**Shopify**, **Upmind**, **Invoice Ninja**, or any system
that can post JSON) tell it when an order is paid, renewed, refunded or cancelled, and it carries
that out on your panel with your reseller API key: IPTV lines are created, renewed and disabled,
sub-reseller accounts are created and topped up. The buyer receives the credentials by email.

It is not a storefront and takes no payments: the platform does.

> **Not yet tested in the real platforms.** The bridge has been run end to end against a real panel
> with sample webhooks (`plugins/e2e/bridge-harness.php`: signatures, creation, duplicates, refunds,
> credits, failures and retry), but it has never received a webhook from a real Shopify, Upmind or
> Invoice Ninja account. Where the platform's behaviour had to be guessed it is listed here and
> marked `UNVERIFIED` in the code:
>
> - **Upmind**: the header (`X-Webhook-Signature`), the algorithm (HMAC-SHA256, hex) and the hook
>   codes `contract_product_activated_hook` / `invoice_payment_received_hook` are from Upmind's
>   documentation and PHP example. The hook codes of *renewed*, *suspended*, *unsuspended* and
>   *cancelled* follow the same naming pattern but are not listed anywhere, and the fields of the
>   contract product object (product id, quantity, client email) are not documented. The bridge
>   looks for the product id in `product_id`, `product.id`, `product.code`, `package_id`,
>   `package.id` and the email in `client.email`, `client.login_email`, `client.notification_email`,
>   `client_email`, `email`. Look at a real delivery (Upmind > Logs > Webhook events log) and adjust
>   the `upmind.events` map in `config.php`.
> - **Invoice Ninja**: webhooks have no event name in the body, so the action is read from the
>   invoice's `status_id` (4 paid, 5 cancelled, 6 reversed). The customer's email is read from
>   `client.contacts[].email`, which needs the client to be part of the payload; a credit note is
>   recognised by its `invoice_id` field. Both are taken from the Invoice Ninja source, not from a
>   running instance.
> - **Shopify**: the webhook created in the admin is signed with the signing secret Shopify shows on
>   that screen; the menu names below are from memory of the admin and may differ slightly.
> - How `mail()` / your SMTP server delivers mail to a given inbox is up to your server.
>
> **1.1.0** (`X-Connector`, `pricing` before a sale, the `sells` check, `REQUEST_ID_SPENT` text) was run for real with the
> same sample webhooks against a real panel (`plugins/e2e/run.sh bridge`); nothing of it depends on a platform.

## Requirements

- PHP 8.1 or newer with the `curl`, `json` and `pdo_sqlite` extensions.
- A web server (nginx or Apache) with **HTTPS**. Platforms send credentials-related payloads; do not
  expose the bridge over plain http.
- An Xtream UI Pro panel whose API address (`cmd/api`, for example `https://api.example.com`) is
  reachable from the bridge, with a valid TLS certificate (always verified).
- A **reseller** account in the panel with enough credits. Everything the bridge sells is charged
  to this reseller: creating and renewing lines, creating sub-reseller accounts and handing credits over.

## Install

1. Extract the archive on the server, for example to `/var/www/xtreampro-bridge/`. You get
   `public/`, `src/`, `bin/`, `var/`, `config.sample.php` and this file.
2. Create the configuration and lock it down:
   ```sh
   cd /var/www/xtreampro-bridge
   cp config.sample.php config.php
   chmod 600 config.php
   chown www-data: config.php var      # the user PHP runs as (php-fpm / mod_php)
   ```
   The bridge **refuses to run** when `config.php` is readable by other users or sits inside `public/`.
   `var/` holds the database (`bridge.sqlite3`, created with mode 0600) and the log; it must be
   writable by the web server user and must never be served.
3. Point the web server at `public/` only (below), then edit `config.php` and run
   `php bin/bridge.php check`.
4. Add a cron entry that retries failures every few minutes, as the same user as the web server:
   ```
   */10 * * * * www-data php /var/www/xtreampro-bridge/bin/bridge.php retry
   ```

PHP caches `config.php` through opcache: after changing it, a change can take up to
`opcache.revalidate_freq` seconds (2 by default) to apply, or reload php-fpm.

### nginx

```nginx
server {
    listen 443 ssl;
    server_name bridge.example.com;
    # ssl_certificate ... ssl_certificate_key ...

    root /var/www/xtreampro-bridge/public;     # NOT the folder above it
    client_max_body_size 1m;

    location / {
        try_files $uri /index.php$is_args$args;
    }
    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }
}
```

### Apache

```apache
<VirtualHost *:443>
    ServerName bridge.example.com
    DocumentRoot /var/www/xtreampro-bridge/public
    # SSLEngine on ... SSLCertificateFile ...
    LimitRequestBody 1048576

    <Directory /var/www/xtreampro-bridge/public>
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>
</VirtualHost>
```

Check: `curl https://bridge.example.com/health` answers `{"ok":true}`, and
`curl https://bridge.example.com/config.php` and `/src/App.php` answer 404.

## Configuration (`config.php`)

| Key | Meaning |
| --- | --- |
| `api_url`, `api_key` | The panel's API address and your reseller API key (panel dashboard, `/api-key`). Admin keys are refused. |
| `panel_url` | Optional dashboard address, printed in the mail to a new sub-reseller. |
| `secrets.<platform>` | The webhook secret of each platform. **Empty = that endpoint is off** and answers 401 to everything. |
| `products.<platform>` | The product map: what each product of the shop sells (below). |
| `mail` | How the buyer receives the credentials: `transport` `mail` (PHP `mail()`) or `smtp` (`smtp.host/port/encryption/user/password`), and `from`. |
| `upmind`, `invoiceninja` | Platform settings (header names, Upmind event map), see their sections. |
| `data_dir` | Where the database and the log live (default `var/`). |

### Product map

`products.<platform>` maps the platform's own product key to what it sells:

```php
'products' => [
    'shopify' => [
        'IPTV-12M'          => ['type' => 'line', 'package_id' => 3],
        'IPTV-TRIAL'        => ['type' => 'line', 'package_id' => 3, 'trial' => true],
        'variant:808950810' => ['type' => 'line', 'package_id' => 4],
        'RESELLER-100'      => ['type' => 'reseller', 'credits' => 100, 'renew_credits' => 30],
    ],
],
```

- `type` `line`: **one IPTV line per unit bought** (quantity 3 = 3 lines). `package_id` is a package
  of your panel (`php bin/bridge.php check` lists them); `trial` creates trial lines.
- `type` `reseller`: a **sub-reseller account** for the customer, created once per customer email;
  `credits` = credits handed over per unit bought, `renew_credits` = credits per renewal. A later
  purchase by the same email tops up the same account.
- Products that are not in the map are acknowledged and ignored (a shop sells other things too).
- Keys: Shopify = the SKU, or `variant:<variant id>`, or `product:<product id>`; Upmind = the
  product id; Invoice Ninja = the product key of the invoice line; generic = the `sku` you send.

`php bin/bridge.php map` prints what is configured.

## What happens

| Event | On the panel | Notes |
| --- | --- | --- |
| paid | `pricing`, then `create_line` per unit, or `pricing`, `create_user` (once per email) + `adjust_credits` | Charged to your reseller. `pricing` runs first: too few credits, a package that is not on sale or one for boxes only fail at once with the amounts, the event is stored as failed and nothing is created. Request id `br-<platform>-<order>-<item>-<n>`, so a repeated event never charges twice. The buyer is mailed the credentials and play links. |
| renewed | `get_line`, `pricing`, then `renew_line` for each line of the order, or `adjust_credits` with `renew_credits` | **Charged.** Request id includes the event id. |
| revoked (refund, cancel) | `disable_line` for the lines, `adjust_credits` (negative) for the **unspent** credits, `disable_user` for an account the order created | Lines are only disabled, never deleted (deleting is final). Only the credits the account still has can come back. A partial refund (Shopify) revokes only the refunded quantity. |
| suspended (Upmind) | like revoked, but credits stay | |
| restored | `enable_line` / `enable_user` | Credits taken back earlier are not given again. |

Rules that always hold:

- **Each event is processed at most once** (unique platform + event id in the database).
- **A valid event is always answered 200**, even when carrying it out failed: the failure is stored
  (with a readable reason) and retried by `php bin/bridge.php retry`, because platforms disable
  endpoints that keep failing. A bad or missing signature is 401, an unknown route 404, a body over 1 MB 413.
- The credentials go **only to the buyer**, by email, in plain text. A mail that cannot be sent is kept and sent by `retry`.
- Nothing secret is logged or answered: no API key, webhook secret or password reaches `var/bridge.log` or a response.
- The credentials of a line are chosen by the bridge and stored only until the panel confirmed
  them (so a retry sends the same ones); the panel's group rules may replace them, the mail always
  shows what the panel finally used.

## Command line

```sh
php bin/bridge.php check            # config, panel connection, package list, secrets, failed events
php bin/bridge.php retry            # run failed events again and send queued mails
php bin/bridge.php map              # print the product map
php bin/bridge.php replay <id>      # run one stored event again (safe: the panel answers a repeated request with the first result)
```

## Shopify

1. In the Shopify admin: **Settings > Notifications > Webhooks > Create webhook** (the "Webhooks"
   section at the bottom of that page). Create three, format **JSON**, URL
   `https://bridge.example.com/hook/shopify`:

   | Event | Topic | Effect |
   | --- | --- | --- |
   | Order payment | `orders/paid` | one provision per line item: SKU / variant / product key x quantity |
   | Refund create | `refunds/create` | revokes the refunded line items and quantities |
   | Order cancellation | `orders/cancelled` | revokes the whole order |

2. Shopify shows a line "Your webhooks will be signed with ..." on that page: copy that key into
   `secrets.shopify`. (For webhooks created by a custom app, use the app's client secret.)
3. Signature: the header `X-Shopify-Hmac-Sha256` is `base64( HMAC-SHA256(raw body, secret) )`,
   compared in constant time on the raw body. Duplicates are recognised by `X-Shopify-Webhook-Id`.
4. Use a SKU on every variant you sell, or map `variant:<id>`.

Customers who check out as guests work: the email comes from the order.

## Upmind

1. Upmind admin > **Settings > Webhooks > Create Webhook**: name, endpoint
   `https://bridge.example.com/hook/upmind`. Then **Add trigger** and tick, in the category
   *Contract product*: *activated*, *renewed*, *suspended*, *unsuspended*, *cancelled*.
2. Copy the endpoint secret Upmind shows into `secrets.upmind`.
3. Signature (documented): header `X-Webhook-Signature` = hex HMAC-SHA256 of the raw body with the
   endpoint secret. The unique `webhook_event_id` is used against duplicates.
4. Products: the key in `products.upmind` is the Upmind product id.
5. **UNVERIFIED** (see the note at the top): the hook codes of renewed / suspended / unsuspended /
   cancelled and the field names of the contract product object. Make sure the codes in your webhook
   log match `upmind.events` in `config.php`; the header name, algorithm and encoding
   (`signature_header`, `signature_algo`, `signature_encoding`) are configurable too.
   The default map is:

   | Hook code | Action |
   | --- | --- |
   | `contract_product_activated_hook` | paid |
   | `contract_product_renewed_hook` | renewed |
   | `contract_product_suspended_hook` | suspended (disable, keep credits) |
   | `contract_product_unsuspended_hook` | restored |
   | `contract_product_cancelled_hook` | revoked (disable, take credits back) |

   `invoice_paid_hook` is deliberately not mapped: it would provision a second time next to
   *activated*. Upmind also lists its IP addresses; you may restrict the endpoint to them in your web server.

## Invoice Ninja

Invoice Ninja v5 webhooks carry **no signature**. They can send custom headers, so the bridge
requires a shared secret in a header.

1. Invoice Ninja: **Settings > Account Management > API Webhooks > New webhook** (names vary by version).
   Target URL `https://bridge.example.com/hook/invoiceninja`, method POST. Add the header
   `X-Bridge-Secret: <a long random string>` (the header name can be changed with
   `invoiceninja.secret_header`).
2. Put the same string into `secrets.invoiceninja`. It is compared in constant time.
3. Event: **Update invoice** (fires when the invoice becomes paid, cancelled or reversed). Optionally
   **Create credit**.
4. The payload is the entity itself, so the bridge reads the action from it:
   - invoice with `status_id` 4 (paid): one provision per invoice line (**product key** x quantity); lines without a product key are skipped
   - `status_id` 5 (cancelled) or 6 (reversed): revoke
   - a credit note with an `invoice_id`: revoke that invoice
5. Duplicate protection: invoice id + status + line position (Invoice Ninja sends no delivery id).
6. Customer email: `client.contacts[].email` (first contact with an email). **UNVERIFIED**: if your
   webhook does not include the client, the event fails with "The webhook carries no customer email
   address" and nothing is created.

A "Create payment" webhook is not used: its payload has no invoice lines.

## Generic webhook

Any system can post a JSON event:

```
POST https://bridge.example.com/hook/generic
X-Bridge-Timestamp: <unix seconds>             rejected when more than 5 minutes off
X-Bridge-Signature: sha256=<hex HMAC-SHA256(raw body, secrets.generic)>
Content-Type: application/json

{"event":"paid","id":"evt-1001","order_id":"1001","item_id":"1","quantity":2,"sku":"plan-a",
 "customer":{"email":"buyer@example.com","name":"Ann Berg"}}
```

- `event`: `paid`, `renewed`, `revoked` or `restored`.
- `id`: unique per event (the same id is processed once). `order_id` + `item_id` identify the purchase;
  the same pair always maps to the same lines.
- `quantity`: units bought (default 1). On `revoked`, a missing `quantity` revokes every unit and a
  missing or `*` `item_id` the whole order. `take_back` (optional, revoke only): `false` disables
  without taking credits back.
- The timestamp header is not part of the signature (the signature covers the raw body only);
  replays inside the five minutes are harmless because every event id is processed once.

```sh
SECRET='the value of secrets.generic'
BODY='{"event":"paid","id":"evt-1001","order_id":"1001","item_id":"1","quantity":2,"sku":"plan-a","customer":{"email":"buyer@example.com","name":"Ann Berg"}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')
curl -i -X POST https://bridge.example.com/hook/generic \
  -H "Content-Type: application/json" \
  -H "X-Bridge-Timestamp: $(date +%s)" \
  -H "X-Bridge-Signature: sha256=$SIG" \
  -d "$BODY"
```

## Troubleshooting

`var/bridge.log` has one line per event; `php bin/bridge.php check` lists failures.
The stored reason of a failed event is one of:

| Message | What to do |
| --- | --- |
| The panel rejected the API key (`INVALID_API_KEY`) | Re-copy the key into `api_key`, then `retry`. |
| The reseller account has not enough credits (`INSUFFICIENT_CREDITS`) | Add credits in the panel, then `retry`. |
| The selected package does not exist or is not available, or is for MAG / Enigma boxes only (`INVALID_PACKAGE`) | Fix `package_id` in the product map (`php bin/bridge.php check` marks box-only packages), then `retry`. |
| This sale was already made and its line has since been deleted on the panel (`REQUEST_ID_SPENT`) | The order has to be placed again (the panel never sells a second line under the same request id). |
| Not a reseller / group may not create sub-resellers (`FORBIDDEN`) | Use a reseller account; check its group permissions. |
| Username or email already taken (`CONFLICT`) | The customer email already has an account in the panel that the bridge did not create; handle it by hand. |
| Could not connect to the panel | Check `api_url`, the certificate and the firewall. |
| The webhook carries no customer email address | The platform did not send the email; see its section. |

HTTP answers: 401 = wrong or missing signature / secret not configured / wrong shared-secret header;
404 = unknown route; 405 = wrong method; 413 = body over 1 MB; 503 = the bridge refuses to run
(unsafe or missing `config.php`; the reason is in the PHP error log).

## Not included

- Taking payments, a storefront, a customer portal, or invoices: the platform does that.
- Password changes and deleting lines or accounts: do those in the panel. Package changes: `change_package` exists in
  the API, but none of the platforms sends an event the bridge could map to it. Revocation only disables, because
  deleting cannot be undone (there is no per-product option for a permanent delete).
- A receiver for the panel's own webhooks (the bridge is the best candidate for one, but it has no consumer for them yet).
- Giving credits back after a *restored* event, partial credit take-back by amount, subscriptions
  that the platform renews without sending an event.
- Several panels or several resellers in one bridge: install one bridge per reseller.
