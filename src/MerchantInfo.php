<?php

declare(strict_types=1);

namespace IvoWmg\Borica;

use IvoWmg\Borica\Support\Latin;

/**
 * M_INFO — the 3DS cardholder data, base64 JSON.
 *
 * Mastercard requirement 209 made the address fields mandatory, and getting
 * them wrong is answered by the directory server, not by Borica, so the RC is
 * unhelpful in both directions: -19 for a rejected value, -17 once the request
 * itself stops validating. Two independent shops arrived at the same shape
 * only after a long sequence of add-field, fail, remove-field, fail:
 *
 *   email, cardholderName, billAddrLine1, shipAddrLine1
 *
 * Additional keys are accepted by the JSON but have repeatedly caused
 * rejections in practice, so this builder keeps to the four and lets a caller
 * add more deliberately rather than by default.
 *
 * Every value is transliterated and stripped to ASCII: Cyrillic in these
 * fields is refused outright.
 */
final class MerchantInfo
{
    public const CARDHOLDER_NAME_MAX = 45;

    public const ADDRESS_MAX = 50;

    public const EMAIL_MAX = 254;

    /** @var array<string, string> */
    private array $extra = [];

    public function __construct(
        private readonly string $email,
        private readonly string $cardholderName,
        private readonly string $billingAddress,
        private readonly ?string $shippingAddress = null,
    ) {}

    /**
     * Add a field beyond the four. Do this only with a reason: each one is a
     * new way for the directory server to refuse the transaction.
     */
    public function with(string $key, string $value): self
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;

        return $clone;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $shipping = $this->shippingAddress ?? $this->billingAddress;

        return array_filter([
            'email' => Latin::convert($this->email, self::EMAIL_MAX),
            'cardholderName' => Latin::convert($this->cardholderName, self::CARDHOLDER_NAME_MAX),
            'billAddrLine1' => Latin::convert($this->billingAddress, self::ADDRESS_MAX),
            'shipAddrLine1' => Latin::convert($shipping, self::ADDRESS_MAX),
        ] + $this->extra, static fn (string $v): bool => $v !== '');
    }

    /** The value for the M_INFO request field. */
    public function encode(): string
    {
        return base64_encode(json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Read an M_INFO back — for logs, where base64 helps nobody. */
    public static function decode(string $encoded): array
    {
        $json = base64_decode($encoded, true);

        if ($json === false) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
