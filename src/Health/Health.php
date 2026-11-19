<?php

namespace OpenSim\Health;

use OpenSim\Config\Settings;
use OpenSim\Models\Avatar;
use OpenSim\Config\GridConfig;

/**
 * Health checks of an OpenSimulator grid, for whoever serves it: the addresses its settings give and what each answers, what
 * an install needs. A tool for every consumer of the engine, alone or together (a web page, a command, a plugin): it takes
 * data and gives data (groups of rows), it shows nothing.
 *
 * A group is a section of the ini files (its title is the name of the section) of one file (its `file`), and a row a setting of
 * it, its label the key as the ini files write it: whoever reads a check must not wonder where to look for a setting. The first
 * group is what the grid says at its login address (get_grid_info), which works without any local config; the files of the config
 * come next, when there is one, and what the grid says is compared with what they say (a difference means a restart is needed, or
 * a problem of config).
 *
 * An address is asked where its redirections lead, what counts is what it answers (the certificate is only reported, with
 * `cert`), and what is right for an address is what is expected of it: a page answers 2xx, an address that is not a page answers
 * 418 (a teapot), a service is called twice, with nothing (it must refuse) and with what it expects (it must answer 200).
 *
 * @package magicoli/opensim-engine
 * @license AGPLv3
 */

class Health
{
    /** How long an address has to answer, in seconds. */
    const TIMEOUT = 3;

    /** How long an address that took too long has, asked again (a page made after a deploy, a cache to fill): slow when it answers. */
    const RETRY_TIMEOUT = 10;

    /** How many addresses are asked at once: the pages of a site call back the engine, and a small pool of workers serves both. */
    const CONCURRENCY = 2;

    /** What is read of a page to find the block it shows. */
    const PAGE_BYTES = 1048576;

    /** The curl error of an address that took too long. */
    const TIMED_OUT = 28;

    /**
     * The addresses of the grid itself, which answer at /get_grid_info: the home of a grid (host:publicport) has no page of its own,
     * asked alone it is a 404. What is at the same address as the login is the same.
     */
    const GRID_ADDRESSES = ['login', 'gatekeeper', 'uas'];

    /** What a service answers to a call with nothing in it, or with the wrong method. */
    const BARE = [400, 405];

    /** @var array{register?:string,helpers?:?callable} the site of the grid being checked (grid()) */
    private static $site = [];

    /** What an address that sends elsewhere answers. */
    const REDIRECTS = [301, 302, 303, 307, 308];

    /** What an address that is not a page answers: not served, and not an error (a teapot). */
    const TEAPOT = [418];

    /**
     * The pages and the caches a row can be, by `group/label`: the name of each in the catalog, `setting_<name>` and
     * `setting_<name>_help` its description. The settings are the registry's (OpenSim\Config\Settings).
     */
    const ROWS = [
        'pages/helpers' => 'helpers',
        'pages/search' => 'web_search',
        'pages/profile' => 'web_profile',
        'pages/guide' => 'destination_guide',
        'pages/welcome' => 'welcome',
        'pages/status' => 'grid_status',
        'pages/directory' => 'directory',
        'cache/assets' => 'cache_assets',
        'cache/tasks' => 'cache_tasks',
    ];

    /**
     * The services an application serves apart from the grid (the helpers of the engine, or another one): one that does not answer
     * and is not served by the helpers of the grid needs it (`thirdParty`).
     */
    const THIRD_PARTY = ['economy', 'web_search', 'search_service', 'data_service', 'offline_messages', 'destination_guide'];

    /** An identifier that is a UUID and nobody has, to ask for what an avatar has (nothing). */
    const UNKNOWN_AVATAR = '00000000-0000-4000-8000-000000000000';

    /**
     * What the grid says of itself at its login address (`/get_grid_info`), as it says it: no value is made up. A grid that does
     * not answer says nothing.
     *
     * @param  string $login the login address of the grid
     * @return array<string,string> key => value, empty when the grid does not answer
     */
    public static function gridInfo($login)
    {
        return \OpenSim\Network\GridInfoReader::read((string) $login);
    }

    /**
     * The names of the files the settings of a grid are in: the one of Robust (Robust.HG.ini, Robust.ini), the one of the
     * simulators and the one of the money server.
     *
     * @param  GridConfig|null $config
     * @return array{robust:string,opensim:string,money:string}
     */
    public static function names($config)
    {
        return [
            'robust' => Settings::file('robust', $config ?: null),
            'opensim' => 'OpenSim.ini',
            'money' => Settings::file('moneyserver'),
        ];
    }

    /**
     * The addresses to ask, as groups of rows: what the grid says (get_grid_info), then, when the config of the grid is there, the
     * files it is in, the sections of each, the addresses they give, and the ones that are expected and not given, empty.
     *
     * @param  array{config?:?GridConfig,live?:array,login?:?string,owner?:?string} $in
     *   - config: the config of the grid (null when this machine has none)
     *   - live:   what the grid says at /get_grid_info, key => value
     *   - login:  the login address
     *   - owner:  the name (Firstname.Lastname) of an avatar of the grid, to ask a profile for
     *   - register:        the registration page of the grid, where a site sends a profile asked signed out
     *   - servedByHelpers: callable(string):bool, whether an address is under the helpers of the grid
     * @return list<array{file:string,title:string,rows:list<array<string,mixed>>}>
     */
    public static function grid($in)
    {
        $config = $in['config'] ?? null;
        $live = array_filter($in['live'] ?? [], 'is_string');
        $login = (string) ($in['login'] ?? '');
        $owner = $in['owner'] ?? null;
        self::$site = ['register' => (string) ($in['register'] ?? ($live['register'] ?? ($config?->find('GridInfoService', 'register') ?? ''))), 'helpers' => $in['servedByHelpers'] ?? null];
        $file = self::names($config);
        $groups = [];

        // What the grid says: every value of get_grid_info, the login address first (it is where it is asked, and it is asked even
        // when the grid does not answer). The addresses are asked, the other values are shown
        if ($login !== '') {
            $live['login'] = $login;
        }
        $home = self::origin($login);
        $keys = array_values(
            array_unique(array_merge(array_intersect(self::gridInfoKeys(), array_keys($live)), array_keys($live))),
        );
        $rows = [];
        foreach ($keys as $key) {
            // The profile is asked here without a name only: asked with the name of an avatar, it is in the config below
            array_push($rows, ...self::address($key, $live[$key], $owner, $home));
        }
        if ($rows) {
            $groups[] = ['file' => 'get_grid_info', 'title' => '', 'rows' => $rows];
        }
        if (!$config) {
            return $groups;
        }

        // The Robust config: what it says of the grid, compared with what the grid says. A difference is a restart that was not
        // done, or a setting that does not reach the grid
        if ($config->has('Robust')) {
            $given = array_filter($config->section('GridInfoService'), fn($value) => $value !== '');
            $rows = [];
            foreach (array_values(array_unique(array_merge(self::gridInfoKeys(), array_keys($given)))) as $key) {
                $value = (string) ($given[$key] ?? '');
                if ($value === '' || !$live) {
                    // Nothing to compare with when the grid does not answer: the addresses are asked from here, the profile
                    // with the name of an avatar too
                    array_push($rows, ...self::address($key, $value, $owner, $home, true));
                    continue;
                }
                $same = isset($live[$key]) && rtrim($live[$key], '/') === rtrim($value, '/');
                // The profile is one row: the address as the config says it, the link and the test those of the owner of the grid
                $profile = $key === 'web_profile_url' && $owner !== null && str_contains($value, '[AGENT_NAME]');
                if ($profile && $same) {
                    $rows[] = self::profileRow($key, $value, $owner);
                    continue;
                }
                $rows[] =
                    [
                        'label' => $key,
                        'kind' => self::isUrl($value) ? 'address' : 'value',
                        self::isUrl($value) ? 'url' : 'text' => $profile
                            ? Avatar::profileAt($value, $owner) ?? $value
                            : $value,
                        // Compared with what the grid says, not asked: the same, or not
                        'level' => $same ? 'ok' : 'error',
                        'status' => sprintf($same ? _('matches %s of get_grid_info') : _('differs from %s of get_grid_info'), $key),
                    ] +
                    ($profile ? ['display' => $value] : []) +
                    ($key === 'search' ? ['tag' => _('Web search')] : []) +
                    ($same ? [] : ['detail' => "get_grid_info $key: " . ($live[$key] ?? '')]);
            }
            $groups[] = ['file' => $file['robust'], 'title' => '[GridInfoService]', 'rows' => $rows];
        }

        // The other settings the health asks, by file and section, in the order of the registry: the addresses of the login, what
        // the simulators call (the search, the registration on a directory, the offline messages), the key of the currency
        $given = array_filter($config->section('GridInfoService'), fn($value) => $value !== '');
        $sections = [];
        foreach (Settings::entries() as $entry) {
            if (!isset($entry['health']) || $entry['section'] === 'GridInfoService' || ($entry['instance'] === 'robust' && !$config->has('Robust'))) {
                continue;
            }
            $id = $entry['instance'] . '/' . $entry['section'];
            $sections[$id] ??= ['file' => Settings::file($entry['instance'], $config), 'title' => '[' . $entry['section'] . ']', 'rows' => []];
            array_push($sections[$id]['rows'], ...self::settingRows($entry, $config, $owner, $given));
        }

        return [...$groups, ...array_values($sections)];
    }

    /**
     * The rows of a setting of the registry, as its `health` says it is asked.
     *
     * @param array<string,mixed> $entry
     * @param array<string,string> $given what the Robust config says in [GridInfoService]
     * @return list<array<string,mixed>>
     */
    private static function settingRows($entry, $config, $owner, $given)
    {
        $value = (string) $config->find($entry['section'], $entry['key']);
        switch ($entry['health']) {
            case 'login-search':
                return [self::loginSearch($value, $given['search'] ?? '')];
            case 'xmlrpc':
                // What the simulators call for the search
                return $value === ''
                    ? [self::emptyRow($entry['key'])]
                    : self::xmlrpc($entry['key'], $value, 'dir_places_query', ['flags' => 0, 'text' => 'health', 'query_start' => '0']);
            case 'register':
                // The registration on a directory, each DATA_SRV_
                $rows = [];
                foreach ($config->keys($entry['section'], '/^' . preg_quote($entry['key'], '/') . '/') as $key => $url) {
                    array_push($rows, ...self::query($key, $url, 'service=offline&host=health.invalid&port=1'));
                }

                return $rows ?: [self::emptyRow($entry['key'] . '*')];
            case 'offline':
                // The module V2 talks to Robust, which is not the helpers: nothing here to ask
                $robust = stripos((string) $config->find($entry['section'], 'OfflineMessageModule'), 'V2') !== false;

                return $robust
                    ? [self::valueRow('OfflineMessageModule', _('served by Robust (module V2)'))]
                    : ($value === '' ? [self::emptyRow($entry['key'])] : self::post($entry['key'], $value, self::retrieveMessages(), 'RetrieveMessages/'));
            case 'secret':
                // The key of the currency, where the MoneyServer has it: never shown, only whether the grid has one. Without it the
                // helpers tie the quotes with their default key, which a money server of the grid does not share: an error for a grid
                // that has a currency
                $module = strtolower((string) $config->find('Economy', 'economymodule'));
                $needed = $module !== '' && $module !== 'betagridlikemoneymodule';
                $has = trim($value) !== '';

                return [
                    [
                        'label' => $entry['key'],
                        'kind' => 'secret',
                        'set' => $has,
                        'level' => $has ? 'ok' : ($needed ? 'error' : 'unset'),
                    ] + ($has ? ['status' => _('set')] : ($needed ? ['status' => _('missing')] : ['reason' => 'health_unset'])),
                ];
            default:
                return self::address($entry['key'], $value, $owner);
        }
    }

    /**
     * The settings of [GridInfoService] that are addresses, shown first, in this order (the one of Robust.HG.ini.example, and
     * `search`, the web page of the search the viewers read in get_grid_info), even when the grid gives none.
     *
     * @return list<string>
     */
    private static function gridInfoKeys()
    {
        return array_values(array_map(
            static fn(array $entry): string => $entry['key'],
            array_filter(Settings::entries(), static fn(array $entry): bool => $entry['section'] === 'GridInfoService' && isset($entry['health'])),
        ));
    }

    /**
     * The rows of a setting that is an address: asked as a page, whatever it is for (the grid info of the login address and of
     * the gatekeeper, a teapot where the viewers only add a name, the profile with the name of an avatar and without any). A value
     * that is not an address is shown, an empty one is a row with its name only.
     *
     * @param  string      $key
     * @param  string      $value
     * @param  string|null $owner    the name of an avatar of the grid
     * @param  string      $home     the origin (scheme, host, port) of the grid, what is there alone is asked at /get_grid_info
     * @param  bool        $variants the profile is asked with the name of an avatar too
     * @return list<array<string,mixed>>
     */
    private static function address($key, $value, $owner, $home = '', $variants = false)
    {
        if ($value === '') {
            return [self::emptyRow($key) + ($key === 'search' ? ['tag' => _('Web search')] : [])];
        }
        if (!self::isUrl($value)) {
            return [self::valueRow($key, $value)];
        }
        $row = self::page($key, $value);
        if (
            in_array($key, self::GRID_ADDRESSES, true) ||
            ($home !== '' &&
                self::origin($value) === $home &&
                trim((string) parse_url($value, PHP_URL_PATH), '/') === '')
        ) {
            // What answers at these addresses is the grid info, the first thing the viewers ask
            $row['probe'] = rtrim($value, '/') . '/get_grid_info';
        } elseif ($key === 'economy') {
            // The address of the helpers, to which the viewers add the name of the script: no page of its own
            $row['expect'] = self::TEAPOT;
        } elseif ($key === 'search') {
            // The web page of the search, not the service the simulators call
            $row['tag'] = _('Web search');
        } elseif (str_contains($value, '[AGENT_NAME]')) {
            // What the grid says is the address without the name, which is not a page (a teapot); the one with the name of an avatar
            // is the one of the config
            if ($variants) {
                return [
                    $owner === null ? self::valueRow($key, $value) : self::profileRow($key, $value, $owner),
                ];
            }
            $row['url'] = rtrim((string) preg_replace('#\[AGENT_NAME\].*$#', '', $value), '/');
            $row['expect'] = self::TEAPOT;
            // A site answers the profile of whoever asks: signed out, as the check is, its registration
            $helpers = self::$site['helpers'] ?? null;
            if ($helpers !== null && !$helpers($value)) {
                $row = ['expect' => self::REDIRECTS, 'stay' => true, 'refuse' => true, 'leadsTo' => (string) (self::$site['register'] ?? ''), 'tag' => _('signed out')] + $row;
                $row['display'] = $value;
            }

            return [$row];
        }

        return [$row];
    }

    /**
     * The profile of an avatar of the grid (its owner): shown as the config says it, with [AGENT_NAME], linked and asked with the
     * name of the owner.
     */
    private static function profileRow($key, $value, $owner)
    {
        return self::page($key, Avatar::profileAt($value, $owner) ?? $value) + ['display' => $value];
    }

    /**
     * Where an address is: scheme, host and port (the default one of the scheme when it gives none).
     *
     * @return string empty when it is not an address
     */
    private static function origin($url)
    {
        $parts = parse_url((string) $url);
        if (empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);

        return $scheme .
            '://' .
            strtolower($parts['host']) .
            ':' .
            ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * `[LoginService] SearchURL`, the documented way to tell the viewers the web search (at login, and as a feature of the grid),
     * which Firestorm does not receive from there alone: `search` of [GridInfoService] is the real one, the one the checks use.
     * The same address as `search` is fine and not asked again; another address is the operator's, asked for what it is.
     *
     * @param string $value  SearchURL of [LoginService]
     * @param string $search search of [GridInfoService]
     */
    private static function loginSearch($value, $search)
    {
        if ($value === '') {
            return self::emptyRow('SearchURL') + ['tag' => _('Web search')];
        }
        if ($search !== '' && rtrim($value, '/') === rtrim($search, '/')) {
            return [
                'label' => 'SearchURL',
                'kind' => 'address',
                'url' => $value,
                'tag' => _('Web search'),
                'level' => 'ok',
                'status' => _('matches [GridInfoService] search'),
            ];
        }

        return self::page('SearchURL', $value, _('Web search'));
    }

    /** The row of an address that is not set: neither served nor tested (`unset`, `health_unset` says why), not a wrong one. */
    public static function emptyRow($label)
    {
        return ['label' => $label, 'kind' => 'address', 'level' => 'unset', 'reason' => 'health_unset'];
    }

    /** The row of a setting that is not an address: its value, shown, nothing to ask. */
    public static function valueRow($label, $value)
    {
        return ['label' => $label, 'kind' => 'value', 'text' => (string) $value, 'level' => 'none'];
    }

    /** What a page that shows a block of the helpers holds: the mark of its root (`data-helpers-block`). */
    public static function blockMarker($block)
    {
        return 'data-helpers-block="' . $block . '"';
    }

    /** A page: asked with a GET, it answers. */
    public static function page($label, $url, $tag = null)
    {
        return ['label' => $label, 'kind' => 'address', 'url' => $url, 'method' => 'get'] + ($tag ? ['tag' => $tag] : []);
    }

    /**
     * A service that answers XML-RPC, asked twice: with nothing, it must refuse, and with a request that makes sense (and asks
     * for nothing: an avatar that does not exist...) it must answer 200.
     *
     * @param  string               $label  what the rows are named
     * @param  string               $url    the address of the service
     * @param  string               $call   the method to call
     * @param  array<string,mixed>  $params its parameters, ints and strings
     * @return list<array<string,mixed>>
     */
    public static function xmlrpc($label, $url, $call, array $params)
    {
        return self::twice(
            $label,
            $url,
            'post',
            ['method' => 'xmlrpc', 'body' => self::xmlrpcBody($call, $params)],
            _('with valid XMLRPC request'),
        );
    }

    /**
     * A service that takes data posted, asked twice: with nothing, it must refuse, and with data that makes sense it must answer
     * 200.
     *
     * @param  string $suffix what follows the address of the service to call it (offline.php/RetrieveMessages/)
     * @return list<array<string,mixed>>
     */
    public static function post($label, $url, $body, $suffix = '')
    {
        return self::twice(
            $label,
            $url,
            'post',
            ['method' => 'post', 'body' => $body] +
                ($suffix === '' ? [] : ['probe' => rtrim($url, '/') . '/' . $suffix]),
            _('with valid post data'),
        );
    }

    /**
     * A service that takes its data in the address, asked twice: with nothing, it must refuse, and with a query that makes sense
     * it must answer 200.
     *
     * @return list<array<string,mixed>>
     */
    public static function query($label, $url, $query)
    {
        return self::twice(
            $label,
            $url,
            'get',
            ['method' => 'get', 'probe' => $url . (str_contains($url, '?') ? '&' : '?') . $query],
            _('with valid query'),
        );
    }

    /**
     * The two rows of a service: the call with nothing, which must be refused, and the right one.
     *
     * @param  string               $how  how the service is called without data: get or post
     * @param  array<string,mixed>  $good what makes the right call (method, body, probe)
     * @return list<array<string,mixed>>
     */
    private static function twice($label, $url, $how, array $good, $tag)
    {
        return [
            [
                'label' => $label,
                'kind' => 'address',
                'url' => $url,
                'method' => $how,
                'body' => '',
                'expect' => self::BARE,
                'refuse' => true,
                'tag' => _('without data'),
            ],
            ['label' => $label, 'kind' => 'address', 'url' => $url, 'tag' => $tag] + $good,
        ];
    }

    /** The request of the offline messages of an avatar, which has none. */
    public static function retrieveMessages()
    {
        return '<?xml version="1.0"?><UUID xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"><Guid>' .
            self::UNKNOWN_AVATAR .
            '</Guid></UUID>';
    }

    /** The body of a call of XML-RPC, with one struct of ints and strings as parameter. */
    private static function xmlrpcBody($method, array $params)
    {
        $members = '';
        foreach ($params as $name => $value) {
            $type = is_int($value) ? 'int' : 'string';
            $members .=
                '<member><name>' .
                htmlspecialchars((string) $name, ENT_XML1) .
                "</name><value><$type>" .
                htmlspecialchars((string) $value, ENT_XML1) .
                "</$type></value></member>";
        }

        return '<?xml version="1.0"?><methodCall><methodName>' .
            htmlspecialchars($method, ENT_XML1) .
            '</methodName><params><param><value><struct>' .
            $members .
            '</struct></value></param></params></methodCall>';
    }

    /**
     * Ask every address, all at once. A row that has a `probe` is asked there, one that has a `skip` is not asked. An address is
     * asked where its redirections lead, whatever its certificate: `cert` says it is one this server does not trust, which is not
     * what counts. What it answers is `level` (ok for a 2xx or a code expected of it, error for anything else, none when it is not
     * defined; slow for one that took longer than the timeout and answered as it should when asked again, longer), `status` (the
     * HTTP code, OK, or the error of curl) and `ms`.
     *
     * @param  list<array{title:string,rows:list<array<string,mixed>>}> $groups
     * @param  float $timeout how long an address has, in seconds
     * @param  float $retry   how long one that took too long has, asked again
     * @return list<array{title:string,rows:list<array<string,mixed>>}>
     */
    public static function answers($groups, $timeout = self::TIMEOUT, $retry = self::RETRY_TIMEOUT)
    {
        $requests = [];
        foreach ($groups as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                if (($row['url'] ?? '') !== '' && !isset($row['skip']) && !isset($row['level'])) {
                    $requests["$g/$r"] = $row;
                }
            }
        }
        // The same request once, its answer given to each row that asks it (an address the grid says and its config says)
        $unique = [];
        $same = [];
        foreach ($requests as $id => $row) {
            $key = json_encode([$row['probe'] ?? $row['url'], $row['method'] ?? 'get', $row['body'] ?? null, !empty($row['stay']), $row['contains'] ?? null]);
            $same[$id] = $unique[$key] ??= $id;
        }
        $results = self::ask(array_intersect_key($requests, array_flip($unique)), $timeout);
        foreach ($same as $id => $first) {
            isset($results[$first]) && ($results[$id] = $results[$first]);
        }
        // The ones that took too long, asked again with more time: slow, not broken, when they answer then
        $late = array_intersect_key($requests, array_filter($results, static fn(array $result): bool => $result['errno'] === self::TIMED_OUT));
        $again = self::ask($late, $retry);

        foreach ($groups as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                $result = $results["$g/$r"] ?? null;
                if (isset($row['level'])) {
                    $verdict = [];
                } elseif (isset($row['skip'])) {
                    $verdict = ['level' => 'none', 'status' => '', 'ms' => null];
                } elseif ($result === null) {
                    $verdict = self::verdict(null, '', 0);
                } else {
                    $verdict = self::verdict(
                        $result['code'],
                        $result['error'],
                        $result['ms'],
                        $result['errno'],
                        $row['expect'] ?? [],
                        !empty($row['refuse']),
                    );
                    $second = $again["$g/$r"] ?? null;
                    if ($second !== null && $second['errno'] !== self::TIMED_OUT) {
                        $later = self::verdict($second['code'], $second['error'], $second['ms'], $second['errno'], $row['expect'] ?? [], !empty($row['refuse']));
                        $verdict = $later['level'] === 'ok'
                            ? ['level' => 'slow', 'status' => $later['status'], 'ms' => $later['ms'], 'detail' => sprintf(_('answered in %d ms, after more than %s s the first time'), $later['ms'], self::seconds($timeout))]
                            : $later;
                        $result = $second;
                    }
                    // A certificate this server does not trust is told, it does not change what the address answers
                    if ($result['certificate'] !== 0) {
                        $verdict['cert'] = true;
                    }
                }
                // What it must answer, and where it must send to; where it led, or sends to
                if ($result !== null && !isset($row['level']) && !isset($row['skip'])) {
                    $verdict += self::expectation($row);
                    $location = !empty($row['stay']) ? $result['redirect'] : $result['effective'];
                    $asked = (string) ($row['probe'] ?? $row['url']);
                    $location !== '' && rtrim($location, '/') !== rtrim($asked, '/') && ($verdict['location'] = $location);
                    // A page that does not show the block it must is wrong, whatever it answers
                    if (($verdict['level'] ?? '') === 'ok' && isset($row['contains']) && !($result['contains'] ?? false)) {
                        $verdict['level'] = 'error';
                        $verdict['detail'] = sprintf(_('the page does not show the block %s'), (string) ($row['block'] ?? ''));
                    }
                    // A redirection that does not lead where it must is wrong
                    if (($verdict['level'] ?? '') === 'ok' && ($row['leadsTo'] ?? '') !== '' && !self::sameAddress((string) ($verdict['location'] ?? ''), $row['leadsTo'])) {
                        $verdict['level'] = 'error';
                        $verdict['detail'] = sprintf(_('sends to %s, not to %s'), $verdict['location'] ?? '', $row['leadsTo']);
                    }
                }
                $address = ($row['kind'] ?? 'address') === 'address';
                $groups[$g]['rows'][$r] = array_diff_key($row, [
                    'method' => 0,
                    'probe' => 0,
                    'skip' => 0,
                    'expect' => 0,
                    'refuse' => 0,
                    'body' => 0,
                    'stay' => 0,
                    'leadsTo' => 0,
                    'contains' => 0,
                ]) +
                    ($address ? $verdict + ['ms' => null] : $verdict);
            }
        }

        return $groups;
    }

    /**
     * Ask a set of addresses, a few at a time (CONCURRENCY).
     *
     * @param  array<string,array<string,mixed>> $requests id => row (url, probe, method: get, post or xmlrpc, body)
     * @param  float $timeout how long each has, in seconds
     * @return array<string,array{code:int,errno:int,error:string,ms:int,certificate:int}>
     */
    private static function ask($requests, $timeout = self::TIMEOUT)
    {
        if (!$requests) {
            return [];
        }
        $multi = curl_multi_init();
        $waiting = $requests;
        $running = [];
        $results = [];
        do {
            // A few at a time: the pages of a site call back the engine, a small pool of workers serves both
            while (count($running) < self::CONCURRENCY && $waiting !== []) {
                $id = (string) array_key_first($waiting);
                $row = array_shift($waiting);
                $body = new \ArrayObject(['text' => '']);
                $handle = self::handle($row, $timeout, isset($row['contains']) ? $body : null);
                curl_multi_add_handle($multi, $handle);
                $running[spl_object_id($handle)] = [$id, $handle, $row['contains'] ?? null, $body];
            }
            curl_multi_exec($multi, $active);
            $active && curl_multi_select($multi, 0.2);
            // The code of the error of each address is told by the set, not by the handle
            while (($done = curl_multi_info_read($multi)) !== false) {
                [$id, $handle, $contains, $body] = $running[spl_object_id($done['handle'])];
                unset($running[spl_object_id($done['handle'])]);
                $errno = (int) $done['result'];
                $results[$id] = [
                    'code' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'errno' => $errno,
                    'error' => curl_error($handle) ?: ($errno ? curl_strerror($errno) : ''),
                    'ms' => (int) round(curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000),
                    // How the certificate checks out, 0 when it is valid (or there is none)
                    'certificate' => (int) curl_getinfo($handle, CURLINFO_SSL_VERIFYRESULT),
                    // Where it led (redirections followed), and where it sends to (a redirection not followed)
                    'effective' => (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
                    'redirect' => (string) (curl_getinfo($handle, CURLINFO_REDIRECT_URL) ?: ''),
                ] + ($contains === null ? [] : ['contains' => str_contains((string) $body['text'], $contains)]);
                curl_multi_remove_handle($multi, $handle);
            }
        } while ($running !== [] || $waiting !== []);
        curl_multi_close($multi);

        return $results;
    }

    /**
     * The request of a row, ready to be asked.
     *
     * @param array<string,mixed> $row
     * @param ?\ArrayObject $body where its answer is kept, when the row looks for a block in it
     * @return \CurlHandle
     */
    private static function handle($row, $timeout, $body = null)
    {
        $handle = curl_init($row['probe'] ?? $row['url']);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min(2, $timeout) * 1000),
            CURLOPT_USERAGENT => 'OpenSimHealth',
            // What counts is where the address leads, not the redirection it has on the way (unless the row asks for the
            // answer of the address itself: a redirection away from it)
            CURLOPT_FOLLOWLOCATION => empty($row['stay']),
            CURLOPT_MAXREDIRS => 5,
            // Only the answer matters, not what follows it; the page of a block is kept, its first megabyte, to find the block in it
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use ($body) {
                $body !== null && strlen($body['text']) < self::PAGE_BYTES && ($body['text'] .= $chunk);

                return strlen($chunk);
            },
            // The certificate is not what is asked: an address with one this server does not trust is asked all the same,
            // and the result says so
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        if (($row['method'] ?? 'get') !== 'get') {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $row['body'] ?? '',
                CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
            ]);
        }

        return $handle;
    }

    /**
     * What an answer means: 2xx is fine, anything else is an error (a 404 is an address that is not there), unless it is what is
     * expected of this address. An address that does not answer gives the code of the error and what it says.
     *
     * @param  int|null $code   the HTTP code at the end of the redirections, 0 when nothing answered, null for an address that is not defined
     * @param  string   $error  what curl says when nothing answered
     * @param  int      $errno  the code of that error
     * @param  int[]    $expect the codes that are the right answer of this address, besides the 2xx
     * @param  bool     $refuse the address must refuse (called with nothing): only the codes expected are the right answer
     * @return array{level:string,status:string,ms:?int,detail?:string}
     */
    public static function verdict($code, $error, $ms, $errno = 0, $expect = [], $refuse = false)
    {
        if ($code === null) {
            return ['level' => 'none', 'status' => '', 'ms' => null];
        }
        if ($code === 0) {
            return [
                'level' => 'error',
                'status' => 'cURL ' . _('error') . ' ' . $errno,
                'ms' => null,
                'detail' => $error,
            ];
        }
        $level = ($code >= 200 && $code < 300 && !$refuse) || in_array($code, $expect, true) ? 'ok' : 'error';

        return ['level' => $level, 'status' => (string) $code, 'ms' => $ms];
    }

    /** A row for what is there or not, and the file or the thing it is about. */
    public static function row($label, $good, $detail = '')
    {
        return [
            'label' => $label,
            'level' => $good ? 'ok' : 'error',
            'status' => $good ? _('OK') : _('missing'),
            'detail' => $detail,
        ];
    }

    /** A row for a file the engine reads: where it is, whether this user can read it; not set when no file is given. */
    public static function path($label, $path)
    {
        if ($path === '' || $path === null) {
            return ['label' => $label, 'kind' => 'file', 'level' => 'unset', 'reason' => 'health_unset'];
        }

        return self::fileRow($label, (string) $path);
    }

    /** A file: where it is, whether this user can read it. */
    private static function fileRow($label, $path)
    {
        $readable = is_readable($path);

        return [
            'label' => $label,
            'kind' => 'file',
            'path' => $path,
            'readable' => $readable,
            'level' => $readable ? 'ok' : 'error',
            'status' => $readable ? _('readable') : (is_file($path) ? _('not readable') : _('missing')),
        ];
    }

    /**
     * The pages of a grid as Robust and the simulators set them (Robust.ini or Robust.HG.ini, the base OpenSim.ini): a page set
     * only in the files of the helpers (DirectoryURL of Helpers.ini) is not (`source` default): a grid needs no helpers to run;
     * set, a page must answer, not set, it is not asked.
     *
     * @param list<array<string,mixed>> $pages SitePages::rows()
     * @param list<string> $urls the addresses Robust and the simulators set
     * @param callable(string):bool $servedByHelpers whether an address is under the helpers
     * @return list<array<string,mixed>>
     */
    public static function configured($pages, $urls, $servedByHelpers)
    {
        foreach ($pages as $i => $page) {
            $grid = array_filter((array) ($page['keys'] ?? []), static fn(string $key): bool => !str_starts_with($key, '[Helpers]')) !== [];
            $grid || ($pages[$i]['source'] = 'default');
        }

        return $pages;
    }

    /**
     * The pages of a grid for people, as SitePages::rows() gives them: each page the grid sets (Robust), asked at its address with
     * the code it must answer; one it does not set is not asked; the base of the helpers is no page of the grid. The profile
     * without a name is not a page: the helpers answer it as a teapot (or a 404), a site sends whoever is signed
     * out, as the check is, to its registration (`register`).
     *
     * @param  list<array{page:string,url:?string,source?:?string,servedBy:?string,helpersDefault?:?string}> $pages
     * @param  ?string $register the registration page of the grid (register of [GridInfoService])
     * @return list<array<string,mixed>>
     */
    public static function pages($pages, $register = null)
    {
        $rows = [];
        foreach ($pages as $page) {
            // The base of the helpers is no setting of Robust: the pages under it are, each asked for itself
            if ($page['page'] === 'helpers') {
                continue;
            }
            $url = (string) ($page['url'] ?? '');
            if ($url === '' || ($page['source'] ?? '') === 'default') {
                $rows[] = self::emptyRow($page['page']);
                continue;
            }
            $tag = ($page['servedBy'] ?? '') === 'helpers' ? _('helpers') : _('site');
            // A page the site serves shows the block of the helpers it is the page of
            $block = ($page['servedBy'] ?? '') === 'site' && ($page['block'] ?? null) !== null
                ? ['block' => $page['block'], 'contains' => self::blockMarker($page['block'])]
                : [];
            if (!str_contains($url, '[AGENT_NAME]')) {
                $rows[] = self::page($page['page'], $url, $tag) + $block;
                continue;
            }
            $bare = rtrim((string) preg_replace('#\[AGENT_NAME\].*$#', '', $url), '/');
            $rows[] = ($page['servedBy'] ?? '') === 'site'
                // The profile of whoever asks, signed out: the registration of the site
                ? self::page($page['page'], $bare, $tag . ', ' . _('signed out')) + [
                    'stay' => true,
                    'refuse' => true,
                    'expect' => self::REDIRECTS,
                    'leadsTo' => (string) $register,
                    'display' => $url,
                ]
                // The profiles without a name: not a page (a teapot for the helpers), or no such profile
                : self::page($page['page'], $bare, $tag) + ['expect' => [404, 418], 'display' => $url];
        }

        return $rows;
    }

    /**
     * A row for a folder this user writes in (a cache): where it is, whether it is there, whether this user writes in it (or can make
     * it). One it cannot is an error: what it would keep is made again on every request.
     */
    public static function writable($label, $folder)
    {
        $good = (new \OpenSim\Support\FileCache($folder))->writable();
        $user = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : get_current_user();
        $exists = is_dir($folder);

        return [
            'label' => $label,
            'kind' => 'folder',
            'path' => $folder,
            'exists' => $exists,
            'writable' => $good,
            'level' => $good ? 'ok' : 'error',
            'status' => $good ? ($exists ? _('writable') : _('can be made')) : _('not writable'),
        ] + ($good || $user === '' ? [] : ['detail' => $user]);
    }

    /**
     * What an install needs of PHP, one row per extension: installed, its version, what it is for. Imagick is required; its JPEG 2000
     * is required by the web assets service, unless the grid sets an external one.
     *
     * @param ?string $external the external web assets service of the grid (WebAssets::external), null when it runs here
     * @param ?array{file:string,section:string,key:string} $setting where the grid sets it (Settings::where('assetsUrl'))
     * @return list<array<string,mixed>>
     */
    public static function extensions($external = null, $setting = null)
    {
        $setting ??= \OpenSim\Config\Settings::where('assetsUrl');

        return [
            self::requirement('xmlrpc', function_exists('xmlrpc_encode'), 'extension or polyfill'),
            self::requirement('imagick', class_exists('Imagick'), 'text to image, web assets'),
            self::jpeg2000(class_exists('Imagick') && \Imagick::queryFormats('JP2') !== [], $external, $setting),
            self::requirement('gettext', function_exists('gettext'), 'translations'),
        ];
    }

    /**
     * Whether imagick reads JPEG 2000, the format of the pictures of the world (textures, profile pictures, map tiles), required by
     * the web assets service (an imagick built with OpenJPEG). Without it, the grid sets an external web assets service: then
     * nothing is needed here.
     *
     * @param bool $reads whether imagick reads JPEG 2000 here
     * @param ?string $external the external web assets service of the grid, null when it runs here
     * @param array{file:string,section:string,key:string} $setting where the grid sets it
     * @return array<string,mixed>
     */
    public static function jpeg2000($reads, $external, $setting = null)
    {
        $setting ??= \OpenSim\Config\Settings::where('assetsUrl');
        $where = $setting['file'] . ' · [' . $setting['section'] . '] · ' . $setting['key'];
        $row = ['label' => 'imagick JPEG 2000', 'kind' => 'requirement', 'installed' => $reads, 'version' => null, 'setting' => $setting];
        if ($reads) {
            return $row + ['level' => 'ok', 'status' => _('reads JPEG 2000'), 'detail' => 'required by the web assets service: the pictures of the world (JPEG 2000)'];
        }
        if ($external !== null) {
            return $row + ['level' => 'ok', 'url' => $external, 'status' => _('external web assets service'), 'detail' => "the pictures of the world are converted by the web assets service of the grid ($where)"];
        }

        return $row + [
            'level' => 'error',
            'status' => _('cannot read JPEG 2000'),
            'detail' => "required by the web assets service, for the pictures of the world (imagick built with OpenJPEG): without it, set an external web assets service ($where)",
        ];
    }

    /** An extension of PHP: installed or missing, its version (a polyfill has none). */
    private static function requirement($extension, $installed, $for)
    {
        $version = $installed ? (phpversion($extension) ?: null) : null;

        return [
            'label' => $extension,
            'kind' => 'requirement',
            'installed' => $installed,
            'version' => $version,
            'level' => $installed ? 'ok' : 'error',
            'status' => $installed ? ($version !== null ? sprintf(_('installed %s'), $version) : _('installed (polyfill)')) : _('missing'),
            'detail' => $for,
        ];
    }

    /**
     * The files an install reads, and whether this user can.
     *
     * @param  string[] $paths
     * @return list<array<string,mixed>>
     */
    public static function files($paths)
    {
        $rows = [];
        foreach ($paths as $file) {
            if (is_file($file)) {
                $rows[] = self::fileRow(basename($file), $file);
            }
        }

        return $rows;
    }

    /**
     * The level of a check, from the levels of its rows: error when one is, else slow, else ok, else none (nothing to ask, a
     * setting not set counting as nothing).
     *
     * @param list<array{rows:list<array<string,mixed>>}> $groups
     */
    public static function level($groups)
    {
        $levels = array_column(array_merge(...array_column($groups, 'rows') ?: [[]]), 'level');
        foreach (['error', 'slow', 'ok'] as $level) {
            if (in_array($level, $levels, true)) {
                return $level;
            }
        }

        return 'none';
    }

    /**
     * The rows with what they are: the name of their setting in the catalog (`name`) and its description (`description`), keys
     * that a site translates; `thirdParty` for a service that does not answer and needs an application of its own (not served by
     * the helpers of the grid).
     *
     * @param list<array{file?:string,title?:string,rows:list<array<string,mixed>>}> $groups
     * @param ?callable(string):bool $servedByHelpers whether an address is under the helpers of the grid
     * @return list<array{file?:string,title?:string,rows:list<array<string,mixed>>}>
     */
    public static function describe($groups, $servedByHelpers = null)
    {
        foreach ($groups as $g => $group) {
            $file = (string) ($group['file'] ?? '');
            $section = match (true) {
                $file === 'get_grid_info' => 'GridInfoService',
                in_array($file, ['pages', 'cache'], true) => $file,
                default => trim((string) ($group['title'] ?? ''), '[] '),
            };
            foreach ($group['rows'] as $r => $row) {
                // The full context of a setting: its instance, its file, its section, its key; what the grid says, its key
                $instance = match (true) {
                    $file === 'get_grid_info' => 'grid_info',
                    preg_match('/^Robust/i', $file) === 1 => 'robust',
                    strcasecmp($file, 'OpenSim.ini') === 0 => 'opensim',
                    strcasecmp($file, 'MoneyServer.ini') === 0 => 'moneyserver',
                    default => null,
                };
                if ($instance !== null && isset($row['label'])) {
                    $row['context'] = ['instance' => $instance, 'file' => $file] + ($instance === 'grid_info' ? [] : ['section' => $section]) + ['key' => (string) $row['label']];
                    $groups[$g]['rows'][$r] = $row;
                }
                $name = self::settingName($section, (string) ($row['label'] ?? ''));
                if ($name === null) {
                    continue;
                }
                $row += ['name' => "setting_$name", 'description' => "setting_{$name}_help"];
                $url = (string) ($row['url'] ?? '');
                if (($row['level'] ?? '') === 'error' && in_array($name, self::THIRD_PARTY, true) && $url !== '' && !($servedByHelpers !== null && $servedByHelpers($url))) {
                    $row['thirdParty'] = true;
                }
                $groups[$g]['rows'][$r] = $row;
            }
        }

        return $groups;
    }

    /**
     * What a wrong or missing address should be, and the line of its file to write (`expected`, `snippet`), from what the options of
     * the grid write (GridOptions::expected()): for a row of a section of a file of the grid that does not answer (error) or is not
     * set, whose value is not the one expected (an address that is right and answers wrong is not a setting to change).
     *
     * @param list<array{file?:string,title?:string,rows:list<array<string,mixed>>}> $groups
     * @param array<string,array<string,array<string,string>>> $expected by file (Robust, OpenSim), section and key, as written
     * @param array<string,string> $constants the constants the values are read with (WebURL...)
     * @return list<array{file?:string,title?:string,rows:list<array<string,mixed>>}>
     */
    public static function expect($groups, $expected, $constants = [])
    {
        foreach ($groups as $g => $group) {
            $file = (string) ($group['file'] ?? '');
            $role = match (true) {
                preg_match('/^Robust/i', $file) === 1 => 'Robust',
                strcasecmp($file, 'OpenSim.ini') === 0 => 'OpenSim',
                default => null,
            };
            $section = trim((string) ($group['title'] ?? ''), '[] ');
            $settings = $role === null ? [] : array_change_key_case($expected[$role] ?? [])[strtolower($section)] ?? [];
            if ($settings === []) {
                continue;
            }
            foreach ($group['rows'] as $r => $row) {
                $label = (string) ($row['label'] ?? '');
                $value = (string) ($row['display'] ?? ($row['url'] ?? ($row['text'] ?? '')));
                if (!in_array($row['level'] ?? '', ['error', 'none', 'unset'], true)) {
                    continue;
                }
                // The key itself, or the one of the grid for a key that is a prefix (DATA_SRV_*)
                $key = null;
                foreach (array_keys($settings) as $candidate) {
                    if (strcasecmp($candidate, $label) === 0 || (str_ends_with($label, '*') && stripos($candidate, substr($label, 0, -1)) === 0)) {
                        $key = $candidate;
                        break;
                    }
                }
                if ($key === null) {
                    continue;
                }
                $should = \OpenSim\Config\ConfigReader::replaceConstants(trim($settings[$key], " \""), $constants);
                if ($value !== '' && rtrim($value, '/') === rtrim($should, '/')) {
                    continue;
                }
                $groups[$g]['rows'][$r] += ['expected' => $should, 'snippet' => "[$section]\n    $key = " . $settings[$key]];
            }
        }

        return $groups;
    }

    /**
     * The name of a row in the catalog, null for one it does not name: the label of its setting in the registry when the catalog
     * describes it, else the one of a page or a cache (ROWS).
     */
    private static function settingName($section, $key)
    {
        foreach (Settings::entries() as $entry) {
            if (!isset($entry['description']) || strcasecmp($entry['section'], (string) $section) !== 0) {
                continue;
            }
            $same = ($entry['prefix'] ?? false) ? stripos((string) $key, $entry['key']) === 0 : strcasecmp($entry['key'], rtrim((string) $key, '*')) === 0;
            if ($same && str_starts_with($entry['label'], 'setting_')) {
                return substr($entry['label'], 8);
            }
        }

        return self::ROWS["$section/$key"] ?? null;
    }

    /**
     * What a row must answer: its codes (`2xx` for a page, the ones expected of it), and where a redirection must send to.
     *
     * @param array<string,mixed> $row
     * @return array{expectedStatus:string,expectedLocation?:string}
     */
    private static function expectation($row)
    {
        $codes = array_map('strval', $row['expect'] ?? []);
        $codes = !empty($row['refuse']) ? $codes : ['2xx', ...$codes];
        $codes === array_map('strval', self::REDIRECTS) && ($codes = ['3xx']);

        return ['expectedStatus' => implode(', ', $codes)] + (($row['leadsTo'] ?? '') !== '' ? ['expectedLocation' => $row['leadsTo']] : []);
    }

    /** Whether two addresses are the same page: scheme, host, port and path, whatever follows (?return=...). */
    private static function sameAddress($a, $b)
    {
        $page = static fn(string $url): string => strtolower(self::origin($url)) . rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return $a !== '' && $page($a) === $page($b);
    }

    /** Seconds as they are said: 3, 1.5. */
    private static function seconds($seconds)
    {
        return rtrim(rtrim(number_format((float) $seconds, 1, '.', ''), '0'), '.');
    }

    /** Whether a value is an address. */
    public static function isUrl($value)
    {
        return (bool) preg_match('#^https?://#i', (string) $value);
    }
}
