<?php
/**
 * Shopify (checked against shopify.dev, "HTTPS webhook subscriptions").
 *
 * Signature: header X-Shopify-Hmac-Sha256 = base64( HMAC-SHA256(raw body, secret) ).
 * The secret is the signing secret Shopify shows for webhooks created in the
 * admin (Settings > Notifications > Webhooks), or the client secret of a custom app.
 * Delivery id: X-Shopify-Webhook-Id (the same on every retry of one delivery).
 *
 * Topics:
 *   orders/paid       one `paid` event per line item (sku / variant / product key x quantity)
 *   refunds/create    one `revoked` event per refunded line item and quantity
 *   orders/cancelled  one `revoked` event for the whole order
 */

declare(strict_types=1);

namespace XtreamPro\Bridge\Adapter;

class Shopify extends BaseAdapter
{
    public function name(): string
    {
        return 'shopify';
    }

    public function verify(array $headers, string $rawBody): bool
    {
        $secret = $this->secret();
        $given = self::header($headers, 'x-shopify-hmac-sha256');
        if ($secret === '' || $given === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        return hash_equals($expected, $given);
    }

    public function events(array $payload, array $headers = []): array
    {
        $topic = self::header($headers, 'x-shopify-topic');
        $delivery = self::header($headers, 'x-shopify-webhook-id');
        switch ($topic) {
            case 'orders/paid':
                return $this->paid($payload, $delivery);
            case 'orders/cancelled':
                return $this->cancelled($payload, $delivery);
            case 'refunds/create':
                return $this->refunded($payload, $delivery);
        }
        return [];
    }

    private function paid(array $order, string $delivery): array
    {
        $orderId = self::str($order['id'] ?? '');
        if ($orderId === '' || !is_array($order['line_items'] ?? null)) {
            return [];
        }
        $email = self::str($order['email'] ?? '') ?: self::str(self::dig($order, 'customer', 'email'));
        $name = trim(self::str(self::dig($order, 'customer', 'first_name')) . ' ' . self::str(self::dig($order, 'customer', 'last_name')));
        $out = [];
        foreach ($order['line_items'] as $item) {
            if (!is_array($item) || !isset($item['id']) || (int) ($item['quantity'] ?? 1) < 1) {
                continue;
            }
            $itemId = self::str($item['id']);
            $out[] = self::event('paid', [
                'order_id' => $orderId,
                'item_id'  => $itemId,
                'quantity' => self::qty($item['quantity'] ?? 1),
                'sku'      => self::str($item['sku'] ?? ''),
                'alt_keys' => ['variant:' . self::str($item['variant_id'] ?? ''), 'product:' . self::str($item['product_id'] ?? '')],
                'email'    => $email,
                'name'     => $name,
                'id'       => ($delivery !== '' ? $delivery : 'paid-' . $orderId) . ':' . $itemId,
            ]);
        }
        return $out;
    }

    private function cancelled(array $order, string $delivery): array
    {
        $orderId = self::str($order['id'] ?? '');
        if ($orderId === '') {
            return [];
        }
        return [self::event('revoked', [
            'order_id' => $orderId,
            'item_id'  => '*',
            'id'       => ($delivery !== '' ? $delivery : 'cancelled-' . $orderId) . ':*',
        ])];
    }

    private function refunded(array $refund, string $delivery): array
    {
        $orderId = self::str($refund['order_id'] ?? '');
        $rows = $refund['refund_line_items'] ?? null;
        if ($orderId === '' || !is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['line_item_id'])) {
                continue;
            }
            $itemId = self::str($row['line_item_id']);
            $out[] = self::event('revoked', [
                'order_id' => $orderId,
                'item_id'  => $itemId,
                'quantity' => self::qty($row['quantity'] ?? 1),
                'id'       => ($delivery !== '' ? $delivery : 'refund-' . self::str($refund['id'] ?? $orderId)) . ':' . $itemId,
            ]);
        }
        return $out;
    }
}
