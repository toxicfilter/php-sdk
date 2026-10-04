<?php

namespace ToxicFilter\Tests;

use ToxicFilter\Exception\ApiError;
use ToxicFilter\Exception\ServerError;
use ToxicFilter\Verdict;

/**
 * Statements of reasons, appeals and the Transparency Database export.
 */
final class StatementsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function statementBody(): array
    {
        return [
            'restrictions' => ['removal'],
            'territories' => [],
            'duration' => null,
            'facts' => ['flagged' => ['harassment'], 'reasons' => ['Insults aimed at the reader'], 'source' => 'own_initiative'],
            'automated' => ['detection' => true, 'decision' => true],
            'ground' => ['type' => 'terms', 'policy' => ['slug' => 'comments', 'version' => 3], 'clauses' => [], 'terms_url' => null],
            'redress' => ['internal' => 'appeals@example.com', 'out_of_court' => true, 'judicial' => true],
            'locale' => 'en',
            'text' => 'We have removed your content.',
        ];
    }

    public function test_a_verdict_carries_its_statement(): void
    {
        $verdict = new Verdict($this->verdict(['decision' => 'block', 'statement' => $this->statementBody()]));

        $this->assertSame('removal', $verdict->statement()['restrictions'][0]);
        $this->assertSame('We have removed your content.', $verdict->statementText());
    }

    public function test_a_verdict_without_a_statement_says_null(): void
    {
        $verdict = new Verdict($this->verdict());

        $this->assertNull($verdict->statement());
        $this->assertNull($verdict->statementText());
        $this->assertNull($verdict->appeal());
        $this->assertNull($verdict->appealDecision());
        $this->assertNull($verdict->transparency());
    }

    public function test_a_record_carries_its_appeal_and_its_filing(): void
    {
        $verdict = new Verdict($this->verdict([
            'appeal' => ['state' => 'open', 'filed_at' => '2026-10-04T10:00:00+00:00', 'reason' => 'A recipe.', 'resolved_at' => null, 'resolved_by' => null, 'explanation' => null],
            'transparency' => ['uuid' => '9f1c', 'submitted_at' => '2026-10-04T10:01:00+00:00'],
        ]));

        $this->assertSame('open', $verdict->appeal()['state']);
        $this->assertSame('9f1c', $verdict->transparency()['uuid']);
    }

    public function test_it_fetches_a_statement_later_in_a_language(): void
    {
        [$tf, $transport] = $this->client([[200, ['id' => 'mod_01', 'statement' => $this->statementBody()]]]);

        $statement = $tf->statement('mod_01', 'es');

        $this->assertSame('removal', $statement['restrictions'][0]);
        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertSame('https://example.test/api/v1/records/mod_01/statement?locale=es', $transport->calls[0]['url']);
        $this->assertNull($transport->calls[0]['key']);
    }

    public function test_no_restriction_is_raised_and_never_retried(): void
    {
        [$tf, $transport] = $this->client([
            [409, ['error' => ['code' => 'no_restriction', 'message' => 'This verdict restricts nothing.']]],
        ]);

        try {
            $tf->statement('mod_01');
            $this->fail('A 409 has to be raised.');
        } catch (ApiError $e) {
            $this->assertNotInstanceOf(ServerError::class, $e);
            $this->assertSame('no_restriction', $e->errorCode);
            $this->assertSame(409, $e->status);
        }

        $this->assertCount(1, $transport->calls);
        $this->assertSame('https://example.test/api/v1/records/mod_01/statement', $transport->calls[0]['url']);
    }

    public function test_a_call_still_in_flight_is_still_retried(): void
    {
        [$tf, $transport] = $this->client([
            [409, ['error' => ['code' => 'idempotency_in_flight', 'message' => 'Still running.']]],
            [200, $this->verdict()],
        ]);

        $tf->text('hello');

        $this->assertCount(2, $transport->calls);
    }

    public function test_it_files_an_appeal(): void
    {
        [$tf, $transport] = $this->client([[201, $this->verdict([
            'decision' => 'block',
            'appeal' => ['state' => 'open', 'filed_at' => '2026-10-04T10:00:00+00:00', 'reason' => 'A recipe.'],
        ])]]);

        $verdict = $tf->appeal('mod_01', 'A recipe.');

        $this->assertSame('POST', $transport->calls[0]['method']);
        $this->assertSame('https://example.test/api/v1/records/mod_01/appeal', $transport->calls[0]['url']);
        $this->assertSame(['reason' => 'A recipe.'], $transport->calls[0]['body']);
        $this->assertNotNull($transport->calls[0]['key']);
        $this->assertSame('open', $verdict->appeal()['state']);
    }

    public function test_an_appeal_without_words_sends_an_empty_body(): void
    {
        [$tf, $transport] = $this->client([[201, $this->verdict(['appeal' => ['state' => 'open']])]]);

        $tf->appeal('mod_01');

        $this->assertSame([], $transport->calls[0]['body']);
    }

    public function test_it_resolves_an_appeal_and_reads_the_decision(): void
    {
        [$tf, $transport] = $this->client([[200, $this->verdict([
            'appeal' => ['state' => 'upheld', 'resolved_by' => 'ana', 'explanation' => 'Because.'],
            'appeal_decision' => 'We have reviewed your appeal and upheld our decision.',
        ])]]);

        $verdict = $tf->resolveAppeal('mod_01', 'upheld', 'ana', 'Because.', 'es');

        $this->assertSame('https://example.test/api/v1/records/mod_01/appeal/resolve', $transport->calls[0]['url']);
        $this->assertSame(
            ['outcome' => 'upheld', 'moderator' => 'ana', 'explanation' => 'Because.', 'locale' => 'es'],
            $transport->calls[0]['body'],
        );
        $this->assertSame('We have reviewed your appeal and upheld our decision.', $verdict->appealDecision());
        $this->assertSame('upheld', $verdict->appeal()['state']);
    }

    public function test_it_exports_a_period_for_the_transparency_database(): void
    {
        [$tf, $transport] = $this->client([[200, ['statements' => [['puid' => 'mod_01']], 'next' => 100]]]);

        $page = $tf->transparency('2026-10-01', '2026-10-31', 'forum', 50);

        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertSame(
            'https://example.test/api/v1/statements/transparency?since=2026-10-01&until=2026-10-31&project=forum&after=50',
            $transport->calls[0]['url'],
        );
        $this->assertSame('mod_01', $page['statements'][0]['puid']);
        $this->assertSame(100, $page['next']);
    }

    public function test_an_export_sends_only_what_was_given(): void
    {
        [$tf, $transport] = $this->client([[200, ['statements' => [], 'next' => null]]]);

        $tf->transparency('2026-10-01');

        $this->assertSame('https://example.test/api/v1/statements/transparency?since=2026-10-01', $transport->calls[0]['url']);
    }
}
