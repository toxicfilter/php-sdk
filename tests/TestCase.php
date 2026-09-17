<?php

namespace ToxicFilter\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    /**
     * A verdict shaped like the real ones, with the second axis on it.
     *
     * @return array<string, mixed>
     */
    protected function verdict(array $overrides = []): array
    {
        return array_replace([
            'id' => 'mod_01',
            'reference' => 'c_1',
            'decision' => 'review',
            'flagged' => ['toxicity'],
            'scores' => ['toxicity' => 0.55],
            'topics' => ['gambling' => 0.82],
            'leads' => ['free_work_for_equity' => 0.9],
            'signals' => [[
                'category' => 'toxicity',
                'score' => 0.55,
                'detector' => 'term',
                'reason' => 'Contains 1 profanity.',
            ]],
            'facts' => ['language' => 'en'],
            'used_ai' => false,
            'cached' => false,
            'degraded' => false,
            'took_ms' => 2,
            'policy' => ['slug' => 'house', 'version' => 4],
            'credits' => ['remaining' => 940, 'charged' => 1],
        ], $overrides);
    }

    /**
     * @param list<array{0: int, 1: mixed}> $responses
     * @return array{0: SleeplessClient, 1: FakeTransport}
     */
    protected function client(array $responses, int $retries = 2): array
    {
        $transport = new FakeTransport($responses);

        return [
            new SleeplessClient('tf_test_key', 'https://example.test', $retries, $transport),
            $transport,
        ];
    }
}
