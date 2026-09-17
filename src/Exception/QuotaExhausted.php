<?php

namespace ToxicFilter\Exception;

/**
 * The account cannot pay for this call.
 *
 * **Never retried, by anything in this library.** 402 means come back with a bigger plan,
 * and a client that retries it hammers forever and never succeeds. Catch it, stop calling,
 * and tell somebody: no amount of waiting produces credits.
 */
class QuotaExhausted extends ApiError
{
    /**
     * @return int
     */
    public function remaining(): int
    {
        return (int) ($this->payload['credits']['remaining'] ?? 0);
    }

    /**
     * What this particular call would have cost. A 402 can arrive with credits still in
     * the account: the check prices the call, not one credit.
     *
     * @return int
     */
    public function required(): int
    {
        return (int) ($this->payload['credits']['required'] ?? 0);
    }

    /**
     * @return string|null
     */
    public function renewsAt(): ?string
    {
        $at = $this->payload['credits']['renews_at'] ?? null;

        return is_string($at) ? $at : null;
    }
}
