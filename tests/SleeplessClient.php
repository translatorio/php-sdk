<?php

namespace Translatorio\Tests;

use Translatorio\Client;

/**
 * The client with its waiting removed.
 *
 * `wait()` is `protected` for exactly this: a suite that really slept through a growing
 * backoff would take a minute to prove something that is true immediately. What was waited
 * for is still recorded, because the LENGTH of the wait is part of the behaviour.
 */
final class SleeplessClient extends Client
{
    /** @var list<int> */
    public array $waited = [];

    /**
     * @param int $seconds
     * @return void
     */
    protected function wait(int $seconds): void
    {
        $this->waited[] = $seconds;
    }
}
