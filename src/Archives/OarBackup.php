<?php

namespace W4OS\Engine\Archives;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Models\Region;
use W4OS\Engine\Provisioning\Slug;
use W4OS\Engine\Runtime\Environment;

/**
 * The scheduled backup of the regions: the OAR of every enabled region of the simulators that run, in the backup root, one folder
 * per grid and one per simulator (`<BackupRoot>/<grid>/<simulator>/<grid>-<simulator>-<region>-<stamp>.oar`), so the names sort by
 * region and then by date. A simulator that does not run, or a region that does not answer, is skipped with a line.
 */
final class OarBackup
{
    /**
     * @param \Closure(list<string>):array{0:int,1:?string} $save `opensim save oar` with its arguments: the exit code, the file
     * @param \Closure(string):bool $running whether the instance of a config runs
     * @param \Closure(string):void $say what the person is told
     */
    public function __construct(
        private Environment $environment,
        private \Closure $save,
        private \Closure $running,
        private \Closure $say,
        private string $stamp = '',
    ) {
        $this->stamp = $stamp !== '' ? $stamp : Archives::stamp();
    }

    /**
     * Save the regions the words name: every grid of the install (none), a grid, or one of its simulators.
     *
     * @param list<string> $refs
     * @param array{output?:string,keep?:int} $options keep: how many OARs to keep for each region
     * @return array{saved:list<string>,skipped:int,failed:int}
     * @throws \RuntimeException
     */
    public function run(array $refs, array $options = []): array
    {
        $etc = $this->environment->layout->etcRoot();
        if ($refs === []) {
            $targets = array_map(static fn(string $nick): array => [$nick, null], Instances::grids($etc));
            if ($targets === []) {
                throw new \RuntimeException('no grid here');
            }
        } else {
            $located = Instances::resolve($this->environment, $refs);
            $targets = [[$located[0], $located[1]]];
        }
        $root = rtrim($options['output'] ?? $this->environment->layout->backupRoot(), '/');
        if (!is_dir($root)) {
            throw new \RuntimeException("the backup root $root does not exist (is the storage mounted?)");
        }

        $result = ['saved' => [], 'skipped' => 0, 'failed' => 0];
        foreach ($targets as [$nick, $only]) {
            $grid = Grid::find($this->environment, $nick) ?? throw new \RuntimeException("grid '$nick' is not known here");
            $model = Grid::at($this->environment, $grid->directory());
            foreach ($model->enabledSimulators() as $simulator) {
                $slug = $simulator->name();
                if ($only !== null && $only !== $slug) {
                    continue;
                }
                $this->simulator($result, $model, $grid, $slug, $root, $options['keep'] ?? null);
            }
        }

        return $result;
    }

    /**
     * @param array{saved:list<string>,skipped:int,failed:int} $result
     */
    private function simulator(array &$result, Grid $model, Grid $grid, string $slug, string $root, ?int $keep): void
    {
        $nick = $grid->nick();
        $name = Archives::part(Slug::simulatorOf($slug, $nick));
        if (!($this->running)($model->simulatorLink($slug))) {
            ($this->say)("$slug is not running, its regions are skipped");
            $result['skipped']++;

            return;
        }
        $regions = array_map(
            static fn(Region $region): string => $region->name(),
            array_filter(
                Region::inDirectory(GridConfig::simulatorsDirectory($model->directory()) . "/$slug/regions"),
                static fn(Region $region): bool => $region->isEnabled(),
            ),
        );
        foreach ($regions as $region) {
            $file = "$root/$nick/$name/" . Archives::oarName($nick, $name, $region, [], $this->stamp);
            $arguments = ['oar', $nick, $slug, '--region', $region, $file];
            if ($keep !== null) {
                array_push($arguments, '--keep', (string) $keep);
            }
            [$code, $made] = ($this->save)($arguments);
            if ($code === 0 && $made !== null) {
                if (is_file($made)) {
                    chmod($made, 0640);
                }
                $result['saved'][] = $made;
            } else {
                ($this->say)("region $region of $slug was not saved");
                $result['failed']++;
            }
        }
    }
}
