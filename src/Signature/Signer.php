<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Signature;

use IvoWmg\Borica\Exceptions\SignatureException;

/**
 * RSA-SHA256 over the MAC_GENERAL source string, hex-uppercased.
 *
 * Keys are read from disk rather than from config strings on purpose: a
 * PEM pasted into an environment variable loses its newlines on the way
 * through most deployment tooling, and openssl then refuses it with an error
 * that says only "cannot get private key".
 */
final class Signer implements Signs
{
    public function __construct(
        private readonly string $privateKeyPath,
        private readonly string $publicKeyPath,
        private readonly ?string $privateKeyPassword = null,
    ) {}

    /** @return string Uppercase hex, ready for the P_SIGN field. */
    public function sign(string $source): string
    {
        $key = @openssl_pkey_get_private(
            'file://'.$this->privateKeyPath,
            $this->privateKeyPassword ?? ''
        );

        if ($key === false) {
            throw new SignatureException(
                'Could not load the private key from '.$this->privateKeyPath.'. '
                .'Check the path, the password, and that the file is PEM. OpenSSL said: '
                .(openssl_error_string() ?: 'nothing')
            );
        }

        if (! openssl_sign($source, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new SignatureException(
                'Signing failed: '.(openssl_error_string() ?: 'unknown error')
            );
        }

        return strtoupper(bin2hex($signature));
    }

    /**
     * @param  string  $pSign  The P_SIGN field, uppercase hex.
     */
    public function verify(string $source, string $pSign): bool
    {
        // The published public key is distributed as a certificate; some
        // merchants hold a bare PEM instead. openssl_pkey_get_public reads
        // either, so long as the file is actually there.
        $contents = @file_get_contents($this->publicKeyPath);

        if ($contents === false) {
            throw new SignatureException(
                'Could not read the public key at '.$this->publicKeyPath.'. '
                .'Verification must never be skipped when this file is missing: '
                .'an unverified callback is an unauthenticated one.'
            );
        }

        $key = @openssl_pkey_get_public($contents);

        if ($key === false) {
            throw new SignatureException(
                'Could not parse the public key: '.(openssl_error_string() ?: 'unknown error')
            );
        }

        $binary = @hex2bin(trim($pSign));

        if ($binary === false || $binary === '') {
            return false;
        }

        return openssl_verify($source, $binary, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function isConfigured(): bool
    {
        return is_readable($this->privateKeyPath) && is_readable($this->publicKeyPath);
    }
}
