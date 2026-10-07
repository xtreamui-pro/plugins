<?php
/**
 * Xtream UI Pro - verification of the webhooks the panel pushes.
 *
 * The panel posts JSON {id, type, created, data} with the headers
 *   X-Xtream-Timestamp: <unix seconds>
 *   X-Xtream-Signature: sha256=<hex HMAC-SHA256(secret, timestamp + "." + body)>
 * Nothing in the body is looked at before the signature has been verified.
 */

namespace XtreamPro\Whmcs;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

class Webhook
{
    /** Seconds a delivery may differ from this server's clock. The panel stamps every attempt anew. */
    const TOLERANCE = 300;

    /** Bytes of body accepted; the panel's events are a few hundred. */
    const MAX_BODY = 65536;

    /**
     * @param string   $body      Raw request body
     * @param string   $timestamp X-Xtream-Timestamp header
     * @param string   $signature X-Xtream-Signature header
     * @param string[] $secrets   Signing secrets of the registered endpoints
     * @param int|null $now       Clock override for tests
     * @return array {status:int, message:string, event:array|null}; the event only when status is 200
     */
    public static function receive($body, $timestamp, $signature, array $secrets, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        if (!$secrets) {
            return self::refuse(503, 'no webhook secret is registered');
        }
        if (strlen($body) > self::MAX_BODY) {
            return self::refuse(413, 'body too large');
        }
        if (!ctype_digit($timestamp) || strlen($timestamp) > 12) {
            return self::refuse(400, 'missing or invalid timestamp');
        }
        if (abs($now - (int) $timestamp) > self::TOLERANCE) {
            return self::refuse(400, 'timestamp outside the accepted window');
        }
        if (strpos($signature, 'sha256=') !== 0) {
            return self::refuse(401, 'missing signature');
        }
        $given = substr($signature, 7);
        $valid = false;
        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $timestamp . '.' . $body, (string) $secret);
            // hash_equals compares in constant time; every secret is tried so timing does not tell which one is right.
            if (hash_equals($expected, $given)) {
                $valid = true;
            }
        }
        if (!$valid) {
            return self::refuse(401, 'invalid signature');
        }
        $event = json_decode($body, true);
        if (!is_array($event) || empty($event['type']) || !is_string($event['type'])) {
            return self::refuse(400, 'unreadable event');
        }
        return array('status' => 200, 'message' => 'ok', 'event' => $event);
    }

    /**
     * The event's own id ("evt_..."): the panel keeps it the same on every retry, so a receiver
     * de-duplicates on it. '' when the event carries none (an older panel) or one of an unexpected shape.
     */
    public static function eventId(array $event)
    {
        return isset($event['id']) && is_string($event['id']) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $event['id'])
            ? $event['id'] : '';
    }

    private static function refuse($status, $message)
    {
        return array('status' => $status, 'message' => $message, 'event' => null);
    }
}
