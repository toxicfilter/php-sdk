<?php

namespace ToxicFilter\Tests;

use ToxicFilter\BatchResult;
use ToxicFilter\Verdict;

/**
 * The fields the API returns and this client could not reach.
 *
 * A caller holding a `Verdict` had no way to get at the masked content, the repetition
 * counts, what a shadow policy would have said, the cursor for the next page of a batch, or
 * anything about where a held verdict stands in the queue. All of it was in `raw`, which
 * means the client was answering "dig it out yourself" about fields its own service
 * documents — and a field somebody reads out of `raw` is a field this client is free to
 * break.
 */
final class ReadableFieldsTest extends TestCase
{
    public function test_it_reads_the_masked_content(): void
    {
        [$tf] = $this->client([[200, $this->verdict([
            'redacted' => 'call me on [redacted]',
        ])]]);

        $this->assertSame('call me on [redacted]', $tf->text('call me on 600 123 456', ['redact' => true])->redacted());
    }

    public function test_content_that_was_not_masked_is_null_and_not_empty(): void
    {
        [$tf] = $this->client([[200, $this->verdict()]]);

        // Null and not '': you did not ask, which is a different statement from "there was
        // nothing to mask" and must not read as "the content is empty".
        $this->assertNull($tf->text('anything')->redacted());
    }

    /**
     * The signal that is not in the message: the same thing arriving forty-seven times.
     */
    public function test_it_reads_the_context(): void
    {
        [$tf] = $this->client([[200, $this->verdict([
            'context' => [
                'actor' => 'u_91',
                'repeats' => 47,
                'similar' => 12,
                'history' => ['seen' => 800, 'blocked' => 2, 'adjustment' => 0.1],
            ],
        ])]]);

        $context = $tf->text('buy now')->context();

        $this->assertSame(47, $context['repeats']);
        $this->assertSame(12, $context['similar']);
        // The adjustment is always reported, because a line moved by somebody's record with
        // no way to see it is not something you can defend.
        $this->assertSame(0.1, $context['history']['adjustment']);
    }

    public function test_a_verdict_judged_on_its_own_has_no_context(): void
    {
        [$tf] = $this->client([[200, $this->verdict()]]);

        $this->assertSame([], $tf->text('anything')->context());
    }

    /**
     * Shadow mode: both verdicts computed, one decides, and you get to see them disagree
     * over your own traffic before you mean it.
     */
    public function test_it_reads_what_the_trialled_policy_would_have_said(): void
    {
        [$tf] = $this->client([[200, $this->verdict([
            'decision' => 'review',
            'shadow' => ['slug' => 'stricter', 'version' => 3, 'decision' => 'block'],
        ])]]);

        $verdict = $tf->text('you fucking legend');

        $this->assertSame('review', $verdict->decision(), 'The live policy decides; the trial never does.');
        $this->assertSame('block', $verdict->shadow()['decision']);
        $this->assertSame('stricter', $verdict->shadow()['slug']);
        $this->assertSame(3, $verdict->shadow()['version']);
    }

    /**
     * A stored verdict spells the same two facts flat, as its columns are named.
     */
    public function test_it_reads_a_stored_verdicts_flat_shadow_fields(): void
    {
        $verdict = new Verdict(['decision' => 'review', 'shadow_slug' => 'stricter', 'shadow_decision' => 'block']);

        $this->assertSame('stricter', $verdict->shadow()['slug']);
        $this->assertSame('block', $verdict->shadow()['decision']);
    }

    public function test_no_shadow_policy_is_null(): void
    {
        [$tf] = $this->client([[200, $this->verdict()]]);

        $this->assertNull($tf->text('anything')->shadow());
    }

    /**
     * Where a held verdict stands, and what has been said about it. Only `review` opens an
     * entry, so everything here is empty on a verdict that was allowed outright.
     */
    public function test_it_reads_the_queue_state(): void
    {
        [$tf] = $this->client([[200, $this->verdict([
            'kind' => 'text',
            'created_at' => '2026-09-17T10:00:00+00:00',
            'batch_id' => 'batch_01',
            'took_ms' => 3,
            'review' => [
                'state' => 'approved',
                'resolved_at' => '2026-09-17T11:00:00+00:00',
                'resolved_by' => 'ana@example.com',
                'note' => 'Enthusiasm.',
            ],
            'feedback' => ['verdict' => 'false_positive', 'note' => null, 'at' => '2026-09-17T11:01:00+00:00'],
            'content' => 'you fucking legend',
            'content_expires_at' => '2026-09-17T17:00:00+00:00',
        ])]]);

        $verdict = $tf->record('mod_01');

        $this->assertSame('approved', $verdict->reviewState());
        $this->assertTrue($verdict->resolved());
        $this->assertSame('ana@example.com', $verdict->resolvedBy());
        $this->assertSame('2026-09-17T11:00:00+00:00', $verdict->resolvedAt());
        $this->assertSame('Enthusiasm.', $verdict->review()['note']);
        $this->assertSame('false_positive', $verdict->feedback()['verdict']);
        $this->assertSame('you fucking legend', $verdict->content());
        $this->assertSame('2026-09-17T17:00:00+00:00', $verdict->contentExpiresAt());
        $this->assertSame('text', $verdict->kind());
        $this->assertSame('2026-09-17T10:00:00+00:00', $verdict->createdAt());
        $this->assertSame('batch_01', $verdict->batchId());
        $this->assertSame(3, $verdict->tookMs());
    }

    /**
     * An open entry nobody has touched, and the difference between "not resolved" and "no
     * feedback": one is a state, the other is an absence.
     */
    public function test_an_open_entry_is_not_resolved_and_has_no_feedback(): void
    {
        $verdict = new Verdict(['decision' => 'review', 'review' => ['state' => 'open'], 'feedback' => null]);

        $this->assertSame('open', $verdict->reviewState());
        $this->assertFalse($verdict->resolved());
        $this->assertNull($verdict->feedback());
        // Retention is off by default, so there is normally nothing to show a moderator.
        $this->assertNull($verdict->content());
    }

    /**
     * The cursor a batch page comes back with. Without it there is no way to ask for the
     * rest, and an offset would skip whatever a worker inserted behind it.
     */
    public function test_a_batch_page_carries_its_cursor(): void
    {
        [$tf, $transport] = $this->client([
            [200, ['batch_id' => 'batch_01', 'status' => 'running', 'results' => [], 'next_after' => 99]],
            [200, ['batch_id' => 'batch_01', 'status' => 'completed', 'results' => [], 'next_after' => null]],
        ]);

        $first = $tf->batchStatus('batch_01');

        $this->assertSame(99, $first->nextAfter());
        $this->assertTrue($first->hasMore());

        $last = $tf->batchStatus('batch_01', ['after' => $first->nextAfter()]);

        $this->assertNull($last->nextAfter());
        $this->assertFalse($last->hasMore());
        $this->assertStringContainsString('after=99', $transport->calls[1]['url']);
    }

    public function test_a_page_with_no_cursor_at_all_is_the_last_one(): void
    {
        $this->assertNull((new BatchResult(['batch_id' => 'b', 'status' => 'completed']))->nextAfter());
    }
}
