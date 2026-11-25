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
     * The simulators that draw the simple map (MapImageModule), by grid, and what gives them the 3D one (Warp3DImageModule): the
     * option map-warp3d of their grid where their core lets them write their tiles (remedy null), else what would (MapRenderer).
     *
     * @param string $account the account they run as (SystemUser), empty for the one running this code
     * @return array<string,array{remedy:?string,simulators:list<string>}>
     */
    public function simpleMaps(string $account = ''): array
    {
        $simple = [];
        foreach ($this->instances as $instance) {
            $core = $instance instanceof \W4OS\Engine\Controllers\OpenSimController ? (string) $instance->core()?->directory() : '';
            $module = $core === '' ? '' : trim((string) (\W4OS\Engine\Config\ConfigReader::expand($instance->settings())['Map']['MapImageModule'] ?? ''), " \t\"");
            if ($core === '' || strcasecmp($module, \W4OS\Engine\Provisioning\MapRenderer::WARP3D) === 0) {
                continue;
            }
            $can = \W4OS\Engine\Provisioning\MapRenderer::hasFolderSetting($core) || \W4OS\Engine\Provisioning\MapRenderer::whyNotWarp3D($core, '', $account) === null;
            $grid = (string) ($instance->grid()?->nick() ?? $instance->name());
            $simple[$grid]['remedy'] = $can ? null : \W4OS\Engine\Provisioning\MapRenderer::remedy($core);
            $simple[$grid]['simulators'][] = $instance->name();
        }

        return $simple;
    }

    /**
     * The Robusts that run and answer `/get_grid_info` with what a viewer refuses: not UTF-8, or not well-formed XML
     * (GridInfoReader::defect()).
     *
     * @param ?\Closure(string):?string $get reads an address (Http::get() without it)
     * @return array<string,array{defect:string,gridName:string}> name => what is wrong, and the name of the grid it can send
     */
    public function brokenGridInfo(?\Closure $get = null): array
    {
        $get ??= static fn(string $url): ?string => \W4OS\Engine\Network\Http::get($url, 2);
        $broken = [];
        foreach ($this->instances as $instance) {
            $port = $instance instanceof \W4OS\Engine\Controllers\RobustController ? (int) $instance->publicPort() : 0;
            $body = $port > 0 && $instance->process() !== null ? $get("http://127.0.0.1:$port/get_grid_info") : null;
            if (is_string($body) && $body !== '' && ($defect = \W4OS\Engine\Network\GridInfoReader::defect($body)) !== null) {
                $name = \W4OS\Engine\Network\GridInfoReader::parse($body)['gridname'] ?? (string) $instance->gridName();
                $broken[$instance->name()] = ['defect' => $defect, 'gridName' => \W4OS\Engine\Config\GridInfoText::ascii($name)];
            }
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

    /**
     * The avatars in world, never a NPC (Grid::presence()): of each grid of the instances, and of each instance that runs (a Robust:
     * its grid; a simulator: its regions): the figure the API gives of the grid. Null when it is not known: the databases of the grid cannot be read, or its Robust runs without its database.
     *
     * @param ?\Closure(\W4OS\Engine\Models\Grid):?array $presence who is in the regions of a grid (Grid::presence() without it)
     * @param ?list<string> $withoutDatabase the Robusts that run without their database (withoutDatabase() without it)
     * @return array{grids:array<string,?int>,instances:array<string,?int>} by the nick of the grid, by the name of the instance
     */
    public function avatars(?\Closure $presence = null, ?array $withoutDatabase = null): array
    {
        $presence ??= static fn(\W4OS\Engine\Models\Grid $grid): ?array => $grid->presence();
        $withoutDatabase ??= $this->withoutDatabase();
        $regions = static fn(AbstractController $instance): array => $instance instanceof \W4OS\Engine\Controllers\OpenSimController && $instance->regionsDirectory() !== ''
            ? array_map(
                static fn(\W4OS\Engine\Models\Region $region): string => strtolower((string) $region->uuid()),
                array_values(array_filter(\W4OS\Engine\Models\Region::inDirectory($instance->regionsDirectory()), static fn(\W4OS\Engine\Models\Region $region): bool => $region->isEnabled())),
            )
            : [];
        // Who is where, by grid, as the grid has it
        $grids = [];
        $nicks = [];
        foreach ($this->instances as $instance) {
            $grid = $instance->grid();
            if ($grid === null) {
                continue;
            }
            $nick = $grid->nick();
            $nicks[$instance->name()] = $nick;
            array_key_exists($nick, $grids) || ($grids[$nick] = $presence($grid));
            if ($instance instanceof \W4OS\Engine\Controllers\RobustController && in_array($instance->name(), $withoutDatabase, true)) {
                $grids[$nick] = null;
            }
        }
        $sum = static fn(array $rows): int => array_sum(array_map('array_sum', $rows));
        $avatars = ['grids' => array_map(static fn(?array $rows): ?int => $rows === null ? null : $sum($rows), $grids), 'instances' => []];
        foreach ($this->instances as $instance) {
            $nick = $nicks[$instance->name()] ?? null;
            if ($nick === null || $instance->process() === null) {
                continue;
            }
            $rows = $grids[$nick];
            $avatars['instances'][$instance->name()] = $rows === null ? null : (
                $instance instanceof \W4OS\Engine\Controllers\RobustController ? $sum($rows) : $sum(array_intersect_key($rows, array_flip($regions($instance))))
            );
        }

        return $avatars;
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
