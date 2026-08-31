<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Signature;

use IvoWmg\Borica\Trtype;

/**
 * MAC_GENERAL — the source string Borica signs and verifies.
 *
 * Field order comes from Table 10 of P-OM-41 v5.0. Each field contributes its
 * byte length followed by its value; an empty field contributes a bare "-".
 *
 * The last element is the one that costs people weeks. The specification calls
 * it RFU and says its value "in the symbol string is one byte 0x2D (minus
 * sign)" — so for an ordinary payment it is a literal dash, never a
 * length-prefixed empty, and it never travels in the request itself.
 *
 * But that same slot carries MERCH_TOKEN_ID once tokenization is in play, and
 * then it is length-prefixed like any other field. A merchant-initiated
 * transaction signed with the bare dash that works everywhere else comes back
 * RC -17 — "terminal denied access" — which reads as a certificate problem and
 * sends people to the wrong place entirely.
 *
 *   no token   …{NONCE}-                     (one-off payment, or the initial
 *                                             customer-initiated transaction
 *                                             that has no token yet)
 *   token      …{NONCE}36ABCDEF-1234-…       (every subsequent MIT)
 *
 * TRTYPE 90 is the exception, and only on the request side: four fields, no
 * tail at all. Its response is verified exactly like every other response.
 */
final class SignatureBuilder
{
    /** The RFU slot when nothing occupies it: one literal dash. */
    private const RFU = '-';

    /** Signed on a registration or management request (TRTYPE 1, 12, 21, 22, 24). */
    public const REQUEST_FIELDS = [
        'TERMINAL', 'TRTYPE', 'AMOUNT', 'CURRENCY', 'ORDER', 'TIMESTAMP', 'NONCE',
    ];

    /** Verified on every response, TRTYPE 90 included. */
    public const RESPONSE_FIELDS = [
        'ACTION', 'RC', 'APPROVAL', 'TERMINAL', 'TRTYPE', 'AMOUNT', 'CURRENCY',
        'ORDER', 'RRN', 'INT_REF', 'PARES_STATUS', 'ECI', 'TIMESTAMP', 'NONCE',
    ];

    /** Signed on a TRTYPE 90 status request. Note the absence of AMOUNT and RFU. */
    public const STATUS_REQUEST_FIELDS = ['TERMINAL', 'TRTYPE', 'ORDER', 'NONCE'];

    /**
     * Source string for a request signature.
     *
     * @param  array<string, string|int|null>  $data
     * @param  string|null  $merchTokenId  The stored credential, on a
     *                                     merchant-initiated transaction only.
     */
    public static function requestSource(array $data, ?string $merchTokenId = null): string
    {
        // A status check is a different shape, not a variation on this one.
        if ((string) ($data['TRTYPE'] ?? '') === Trtype::STATUS_CHECK) {
            return self::statusRequestSource($data);
        }

        return self::concat($data, self::REQUEST_FIELDS).self::tail($merchTokenId);
    }

    /**
     * Source string for verifying any response, TRTYPE 90 included.
     *
     * The token is read from the response itself: the gateway echoes back the
     * MERCH_TOKEN_ID it acted on, and a response that carries one must be
     * verified with it in the tail.
     *
     * @param  array<string, string|int|null>  $data
     */
    public static function responseSource(array $data): string
    {
        $token = $data['MERCH_TOKEN_ID'] ?? null;

        return self::concat($data, self::RESPONSE_FIELDS)
            .self::tail($token === null ? null : (string) $token);
    }

    /**
     * Source string for a TRTYPE 90 status request — no AMOUNT, no tail.
     *
     * @param  array<string, string|int|null>  $data
     */
    public static function statusRequestSource(array $data): string
    {
        return self::concat($data, self::STATUS_REQUEST_FIELDS);
    }

    /**
     * The eighth element: a bare dash, or the token length-prefixed.
     *
     * A literal "-" counts as absent. Passing the dash through as a one-byte
     * value would produce "1-" and fail, which is exactly what happens when a
     * default of '-' is threaded into the length-prefixing branch by accident.
     */
    private static function tail(?string $merchTokenId): string
    {
        if ($merchTokenId === null || $merchTokenId === '' || $merchTokenId === self::RFU) {
            return self::RFU;
        }

        return strlen($merchTokenId).$merchTokenId;
    }

    /**
     * @param  array<string, string|int|null>  $data
     * @param  list<string>  $fields
     */
    private static function concat(array $data, array $fields): string
    {
        $source = '';

        foreach ($fields as $field) {
            $value = (string) ($data[$field] ?? '');

            // strlen, not mb_strlen: the prefix counts bytes. Every signed field
            // is ASCII by protocol, so the two agree today — but a field that
            // ever carried a multi-byte character would sign a wrong length and
            // fail verification with nothing in the response to explain it.
            $source .= $value === '' ? self::RFU : strlen($value).$value;
        }

        return $source;
    }
}
