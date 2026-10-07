<?php
/**
 * Xtream UI Pro webhook bridge - configuration.
 *
 * Copy to config.php (next to public/, NEVER inside it) and run:
 *   chmod 600 config.php
 * The bridge refuses to start when config.php is readable by other users or
 * sits inside public/.
 */

return [
    // Address of the panel's API (cmd/api) and the reseller's own API key
    // (panel dashboard > /api-key). Use https.
    'api_url' => 'https://api.example.com',
    'api_key' => '',

    // Optional: dashboard address, shown in the mail to a new sub-reseller.
    'panel_url' => '',

    // Webhook secrets, one per platform. An empty secret = that endpoint
    // answers 401 to everything (switched off).
    'secrets' => [
        'shopify'      => '', // Shopify: the webhook signing secret
        'upmind'       => '', // Upmind: the endpoint secret shown on the webhook
        'invoiceninja' => '', // Invoice Ninja: the value of the custom header (see below)
        'generic'      => '', // your own system: any long random string
    ],

    // Upmind: the header and algorithm are confirmed in Upmind's docs; the
    // hook codes of renew / suspend / unsuspend / cancel are NOT (see README),
    // so all of this can be changed here.
    'upmind' => [
        'signature_header'   => 'X-Webhook-Signature',
        'signature_algo'     => 'sha256',
        'signature_encoding' => 'hex', // or base64
        'events' => [
            'contract_product_activated_hook'   => 'paid',
            'contract_product_renewed_hook'      => 'renewed',
            'contract_product_suspended_hook'    => 'suspended', // disables, keeps the credits
            'contract_product_unsuspended_hook'  => 'restored',
            'contract_product_cancelled_hook'    => 'revoked',   // disables, takes unspent credits back
        ],
    ],

    // Invoice Ninja signs nothing: it sends the secret in a custom header.
    'invoiceninja' => [
        'secret_header' => 'X-Bridge-Secret',
    ],

    // What each product of the shop sells. Per platform, the key is:
    //   Shopify         the variant's SKU, or "variant:<variant id>", or "product:<product id>"
    //   Upmind          the Upmind product id
    //   Invoice Ninja   the product key of the invoice line
    //   generic         the "sku" you send
    // type "line":     one IPTV line per unit bought, with package_id (see `bin/bridge.php check`)
    //                  and trial (true = trial line)
    // type "reseller": a sub-reseller account for the customer (created once per email address);
    //                  credits = credits handed over per unit bought, renew_credits = per renewal
    'products' => [
        'shopify' => [
            'IPTV-12M' => ['type' => 'line', 'package_id' => 3],
            'IPTV-TRIAL' => ['type' => 'line', 'package_id' => 3, 'trial' => true],
            'RESELLER-100' => ['type' => 'reseller', 'credits' => 100],
        ],
        'upmind' => [
            // 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' => ['type' => 'line', 'package_id' => 3],
        ],
        'invoiceninja' => [
            // 'iptv-12m' => ['type' => 'line', 'package_id' => 3],
        ],
        'generic' => [
            // 'plan-a' => ['type' => 'line', 'package_id' => 3],
        ],
    ],

    // How the buyer gets the credentials. They are never sent to anyone else.
    'mail' => [
        'transport' => 'mail',                 // mail (PHP mail()), smtp
        'from'      => 'noreply@example.com',  // must be an address your server may send from
        'smtp' => [
            'host'       => 'smtp.example.com',
            'port'       => 587,
            'encryption' => 'tls',             // tls (STARTTLS), ssl, none
            'user'       => '',
            'password'   => '',
        ],
    ],

    // Where the database and the log live. Default: var/ next to public/.
    // 'data_dir' => '/var/lib/xtreampro-bridge',
];
