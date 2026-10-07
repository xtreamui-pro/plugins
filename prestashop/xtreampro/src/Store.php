<?php
/**
 * Where the provisioner keeps what it did. PLATFORM INDEPENDENT.
 *
 * The PrestaShop module implements this with its own database tables
 * (classes/DbStore.php); the end-to-end harness implements it in memory.
 *
 * A "unit" is one bought item: order detail id + unit number (a customer who
 * orders quantity 3 gets units 1, 2 and 3). Fields of a unit row:
 *
 *   order_id, customer_id   ints, for display
 *   kind                    'line' or 'reseller'
 *   status                  'ok' | 'error' | 'suspended' | 'revoked'
 *   panel_id                line id (digits) or sub-reseller UUID in the panel
 *   username, password      credentials to show the buyer ('' when not to be shown)
 *   links                   array of play links as the panel returned them (lines)
 *   credits                 credits handed over by this unit (reseller units)
 *   generation              counts revocations; part of the credit transfer request ids
 *   renewals                counts successful renewals; part of the renew request id
 *   error                   text of the last problem, '' when there is none
 *
 * Fields of an account row (the one sub-reseller account of a customer):
 *
 *   panel_user_id           UUID in the panel, '' until the panel confirmed the creation
 *   username, password      what the account was (or is about to be) created with
 *   origin                  "<detail id>-<unit>" of the unit that created the account
 *   disabled                1 while this module keeps the account disabled
 */
interface XtreamproStore
{
    /** @return array|null */
    public function getUnit($detailId, $unit);

    /** Insert or update: only the given fields change. */
    public function saveUnit($detailId, $unit, array $fields);

    /** @return array|null */
    public function getAccount($customerId);

    /** Insert or update: only the given fields change. */
    public function saveAccount($customerId, array $fields);
}
