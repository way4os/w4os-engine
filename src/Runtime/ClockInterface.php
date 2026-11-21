<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

/**
 * The time of the machine: what waits (a start, a stop) asks, so that a test does not wait.
 */
interface ClockInterface
{
    /** Wait for some seconds. */
    public function sleep(float $seconds): void;

    /** The time, in seconds. */
    public function time(): float;
}
