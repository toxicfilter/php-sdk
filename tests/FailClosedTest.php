<?php

namespace ToxicFilter\Tests;

use ToxicFilter\Exception\ServerError;
use ToxicFilter\Verdict;

/**
 * Nothing that is not an answer from the API ever reads as `allow`.
 *
 * 1.0.0 treated every status under 400 as success, decoded a body that was not JSON into an
 * empty array, and read a missing `decision` as `allow`. So a base URL on http (answered
 * with a 301 the transport deliberately does not follow) or a proxy's HTML page served with
 * a 200 came back as "publish it" for every comment on the site. A moderation client that
 * fails open is a moderation client that is switched off without anybody knowing.
 */
final class FailClosedTest extends TestCase
{
    public function test_a_redirect_is_never_an_answer(): void
    {
        [$tf, $transport] = $this->client([
            [301, '', true],
            [301, '', true],
            [301, '', true],
        ]);

        try {
            $tf->text('anything');
            $this->fail('A redirect was read as a verdict.');
        } catch (ServerError $e) {
            $this->assertSame(301, $e->status);
            $this->assertStringContainsString('redirect', $e->getMessage());
            $this->assertStringContainsString('https', $e->getMessage());
        }

        $this->assertCount(3, $transport->calls, 'Reported as retryable, like any other failure of the path.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bodiesThatAreNotAnswers(): array
    {
        return [
            'a proxy page' => ['<html><body>Welcome to nginx!</body></html>'],
            'nothing at all' => [''],
            'a JSON list' => ['[]'],
            'a JSON string' => ['"ok"'],
            'JSON null' => ['null'],
        ];
    }

    /**
     * @dataProvider bodiesThatAreNotAnswers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bodiesThatAreNotAnswers')]
    public function test_a_success_that_is_not_a_json_object_is_never_an_answer(string $body): void
    {
        [$tf] = $this->client([[200, $body, true]], retries: 0);

        try {
            $tf->text('anything');
            $this->fail('A body that is not a JSON object was read as a verdict.');
        } catch (ServerError $e) {
            $this->assertSame(200, $e->status);
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_a_verdict_without_a_decision_never_reads_as_allowed(): void
    {
        $verdict = new Verdict(['id' => 'mod_01']);

        foreach (['decision', 'allowed', 'needsReview', 'blocked'] as $method) {
            try {
                $verdict->{$method}();
                $this->fail("$method() answered on a verdict that carried no decision.");
            } catch (ServerError $e) {
                $this->assertStringContainsString('decision', $e->getMessage());
            }
        }

        // Everything that is not the decision still reads, so a caller can log what came.
        $this->assertSame('mod_01', $verdict->id());
    }

    public function test_a_decision_nobody_knows_is_refused_rather_than_published(): void
    {
        // Not `allow`, not `review`, not `block`: every one of the three checks says no, and
        // the pattern in the README would then publish it.
        $this->expectException(ServerError::class);

        (new Verdict(['decision' => 'maybe']))->allowed();
    }

    public function test_a_json_object_with_a_decision_still_reads(): void
    {
        [$tf] = $this->client([[200, $this->verdict(['decision' => 'block'])]]);

        $this->assertTrue($tf->text('anything')->blocked());
    }
}
