<?php

declare(strict_types=1);

namespace IvoWmg\Borica;

/**
 * Transaction types.
 *
 * The choice between SALE and PREAUTH shapes the whole integration and cannot
 * be changed later without the bank: SALE takes the money at once, PREAUTH
 * holds it until a COMPLETE follows. A shop charging on dispatch rather than
 * on order wants PREAUTH — and then owes every authorization either a
 * completion or a reversal.
 */
final class Trtype
{
    /** Sale — funds captured immediately. Also the type used for tokenized MIT. */
    public const SALE = '1';

    /** Pre-authorization — funds held, awaiting COMPLETE. */
    public const PREAUTH = '12';

    /** Complete a pre-authorization. Amount may be lower than authorized, never higher. */
    public const COMPLETE = '21';

    /** Reverse a pre-authorization. Must carry the FULL authorized amount. */
    public const REVERSAL = '22';

    /** Refund a completed transaction. May be partial. */
    public const REFUND = '24';

    /** Withdraw a stored credential. */
    public const TOKEN_REVOKE = '82';

    /** Status check. Signed differently from every other type — see [[Signature\SignatureBuilder]]. */
    public const STATUS_CHECK = '90';

    /** Types that move money and travel server-to-server. */
    public const MANAGEMENT = [self::COMPLETE, self::REVERSAL, self::REFUND];

    public static function label(string $trtype): string
    {
        return match ($trtype) {
            self::SALE => 'Sale',
            self::PREAUTH => 'Pre-authorization',
            self::COMPLETE => 'Complete',
            self::REVERSAL => 'Reversal',
            self::REFUND => 'Refund',
            self::TOKEN_REVOKE => 'Token revoke',
            self::STATUS_CHECK => 'Status check',
            default => 'Unknown ('.$trtype.')',
        };
    }
}
