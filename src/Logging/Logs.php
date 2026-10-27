<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Logging;

/**
 * Where every exchange with the gateway is kept: the requests the host signs, the gateway's
 * answers, the callbacks. Payments are reconciled against the bank months later, and a log file
 * rotates away long before that.
 *
 * An implementation receives fields already passed through [[Redactor]] and must never throw: a
 * lost log line is bad, a payment broken by logging is worse.
 */
interface Logs
{
    public const REQUEST = 'request';

    public const RESPONSE = 'response';

    public const CALLBACK = 'callback';

    /** @param array<string, string> $fields redacted */
    public function record(string $direction, array $fields, bool $verified = true): void;
}
