<?php

namespace ToxicFilter\Tests;

use ToxicFilter\Transport;

/**
 * Answers with whatever it was handed, and remembers what it was asked.
 *
 * A queued response may be an exception instead of a body, which is how a transport that
 * cannot reach the service is simulated: ours reports that as a `ServerError`, and the
 * retry loop only asks again about errors of ours.
 *
 * A third element, `true`, sends the payload as the body verbatim instead of encoding it,
 * which is how a proxy's HTML page or an empty 301 is simulated: a transport that could
 * only speak JSON could never show the client receiving something that is not.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{0: int, 1: mixed, 2?: bool}> */
    private array $responses;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * @param list<array{0: int, 1: mixed, 2?: bool}> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /**
     * Records the call and hands back the next queued answer.
     *
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body
     * @return array{status: int, body: string}
     */
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body === null ? null : json_decode($body, true),
            'key' => $headers['Idempotency-Key'] ?? null,
        ];

        [$status, $payload, $raw] = array_pad(array_shift($this->responses), 3, false);

        if ($payload instanceof \Throwable) {
            throw $payload;
        }

        return ['status' => $status, 'body' => $raw ? (string) $payload : (string) json_encode($payload)];
    }
}
