<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Tests;

use IvoWmg\Borica\Borica;
use IvoWmg\Borica\HttpClient;
use IvoWmg\Borica\Logging\Logs;
use IvoWmg\Borica\Logging\Redactor;
use IvoWmg\Borica\MerchantInfo;
use IvoWmg\Borica\Signature\SignatureBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Every exchange is logged, and nothing a log must not hold gets there: no card number, token,
 * signature or cardholder data.
 */
class LoggingTest extends TestCase
{
    private FakeSigner $signer;

    /** @var list<array{0: string, 1: array<string, string>, 2: bool}> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new FakeSigner;
    }

    private function borica(?HttpClient $http = null): Borica
    {
        $log = new class($this->rows) implements Logs
        {
            public function __construct(private array &$rows) {}

            public function record(string $direction, array $fields, bool $verified = true): void
            {
                $this->rows[] = [$direction, $fields, $verified];
            }
        };

        return new Borica([
            'terminal' => 'V1800001', 'merchant_id' => '1234567890', 'merchant_name' => 'Test Shop', 'merchant_url' => 'https://example.com',
            'email' => 'shop@example.com', 'country' => 'BG', 'currency' => 'EUR', 'url' => 'https://gateway.invalid/cgi_link',
            'backref_url' => 'https://example.com/callback',
        ], $this->signer, $http, $log);
    }

    private function gateway(array $answer): HttpClient
    {
        return new class($answer, $this->signer) implements HttpClient
        {
            public function __construct(private array $answer, private FakeSigner $signer) {}

            public function post(string $url, array $fields): string
            {
                $body = $this->answer + ['TERMINAL' => $fields['TERMINAL'], 'TRTYPE' => $fields['TRTYPE'], 'ORDER' => $fields['ORDER'],
                    'AMOUNT' => $fields['AMOUNT'] ?? '', 'CURRENCY' => $fields['CURRENCY'] ?? '', 'TIMESTAMP' => $fields['TIMESTAMP'] ?? '', 'NONCE' => $fields['NONCE']];
                $body['P_SIGN'] = $this->signer->sign(SignatureBuilder::responseSource($body));

                return json_encode($body);
            }
        };
    }

    public function test_the_form_for_the_bank_is_logged_without_the_cardholder_and_the_signature(): void
    {
        $fields = $this->borica()->initialTokenized('000042', '9.90', 'Subscription', new MerchantInfo('ivan@example.com', 'Иван Петров', 'София'));

        $this->assertCount(1, $this->rows);
        [$direction, $logged] = $this->rows[0];
        $this->assertSame(Logs::REQUEST, $direction);
        $this->assertSame(['000042', '9.90', 'EUR', '1', 'MERCH'], [$logged['ORDER'], $logged['AMOUNT'], $logged['CURRENCY'], $logged['TRTYPE'], $logged['MK_TOKEN']]);
        $this->assertArrayHasKey('P_SIGN', $fields);
        foreach (['P_SIGN', 'M_INFO', 'EMAIL'] as $secret) {
            $this->assertArrayNotHasKey($secret, $logged);
        }
        $this->assertStringNotContainsString('ivan@example.com', json_encode($logged));
    }

    public function test_a_charge_and_its_answer_are_logged_with_the_token_only_as_a_flag(): void
    {
        $gateway = $this->gateway(['ACTION' => '0', 'RC' => '00', 'RRN' => '612345678901', 'INT_REF' => 'ABC', 'CARD' => '4341XXXXXXXX0044', 'MERCH_TOKEN_ID' => 'SECRETTOKEN']);

        $this->borica($gateway)->charge('SECRETTOKEN', '000043', '9.90', 'Renewal');

        $this->assertSame([Logs::REQUEST, Logs::RESPONSE], array_column($this->rows, 0));
        $this->assertSame('[present]', $this->rows[0][1]['MERCH_TOKEN_ID']);
        $this->assertSame(['00', '612345678901', '****0044', '[present]'], [$this->rows[1][1]['RC'], $this->rows[1][1]['RRN'], $this->rows[1][1]['CARD'], $this->rows[1][1]['MERCH_TOKEN_ID']]);
        $this->assertTrue($this->rows[1][2], 'verified');
        $this->assertStringNotContainsString('SECRETTOKEN', json_encode($this->rows));
        $this->assertStringNotContainsString('4341XXXXXXXX0044', json_encode($this->rows));
    }

    public function test_a_callback_is_logged_with_whether_it_verified(): void
    {
        $this->borica()->response(['ACTION' => '0', 'RC' => '00', 'ORDER' => '000044', 'AMOUNT' => '1.00', 'P_SIGN' => 'FORGED']);

        $this->assertSame(Logs::CALLBACK, $this->rows[0][0]);
        $this->assertFalse($this->rows[0][2]);
        $this->assertArrayNotHasKey('P_SIGN', $this->rows[0][1]);
    }

    public function test_the_redactor_keeps_only_what_it_knows(): void
    {
        $this->assertSame(['ORDER' => '000045'], Redactor::fields(['ORDER' => '000045', 'SOMETHING_NEW' => 'x', 'PAN' => '4341000000000044', 'EMAIL' => 'a@b.bg']));
    }
}
