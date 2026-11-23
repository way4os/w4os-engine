<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

use W4OS\Engine\Controllers\AbstractController;
use W4OS\Engine\Controllers\OpenSimController;

/**
 * How a simulator loads, shown while its start is waited for: what the process prints first, then the steps its log reports
 * (see StartupProgress), each one once.
 */
final class StartProgress
{
    /** @var array<string,true> */
    private array $shown = [];
    private readonly int $regions;

    /** @var list<string> */
    private readonly array $names;

    /**
     * @param int $mark where the log of this start begins (see LogFile::mark())
     * @param string $output the file the process writes its first output to
     */
    public function __construct(
        private readonly Environment $environment,
        private readonly AbstractController $instance,
        private readonly LogFile $log,
        private readonly int $mark,
        private readonly string $output = '',
    ) {
        $names = [];
        if ($instance instanceof OpenSimController && $instance->regionsDirectory() !== '') {
            foreach (glob($instance->regionsDirectory() . '/*.ini') ?: [] as $file) {
                preg_match_all('/^[ \t]*\[([^\]]+)\]/m', (string) file_get_contents($file), $found);
                array_push($names, ...array_map('trim', $found[1]));
            }
        }
        $this->names = array_values(array_unique($names));
        $this->regions = max(1, count($this->names));
    }

    /** @return list<string> the names of its regions, by its region files */
    public function names(): array
    {
        return $this->names;
    }

    /** Whether all its regions said they are up, in its log or in this text (its screen). */
    public function up(string $screen = ''): bool
    {
        preg_match_all(StartService::REGION_UP, $this->log->since($this->mark) . "\n" . $screen, $found);
        $up = array_unique(array_filter([...$found[1], ...$found[2]], 'strlen'));

        return count($up) >= $this->regions;
    }

    /** Whether its log cannot tell when its regions are up: it writes, but no DEBUG line, where they would be said. */
    public function blind(): bool
    {
        $since = $this->log->since($this->mark);

        return preg_match('/^\S+ \S+ (?:INFO|WARN) /m', $since) === 1 && preg_match('/^\S+ \S+ DEBUG /m', $since) !== 1;
    }

    /** Show the steps not shown yet. */
    public function show(): void
    {
        $steps = [];
        if ($this->output !== '' && is_file($this->output)) {
            array_push(
                $steps,
                ...StartupProgress::steps((string) file_get_contents($this->output), $this->regions, true),
            );
        }
        array_push($steps, ...StartupProgress::steps($this->log->since($this->mark), $this->regions));
        foreach ($steps as $step) {
            if (!isset($this->shown[$step])) {
                $this->shown[$step] = true;
                $this->environment->reporter->step($step);
            }
        }
    }

    /** End of the progress: the steps not shown yet, then no more. */
    public function finish(): void
    {
        $this->show();
        $this->environment->reporter->stepEnd();
    }
}
