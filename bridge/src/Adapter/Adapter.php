<?php
/**
 * One adapter per platform: checks that a webhook really comes from the
 * platform, and turns its payload into normalized events.
 *
 * A normalized event is an array:
 *   type      paid | renewed | revoked | restored
 *   order_id  the platform's order / invoice / contract id (string)
 *   item_id   the order item (string); "*" on a revoke = every item of the order
 *   quantity  units bought (int >= 1); null on a revoke = every unit
 *   sku       product key used to find the product in config "products"
 *   alt_keys  more keys to try when `sku` is not in the map (e.g. "variant:123")
 *   email     customer email ("" when the platform does not send it)
 *   name      customer name
 *   id        idempotency id, unique per platform: an event with this id is processed once
 *   take_back revoke only: true takes the unspent credits of reseller products back
 */

declare(strict_types=1);

namespace XtreamPro\Bridge\Adapter;

interface Adapter
{
    /** Short name used in the URL (/hook/<name>) and in request ids. */
    public function name(): string;

    /**
     * Constant-time check on the RAW body. Must return false when the
     * platform's secret is not configured.
     *
     * @param array<string,string> $headers lower-case header name => value
     */
    public function verify(array $headers, string $rawBody): bool;

    /**
     * @param array $payload decoded JSON body
     * @param array<string,string> $headers lower-case header name => value
     * @return array[] normalized events (empty = nothing to do for this topic)
     */
    public function events(array $payload, array $headers = []): array;
}
