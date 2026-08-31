<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Exceptions;

/**
 * Raised when a signature cannot be produced or a key cannot be loaded.
 *
 * Never caught-and-ignored: a payment that cannot be signed must not be sent,
 * and a callback that cannot be verified must not be trusted.
 */
class SignatureException extends BoricaException {}
