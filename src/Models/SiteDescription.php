<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\GridConfig;
use OpenSim\Runtime\Environment;
use OpenSim\Text\DestinationList;

/**
 * What a site needs of a grid for its helpers, given by the engine of the machine that has its files: who the grid is, the
 * settings its helpers read (the addresses, the names of the databases, the settings of the helpers), the text of its list of
 * destinations when it is a file. Never a secret: the access to the databases is the site's own (DatabaseAccess), the keys and
 * passwords stay in the files. The engine a site bundles makes its grid of it (grid()), never of a folder.
 */
final class SiteDescription
{
    /** The sections the helpers read, by file of the grid. */
    private const SECTIONS = [
        'Const', 'GridInfoService', 'LoginService', 'DatabaseService', 'Hypergrid', 'Search', 'DataSnapshot', 'Messaging', 'Economy',
        'Gloebit', 'MoneyServer', 'MySql', 'Helpers', 'UserProfiles', 'Groups', 'Models',
    ];

    /** The keys never given: what opens a database, a console, an account, a service. */
    private const SECRET = '/(pass(word)?|secret|key)$/i';

    /**
     * The description of a grid.
     *
     * @return array{grid:array<string,mixed>,config:array<string,array<string,array<string,string>>>,destinations:?string,checkedAt:string}
     */
    public static function of(Grid $grid): array
    {
        $tree = $grid->config()?->tree() ?? [];
        $config = [];
        foreach (['Robust', 'OpenSim', 'Helpers', 'MoneyServer'] as $role) {
            foreach (array_intersect_key((array) ($tree[$role] ?? []), array_flip(self::SECTIONS)) as $section => $keys) {
                foreach ((array) $keys as $key => $value) {
                    if (!is_string($value) || preg_match(self::SECRET, (string) $key) === 1) {
                        continue;
                    }
                    // A database by its name only: the site reaches it with its own access
                    $value = preg_match('/ConnectionString$/i', (string) $key) === 1 ? self::database($value) : $value;
                    // The [MySql] of the money server: its database only
                    if ($section === 'MySql' && strtolower((string) $key) !== 'database') {
                        continue;
                    }
                    $config[$role][$section][$key] = $value;
                }
            }
        }
        $source = trim((string) ($config['Helpers']['Helpers']['GuideSource'] ?? ''));
        $local = $source !== '' && preg_match('#^https?://#i', $source) !== 1;
        // A list of the machine is given as its text, its path is the machine's
        $local && ($config['Helpers']['Helpers']['GuideSource'] = '');

        return [
            'grid' => ['nick' => $grid->nick(), 'name' => $grid->name(), 'baseHostname' => $grid->baseHostname(), 'publicPort' => $grid->publicPort(), 'standalone' => $grid->isStandalone()],
            'config' => $config,
            'destinations' => $local ? DestinationList::read($source, $grid->directory() !== '' ? $grid->directory() : null) : null,
            'checkedAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $grid->environment()->clock->time()),
        ];
    }

    /**
     * The grid of a description, for the engine a site bundles: described, its config the one given, no folder.
     *
     * @param array<string,mixed> $description what of() gives
     */
    public static function grid(Environment $environment, array $description): Grid
    {
        return Grid::described(
            $environment,
            ['remote' => true] + (array) ($description['grid'] ?? []) + (isset($description['destinations']) ? ['destinationsText' => (string) $description['destinations']] : []),
            new GridConfig((array) ($description['config'] ?? [])),
        );
    }

    /** A connection string with its database only, its server empty for the access of the site to fill. */
    private static function database(string $value): string
    {
        $name = preg_match('/(?:^|;)\s*Database\s*=\s*([^;]*)/i', trim($value, " \t\""), $m) === 1 ? trim($m[1]) : '';

        return $name === '' ? '' : "Data Source=;Database=$name;";
    }
}
