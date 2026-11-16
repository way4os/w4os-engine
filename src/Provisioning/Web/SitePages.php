<?php

declare(strict_types=1);

namespace OpenSim\Provisioning\Web;

use OpenSim\Config\IniEditor;
use OpenSim\Models\Grid;

/**
 * The pages of a grid for people (its search, its profiles, its destinations guide, its splash page, its status, its directory), and
 * who serves each: the helpers when its address is under their base (Grid::servedByHelpers()), the site of the grid elsewhere. Each
 * address is set on its own, in the one setting the grid has for it (Robust, Helpers.ini for what Robust has no place for); the base
 * of the helpers is `HelpersURL` of Helpers.ini.
 *
 *     SitePages::rows($grid);                                            // each page, its address, where it comes from, who serves it
 *     SitePages::set($grid, 'search', 'https://example.org/search');     // the file and the keys written, what must restart
 */
final class SitePages
{
    /** The base of the helpers, set like a page. */
    public const HELPERS = 'helpers';

    /**
     * The pages, by the name they are set with: the service of the grid that has the address (Service), the settings it is written in
     * (section, key; the ones after the first are written when the file has them), the default page of the helpers when the service has
     * none and the address it has under their base (Services::PAGES), what follows the address (the name of the avatar of a profile).
     */
    public const PAGES = [
        'search' => [
            'service' => 'search',
            'keys' => [['GridInfoService', 'search'], ['LoginService', 'SearchURL']],
            'default' => 'search',
        ],
        'profile' => [
            'service' => 'web-profile',
            'keys' => [['GridInfoService', 'web_profile_url']],
            'default' => 'profile',
            'suffix' => '[AGENT_NAME]',
        ],
        'guide' => ['service' => 'guide', 'keys' => [['LoginService', 'DestinationGuide']], 'default' => 'guide'],
        'welcome' => ['service' => 'welcome', 'keys' => [['GridInfoService', 'welcome']], 'default' => 'welcome'],
        'status' => ['service' => 'grid-status', 'keys' => [['GridInfoService', 'GridStatus']], 'default' => 'grid_status'],
        'directory' => ['service' => 'directory', 'keys' => [['Helpers', 'DirectoryURL']], 'default' => 'directory'],
    ];

    /**
     * The base of the helpers and each page: its address, where it comes from (Service::source(), `config` or `default` for the
     * base), who serves it (`helpers` or `site`, null without an address), the address it would have under the base of the helpers,
     * and the settings it is set in.
     *
     * @return list<array{page:string,url:?string,source:?string,servedBy:?string,helpersDefault?:?string,keys:list<string>}>
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
            $default = $grid->helperUrl($definition['default']);
            if ($url === null && $default !== '') {
                $url = $default . (isset($definition['suffix']) ? '/' . $definition['suffix'] : '');
                $source = 'default';
            }
            $rows[] = [
                'page' => $page,
                'url' => $url,
                'source' => $source,
                'servedBy' => $url === null ? null : ($grid->servedByHelpers($url) ? 'helpers' : 'site'),
                'helpersDefault' => $default === '' ? null : $default,
                'keys' => array_map(static fn(array $key): string => "[{$key[0]}] {$key[1]}", $definition['keys']),
            ];
        }

        return $rows;
    }

    /**
     * Set the address of a page, or the base of the helpers, in the one setting of it: Robust (the grid tells its viewers: Robust has
     * to restart), Helpers.ini for the base and the directory (the helpers read it at once). The address of a profile ends with the
     * name of the avatar, `[AGENT_NAME]`, added when it is not there.
     *
     * @return array{page:string,url:string,file:string,keys:list<string>,restart:?string} what was written, and what must restart
     * @throws \InvalidArgumentException for a page that is not one, an address that is not one, a grid that has no file for it
     */
    public static function set(Grid $grid, string $page, string $url): array
    {
        $url = trim($url);
        if ($page !== self::HELPERS && !isset(self::PAGES[$page])) {
            throw new \InvalidArgumentException(
                "'$page' is not a page: " . implode(', ', [self::HELPERS, ...array_keys(self::PAGES)]),
            );
        }
        if (preg_match('#^https?://[^\s"<>/]+(/[^\s"<>]*)?$#i', $url) !== 1) {
            throw new \InvalidArgumentException("'$url' is not an address (https://...)");
        }
        $definition = self::PAGES[$page] ?? ['keys' => [['Helpers', HelpersConfig::URL_KEY]]];
        if (isset($definition['suffix']) && !str_contains($url, $definition['suffix'])) {
            $url = rtrim($url, '/') . '/' . $definition['suffix'];
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
                $ini->set($section, $key, '"' . $url . '"');
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

    /** The file of the grid its viewers are told its addresses from: its Robust config, the one of a standalone. */
    private static function servicesFile(Grid $grid): ?string
    {
        return $grid->isStandalone() ? $grid->directory() . '/config-include/StandaloneCommon.ini' : $grid->robustIni();
    }
}
