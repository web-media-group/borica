<?php

declare(strict_types=1);

namespace IvoWmg\Borica;

use InvalidArgumentException;

/**
 * The ORDER field holds exactly six digits.
 *
 * Every shop outgrows that. Order 2 000 035 goes to the gateway as "000035",
 * and the callback comes back carrying only those six digits — so the shop has
 * to find its own order again from a number that is no longer unique.
 *
 * Truncation is safe in practice only because the gateway reconciles the last
 * 30 days: two orders can share a remainder, but not within one window unless
 * the shop takes a million orders a month. Any lookup must therefore be scoped
 * by time, and must prefer the most recent match — a bare `where(id, 35)` finds
 * the wrong order the moment ids pass a million.
 *
 * A shop numbering by something other than a sequential id (gift cards, say)
 * should not derive the reference from that id at all — see [[allocate]].
 */
final class OrderReference
{
    public const LENGTH = 6;

    public const MODULUS = 1000000;

    /** The gateway reconciles roughly this far back; scope lookups to it. */
    public const RECONCILIATION_DAYS = 30;

    /**
     * Format a numeric id for the ORDER field, keeping the last six digits.
     */
    public static function fromId(int $id): string
    {
        if ($id < 0) {
            throw new InvalidArgumentException('Order id cannot be negative.');
        }

        return str_pad((string) ($id % self::MODULUS), self::LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * The remainder to match ids against when a callback arrives.
     *
     * Use it as `WHERE id % 1000000 = ? AND created_at >= ? ORDER BY id DESC`,
     * never as a direct id.
     */
    public static function toRemainder(string $order): int
    {
        return (int) ltrim($order, '0') ?: 0;
    }

    /**
     * A reference that does not encode a sequential id.
     *
     * Six digits give a million values; collisions are certain over time, so
     * whatever stores this must carry a unique index and the caller must retry
     * on violation. Deriving the reference from a row id instead looks simpler
     * and is the reason two records can end up sharing one payment.
     */
    public static function allocate(): string
    {
        return str_pad((string) random_int(0, self::MODULUS - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    public static function isValid(string $order): bool
    {
        return preg_match('/^\d{'.self::LENGTH.'}$/', $order) === 1;
    }
}
