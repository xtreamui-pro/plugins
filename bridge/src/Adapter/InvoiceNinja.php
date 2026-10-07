<?php
/**
 * Invoice Ninja v5 (checked against the invoiceninja/invoiceninja source,
 * app/Jobs/Util/WebhookSingle.php and app/Models/Webhook.php).
 *
 * Invoice Ninja signs nothing. A webhook can carry custom headers, so the
 * bridge requires a shared secret in a header of your choice
 * ("invoiceninja.secret_header", default X-Bridge-Secret), compared in
 * constant time.
 *
 * The body is the entity itself - no event name. The action is read from it:
 *   invoice with status_id 4 (paid)                 -> paid, one event per line item
 *   invoice with status_id 5 (cancelled) / 6 (reversed) -> revoked
 *   credit that has an invoice_id (a credit note)   -> revoked for that invoice
 * so subscribe to "Update invoice" (and optionally "Create credit"). A "Create
 * payment" webhook carries no line items and is not used.
 *
 * UNVERIFIED: the customer's email is read from client.contacts[].email
 * (needs the client in the payload; Invoice Ninja includes it for invoice
 * events as far as the source shows).
 */

declare(strict_types=1);

namespace XtreamPro\Bridge\Adapter;

class InvoiceNinja extends BaseAdapter
{
    private const PAID = 4;
    private const CANCELLED = 5;
    private const REVERSED = 6;

    public function name(): string
    {
        return 'invoiceninja';
    }

    public function verify(array $headers, string $rawBody): bool
    {
        $secret = $this->secret();
        $given = self::header($headers, $this->config->string('invoiceninja.secret_header', 'X-Bridge-Secret'));
        if ($secret === '' || $given === '') {
            return false;
        }
        return hash_equals($secret, $given);
    }

    public function events(array $payload, array $headers = []): array
    {
        $id = self::str($payload['id'] ?? '');
        if ($id === '') {
            return [];
        }
        $status = (int) ($payload['status_id'] ?? 0);

        // A credit note created from an invoice takes that invoice back.
        $invoiceId = self::str($payload['invoice_id'] ?? '');
        if (array_key_exists('invoice_id', $payload)) {
            if ($invoiceId === '') {
                return [];
            }
            return [self::event('revoked', ['order_id' => $invoiceId, 'item_id' => '*', 'id' => 'credit-' . $id])];
        }

        if ($status === self::CANCELLED || $status === self::REVERSED) {
            return [self::event('revoked', ['order_id' => $id, 'item_id' => '*', 'id' => 'inv-' . $id . '-s' . $status])];
        }
        if ($status !== self::PAID || !is_array($payload['line_items'] ?? null)) {
            return [];
        }

        [$email, $name] = $this->customer($payload);
        $out = [];
        foreach ($payload['line_items'] as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            $key = self::str($line['product_key'] ?? '');
            if ($key === '' || (float) ($line['quantity'] ?? 1) <= 0) {
                continue;
            }
            $out[] = self::event('paid', [
                'order_id' => $id,
                'item_id'  => (string) $index,
                'quantity' => self::qty($line['quantity'] ?? 1),
                'sku'      => $key,
                'email'    => $email,
                'name'     => $name,
                'id'       => 'inv-' . $id . '-s' . $status . '-' . $index,
            ]);
        }
        return $out;
    }

    /** @return array{0:string,1:string} email, name */
    private function customer(array $invoice): array
    {
        $client = is_array($invoice['client'] ?? null) ? $invoice['client'] : [];
        $email = '';
        $name = self::str($client['name'] ?? '');
        foreach (is_array($client['contacts'] ?? null) ? $client['contacts'] : [] as $contact) {
            if (is_array($contact) && self::str($contact['email'] ?? '') !== '') {
                $email = self::str($contact['email']);
                if ($name === '') {
                    $name = trim(self::str($contact['first_name'] ?? '') . ' ' . self::str($contact['last_name'] ?? ''));
                }
                break;
            }
        }
        return [$email, $name];
    }
}
