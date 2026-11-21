<?php

declare(strict_types=1);

namespace W4OS\Engine\Testing;

use W4OS\Engine\Runtime\LaunchSpec;
use W4OS\Engine\Runtime\LauncherInterface;

/**
 * Launches nothing: the process appears in the table of the fake machine, and what the instance would write is written.
 */
final class LauncherFake implements LauncherInterface
{
    /** @var list<LaunchSpec> */
    public array $launched = [];

    /** @var ?\Closure(LaunchSpec):void what the launched instance does (writes its log...) */
    public ?\Closure $behavior = null;

    public bool $exitsAtOnce = false;

    public function __construct(private readonly ProcessTableFake $processes) {}

    public function launch(LaunchSpec $spec): bool
    {
        $this->launched[] = $spec;
        if (!$this->exitsAtOnce) {
            $this->processes->add(implode(' ', $spec->command));
        }
        if ($this->behavior !== null) {
            ($this->behavior)($spec);
        }

        return true;
    }
}
