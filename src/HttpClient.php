<?php

declare(strict_types=1);

namespace IvoWmg\Borica;

/**
 * How server-to-server requests leave the process.
 *
 * An interface so the package does not force a client on its host, and so a
 * test can exercise the whole path — signing included — without a gateway.
 */
interface HttpClient
{
    /**
     * @param  array<string, string>  $fields  Form-encoded.
     * @return string The raw body; parsing is the caller's problem, because
     *                the gateway answers in more than one format.
     */
    public function post(string $url, array $fields): string;
}
