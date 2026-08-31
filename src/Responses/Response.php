<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Responses;

use IvoWmg\Borica\ResponseCode;

/**
 * A gateway reply — from a callback POST or a server-to-server call.
 *
 * Deliberately does not decide what should happen to an order. Success here
 * means "the gateway approved this transaction", which is not the same as
 * "authorize the order": that also needs the amount to match what was asked
 * for, and only the caller knows what that was. See [[matches]].
 */
final class Response
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly array $data,
        private readonly bool $verified,
    ) {}

    /** Whether P_SIGN checked out. Nothing below means anything without this. */
    public function isVerified(): bool
    {
        return $this->verified;
    }

    /**
     * Approved.
     *
     * ACTION 1 means duplicate, and a duplicate of a successful transaction is
     * itself successful — the money moved. Treating it as failure cancels a
     * paid order.
     */
    public function isSuccess(): bool
    {
        return $this->verified
            && in_array($this->action(), ResponseCode::ACTION_SUCCESS, true)
            && ResponseCode::isSuccess($this->rc());
    }

    /** The customer is still at their bank. Nothing may be cancelled. */
    public function isInProgress(): bool
    {
        return ResponseCode::isInProgress($this->rc());
    }

    /** The gateway says this already ran; ask for status before deciding. */
    public function isDuplicate(): bool
    {
        return ResponseCode::isDuplicate($this->rc());
    }

    /** A refusal that will not change. The only case where cancelling is safe. */
    public function isFinalFailure(): bool
    {
        return $this->verified && ResponseCode::isFinalFailure($this->rc());
    }

    /**
     * Whether this reply concerns the amount and currency that were requested.
     *
     * A valid signature proves the message came from Borica, not that it is
     * about this order for this sum. Without this check a genuinely signed
     * response for 1.00 would authorize an order of 100.00.
     *
     * Compared to 2 decimals: the gateway works to 2 and a schema storing 3
     * otherwise produces false mismatches such as 40.674 against 40.670.
     */
    public function matches(string|float $amount, string $currency): bool
    {
        return number_format((float) $this->amount(), 2, '.', '')
            === number_format((float) $amount, 2, '.', '')
            && strcasecmp($this->currency(), $currency) === 0;
    }

    public function action(): string
    {
        return (string) ($this->data['ACTION'] ?? '');
    }

    public function rc(): string
    {
        return (string) ($this->data['RC'] ?? '');
    }

    public function message(): string
    {
        return ResponseCode::describe($this->rc());
    }

    public function order(): string
    {
        return (string) ($this->data['ORDER'] ?? '');
    }

    public function amount(): string
    {
        return (string) ($this->data['AMOUNT'] ?? '');
    }

    public function currency(): string
    {
        return (string) ($this->data['CURRENCY'] ?? '');
    }

    /** Retrieval reference number — the handle for completing or refunding. */
    public function rrn(): ?string
    {
        return $this->data['RRN'] ?? null;
    }

    public function internalReference(): ?string
    {
        return $this->data['INT_REF'] ?? null;
    }

    public function approval(): ?string
    {
        return $this->data['APPROVAL'] ?? null;
    }

    /** Present once tokenization is in play. Store it encrypted. */
    public function token(): ?string
    {
        $token = $this->data['MERCH_TOKEN_ID'] ?? null;

        return ($token === null || $token === '' || $token === '-') ? null : (string) $token;
    }

    /**
     * The reply, minus anything that must not reach a log.
     *
     * @return array<string, mixed>
     */
    public function loggable(): array
    {
        return array_diff_key($this->data, array_flip(['P_SIGN', 'CARD', 'PAN', 'MERCH_TOKEN_ID']));
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }
}
