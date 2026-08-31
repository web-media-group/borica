<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Tests;

use IvoWmg\Borica\Borica;
use IvoWmg\Borica\Exceptions\BoricaException;
use IvoWmg\Borica\HttpClient;
use IvoWmg\Borica\MerchantInfo;
use IvoWmg\Borica\Signature\SignatureBuilder;
use IvoWmg\Borica\Trtype;
use PHPUnit\Framework\TestCase;

/**
 * End to end bar the network and the RSA: build a request, hand the fields to
 * a gateway that answers them, read the reply back.
 *
 * The signer is faked so these stay about which source string gets signed
 * rather than about openssl. [[SignerTest]] covers the real algorithm.
 */
class BoricaTest extends TestCase
{
    private FakeSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new FakeSigner;
    }

    private function borica(?HttpClient $http = null): Borica
    {
        return new Borica([
            'terminal' => 'V1800001',
            'merchant_id' => '1234567890',
            'merchant_name' => 'Test Shop',
            'merchant_url' => 'https://example.com',
            'email' => 'shop@example.com',
            'country' => 'BG',
            'currency' => 'EUR',
            'url' => 'https://gateway.invalid/cgi_link',
            'backref_url' => 'https://example.com/callback',
        ], $this->signer, $http);
    }

    /** A gateway that signs whatever it is told to say. */
    private function gateway(array $response): HttpClient
    {
        return new class($response, $this->signer) implements HttpClient
        {
            public array $received = [];

            public function __construct(private array $response, private FakeSigner $signer) {}

            public function post(string $url, array $fields): string
            {
                $this->received = $fields;

                $body = $this->response + [
                    'TERMINAL' => $fields['TERMINAL'],
                    'TRTYPE' => $fields['TRTYPE'],
                    'ORDER' => $fields['ORDER'],
                    'AMOUNT' => $fields['AMOUNT'] ?? '',
                    'CURRENCY' => $fields['CURRENCY'] ?? '',
                    'TIMESTAMP' => $fields['TIMESTAMP'] ?? '',
                    'NONCE' => $fields['NONCE'],
                ];

                $body['P_SIGN'] = $this->signer->sign(SignatureBuilder::responseSource($body));

                return json_encode($body);
            }
        };
    }

    public function test_a_registration_is_signed_and_verifiable(): void
    {
        $fields = $this->borica()->registration(
            trtype: Trtype::PREAUTH,
            order: '000042',
            amount: '25.5',
            description: 'Order 42',
            info: new MerchantInfo('a@b.bg', 'Иван Петров', 'бул. Витоша № 1'),
        );

        $this->assertSame('25.50', $fields['AMOUNT'], 'Two decimals, or RC -10.');
        $this->assertSame('EUR', $fields['CURRENCY']);
        $this->assertSame('000042', $fields['ORDER']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{32}$/', $fields['NONCE']);
        $this->assertMatchesRegularExpression('/^\d{14}$/', $fields['TIMESTAMP']);

        $this->assertTrue($this->signer->verify(
            SignatureBuilder::requestSource($fields),
            $fields['P_SIGN']
        ));

        $info = MerchantInfo::decode($fields['M_INFO']);
        $this->assertSame('Ivan Petrov', $info['cardholderName']);
        $this->assertSame('bul. Vitosha No 1', $info['billAddrLine1']);
    }

    /** Six digits, always. A silent truncation here attaches money to the wrong order. */
    public function test_it_refuses_an_order_that_is_not_six_digits(): void
    {
        $this->expectException(BoricaException::class);

        $this->borica()->registration(Trtype::SALE, '42', '1.00', 'x');
    }

    public function test_a_verified_success_is_reported_as_one(): void
    {
        $gateway = $this->gateway(['ACTION' => '0', 'RC' => '00', 'RRN' => '028601253152']);

        $response = $this->borica($gateway)->complete('000042', '25.50', '028601253152', 'ABC');

        $this->assertTrue($response->isVerified());
        $this->assertTrue($response->isSuccess());
        $this->assertSame('028601253152', $response->rrn());
    }

    /** ACTION 1 is a duplicate of something that worked. The money moved. */
    public function test_action_one_with_rc_zero_is_a_success(): void
    {
        $gateway = $this->gateway(['ACTION' => '1', 'RC' => '00']);

        $this->assertTrue($this->borica($gateway)->complete('000042', '1.00', 'R', 'I')->isSuccess());
    }

    public function test_a_tampered_response_does_not_verify(): void
    {
        $gateway = $this->gateway(['ACTION' => '0', 'RC' => '00']);
        $response = $this->borica($gateway)->complete('000042', '25.50', 'R', 'I');

        $tampered = $response->all();
        $tampered['AMOUNT'] = '2550.00';

        $this->assertFalse($this->borica()->response($tampered)->isVerified());
    }

    /**
     * A signature proves the sender, not the sum. This is the check that stops
     * a genuinely signed reply for 1.00 from authorizing an order of 100.00.
     */
    public function test_amount_is_checked_separately_from_the_signature(): void
    {
        $gateway = $this->gateway(['ACTION' => '0', 'RC' => '00']);
        $response = $this->borica($gateway)->complete('000042', '1.00', 'R', 'I');

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->matches('100.00', 'EUR'));
        $this->assertTrue($response->matches('1.00', 'EUR'));
    }

    /** Borica works to two decimals; a three-decimal column must not cause a false mismatch. */
    public function test_amount_comparison_tolerates_a_third_decimal(): void
    {
        $gateway = $this->gateway(['ACTION' => '0', 'RC' => '00']);
        $response = $this->borica($gateway)->complete('000042', '40.67', 'R', 'I');

        $this->assertTrue($response->matches('40.670', 'EUR'));
        $this->assertTrue($response->matches(40.674, 'EUR'));
    }

    public function test_in_progress_codes_are_not_failures(): void
    {
        foreach (['-31', '-33', '-40'] as $rc) {
            $response = $this->borica($this->gateway(['ACTION' => '0', 'RC' => $rc]))
                ->complete('000042', '1.00', 'R', 'I');

            $this->assertTrue($response->isInProgress(), $rc.' must read as in progress');
            $this->assertFalse($response->isFinalFailure(), $rc.' must never reach the cancel path');
        }
    }

    /** -24 looks like an in-progress code and is not one. */
    public function test_minus_twenty_four_is_a_final_failure(): void
    {
        $response = $this->borica($this->gateway(['ACTION' => '0', 'RC' => '-24']))
            ->complete('000042', '1.00', 'R', 'I');

        $this->assertFalse($response->isInProgress());
        $this->assertTrue($response->isFinalFailure());
    }

    public function test_duplicates_are_neither_success_nor_final_failure(): void
    {
        $response = $this->borica($this->gateway(['ACTION' => '0', 'RC' => '-21']))
            ->complete('000042', '1.00', 'R', 'I');

        $this->assertTrue($response->isDuplicate());
        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->isFinalFailure());
    }

    /** The MIT signature carries the token; the initial one cannot. */
    public function test_a_tokenized_charge_signs_with_the_token(): void
    {
        $token = 'ABCDEF01-2345-6789-ABCD-EF0123456789';
        $gateway = $this->gateway(['ACTION' => '0', 'RC' => '00']);

        $this->borica($gateway)->charge($token, '000042', '19.99', 'Subscription');

        $sent = $gateway->received;
        $this->assertSame($token, $sent['MERCH_TOKEN_ID']);
        $this->assertSame('M', $sent['MERCH_TRAN_STATE']);
        $this->assertArrayNotHasKey('MK_TOKEN', $sent, 'MK_TOKEN belongs to the initial transaction only.');

        $this->assertTrue(
            $this->signer->verify(SignatureBuilder::requestSource($sent, $token), $sent['P_SIGN']),
            'An MIT signed without the token is answered RC -17.'
        );
    }

    public function test_the_initial_tokenized_request_asks_for_a_token(): void
    {
        $fields = $this->borica()->initialTokenized('000042', '0.01', 'Trial');

        $this->assertSame('MERCH', $fields['MK_TOKEN']);
        $this->assertSame('S', $fields['MERCH_TRAN_STATE']);
        $this->assertArrayNotHasKey('MERCH_TOKEN_ID', $fields);
        $this->assertTrue($this->signer->verify(
            SignatureBuilder::requestSource($fields),
            $fields['P_SIGN']
        ));
    }

    public function test_a_status_request_omits_amount_and_signs_over_four_fields(): void
    {
        $gateway = $this->gateway(['ACTION' => '0', 'RC' => '00']);

        $this->borica($gateway)->status('000042');

        $sent = $gateway->received;
        $this->assertArrayNotHasKey('AMOUNT', $sent);
        $this->assertTrue($this->signer->verify(
            SignatureBuilder::statusRequestSource($sent),
            $sent['P_SIGN']
        ));
    }

    public function test_a_response_without_a_signature_is_not_verified(): void
    {
        $this->assertFalse($this->borica()->response(['ACTION' => '0', 'RC' => '00'])->isVerified());
    }

    /** Nothing that identifies a card may reach a log. */
    public function test_secrets_are_kept_out_of_the_loggable_payload(): void
    {
        $response = $this->borica()->response([
            'ACTION' => '0', 'RC' => '00', 'P_SIGN' => 'DEAD',
            'CARD' => '4111111111111111', 'MERCH_TOKEN_ID' => 'tok',
        ]);

        $this->assertSame(['ACTION', 'RC'], array_keys($response->loggable()));
    }
}
