<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Tests;

use IvoWmg\Borica\ResponseCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The classification, pinned.
 *
 * Which codes count as "still running" decides whether a customer who takes
 * their time at their bank keeps their order or loses it while their card is
 * charged. One production system carried three tables that disagreed with
 * each other and with the gateway; this is the one that does not.
 */
class ResponseCodeTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function inProgressCodes(): array
    {
        return [
            'payment form open' => ['-40'],
            '3D-Secure running' => ['-33'],
            'transaction running' => ['-31'],
            'gateway has nothing yet' => ['-24'],
        ];
    }

    #[DataProvider('inProgressCodes')]
    public function test_an_in_progress_code_never_reaches_the_cancel_path(string $rc): void
    {
        $this->assertTrue(ResponseCode::isInProgress($rc));
        $this->assertFalse(ResponseCode::isFinalFailure($rc));
        $this->assertFalse(ResponseCode::isSuccess($rc));
    }

    /**
     * Of the 131 production orders that saw -24, seven went on to pay.
     * Cancelling on it would have taken their money and cancelled the order.
     */
    public function test_minus_twenty_four_is_not_a_refusal(): void
    {
        $this->assertTrue(ResponseCode::isInProgress('-24'));
        $this->assertFalse(ResponseCode::isFinalFailure('-24'));
    }

    public function test_duplicates_are_asked_about_rather_than_cancelled(): void
    {
        foreach (['-21', '-32'] as $rc) {
            $this->assertTrue(ResponseCode::isDuplicate($rc));
            $this->assertFalse(ResponseCode::isFinalFailure($rc), $rc.' must not cancel on its own');
        }
    }

    public function test_real_refusals_are_final(): void
    {
        foreach (['-19', '-20', '-25', '-30', '05', '51', '54'] as $rc) {
            $this->assertTrue(ResponseCode::isFinalFailure($rc), $rc.' should be final');
        }
    }

    public function test_only_double_zero_is_success(): void
    {
        $this->assertTrue(ResponseCode::isSuccess('00'));
        $this->assertFalse(ResponseCode::isSuccess('0'));
        $this->assertFalse(ResponseCode::isSuccess(''));
    }

    /** Two-digit codes come from the issuer, negative ones from the gateway. */
    public function test_issuer_codes_are_told_apart_from_gateway_codes(): void
    {
        $this->assertTrue(ResponseCode::isIssuerCode('51'));
        $this->assertFalse(ResponseCode::isIssuerCode('-19'));
        $this->assertFalse(ResponseCode::isIssuerCode('00'));
    }

    /** The descriptions that were wrong on every surface of one live shop. */
    public function test_the_corrected_descriptions(): void
    {
        $this->assertStringContainsString('cardholder', ResponseCode::describe('-19'));
        $this->assertStringContainsString('abandoned', ResponseCode::describe('-20'));
        $this->assertStringContainsString('no such transaction', ResponseCode::describe('-24'));
        $this->assertStringContainsString('already in progress', ResponseCode::describe('-31'));
        $this->assertStringContainsString('fraud', ResponseCode::describe('-30'));
    }

    public function test_an_unknown_code_still_says_something(): void
    {
        $this->assertStringContainsString('99', ResponseCode::describe('99'));
        $this->assertStringContainsString('issuer', ResponseCode::describe('99'));
        $this->assertStringContainsString('-999', ResponseCode::describe('-999'));
    }

    /**
     * The reason this method exists: a shop that shows the technical text to
     * a shopper tells someone whose bank is still deciding that they were
     * refused, and they go and pay somewhere else.
     */
    #[DataProvider('inProgressCodes')]
    public function test_the_customer_is_asked_to_wait_not_told_they_failed(string $rc): void
    {
        $message = ResponseCode::customerMessage($rc);

        $this->assertStringNotContainsStringIgnoringCase('declined', $message);
        $this->assertStringNotContainsStringIgnoringCase('failed', $message);
        $this->assertStringContainsStringIgnoringCase('wait', $message);
    }

    public function test_the_customer_is_never_shown_a_field_name(): void
    {
        foreach (['-1', '-10', '-15', '-17', '-28', '-48'] as $rc) {
            $message = ResponseCode::customerMessage($rc);

            $this->assertStringNotContainsString('RRN', $message);
            $this->assertStringNotContainsString('P_SIGN', $message);
            $this->assertStringNotContainsString('addendum', $message);
            $this->assertNotEmpty($message);
        }
    }

    public function test_issuer_declines_get_a_useful_customer_message(): void
    {
        $this->assertStringContainsString('Insufficient funds', ResponseCode::customerMessage('51'));
        $this->assertStringContainsString('expired', ResponseCode::customerMessage('54'));
        $this->assertStringContainsString('contact your bank', ResponseCode::customerMessage('99'));
    }
}
