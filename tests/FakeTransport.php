<?php

namespace ToxicFilter\Tests;

use ToxicFilter\Transport;

/**
 * Answers with whatever it was handed, and remembers what it was asked.
 *
 * A queued response may be an exception instead of a body, which is how a transport that
 * cannot reach the service is simulated: ours reports that as a `ServerError`, and the
 * retry loop only asks again about errors of ours.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{0: int, 1: mixed}> */
    private array $responses;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * @param list<array{0: int, 1: mixed}> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body === null ? null : json_decode($body, true),
            'key' => $headers['Idempotency-Key'] ?? null,
        ];

        [$status, $payload] = array_shift($this->responses);

        if ($payload instanceof \Throwable) {
            throw $payload;
        }

        return ['status' => $status, 'body' => (string) json_encode($payload)];
    }
}
