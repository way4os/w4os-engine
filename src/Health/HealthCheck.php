<?php

declare(strict_types=1);

namespace OpenSim\Health;

use OpenSim\Assets\WebAssets;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Cache;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridOptions;
use OpenSim\Provisioning\Web\SitePages;
use OpenSim\Runtime\Environment;

/**
 * The health of a grid checked (Health): every address its config gives and what the grid says of itself, asked, the pages for
 * people, the PHP extensions, the folders the engine writes, the options of its simulators, the files it reads; each row named,
 * what a wrong address should be. A check takes the time of the slowest address: the last one is kept with its time in the cache of
 * the grid, given again to whoever asks, checked anew on demand or by the task of the grid (`health`, SearchTasks).
 *
 *     HealthCheck::last($environment, $grid, $config);                    // the last check, null when there is none
 *     HealthCheck::refresh($environment, $grid, $config, $tasksFolder);    // check now, and keep it
 */
final class HealthCheck
{
    /** Seconds between two checks of the task of the grid. */
    public const EVERY = 900;

    /** The key of the last check of a grid in its cache, then its nick. */
    private const KEY = 'health.';

    /**
     * Check the health of a grid now: every line.
     *
     * @param string $tasks the folder of the state of the tasks
     * @return array{level:string,groups:list<array<string,mixed>>,checked:int}
     */
    public static function run(Environment $environment, Grid $grid, ?GridConfig $config, string $tasks): array
    {
        $groups = self::finish(Health::answers(self::lines($grid, $config, $tasks)), $grid, $config);
        $now = (int) $environment->clock->time();
        // Each line says when it was checked: all of them now
        foreach ($groups as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                $groups[$g]['rows'][$r]['checkedAt'] = $now;
            }
        }

        return ['level' => Health::level($groups), 'groups' => $groups, 'checkedAt' => $now];
    }

    /**
     * Check one line now, by its id, and keep it in the last check of the grid.
     *
     * @return ?array<string,mixed> the line, with when it was checked (`checked`); null for an id the health has not
     */
    public static function one(Environment $environment, Grid $grid, ?GridConfig $config, string $tasks, string $id): ?array
    {
        foreach (self::lines($grid, $config, $tasks) as $group) {
            foreach ($group['rows'] as $row) {
                if (($row['id'] ?? '') !== $id) {
                    continue;
                }
                $line = self::finish(Health::answers([['rows' => [$row]] + $group]), $grid, $config)[0]['rows'][0];
                $line['checkedAt'] = (int) $environment->clock->time();
                // The last check keeps it, the level of all of it again
                $last = self::last($environment, $grid, $config);
                if ($last !== null) {
                    foreach ($last['groups'] as $g => $kept) {
                        foreach ($kept['rows'] as $r => $was) {
                            ($was['id'] ?? '') === $id && ($last['groups'][$g]['rows'][$r] = $line);
                        }
                    }
                    $last['level'] = Health::level($last['groups']);
                    Cache::for($environment, $config)->set(self::KEY . $grid->nick(), $last, 0);
                }

                return $line;
            }
        }

        return null;
    }

    /**
     * The lines of the health of a grid, not asked yet, each with its id: what the grid says, its files, its pages, what the engine
     * needs (shown as it is).
     *
     * @return list<array<string,mixed>>
     */
    private static function lines(Grid $grid, ?GridConfig $config, string $tasks): array
    {
        $live = $grid->gridInfo();
        // Where a site sends a profile asked signed out, as the check is
        $register = (string) ($live['register'] ?? ($config?->find('GridInfoService', 'register') ?? ''));
        $groups = [
            ...Health::grid([
                'config' => $config,
                'live' => $live,
                'login' => (string) $grid->loginUrl(),
                'register' => $register,
                'servedByHelpers' => static fn(string $url): bool => $grid->servedByHelpers($url),
            ]),
            // The pages for people the grid declares: the helpers' under their base, the site's elsewhere
            ['file' => 'pages', 'title' => '', 'rows' => Health::pages(Health::configured(SitePages::rows($grid), self::urls($config), static fn(string $url): bool => $grid->servedByHelpers($url)), $register)],
        ];
        // The web assets service needs JPEG 2000 here, unless the grid sets an external one
        $assets = (new \OpenSim\Models\Service($grid->environment(), $grid, 'assets'))->address();
        $groups[] = ['file' => 'PHP', 'title' => '', 'rows' => Health::extensions(
            WebAssets::external($grid),
            ['file' => (string) $assets['file'], 'section' => (string) $assets['section'], 'key' => (string) $assets['setting']],
        )];
        $groups[] = [
            'file' => 'cache',
            'title' => '',
            'rows' => [
                Health::writable('assets', WebAssets::folder($grid)),
                // The state of the tasks: without it the search index never fills (TasksRoot)
                Health::writable('tasks', $tasks),
            ],
        ];
        // The options of the grid each simulator of this machine has in its files (profiles, groups...)
        $options = new GridOptions($grid);
        $groups[] = ['file' => 'options', 'title' => '', 'rows' => array_map(
            static function ($simulator) use ($options): array {
                $missing = $options->missingIn($simulator->settings());

                // The options of the grid a simulator has, or misses, and what repairs them
                return [
                    'label' => $simulator->slug(),
                    'kind' => 'option',
                    'missing' => $missing,
                    'level' => $missing === [] ? 'ok' : 'error',
                    'status' => $missing === [] ? _('all the options of the grid') : sprintf(_('missing: %s'), implode(', ', $missing)),
                ] + ($missing === [] ? [] : ['fix' => 'POST /grids/{grid}/options/repair']);
            },
            $grid->simulators(),
        )];
        $files = Health::files($config?->files() ?? []);
        $files === [] || ($groups[] = ['file' => 'files', 'title' => '', 'rows' => $files]);
        // Each line its id: its file, its section, its setting, how it is asked
        foreach ($groups as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                $groups[$g]['rows'][$r] = ['id' => substr(sha1(implode("\n", [$group['file'] ?? '', $group['title'] ?? '', $row['label'] ?? '', $row['tag'] ?? '', $row['method'] ?? ''])), 0, 12)] + $row;
            }
        }

        return $groups;
    }

    /**
     * The addresses Robust and the simulators set: what tells whether the grid uses its helpers.
     *
     * @return list<string>
     */
    private static function urls(?GridConfig $config): array
    {
        $tree = $config?->tree() ?? [];
        $layers = [$tree['Robust'] ?? [], $tree['OpenSim'] ?? [], ...array_values($tree['simulators'] ?? [])];
        $urls = [];
        foreach ($layers as $ini) {
            foreach ((array) $ini as $keys) {
                foreach ((array) $keys as $value) {
                    is_string($value) && Health::isUrl(trim($value, '"')) && ($urls[] = trim($value, '"'));
                }
            }
        }

        return $urls;
    }

    /**
     * The lines asked, with what each is (a key of the catalog), a service that needs an application of its own, and what a wrong or
     * missing address should be.
     *
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    private static function finish(array $groups, Grid $grid, ?GridConfig $config): array
    {
        $groups = Health::describe($groups, static fn(string $url): bool => $grid->servedByHelpers($url));

        return Health::expect($groups, (new GridOptions($grid))->expected(), $config?->section('Const') ?? []);
    }

    /**
     * The last check of a grid, as it was kept.
     *
     * @return ?array{level:string,groups:list<array<string,mixed>>,checked:int}
     */
    public static function last(Environment $environment, Grid $grid, ?GridConfig $config): ?array
    {
        $last = Cache::for($environment, $config)->get(self::KEY . $grid->nick());

        return is_array($last) && isset($last['groups'], $last['checkedAt']) ? $last : null;
    }

    /**
     * Check the health of a grid now, and keep it.
     *
     * @return array{level:string,groups:list<array<string,mixed>>,checked:int}
     */
    public static function refresh(Environment $environment, Grid $grid, ?GridConfig $config, string $tasks): array
    {
        $check = self::run($environment, $grid, $config, $tasks);
        Cache::for($environment, $config)->set(self::KEY . $grid->nick(), $check, 0);

        return $check;
    }
}
