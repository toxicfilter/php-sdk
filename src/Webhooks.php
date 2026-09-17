<?php

namespace ToxicFilter;

/**
 * Checking that a delivery is ours.
 *
 * Your webhook URL is public, so a handler that acts on whatever arrives can be written
 * into from outside. Verify every delivery.
 */
class Webhooks
{
    public const HEADER = 'X-ToxicFilter-Signature';

    /**
     * @param string $payload The RAW body, exactly as received.
     * @param string $header The value of `X-ToxicFilter-Signature`.
     * @param string $secret Your endpoint's signing secret.
     * @param int $tolerance Seconds of clock difference to allow.
     * @return bool
     */
    public static function verify(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$name, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[(string) $name] = (string) $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $signature = (string) ($parts['v1'] ?? '');

        if ($timestamp <= 0 || $signature === '') {
            return false;
        }

        // The timestamp is signed WITH the body, so a delivery captured today cannot be
        // replayed tomorrow: moving it breaks the signature and leaving it stale puts the
        // request outside this window.
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        // Constant time. A comparison that returns early on the first wrong character
        // tells an attacker how much of their guess was right.
        return hash_equals($expected, $signature);
    }

    /**
     * The event, once it is known to be ours.
     *
     * @param string $payload The RAW body. Re-encoding a parsed array signs a different
     *                        string, since a key order or an escaped slash may differ.
     * @param string $header
     * @param string $secret
     * @param int $tolerance
     * @return array<string, mixed>|null Null when it does not verify.
     */
    public static function event(string $payload, string $header, string $secret, int $tolerance = 300): ?array
    {
        if (! self::verify($payload, $header, $secret, $tolerance)) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
