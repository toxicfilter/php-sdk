<?php

namespace ToxicFilter\Tests;

use ToxicFilter\Webhooks;

/**
 * The receiving half.
 *
 * Signed like Stripe (`t=...,v1=...`, HMAC-SHA256 over `timestamp.body`) because the
 * shape is already understood by everybody, and the timestamp is signed WITH the body so a
 * captured delivery cannot be replayed tomorrow.
 */
final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private function sign(string $payload, ?int $at = null): string
    {
        $t = $at ?? time();

        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, self::SECRET);
    }

    public function test_it_accepts_a_delivery_of_ours(): void
    {
        $payload = (string) json_encode(['event' => 'moderation.blocked', 'data' => ['id' => 'mod_01']]);

        $this->assertTrue(Webhooks::verify($payload, $this->sign($payload), self::SECRET));

        $event = Webhooks::event($payload, $this->sign($payload), self::SECRET);

        $this->assertSame('moderation.blocked', $event['event']);
        $this->assertSame('mod_01', $event['data']['id']);
    }

    public function test_it_refuses_a_forged_signature(): void
    {
        $payload = (string) json_encode(['event' => 'moderation.blocked']);
        $header = 't=' . time() . ',v1=' . str_repeat('0', 64);

        $this->assertFalse(Webhooks::verify($payload, $header, self::SECRET));
        $this->assertNull(Webhooks::event($payload, $header, self::SECRET));
    }

    public function test_it_refuses_a_body_that_changed_after_signing(): void
    {
        $header = $this->sign('{"event":"moderation.blocked"}');

        $this->assertFalse(Webhooks::verify('{"event":"moderation.allowed"}', $header, self::SECRET));
    }

    public function test_it_refuses_a_replay_from_yesterday(): void
    {
        $payload = '{"event":"moderation.blocked"}';

        // Correctly signed, and stale. This is the whole reason the timestamp is inside the
        // signed string rather than beside it.
        $this->assertFalse(Webhooks::verify($payload, $this->sign($payload, time() - 86400), self::SECRET));
    }

    public function test_it_refuses_a_header_that_is_not_one(): void
    {
        $payload = '{"event":"moderation.blocked"}';

        foreach (['', 'nonsense', 'v1=abc', 't=0,v1=abc', 't=' . time()] as $header) {
            $this->assertFalse(
                Webhooks::verify($payload, $header, self::SECRET),
                'Accepted a malformed header: ' . var_export($header, true)
            );
        }
    }

    public function test_the_wrong_secret_is_refused(): void
    {
        $payload = '{"event":"moderation.blocked"}';

        $this->assertFalse(Webhooks::verify($payload, $this->sign($payload), 'whsec_somebody_else'));
    }
}
