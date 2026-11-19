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
     * Check the health of a grid now.
     *
     * @param string $tasks the folder of the state of the tasks
     * @return array{level:string,groups:list<array<string,mixed>>,checked:int}
     */
    public static function run(Environment $environment, Grid $grid, ?GridConfig $config, string $tasks): array
    {
        $groups = Health::answers([
            ...Health::grid([
                'config' => $config,
                'live' => $grid->gridInfo(),
                'login' => (string) $grid->loginUrl(),
            ]),
            // The pages for people: the helpers' under their base, the site's elsewhere
            ['file' => 'pages', 'title' => '', 'rows' => Health::pages(SitePages::rows($grid))],
        ]);
        $groups[] = ['file' => 'PHP', 'title' => '', 'rows' => Health::extensions()];
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

                return [
                    'label' => $simulator->slug(),
                    'url' => '',
                    'text' => $missing === [] ? '' : implode(', ', $missing),
                    'level' => $missing === [] ? 'ok' : 'error',
                    'status' => $missing === [] ? _('OK') : _('missing'),
                    'ms' => null,
                ];
            },
            $grid->simulators(),
        )];
        $files = Health::files($config?->files() ?? []);
        $files === [] || ($groups[] = ['file' => 'files', 'title' => '', 'rows' => $files]);
        // What each row is (a key of the catalog), and a service that needs an application of its own
        $groups = Health::describe($groups, static fn(string $url): bool => $grid->servedByHelpers($url));
        // What a wrong or missing address should be, and the line of its file to write
        $groups = Health::expect($groups, $options->expected(), $config?->section('Const') ?? []);

        return ['level' => Health::level($groups), 'groups' => $groups, 'checked' => (int) $environment->clock->time()];
    }

    /**
     * The last check of a grid, as it was kept.
     *
     * @return ?array{level:string,groups:list<array<string,mixed>>,checked:int}
     */
    public static function last(Environment $environment, Grid $grid, ?GridConfig $config): ?array
    {
        $last = Cache::for($environment, $config)->get(self::KEY . $grid->nick());

        return is_array($last) && isset($last['groups'], $last['checked']) ? $last : null;
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
