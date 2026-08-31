<?php

declare(strict_types=1);

namespace IvoWmg\Borica;

/**
 * RC values, taken from the official code list (P-OM-41).
 *
 * Two distinct families arrive in the same field. Negative codes come from the
 * gateway and describe the request; two-digit codes come from the issuer
 * through the acquirer and describe the card. "00" is the only success.
 *
 * The classification below matters more than the text. A code that means
 * "still running" answered as a decline cancels an order whose authorization
 * then succeeds a minute later, leaving money held against a cancelled order —
 * the single most damaging mistake available in this protocol.
 */
final class ResponseCode
{
    public const SUCCESS = '00';

    /**
     * The customer is still on the payment form or at their bank. Not a
     * decline: nothing may be cancelled while one of these stands.
     *
     * Note that -24 is NOT here, though it is easy to assume it belongs. Per
     * the official list -24 is "transaction context data mismatch" — a wrong
     * currency, or a transaction older than 24 hours. Treating it as
     * in-progress leaves a genuinely broken payment pending forever.
     */
    public const IN_PROGRESS = ['-31', '-33', '-40'];

    /**
     * The gateway says this transaction already ran. It may well have
     * succeeded: never cancel on this code alone, ask for status first.
     */
    public const DUPLICATE = ['-21', '-32'];

    /** @var array<string, string> */
    private const DESCRIPTIONS = [
        '00' => 'Approved',
        '-1' => 'A mandatory request field is not filled in',
        '-2' => 'CGI request validation failed',
        '-3' => 'Acquirer host does not respond, or bad response template',
        '-4' => 'No connection to the acquirer host',
        '-5' => 'Acquirer host connection failed during processing',
        '-6' => 'e-Gateway configuration error',
        '-7' => 'Acquirer host response is invalid',
        '-10' => 'Error in the AMOUNT field',
        '-11' => 'Error in the CURRENCY field',
        '-12' => 'Error in the MERCHANT field',
        '-13' => 'Unexpected referrer IP address',
        '-15' => 'Error in the RRN field',
        '-16' => 'Another transaction is running on this terminal',
        '-17' => 'Terminal denied access to e-Gateway (typically a P_SIGN failure)',
        '-19' => 'Error in the authentication request, or authentication failed',
        '-20' => 'TIMESTAMP too far from gateway time (1 hour by default)',
        '-21' => 'The transaction has already been executed',
        '-22' => 'Transaction contains invalid authentication information',
        '-23' => 'Invalid transaction context',
        '-24' => 'Transaction context data mismatch (wrong currency, or older than 24h)',
        '-25' => 'Confirmation cancelled by the cardholder',
        '-26' => 'Invalid action BIN',
        '-27' => 'Invalid merchant name',
        '-28' => 'Invalid incoming addendum',
        '-29' => 'Invalid or duplicate authentication reference',
        '-30' => 'Transaction declined as fraud',
        '-31' => 'Transaction already in progress',
        '-32' => 'Duplicate declined transaction',
        '-33' => 'Cardholder authentication in progress',
        '-40' => 'Client side transaction form in progress',
    ];

    public static function isSuccess(string $rc): bool
    {
        return $rc === self::SUCCESS;
    }

    public static function isInProgress(string $rc): bool
    {
        return in_array($rc, self::IN_PROGRESS, true);
    }

    public static function isDuplicate(string $rc): bool
    {
        return in_array($rc, self::DUPLICATE, true);
    }

    /**
     * A definitive refusal: neither success, nor still running, nor a duplicate
     * that needs asking about. Only these may cancel an order.
     */
    public static function isFinalFailure(string $rc): bool
    {
        return ! self::isSuccess($rc)
            && ! self::isInProgress($rc)
            && ! self::isDuplicate($rc);
    }

    /** True when the code came from the issuer rather than the gateway. */
    public static function isIssuerCode(string $rc): bool
    {
        return $rc !== self::SUCCESS && ctype_digit($rc);
    }

    public static function describe(string $rc): string
    {
        if (isset(self::DESCRIPTIONS[$rc])) {
            return self::DESCRIPTIONS[$rc];
        }

        if (self::isIssuerCode($rc)) {
            return 'Declined by the card issuer (code '.$rc.')';
        }

        return 'Unknown response code '.$rc;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::DESCRIPTIONS;
    }
}
