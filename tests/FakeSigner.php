<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Tests;

use IvoWmg\Borica\Signature\Signs;

/**
 * A signer without a key pair.
 *
 * Real RSA proves openssl works, which is not what these tests are about —
 * what matters is that the right *source string* reaches the signer, and a
 * deterministic digest shows that just as well. It also keeps the suite from
 * depending on the host's openssl.cnf, which is missing often enough on
 * Windows to matter.
 *
 * [[SignerTest]] covers the real thing.
 */
final class FakeSigner implements Signs
{
    /** @var list<string> Every source string signed, in order. */
    public array $signed = [];

    public function sign(string $source): string
    {
        $this->signed[] = $source;

        return strtoupper(hash('sha256', $source));
    }

    public function verify(string $source, string $pSign): bool
    {
        return hash_equals(strtoupper(hash('sha256', $source)), strtoupper($pSign));
    }
}
