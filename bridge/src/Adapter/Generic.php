<?php
/**
 * Generic webhook any system can post (see README, "Generic webhook").
 *
 *   X-Bridge-Signature: sha256=<hex HMAC-SHA256(raw body, secret)>
 *   X-Bridge-Timestamp: <unix seconds>   (rejected when older / newer than 5 minutes)
 *   body: {"event":"paid|renewed|revoked|restored","order_id":"..","item_id":"..","quantity":1,
 *          "sku":"..","customer":{"email":"..","name":".."},"id":"<unique event id>",
 *          "take_back":true}            (take_back is optional, revoke only)
 */

declare(strict_types=1);

namespace XtreamPro\Bridge\Adapter;

class Generic extends BaseAdapter
{
    public const MAX_AGE = 300;

    public function name(): string
    {
        return 'generic';
    }

    public function verify(array $headers, string $rawBody): bool
    {
        $secret = $this->secret();
        $given = self::header($headers, 'x-bridge-signature');
        $stamp = self::header($headers, 'x-bridge-timestamp');
        if ($secret === '' || $given === '' || $stamp === '' || !ctype_digit($stamp)) {
            return false;
        }
        if (abs(time() - (int) $stamp) > self::MAX_AGE) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $given);
    }

    public function events(array $payload, array $headers = []): array
    {
        $type = self::str($payload['event'] ?? '');
        $id = self::str($payload['id'] ?? '');
        $orderId = self::str($payload['order_id'] ?? '');
        if (!in_array($type, ['paid', 'renewed', 'revoked', 'restored'], true) || $id === '' || $orderId === '') {
            return [];
        }
        $itemId = self::str($payload['item_id'] ?? '');
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $hasQuantity = isset($payload['quantity']) && is_numeric($payload['quantity']);
        return [self::event($type, [
            'order_id'  => $orderId,
            'item_id'   => $itemId !== '' ? $itemId : ($type === 'revoked' || $type === 'restored' ? '*' : '1'),
            'quantity'  => $hasQuantity ? self::qty($payload['quantity']) : ($type === 'paid' || $type === 'renewed' ? 1 : null),
            'sku'       => self::str($payload['sku'] ?? ''),
            'email'     => self::str($customer['email'] ?? ''),
            'name'      => self::str($customer['name'] ?? ''),
            'id'        => $id,
            'take_back' => ($payload['take_back'] ?? true) !== false,
        ])];
    }
}
