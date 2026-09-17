<?php

namespace ToxicFilter;

/**
 * How a request actually leaves the machine.
 *
 * One method, so this package pins no HTTP library and can be pointed at anything:
 * `Http::` in Laravel, or a test that dispatches into a real application.
 */
interface Transport
{
    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body Raw JSON, already encoded.
     * @return array{status: int, body: string}
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
