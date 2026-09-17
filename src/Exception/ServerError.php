<?php

namespace ToxicFilter\Exception;

/**
 * Our fault, or the network's. Retried automatically; if you are seeing it, the retries
 * ran out.
 */
class ServerError extends ApiError
{
    /**
     * @return bool Always true: their side failed, so the same request may well work.
     */
    public function isRetryable(): bool
    {
        return true;
    }
}
