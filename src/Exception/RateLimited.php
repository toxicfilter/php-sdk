<?php

namespace ToxicFilter\Exception;

/**
 * Too many requests. Wait and ask again, which this client already did, up to its retry
 * limit, before giving you this.
 */
class RateLimited extends ApiError
{
    /**
     * @return bool Always true: waiting is the whole answer to a rate limit.
     */
    public function isRetryable(): bool
    {
        return true;
    }

    /**
     * @return int Seconds the server asked for, or 1.
     */
    public function retryAfter(): int
    {
        return max(1, (int) ($this->payload['retry_after'] ?? 1));
    }
}
