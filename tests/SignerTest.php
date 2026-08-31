<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Tests;

use IvoWmg\Borica\Exceptions\SignatureException;
use IvoWmg\Borica\Signature\Signer;
use PHPUnit\Framework\TestCase;

/**
 * The real RSA path.
 *
 * Generating a key pair needs an openssl.cnf, which is absent often enough on
 * Windows that these skip rather than fail there. CI on Linux runs them.
 */
class SignerTest extends TestCase
{
    private string $privateKeyPath = '';

    private string $publicKeyPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            $this->markTestSkipped('openssl cannot generate a key here (no openssl.cnf).');
        }

        openssl_pkey_export($key, $privatePem);

        $this->privateKeyPath = tempnam(sys_get_temp_dir(), 'bk').'.key';
        $this->publicKeyPath = tempnam(sys_get_temp_dir(), 'bk').'.pem';

        file_put_contents($this->privateKeyPath, $privatePem);
        file_put_contents($this->publicKeyPath, openssl_pkey_get_details($key)['key']);
    }

    protected function tearDown(): void
    {
        foreach ([$this->privateKeyPath, $this->publicKeyPath] as $path) {
            if ($path !== '') {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    private function signer(): Signer
    {
        return new Signer($this->privateKeyPath, $this->publicKeyPath);
    }

    public function test_it_signs_and_verifies_its_own_signature(): void
    {
        $source = '8V18000011141.003BGN6113920142020101308393232D41AAAFC7F8119A3BB7C4868E0B256F9-';
        $signature = $this->signer()->sign($source);

        $this->assertMatchesRegularExpression('/^[0-9A-F]+$/', $signature, 'P_SIGN is uppercase hex.');
        $this->assertTrue($this->signer()->verify($source, $signature));
    }

    /** One byte different in the source — the whole point of the dash rules. */
    public function test_a_different_source_does_not_verify(): void
    {
        $signature = $this->signer()->sign('…{NONCE}-');

        $this->assertFalse($this->signer()->verify('…{NONCE}1-', $signature));
    }

    public function test_it_reports_a_missing_private_key_rather_than_signing_nothing(): void
    {
        $signer = new Signer('/no/such/terminal.key', $this->publicKeyPath);

        $this->expectException(SignatureException::class);
        $signer->sign('anything');
    }

    /**
     * A deployment that lost its public key must stop, not wave callbacks
     * through unverified.
     */
    public function test_a_missing_public_key_is_an_error_not_a_false(): void
    {
        $signer = new Signer($this->privateKeyPath, '/no/such/apgw.cer');

        $this->expectException(SignatureException::class);
        $signer->verify('anything', 'DEADBEEF');
    }

    public function test_rubbish_in_p_sign_is_rejected_quietly(): void
    {
        $this->assertFalse($this->signer()->verify('anything', 'not hex'));
        $this->assertFalse($this->signer()->verify('anything', ''));
    }

    public function test_is_configured_reflects_the_files_on_disk(): void
    {
        $this->assertTrue($this->signer()->isConfigured());
        $this->assertFalse((new Signer('/nope.key', '/nope.pem'))->isConfigured());
    }
}
