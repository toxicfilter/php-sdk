<?php

namespace ToxicFilter;

use ToxicFilter\Exception\ServerError;

/**
 * cURL, and nothing else.
 *
 * No Guzzle, no PSR-18 adapter, no dependency at all beyond the extension every PHP
 * installation already has. A client for one small API is not worth a dependency tree, and
 * a library that forces a major version of an HTTP client on its host is a library that
 * gets removed the first time the host disagrees.
 */
class CurlTransport implements Transport
{
    /**
     * @param int $timeout Seconds to wait for the whole request.
     * @param int $connectTimeout Seconds to wait for the connection.
     */
    public function __construct(
        private readonly int $timeout = 10,
        private readonly int $connectTimeout = 5,
    ) {
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body
     * @return array{status: int, body: string}
     */
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $handle = curl_init($url);

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            // A moderation call that follows a redirect has stopped being a moderation
            // call: the second request goes somewhere this client never authenticated to.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        // No `curl_close`: a no-op since PHP 8.0, when handles became objects freed with
        // their last reference, and deprecated in 8.5, where it would print a notice into
        // the host application's log on every call.

        if ($response === false) {
            // Reported as a server error rather than its own type, because the client
            // retries both and the caller does the same thing about either: the request
            // did not arrive, so ask again.
            throw new ServerError('Could not reach ToxicFilter: ' . $error, 0);
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
