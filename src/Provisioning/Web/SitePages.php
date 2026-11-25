<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning\Web;

use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Config\IniEditor;
use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Models\AddressHistory;
use W4OS\Engine\Models\Grid;

/**
 * The pages of a grid for people (its search, its profiles, its destinations guide, its splash page, its status, its directory), and
 * who serves each: the helpers when its address is under their base (Grid::servedByHelpers()), the site of the grid elsewhere. Each
 * address is set on its own, in the one setting the grid has for it (Robust, Helpers.ini for what Robust has no place for); the base
 * of the helpers is `HelpersURL` of Helpers.ini.
 *
 *     SitePages::rows($grid);                                            // each page, its address, where it comes from, who serves it
 *     SitePages::set($grid, 'search', 'https://example.org/search');     // the file and the keys written, what must restart
 *     SitePages::declare($grid, 'profile', 'https://example.org/opensim/profile');   // the site's own page, never over another's
 */
final class SitePages
{
    /** The base of the helpers, set like a page. */
    public const HELPERS = 'helpers';

    /** The pages of the grid info a site serves that the helpers have none of, by the name they are set with: their settings. */
    public const INFO_PAGES = [
        'register' => ['keys' => [['GridInfoService', 'register']]],
        'password' => ['keys' => [['GridInfoService', 'password']]],
        'about' => ['keys' => [['GridInfoService', 'about']]],
        'help' => ['keys' => [['GridInfoService', 'help']]],
    ];

    /** The pages the site declared, in the folder of the grid: page => the address it gave, as the grid reads it. */
    public const DECLARED = 'site-pages.json';

    /**
     * The pages, by the name they are set with: the service of the grid that has the address (Service), the settings it is written in
     * (section, key; the ones after the first are written when the file has them), the default page of the helpers when the service has
     * none and the address it has under their base (Services::PAGES), what follows the address (the name of the avatar of a profile),
     * the block of the helpers a page of the site shows for it (`block`).
     */
    public const PAGES = [
        'search' => [
            'service' => 'search',
            'keys' => [['GridInfoService', 'search'], ['LoginService', 'SearchURL']],
            'default' => 'search',
            'block' => 'search',
        ],
        'profile' => [
            'service' => 'web-profile',
            'keys' => [['GridInfoService', 'web_profile_url']],
            'default' => 'profile',
            'suffix' => '[AGENT_NAME]',
            'block' => 'profile',
        ],
        'guide' => ['service' => 'guide', 'keys' => [['LoginService', 'DestinationGuide']], 'default' => 'guide', 'block' => 'destination_guide'],
        'welcome' => ['service' => 'welcome', 'keys' => [['GridInfoService', 'welcome']], 'default' => 'welcome'],
        'status' => ['service' => 'grid-status', 'keys' => [['GridInfoService', 'GridStatus']], 'default' => 'grid_status', 'block' => 'grid-status'],
        'directory' => ['service' => 'directory', 'keys' => [['Helpers', 'DirectoryURL']], 'default' => 'directory', 'block' => 'directory_info'],
    ];

    /**
     * The base of the helpers and each page: its address as the grid sets it (none when it does not: the address it would have under
     * the helpers is not one), where it comes from (Service::source(), `config` or `economy` for the base), who serves it (`helpers`
     * or `site`, null without an address), the address it would have under the base of the helpers, and the settings it is set in.
     *
     * @return list<array{page:string,url:?string,source:?string,servedBy:?string,helpersDefault?:?string,keys:list<string>,block?:?string}>
     */
    public static function rows(Grid $grid): array
    {
        $rows = [
            [
                'page' => self::HELPERS,
                'url' => $grid->helpersUrl(),
                'source' => $grid->helpersUrlSource(),
                'servedBy' => $grid->helpersUrl() === null ? null : 'helpers',
                'keys' => ['[Helpers] ' . HelpersConfig::URL_KEY],
            ],
        ];
        foreach (self::PAGES as $page => $definition) {
            $service = $grid->service($definition['service']);
            $url = $service?->url();
            $source = $service?->source();
            // The address it would have under the helpers is no address of the grid: a page the grid does not set it has not
            $default = $grid->helperUrl($definition['default']);
            $rows[] = [
                'page' => $page,
                'url' => $url,
                'source' => $source,
                'servedBy' => $url === null ? null : ($grid->servedByHelpers($url) ? 'helpers' : 'site'),
                'helpersDefault' => $default === '' ? null : $default,
                'keys' => array_map(static fn(array $key): string => "[{$key[0]}] {$key[1]}", $definition['keys']),
                'block' => $definition['block'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Set the address of a page, or the base of the helpers, in the one setting of it: Robust (the grid tells its viewers: Robust has
     * to restart), Helpers.ini for the base and the directory (the helpers read it at once). The address of a profile ends with the
     * name of the avatar, `[AGENT_NAME]`, added when it is not there. `$onSite`: an address on the web site of the grid is written
     * `${Const|WebURL}` and its path, which follow the site when its address changes.
     *
     * @return array{page:string,url:string,file:string,keys:list<string>,restart:?string} what was written, and what must restart
     * @throws \InvalidArgumentException for a page that is not one, an address that is not one, a grid that has no file for it
     */
    public static function set(Grid $grid, string $page, string $url, bool $onSite = false): array
    {
        $url = trim($url);
        if ($page !== self::HELPERS && !isset(self::PAGES[$page]) && !isset(self::INFO_PAGES[$page])) {
            throw new \InvalidArgumentException(
                "'$page' is not a page: " . implode(', ', [self::HELPERS, ...array_keys(self::PAGES), ...array_keys(self::INFO_PAGES)]),
            );
        }
        if (preg_match('#^https?://[^\s"<>/]+(/[^\s"<>]*)?$#i', $url) !== 1) {
            throw new \InvalidArgumentException("'$url' is not an address (https://...)");
        }
        $definition = self::PAGES[$page] ?? self::INFO_PAGES[$page] ?? ['keys' => [['Helpers', HelpersConfig::URL_KEY]]];
        if (isset($definition['suffix']) && !str_contains($url, $definition['suffix'])) {
            $url = rtrim($url, '/') . '/' . $definition['suffix'];
        }
        $site = rtrim((string) $grid->site(), '/');
        $value = $url;
        if ($onSite && $site !== '' && ($url === $site || str_starts_with($url, "$site/"))) {
            $value = '${Const|WebURL}' . substr($url, strlen($site));
        }
        $helpers = $definition['keys'][0][0] === 'Helpers';
        $file = $helpers ? HelpersConfig::path($grid->directory()) : self::servicesFile($grid);
        if ($file === null || $grid->directory() === '') {
            throw new \InvalidArgumentException("the grid {$grid->nick()} has no config on this machine");
        }
        $ini = is_file($file) ? IniEditor::load($file) : new IniEditor('');
        $written = [];
        foreach ($definition['keys'] as $i => [$section, $key]) {
            // The settings after the first are the same page told elsewhere: written when the file has them
            if ($i === 0 || $ini->get($section, $key) !== null) {
                $ini->set($section, $key, '"' . $value . '"');
                $written[] = "[$section] $key";
            }
        }
        $ini->save($file);

        return [
            'page' => $page,
            'url' => $url,
            'file' => $file,
            'keys' => $written,
            'restart' => $helpers ? null : ($grid->isStandalone() ? 'simulator' : 'Robust'),
        ];
    }

    /**
     * Set the address of a page as the site of the grid declares it (a page it serves), with set() on its site, and keep it as the
     * site's. The site replaces an empty address, its own of before, or the one this install gave (the default of the helpers, an
     * address its import added); never one given otherwise (by the grid it was migrated from, by a manager): refused, nothing
     * written. The address it replaces is kept for the viewers that read it (AddressHistory).
     *
     * @return array{page:string,url:string,file:string,keys:list<string>,restart:?string,previous:?string} set()'s, and the address
     *         it replaced
     * @throws InvalidValue `page-set-elsewhere` for an address given otherwise; \InvalidArgumentException as set()
     */
    public static function declare(Grid $grid, string $page, string $url): array
    {
        if (!isset(self::PAGES[$page]) && !isset(self::INFO_PAGES[$page])) {
            throw new \InvalidArgumentException("'$page' is not a page of a site: " . implode(', ', [...array_keys(self::PAGES), ...array_keys(self::INFO_PAGES)]));
        }
        $now = self::current($grid, $page);
        $suffix = self::PAGES[$page]['suffix'] ?? '';
        $bare = static fn(?string $address): string => rtrim($suffix === '' ? (string) $address : str_replace($suffix, '', (string) $address), '/');
        $given = [$bare(self::declared($grid)[$page] ?? null), $bare($url), ...array_map($bare, self::installed($grid, $page))];
        if ($now !== '' && !in_array($bare($now), array_filter($given), true)) {
            $from = ($from = AddressHistory::imported($grid)[(self::PAGES[$page] ?? self::INFO_PAGES[$page])['keys'][0][1]] ?? null) !== null && $bare($from) === $bare($now);
            throw InvalidValue::because('page-set-elsewhere', "the address of the $page page is $now, " . ($from ? 'the one of the grid it was migrated from' : 'given otherwise than by the site') . ': the site does not replace it (it is changed in the settings of the grid)');
        }
        $set = AddressHistory::around($grid, static fn(): array => self::set($grid, $page, $url, true));
        $declared = self::declared($grid);
        $declared[$page] = self::current($grid, $page);
        $file = rtrim($grid->directory(), '/') . '/' . self::DECLARED;
        @file_put_contents($file, json_encode($declared, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false && error_log("opensim: the pages the site of {$grid->nick()} declared cannot be kept in $file");

        return $set + ['previous' => $now === '' ? null : $now];
    }

    /** The address of a page as the grid reads it (its first setting, its constants replaced); empty when it has none. */
    public static function current(Grid $grid, string $page): string
    {
        $definition = self::PAGES[$page] ?? self::INFO_PAGES[$page] ?? ['keys' => [['Helpers', HelpersConfig::URL_KEY]]];
        [$section, $key] = $definition['keys'][0];
        $file = $section === 'Helpers' ? HelpersConfig::path($grid->directory()) : self::servicesFile($grid);

        return $file === null || $grid->directory() === '' || !is_file($file) ? '' : trim((string) (ConfigReader::load($file)[$section][$key] ?? ''), " \t\"");
    }

    /** @return array<string,string> the pages the site declared: page => the address, as the grid read it then */
    public static function declared(Grid $grid): array
    {
        $file = rtrim($grid->directory(), '/') . '/' . self::DECLARED;
        $kept = $grid->directory() !== '' && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($kept) ? array_filter($kept, static fn($url, $page): bool => is_string($url) && is_string($page), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * The settings the site declared that still say what it declared, `section|key` in lower case: the options never lay theirs
     * over them.
     *
     * @return list<string>
     */
    public static function declaredKeys(Grid $grid): array
    {
        $keys = [];
        foreach (self::declared($grid) as $page => $url) {
            $definition = self::PAGES[$page] ?? self::INFO_PAGES[$page] ?? null;
            if ($definition !== null && self::current($grid, $page) === $url) {
                array_push($keys, ...array_map(static fn(array $key): string => strtolower("{$key[0]}|{$key[1]}"), $definition['keys']));
            }
        }

        return $keys;
    }

    /**
     * The addresses this install gave a page: the one it has under the helpers, and the ones the import of the grid added (its
     * report, what this install added that the old config had not).
     *
     * @return list<string>
     */
    private static function installed(Grid $grid, string $page): array
    {
        $definition = self::PAGES[$page] ?? self::INFO_PAGES[$page];
        $default = isset($definition['default']) ? $grid->helperUrl($definition['default']) : '';
        $found = $default === '' ? [] : [$default];
        $report = rtrim($grid->directory(), '/') . '/' . $grid->nick() . '.import-report.txt';
        $added = false;
        foreach ($grid->directory() !== '' && is_file($report) ? (file($report, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            $added = str_contains($line, 'added by this install') || ($added && str_starts_with($line, '  '));
            foreach ($added ? $definition['keys'] : [] as [$section, $key]) {
                if (preg_match('/^\s+\[' . preg_quote($section, '/') . '\] ' . preg_quote($key, '/') . ' = (.*) \((?:Robust|the simulators)\)$/', $line, $m) === 1) {
                    $found[] = trim($m[1]);
                }
            }
        }

        return $found;
    }

    /** The file of the grid its viewers are told its addresses from: its Robust config, the one of a standalone. */
    private static function servicesFile(Grid $grid): ?string
    {
        return $grid->isStandalone() ? $grid->directory() . '/config-include/StandaloneCommon.ini' : $grid->robustIni();
    }
}
