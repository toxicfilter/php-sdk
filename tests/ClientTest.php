<?php

namespace ToxicFilter\Tests;

use ToxicFilter\Exception\InvalidRequest;
use ToxicFilter\Exception\QuotaExhausted;
use ToxicFilter\Exception\RateLimited;
use ToxicFilter\Exception\ServerError;

/**
 * The PHP client, against a transport that answers like the API does.
 *
 * These are the behaviours a caller cannot see and would otherwise find out from an
 * invoice: what gets retried, what never does, and whether a retry is the same request or
 * a second one. They run anywhere, with no Laravel and no network.
 *
 * They do NOT answer whether the client agrees with the API about field names and routes.
 * A stub agrees with whatever it is sent, so that question is settled on the other side, by
 * the application's own `PhpSdkTest`, which dispatches this client into the real thing. Two
 * suites, two different questions, and neither one substitutes for the other.
 */
final class ClientTest extends TestCase
{
    public function test_it_reads_the_answer(): void
    {
        [$tf, $transport] = $this->client([[200, $this->verdict()]]);

        $verdict = $tf->text('you fucking legend', ['locales' => ['en'], 'reference' => 'c_1']);

        $this->assertTrue($verdict->needsReview());
        $this->assertFalse($verdict->allowed());
        $this->assertFalse($verdict->blocked());
        $this->assertSame('mod_01', $verdict->id());
        $this->assertSame('c_1', $verdict->reference());
        $this->assertSame(['toxicity'], $verdict->flagged());
        $this->assertSame(0.55, $verdict->score('toxicity'));
        $this->assertSame(0.0, $verdict->score('hate'), 'A category with no score is 0, not an error.');
        $this->assertSame(['Contains 1 profanity.'], $verdict->reasons());
        $this->assertSame(1, $verdict->charged());
        $this->assertSame(940, $verdict->creditsRemaining());

        $this->assertSame('https://example.test/api/v1/text', $transport->calls[0]['url']);
        $this->assertSame('you fucking legend', $transport->calls[0]['body']['content']);
        $this->assertSame(['en'], $transport->calls[0]['body']['locales']);
    }

    public function test_a_conversation_goes_up_as_a_conversation(): void
    {
        [$tf, $transport] = $this->client([[200, $this->verdict()]]);

        $tf->conversation([
            ['author' => 'a', 'content' => 'hello'],
            ['author' => 'b', 'content' => 'hi'],
        ], ['locales' => ['en']]);

        $this->assertSame('https://example.test/api/v1/conversation', $transport->calls[0]['url']);
        $this->assertCount(2, $transport->calls[0]['body']['messages']);
    }

    public function test_it_reads_the_second_axis(): void
    {
        [$tf] = $this->client([[200, $this->verdict()]]);

        $verdict = $tf->text('anything');

        $this->assertSame(['gambling' => 0.82], $verdict->topics());
        $this->assertSame(0.82, $verdict->topic('gambling'));
        $this->assertSame(0.0, $verdict->topic('crypto'), 'A subject nobody measures is 0.');
        $this->assertSame(['language' => 'en'], $verdict->facts());
        $this->assertFalse($verdict->degraded());
    }

    /**
     * And the third: who is writing and what they want, which is neither a harm nor a
     * subject. Several types can apply to the same person at once.
     */
    public function test_it_reads_the_lead_types(): void
    {
        [$tf] = $this->client([[200, $this->verdict()]]);

        $verdict = $tf->text('anything');

        $this->assertSame(['free_work_for_equity' => 0.9], $verdict->leads());
        $this->assertSame(0.9, $verdict->lead('free_work_for_equity'));
        $this->assertSame(0.0, $verdict->lead('no_show'), 'A type that did not show is 0.');
    }

    /**
     * Fifteen categories and three decisions collapsed into one boolean is the product this
     * API was built not to be, and a client that offers the boolean anyway undoes the
     * argument on the way out of the door.
     */
    public function test_there_is_no_toxic_boolean(): void
    {
        $methods = get_class_methods(\ToxicFilter\Verdict::class);

        foreach ($methods as $method) {
            $this->assertStringNotContainsStringIgnoringCase('toxic', $method);
        }

        $this->assertContains('needsReview', $methods, 'The middle outcome has to be as easy to reach as the other two.');
        $this->assertContains('blocked', $methods);
        $this->assertContains('allowed', $methods);
    }

    public function test_it_retries_a_rate_limit_and_a_server_error(): void
    {
        [$tf, $transport] = $this->client([
            [429, ['error' => ['code' => 'rate_limited'], 'retry_after' => 3]],
            [500, ['error' => ['code' => 'server_error']]],
            [200, $this->verdict()],
        ]);

        $verdict = $tf->text('hello');

        $this->assertSame('review', $verdict->decision());
        $this->assertCount(3, $transport->calls);
        $this->assertSame([3, 4], $tf->waited, 'The rate limit says how long; the server error grows.');
    }

    /**
     * The service says how long, and that beats guessing: it knows when its own window
     * turns over. Bounded all the same, or a number on the wire decides how long the
     * caller's request hangs.
     */
    public function test_the_wait_a_rate_limit_asks_for_is_bounded(): void
    {
        [$tf, $transport] = $this->client([
            [429, ['error' => ['code' => 'rate_limited'], 'retry_after' => 86_400]],
            [200, $this->verdict()],
        ]);

        $tf->text('hello');

        $this->assertSame([30], $tf->waited, 'A day is not a retry, it is a hang.');
        $this->assertCount(2, $transport->calls, 'Still retried: the wait is capped, not abandoned.');
    }

    public function test_a_shorter_wait_is_honoured_exactly(): void
    {
        [$tf] = $this->client([
            [429, ['error' => ['code' => 'rate_limited'], 'retry_after' => 7]],
            [200, $this->verdict()],
        ]);

        $tf->text('hello');

        $this->assertSame([7], $tf->waited);
    }

    /**
     * A 429 with nothing to say still waits a second rather than asking again at once,
     * which would be refused again for the same reason.
     */
    public function test_a_rate_limit_with_no_number_still_waits(): void
    {
        [$tf] = $this->client([
            [429, ['error' => ['code' => 'rate_limited']]],
            [200, $this->verdict()],
        ]);

        $tf->text('hello');

        $this->assertSame([1], $tf->waited);
    }

    public function test_a_retry_is_the_same_request(): void
    {
        [$tf, $transport] = $this->client([
            [500, ['error' => ['code' => 'server_error']]],
            [200, $this->verdict()],
        ]);

        $tf->text('hello');

        $this->assertNotNull($transport->calls[0]['key']);
        $this->assertSame(
            $transport->calls[0]['key'],
            $transport->calls[1]['key'],
            'A retry that changes its key is a second charge and a second verdict for one comment.'
        );
    }

    public function test_two_calls_are_two_keys(): void
    {
        [$tf, $transport] = $this->client([[200, $this->verdict()], [200, $this->verdict()]]);

        $tf->text('one');
        $tf->text('two');

        $this->assertNotSame($transport->calls[0]['key'], $transport->calls[1]['key']);
    }

    /**
     * The one distinction this library exists to get right. A 402 means come back with a
     * bigger plan; asking again cannot help and only burns the caller's time.
     */
    public function test_it_never_retries_a_quota(): void
    {
        [$tf, $transport] = $this->client([
            [402, ['error' => ['code' => 'quota_exhausted'], 'credits' => ['remaining' => 3, 'required' => 40]]],
        ]);

        try {
            $tf->image('https://cdn.example.test/a.jpg');
            $this->fail('A quota failure has to be raised.');
        } catch (QuotaExhausted $e) {
            $this->assertSame(3, $e->remaining());
            $this->assertSame(40, $e->required());
        }

        $this->assertCount(1, $transport->calls);
        $this->assertSame([], $tf->waited);
    }

    public function test_it_gives_up_eventually(): void
    {
        [$tf, $transport] = $this->client([
            [500, []],
            [500, []],
            [500, []],
        ]);

        $this->expectException(ServerError::class);

        try {
            $tf->text('hello');
        } finally {
            $this->assertCount(3, $transport->calls, 'Two retries on top of the first attempt.');
        }
    }

    public function test_a_rate_limit_that_never_clears_is_still_raised(): void
    {
        [$tf] = $this->client([
            [429, ['retry_after' => 1]],
            [429, ['retry_after' => 1]],
            [429, ['retry_after' => 1]],
        ]);

        $this->expectException(RateLimited::class);

        $tf->text('hello');
    }

    public function test_a_bad_request_is_typed(): void
    {
        [$tf] = $this->client([
            [422, ['error' => ['code' => 'validation_failed'], 'errors' => ['content' => ['The content field is required.']]]],
        ]);

        try {
            $tf->text('');
            $this->fail('A 422 has to be raised.');
        } catch (InvalidRequest $e) {
            $this->assertArrayHasKey('content', $e->fields());
            $this->assertSame(422, $e->status);
        }
    }

    public function test_a_transport_failure_is_retried_like_a_server_error(): void
    {
        [$tf, $transport] = $this->client([
            [0, new ServerError('Could not reach ToxicFilter: connection refused')],
            [200, $this->verdict()],
        ]);

        $verdict = $tf->text('hello');

        $this->assertSame('review', $verdict->decision());
        $this->assertCount(2, $transport->calls);
    }

    public function test_a_batch_separates_verdicts_from_failures(): void
    {
        [$tf, $transport] = $this->client([[200, [
            'batch_id' => 'batch_01',
            'status' => 'completed',
            'count' => 2,
            'processed' => 2,
            'failed' => 1,
            'credits_charged' => 13,
            'results' => [
                ['index' => 0, 'reference' => 'c_1'] + $this->verdict(),
                ['index' => 1, 'reference' => 'c_2', 'error' => ['code' => 'invalid_item', 'message' => 'kind is required']],
            ],
        ]]]);

        $batch = $tf->batch([
            ['kind' => 'text', 'content' => 'one', 'reference' => 'c_1'],
            ['kind' => 'text', 'reference' => 'c_2'],
        ]);

        $this->assertSame('batch_01', $batch->id());
        $this->assertTrue($batch->finished());
        $this->assertSame(13, $batch->creditsCharged());
        $this->assertCount(1, $batch->verdicts());
        $this->assertCount(1, $batch->failures());
        $this->assertSame('c_1', $batch->verdicts()[0]->reference(), 'Keyed by the position it was sent in.');
        $this->assertSame('invalid_item', $batch->failures()[1]['code']);
        $this->assertSame('https://example.test/api/v1/batch', $transport->calls[0]['url']);
    }

    public function test_async_sets_the_flag(): void
    {
        [$tf, $transport] = $this->client([[202, ['batch_id' => 'batch_02', 'status' => 'queued', 'count' => 1]]]);

        $batch = $tf->batchAsync([['kind' => 'text', 'content' => 'one']]);

        $this->assertTrue($transport->calls[0]['body']['async']);
        $this->assertFalse($batch->finished());
    }

    public function test_it_reads_and_resolves_the_queue(): void
    {
        [$tf, $transport] = $this->client([
            [200, ['data' => [$this->verdict()], 'next_cursor' => null]],
            [200, $this->verdict(['review_state' => 'resolved'])],
        ]);

        $tf->records(['state' => 'open']);
        $tf->resolve('mod_01', 'approved', 'ana@example.com');

        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertStringContainsString('/api/v1/records', $transport->calls[0]['url']);
        $this->assertStringContainsString('state=open', $transport->calls[0]['url']);

        $this->assertSame('POST', $transport->calls[1]['method']);
        $this->assertSame('approved', $transport->calls[1]['body']['action']);
        $this->assertSame('ana@example.com', $transport->calls[1]['body']['moderator']);
    }

    /**
     * A GET has nothing to make idempotent, and sending a key on one only teaches a proxy
     * to cache something it should not.
     */
    public function test_reads_carry_no_idempotency_key(): void
    {
        [$tf, $transport] = $this->client([
            [200, ['ok' => true]],
            [200, ['credits' => ['remaining' => 10]]],
        ]);

        $tf->ping();
        $tf->usage();

        $this->assertNull($transport->calls[0]['key']);
        $this->assertNull($transport->calls[1]['key']);
    }
}
