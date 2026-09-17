<?php

namespace ToxicFilter\Exception;

use RuntimeException;

/**
 * Anything the API refused.
 *
 * Carries the status, the machine-readable `code` and the whole body, because the
 * difference between two of these decides what a client should DO (retry, stop, upgrade),
 * and a message string cannot be branched on.
 */
class ApiError extends RuntimeException
{
    /**
     * @param string $message
     * @param int $status
     * @param string|null $errorCode The API's own code, e.g. `quota_exhausted`.
     * @param array<string, mixed> $payload The decoded body, in full.
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly array $payload = [],
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Whether asking again could plausibly work.
     *
     * The one distinction this library exists to get right for you: a 429 means come back
     * in a minute, a 402 means come back with a bigger plan. A client that retries both
     * hammers the second one forever and never succeeds.
     *
     * @return bool
     */
    public function isRetryable(): bool
    {
        return false;
    }
}
