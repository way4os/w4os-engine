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

    /**
     * The modules enabled that the config of a simulator does not set up, kept off at its start (ModuleLinker::unconfigured()).
     *
     * @param list<string> $modules the modules enabled
     * @return array<string,list<string>> module => the simulators it is off for
     */
    public function unconfigured(array $modules): array
    {
        $off = [];
        foreach ($this->instances as $instance) {
            if (!$instance instanceof \W4OS\Engine\Controllers\OpenSimController) {
                continue;
            }
            foreach (array_keys(\W4OS\Engine\Runtime\ModuleLinker::unconfigured($modules, \W4OS\Engine\Config\ConfigReader::expand($instance->settings()))) as $module) {
                $off[$module][] = $instance->name();
            }
        }

        return $off;
    }

    /**
     * The Robusts that run and answer `/get_grid_info` with what a viewer refuses: not UTF-8, or not well-formed XML
     * (GridInfoReader::defect()).
     *
     * @param ?\Closure(string):?string $get reads an address (Http::get() without it)
     * @return array<string,string> name => what is wrong
     */
    public function brokenGridInfo(?\Closure $get = null): array
    {
        $get ??= static fn(string $url): ?string => \W4OS\Engine\Network\Http::get($url, 2);
        $broken = [];
        foreach ($this->instances as $instance) {
            $port = $instance instanceof \W4OS\Engine\Controllers\RobustController ? (int) $instance->publicPort() : 0;
            $body = $port > 0 && $instance->process() !== null ? $get("http://127.0.0.1:$port/get_grid_info") : null;
            is_string($body) && $body !== '' && ($defect = \W4OS\Engine\Network\GridInfoReader::defect($body)) !== null && ($broken[$instance->name()] = $defect);
        }

        return $broken;
    }

    /**
     * The instances that run on a .NET major other than the one their core targets (RuntimeProbe::wrongRuntime()).
     *
     * @return array<string,array{running:int,target:int}> name => the major it runs on, the one it targets
     */
    public function wrongRuntimes(RuntimeProbe $probe): array
    {
        $wrong = [];
        foreach ($this->instances as $instance) {
            ($found = $probe->wrongRuntime($instance)) === null || ($wrong[$instance->name()] = $found);
        }

        return $wrong;
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
