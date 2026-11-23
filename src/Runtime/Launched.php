<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

use W4OS\Engine\Controllers\AbstractController;

/** An instance launched and not waited for yet (StartService::launch()): what its wait reads, and when it was launched. */
final class Launched
{
    public function __construct(
        public readonly AbstractController $instance,
        public readonly string $base,
        public readonly LogFile $log,
        public readonly int $mark,
        public readonly string $output,
        public readonly bool $remote,
        public readonly float $at,
    ) {}
}
