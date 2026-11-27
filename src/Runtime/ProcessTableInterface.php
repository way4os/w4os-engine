<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

/**
 * The processes of the machine: the ones of the instances, and the signals sent to them.
 */
interface ProcessTableInterface
{
    /**
     * The OpenSimulator processes running (dotnet and mono started with a config file).
     *
     * @return list<Process>
     */
    public function all(): array;

    /** Whether a process still exists. */
    public function alive(int $pid): bool;

    /** Send a signal (TERM, KILL) to a process. */
    public function signal(int $pid, string $signal): bool;

    /** Whether this process may signal it (another account's is not, unless this one is root). */
    public function controls(int $pid): bool;

    /**
     * The ports bound on the machine: a TCP socket that listens, a UDP socket.
     *
     * @return list<int>
     */
    public function listening(): array;

    /** How many CPUs the machine has. */
    public function cpus(): int;

    /** The memory of the machine, in kilobytes (0 when it cannot tell). */
    public function memory(): int;
}
