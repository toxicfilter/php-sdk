<?php

namespace ToxicFilter\Exception;

/**
 * The request was malformed and nothing was judged.
 */
class InvalidRequest extends ApiError
{
    /**
     * Which fields were wrong, when the API said.
     *
     * @return array<string, list<string>>
     */
    public function fields(): array
    {
        return (array) ($this->payload['errors'] ?? $this->payload['error']['fields'] ?? []);
    }
}
