<?php

namespace ToxicFilter\Tests;

use ToxicFilter\Client;

/**
 * The client with its waiting removed.
 *
 * `wait()` is `protected` for exactly this: a suite that really slept through a growing
 * backoff would take a minute to prove something that is true immediately, and a test
 * nobody runs because it is slow is a test that does not exist. What was waited for is
 * still recorded, because the LENGTH of the wait is part of the behaviour.
 */
final class SleeplessClient extends Client
{
    /** @var list<int> */
    public array $waited = [];

    protected function wait(int $seconds): void
    {
        $this->waited[] = $seconds;
    }
}
