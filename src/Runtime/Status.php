<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

use W4OS\Engine\Controllers\AbstractController;

/**
 * The instances of this machine and what they use: the enabled ones, running or not, and the totals of the OpenSimulator
 * processes that run.
 */
final class Status
{
    /**
     * @param list<AbstractController> $instances
     * @param list<Process> $processes every OpenSimulator process of the machine, enabled instances or not
     */
    public function __construct(public readonly array $instances, public readonly array $processes) {}

    public function up(): int
    {
        return count(array_filter($this->instances, static fn(AbstractController $i): bool => $i->process() !== null));
    }

    public function down(): int
    {
        return count($this->instances) - $this->up();
    }

    /**
     * The Robusts that run without their database: they answer, with an error, what needs it (RobustController::databaseAnswers()).
     *
     * @param ?\Closure(string,array<string,string>):array{0:int,1:string} $post the POST of the probe (Http::exchange() without it)
     * @return list<string> their names
     */
    public function withoutDatabase(?\Closure $post = null): array
    {
        $names = [];
        foreach ($this->instances as $instance) {
            if ($instance instanceof \W4OS\Engine\Controllers\RobustController && $instance->process() !== null && $instance->databaseAnswers($post) === false) {
                $names[] = $instance->name();
            }
        }

        return $names;
    }

    /**
     * The simulators whose process runs and that do not answer at /simstatus/ within two seconds: hung, or still loading.
     *
     * @param ?\Closure(string):?string $get reads an address (Http::get() without it)
     * @return list<string> their names
     */
    public function unresponsive(?\Closure $get = null): array
    {
        $get ??= static fn(string $url): ?string => \W4OS\Engine\Network\Http::get($url, 2);
        $names = [];
        foreach ($this->instances as $instance) {
            $port = $instance instanceof \W4OS\Engine\Controllers\OpenSimController ? (int) $instance->httpPort() : 0;
            if ($port > 0 && $instance->process() !== null && trim((string) $get("http://127.0.0.1:$port/simstatus/")) !== 'OK') {
                $names[] = $instance->name();
            }
        }

        return $names;
    }

    /** @return array{cpu:float,memory:float,rss:int} */
    public function totals(): array
    {
        return [
            'cpu' => array_sum(array_map(static fn(Process $p): float => $p->cpu, $this->processes)),
            'memory' => array_sum(array_map(static fn(Process $p): float => $p->memory, $this->processes)),
            'rss' => array_sum(array_map(static fn(Process $p): int => $p->rss, $this->processes)),
        ];
    }
}
