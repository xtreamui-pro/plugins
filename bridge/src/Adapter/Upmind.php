<?php
/**
 * Upmind (docs.upmind.com > Developers > Webhooks).
 *
 * VERIFIED against the docs:
 *   - header X-Webhook-Signature = hex HMAC-SHA256(raw body, endpoint secret)
 *   - the body is JSON: webhook_event_id (unique, use it against duplicates),
 *     hook_code, hook_category, object, version
 *   - hook codes `contract_product_activated_hook` and `invoice_payment_received_hook`
 *     appear in Upmind's own PHP example; `invoice_paid_hook` in the docs text
 *
 * UNVERIFIED (the docs list the trigger NAMES, not their hook codes or the
 * fields of a contract product object):
 *   - the hook codes of "renewed", "suspended", "unsuspended" and "cancelled"
 *     follow the pattern of the verified ones (contract_product_<name>_hook)
 *   - the product id / quantity / client email are read from the places the
 *     contract product object most likely has them (see PRODUCT_PATHS, EMAIL_PATHS)
 * Everything unverified is configurable: "upmind.events" (hook code -> action),
 * "upmind.signature_header", "upmind.signature_algo", "upmind.signature_encoding".
 * Check a real delivery in Upmind (Logs > Webhook events log) and adjust config.php.
 */

declare(strict_types=1);

namespace XtreamPro\Bridge\Adapter;

class Upmind extends BaseAdapter
{
    /** hook code => action: paid | renewed | revoked | suspended | restored */
    public const DEFAULT_EVENTS = [
        'contract_product_activated_hook'    => 'paid',
        'contract_product_renewed_hook'      => 'renewed',
        'contract_product_suspended_hook'    => 'suspended',
        'contract_product_unsuspended_hook'  => 'restored',
        'contract_product_cancelled_hook'    => 'revoked',
    ];

    /** UNVERIFIED: where the product id is looked for in the contract product object. */
    private const PRODUCT_PATHS = ['product_id', 'product.id', 'product.code', 'package_id', 'package.id'];

    /** UNVERIFIED: where the client's email is looked for. */
    private const EMAIL_PATHS = ['client.email', 'client.login_email', 'client.notification_email', 'client_email', 'email'];

    public function name(): string
    {
        return 'upmind';
    }

    public function verify(array $headers, string $rawBody): bool
    {
        $secret = $this->secret();
        $header = strtolower($this->config->string('upmind.signature_header', 'X-Webhook-Signature'));
        $algo = strtolower($this->config->string('upmind.signature_algo', 'sha256'));
        $encoding = $this->config->string('upmind.signature_encoding', 'hex');
        $given = self::header($headers, $header);
        if ($secret === '' || $given === '' || !in_array($algo, hash_hmac_algos(), true)) {
            return false;
        }
        $expected = $encoding === 'base64'
            ? base64_encode(hash_hmac($algo, $rawBody, $secret, true))
            : hash_hmac($algo, $rawBody, $secret);
        return hash_equals($expected, $given);
    }

    public function events(array $payload, array $headers = []): array
    {
        $map = $this->config->get('upmind.events', self::DEFAULT_EVENTS);
        $action = is_array($map) ? ($map[self::str($payload['hook_code'] ?? '')] ?? null) : null;
        $object = is_array($payload['object'] ?? null) ? $payload['object'] : [];
        $id = self::str($payload['webhook_event_id'] ?? '');
        $contractId = self::str($object['id'] ?? '');
        if (!is_string($action) || $id === '' || $contractId === '') {
            return [];
        }
        $type = $action === 'suspended' ? 'revoked' : $action;
        if (!in_array($type, ['paid', 'renewed', 'revoked', 'restored'], true)) {
            return [];
        }

        $keys = [];
        foreach (self::PRODUCT_PATHS as $path) {
            $v = self::str(self::dig($object, ...explode('.', $path)));
            if ($v !== '') {
                $keys[] = $v;
            }
        }
        $email = '';
        foreach (self::EMAIL_PATHS as $path) {
            $email = self::str(self::dig($object, ...explode('.', $path)));
            if ($email !== '') {
                break;
            }
        }
        $name = self::str(self::dig($object, 'client', 'fullname')) ?: self::str(self::dig($object, 'client', 'full_name'));
        $isRevoke = $type === 'revoked' || $type === 'restored';

        return [self::event($type, [
            'order_id'  => $contractId,
            'item_id'   => $isRevoke ? '*' : $contractId,
            'quantity'  => $isRevoke ? null : self::qty($object['quantity'] ?? 1),
            'sku'       => $keys[0] ?? '',
            'alt_keys'  => array_slice($keys, 1),
            'email'     => $email,
            'name'      => $name,
            'id'        => $id,
            'take_back' => $action !== 'suspended',
        ])];
    }
}
