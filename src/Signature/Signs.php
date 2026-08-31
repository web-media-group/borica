<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Signature;

/**
 * Producing and checking P_SIGN.
 *
 * An interface so that building a request can be tested without a key pair,
 * and so a host with its keys in a vault or an HSM can supply its own
 * implementation instead of paths on disk.
 */
interface Signs
{
    /** @return string Uppercase hex for the P_SIGN field. */
    public function sign(string $source): string;

    public function verify(string $source, string $pSign): bool;
}
