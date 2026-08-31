<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Tests;

use IvoWmg\Borica\Signature\SignatureBuilder;
use IvoWmg\Borica\Trtype;
use PHPUnit\Framework\TestCase;

/**
 * The two worked examples in Appendix 1 of P-OM-41 v5.0 are the only source
 * of truth for this string that does not require a live terminal, so they are
 * reproduced here byte for byte. A change that still passes these is safe; a
 * change that breaks them would have cost days to diagnose against the gateway.
 */
class SignatureBuilderTest extends TestCase
{
    /** Appendix 1, §9.6 — the request example, verbatim. */
    public function test_it_reproduces_the_official_request_example(): void
    {
        $expected = '8V18000011141.003BGN6113920142020101308393232D41AAAFC7F8119A3BB7C4868E0B256F9-';

        $source = SignatureBuilder::requestSource([
            'TERMINAL' => 'V1800001',
            'TRTYPE' => '1',
            'AMOUNT' => '1.00',
            'CURRENCY' => 'BGN',
            'ORDER' => '113920',
            'TIMESTAMP' => '20201013083932',
            'NONCE' => 'D41AAAFC7F8119A3BB7C4868E0B256F9',
        ]);

        $this->assertSame($expected, $source);
    }

    /** Appendix 1, §9.7 — the response example, including its two empty fields. */
    public function test_it_reproduces_the_official_response_example(): void
    {
        $expected = '112006S975398V18000011149.003BGN6154744120286012531521697E2F39EFCA1CAF1--'
            .'1420201012160009329EADBD70C0A5AFBAD3DF405902602F79-';

        $source = SignatureBuilder::responseSource([
            'ACTION' => '1',
            'RC' => '00',
            'APPROVAL' => 'S97539',
            'TERMINAL' => 'V1800001',
            'TRTYPE' => '1',
            'AMOUNT' => '9.00',
            'CURRENCY' => 'BGN',
            'ORDER' => '154744',
            'RRN' => '028601253152',
            'INT_REF' => '97E2F39EFCA1CAF1',
            'PARES_STATUS' => '',
            'ECI' => '',
            'TIMESTAMP' => '20201012160009',
            'NONCE' => '9EADBD70C0A5AFBAD3DF405902602F79',
        ]);

        $this->assertSame($expected, $source);
    }

    /** An absent key and an empty string must behave identically. */
    public function test_missing_fields_are_treated_as_empty(): void
    {
        $withEmpty = SignatureBuilder::responseSource([
            'ACTION' => '0', 'RC' => '00', 'APPROVAL' => '', 'TERMINAL' => 'V1800001',
            'TRTYPE' => '1', 'AMOUNT' => '1.00', 'CURRENCY' => 'BGN', 'ORDER' => '000001',
            'RRN' => '', 'INT_REF' => '', 'PARES_STATUS' => '', 'ECI' => '',
            'TIMESTAMP' => '20201013083932', 'NONCE' => 'ABC',
        ]);

        $withMissing = SignatureBuilder::responseSource([
            'ACTION' => '0', 'RC' => '00', 'TERMINAL' => 'V1800001',
            'TRTYPE' => '1', 'AMOUNT' => '1.00', 'CURRENCY' => 'BGN', 'ORDER' => '000001',
            'TIMESTAMP' => '20201013083932', 'NONCE' => 'ABC',
        ]);

        $this->assertSame($withEmpty, $withMissing);
    }

    /**
     * Table 10: a status request signs four fields and stops. Signing it like
     * the other types is the mistake that produces an unexplained rejection.
     */
    public function test_a_status_request_has_four_fields_and_no_rfu(): void
    {
        $source = SignatureBuilder::requestSource([
            'TERMINAL' => 'V1800001',
            'TRTYPE' => Trtype::STATUS_CHECK,
            'ORDER' => '113920',
            'NONCE' => 'D41AAAFC7F8119A3BB7C4868E0B256F9',
            // Present in the request body, absent from its signature:
            'AMOUNT' => '1.00',
            'CURRENCY' => 'BGN',
            'TIMESTAMP' => '20201013083932',
        ]);

        $this->assertSame(
            '8V1800001290611392032D41AAAFC7F8119A3BB7C4868E0B256F9',
            $source
        );
        $this->assertStringEndsNotWith('-', $source);
    }

    /** A status *response* is verified like every other response — RFU included. */
    public function test_a_status_response_still_carries_rfu(): void
    {
        $source = SignatureBuilder::responseSource([
            'ACTION' => '0', 'RC' => '00', 'APPROVAL' => 'S97539', 'TERMINAL' => 'V1800001',
            'TRTYPE' => '90', 'AMOUNT' => '1.00', 'CURRENCY' => 'BGN', 'ORDER' => '113920',
            'RRN' => '028601253152', 'INT_REF' => '97E2F39EFCA1CAF1',
            'PARES_STATUS' => 'Y', 'ECI' => '05',
            'TIMESTAMP' => '20201013083932', 'NONCE' => 'ABC',
        ]);

        $this->assertStringEndsWith('-', $source);
    }

    /** The length prefix counts bytes, so it must not be fooled by an empty value. */
    public function test_the_prefix_is_a_byte_count(): void
    {
        $source = SignatureBuilder::statusRequestSource([
            'TERMINAL' => 'AB', 'TRTYPE' => '90', 'ORDER' => '000001', 'NONCE' => 'XY',
        ]);

        $this->assertSame('2AB29060000012XY', $source);
    }

    // ── Tokenized (merchant-initiated) transactions ──────────────────────────

    private function requestFields(): array
    {
        return [
            'TERMINAL' => 'V1800001',
            'TRTYPE' => Trtype::SALE,
            'AMOUNT' => '1.00',
            'CURRENCY' => 'BGN',
            'ORDER' => '113920',
            'TIMESTAMP' => '20201013083932',
            'NONCE' => 'D41AAAFC7F8119A3BB7C4868E0B256F9',
        ];
    }

    /**
     * The whole point of the tail. Signing an MIT with the bare dash that
     * works for every other request is answered with RC -17.
     */
    public function test_a_token_is_length_prefixed_in_the_tail(): void
    {
        $token = 'ABCDEF01-2345-6789-ABCD-EF0123456789';

        $withToken = SignatureBuilder::requestSource($this->requestFields(), $token);
        $without = SignatureBuilder::requestSource($this->requestFields());

        $this->assertStringEndsWith('36'.$token, $withToken);
        $this->assertStringEndsWith('-', $without);

        // Only the tail differs; the seven core fields are untouched.
        $this->assertSame(
            substr($without, 0, -1),
            substr($withToken, 0, -strlen('36'.$token))
        );
    }

    /**
     * A default of '-' threaded into the prefixing branch yields "1-", which
     * is a different string from "-" and fails. Both spellings of "no token"
     * must land on the same source.
     */
    public function test_a_literal_dash_counts_as_no_token(): void
    {
        $expected = SignatureBuilder::requestSource($this->requestFields());

        $this->assertSame($expected, SignatureBuilder::requestSource($this->requestFields(), '-'));
        $this->assertSame($expected, SignatureBuilder::requestSource($this->requestFields(), ''));
        $this->assertSame($expected, SignatureBuilder::requestSource($this->requestFields(), null));
        $this->assertStringEndsNotWith('1-', $expected);
    }

    /** The gateway echoes the token back, and the response is verified with it. */
    public function test_a_response_carrying_a_token_verifies_with_it(): void
    {
        $token = 'ABCDEF01-2345-6789-ABCD-EF0123456789';

        $fields = [
            'ACTION' => '0', 'RC' => '00', 'APPROVAL' => 'S97539', 'TERMINAL' => 'V1800001',
            'TRTYPE' => '1', 'AMOUNT' => '1.00', 'CURRENCY' => 'BGN', 'ORDER' => '113920',
            'RRN' => '028601253152', 'INT_REF' => '97E2F39EFCA1CAF1',
            'PARES_STATUS' => 'Y', 'ECI' => '05',
            'TIMESTAMP' => '20201013083932', 'NONCE' => 'ABC',
        ];

        $this->assertStringEndsWith('-', SignatureBuilder::responseSource($fields));
        $this->assertStringEndsWith(
            '36'.$token,
            SignatureBuilder::responseSource($fields + ['MERCH_TOKEN_ID' => $token])
        );
    }

    /** A status request has no tail, with or without a token in play. */
    public function test_a_status_request_never_carries_a_token(): void
    {
        $data = [
            'TERMINAL' => 'V1800001', 'TRTYPE' => Trtype::STATUS_CHECK,
            'ORDER' => '113920', 'NONCE' => 'ABC',
        ];

        $this->assertSame(
            SignatureBuilder::requestSource($data),
            SignatureBuilder::requestSource($data, 'ABCDEF01-2345-6789-ABCD-EF0123456789')
        );
    }
}
