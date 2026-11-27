<?php

declare(strict_types=1);

namespace W4OS\Engine\Testing;

use W4OS\Engine\Runtime\Process;
use W4OS\Engine\Runtime\ProcessTableInterface;

/**
 * The processes of a machine that has only the ones a test gives it.
 */
final class ProcessTableFake implements ProcessTableInterface
{
    /** @var array<int,Process> */
    public array $processes = [];

    /** The CPUs of the machine. */
    public int $cpus = 1;

    /** The memory of the machine, in kilobytes. */
    public int $memory = 8 * 1024 * 1024;

    /** @var list<string> the signals sent, "TERM 123" */
    public array $signals = [];

    /** @var list<string> the signals that make a process go: the others are ignored by it */
    public array $obeys = ['TERM', 'KILL'];

    /** @var list<int> the processes of another account, this one may not signal */
    public array $foreign = [];

    /** @var list<int> the ports bound on the machine */
    public array $listening = [];

    private int $next = 4000;

    public function add(
        string $arguments,
        string $user = 'opensim',
        float $cpu = 1.5,
        float $memory = 2.5,
        int $rss = 100000,
    ): Process {
        $process = new Process($this->next++, $user, $cpu, $memory, $rss, $arguments);
        $this->processes[$process->pid] = $process;

        return $process;
    }

    public function remove(int $pid): void
    {
        unset($this->processes[$pid]);
    }

    public function all(): array
    {
        return array_values($this->processes);
    }

    public function alive(int $pid): bool
    {
        return isset($this->processes[$pid]);
    }

    public function listening(): array
    {
        return $this->listening;
    }

    public function controls(int $pid): bool
    {
        return !in_array($pid, $this->foreign, true);
    }

    public function signal(int $pid, string $signal): bool
    {
        $this->signals[] = "$signal $pid";
        if (in_array($signal, $this->obeys, true)) {
            $this->remove($pid);
        }

        return true;
    }

    public function cpus(): int
    {
        return $this->cpus;
    }

    public function memory(): int
    {
        return $this->memory;
    }
}
