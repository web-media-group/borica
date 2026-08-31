<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Http;

use IvoWmg\Borica\Exceptions\BoricaException;
use IvoWmg\Borica\HttpClient;

/**
 * The dependency-free client, for use outside Laravel.
 *
 * Peer verification stays on. Turning it off to get past a certificate error
 * makes every reply forgeable, and the replies here move money.
 */
final class CurlClient implements HttpClient
{
    public function __construct(private readonly int $timeout = 60) {}

    /** @param array<string, string> $fields */
    public function post(string $url, array $fields): string
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new BoricaException('Could not initialise cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            // The transaction may well have been processed. Never read a
            // transport failure as a decline; ask TRTYPE 90 instead.
            throw new BoricaException('Request to the gateway failed: '.$error);
        }

        return (string) $body;
    }
}
