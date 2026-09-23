<?php

namespace ToxicFilter\Tests;

use ToxicFilter\BatchResult;

/**
 * Every item that never became a verdict, wherever the API filed it.
 *
 * A batch read back with `batchStatus()` always carries `results` (the verdicts filed so
 * far) and puts the item errors in `errors`, so reading `results` first and `errors` only
 * when it was missing reported an async batch as having no failures at all. A sync batch
 * carries the rejected items in both lists, and a chunk that died is filed with no index.
 */
final class BatchFailuresTest extends TestCase
{
    public function test_a_batch_read_back_reports_the_failures_in_errors(): void
    {
        $batch = new BatchResult([
            'batch_id' => 'batch_01',
            'status' => 'completed',
            'results' => [['index' => 0] + $this->verdict()],
            'errors' => [
                ['index' => 3, 'error' => ['code' => 'unknown_policy', 'message' => 'No such policy.']],
            ],
        ]);

        $this->assertCount(1, $batch->verdicts());
        $this->assertSame([3 => ['code' => 'unknown_policy', 'message' => 'No such policy.']], $batch->failures());
    }

    public function test_an_item_in_both_lists_is_one_failure(): void
    {
        $error = ['index' => 1, 'reference' => 'c_2', 'error' => ['code' => 'invalid_item', 'message' => 'kind is required']];

        $batch = new BatchResult([
            'results' => [['index' => 0] + $this->verdict(), $error],
            'errors' => [$error],
        ]);

        $this->assertSame([1 => $error['error']], $batch->failures());
    }

    public function test_a_failure_with_no_position_never_takes_a_real_one(): void
    {
        $batch = new BatchResult([
            'results' => [],
            'errors' => [
                ['error' => ['code' => 'chunk_failed', 'message' => '25 items in this batch could not be processed.']],
                ['index' => 0, 'error' => ['code' => 'internal_error', 'message' => 'Could not be judged.']],
                ['error' => ['code' => 'chunk_failed', 'message' => '3 items in this batch could not be processed.']],
            ],
        ]);

        $failures = $batch->failures();

        $this->assertSame('internal_error', $failures[0]['code'], 'Item 0 keeps its own position.');
        $this->assertSame('chunk_failed', $failures[-1]['code']);
        $this->assertSame('chunk_failed', $failures[-2]['code']);
        $this->assertCount(3, $failures);
    }
}
