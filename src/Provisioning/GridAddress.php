<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Config\IniEditor;
use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\Web\Snippets;

/**
 * The address of a grid changed everywhere its files on this machine say it: its host and its public port (the login URI), and the
 * address of its web site.
 *
 *  - Robust: `[Const]` BaseHostname, BaseURL (its scheme kept), PublicPort, WebURL; `[Network]` ConsoleHost when it named the host;
 *  - every address of the old login URI or of the old site in the ini files of the grid (Robust, OpenSim.ini, Helpers.ini,
 *    MoneyServer.ini, Gloebit.ini, the configs of its simulators): in Robust, by its constants (`${Const|WebURL}/register`,
 *    `${Const|BaseURL}:${Const|PublicPort}`), elsewhere as the new address;
 *  - the regions: `ExternalHostName`, what viewers connect to, when it is SYSTEMIP or the old host (another host is left as it is);
 *  - the list of destinations of the grid (destinations.txt of its folder, and the file GuideSource names on this machine): the old
 *    host:port of its places;
 *  - setup.json, the record of its setup: the old host and the old site;
 *  - the web-server files of its folder (web/): made again for the new site.
 *
 * The master of the simulators (OpenSimGridDefaults.ini) follows Robust when they start. A connection string is never touched, nor a
 * comment. Plan (the default) says each line that would change; apply writes them, then the grid and its simulators restart.
 */
final class GridAddress
{
    /** The files of the grid that may hold one of its addresses, in its folder. */
    private const FILES = ['OpenSim.ini', 'Helpers.ini', 'MoneyServer.ini', 'Gloebit.ini'];

    /** The web-server files of a grid's folder (web/) and the server each is for. */
    private const WEB = ['%s-apache.conf' => 'apache', '%s-nginx.conf' => 'nginx', '%s.caddyfile' => 'caddy'];

    public function __construct(private readonly Grid $grid) {}

    /**
     * @param array{host?:?string,port?:int|string|null,site?:?string} $to what changes, the rest as it is
     * @return array{from:array{host:string,port:int,site:string},to:array{host:string,port:int,site:string},changes:list<array{file:string,line:int,before:string,after:string}>,applied:bool}
     * @throws InvalidValue for a host, a port or a site that cannot be one, or nothing to change
     */
    public function change(array $to, bool $apply = false): array
    {
        $directory = $this->grid->directory();
        $robust = $this->grid->robustIni() ?? throw InvalidValue::because('invalid-request', "the grid {$this->grid->nick()} has no Robust config on this machine");
        $from = ['host' => $this->grid->baseHostname(), 'port' => $this->grid->publicPort(), 'site' => rtrim((string) $this->grid->site(), '/')];
        $new = $this->checked($to, $from);
        $new === $from && throw InvalidValue::because('invalid-request', 'the grid has this address already: give another host, port or site');

        $texts = [];
        $read = static function (string $file) use (&$texts): ?string {
            return $texts[$file] ??= is_file($file) ? (string) file_get_contents($file) : null;
        };
        $written = [];
        $write = static function (string $file, string $text) use (&$written, $read): void {
            $read($file) === $text || ($written[$file] = $text);
        };

        // Robust: its constants, then the addresses it writes out
        $editor = new IniEditor((string) $read($robust));
        $scheme = parse_url(trim((string) $editor->get('Const', 'BaseURL'), '"'), PHP_URL_SCHEME) ?: 'http';
        $editor->set('Const', 'BaseHostname', "\"{$new['host']}\"");
        str_contains((string) $editor->get('Const', 'BaseURL'), '${') || $editor->set('Const', 'BaseURL', "\"$scheme://{$new['host']}\"");
        $new['port'] === $from['port'] || $editor->set('Const', 'PublicPort', (string) $new['port']);
        $new['site'] !== '' && $new['site'] !== $from['site'] && $editor->set('Const', 'WebURL', "\"{$new['site']}\"");
        $console = trim((string) $editor->get('Network', 'ConsoleHost'), '"');
        $console !== '' && strcasecmp($console, $from['host']) === 0 && $editor->set('Network', 'ConsoleHost', "\"{$new['host']}\"");
        $hasWeb = $editor->get('Const', 'WebURL') !== null;
        // What moves: the login URI when the host or the port changes, the site when it changes
        $logins = $new['host'] === $from['host'] && $new['port'] === $from['port'] ? [] : $this->logins($from, $scheme);
        $oldSite = $new['site'] !== $from['site'] ? $from['site'] : '';
        $write($robust, $this->addresses($editor->toString(), $logins, '${Const|BaseURL}:${Const|PublicPort}', $oldSite, $hasWeb ? '${Const|WebURL}' : $new['site']));

        // The other ini files of the grid and of its simulators
        $simulators = GridConfig::simulatorsDirectory($directory);
        $login = "$scheme://{$new['host']}:{$new['port']}";
        foreach ([...array_map(static fn(string $file): string => "$directory/$file", self::FILES), ...(glob("$simulators/*.ini") ?: [])] as $file) {
            ($text = $read($file)) === null || $write($file, $this->addresses($text, $logins, $login, $oldSite, $new['site']));
        }
        foreach ($new['host'] === $from['host'] ? [] : (glob("$simulators/*/regions/*.ini") ?: []) as $file) {
            $write($file, $this->regions((string) $read($file), $from['host'], $new['host']));
        }

        // The places of the guide, the record of the setup
        $guide = trim((string) $this->grid->config()?->find('Helpers', 'GuideSource'), " \t\"");
        $lists = array_unique(array_filter(["$directory/destinations.txt", $guide !== '' && !preg_match('#^[a-z]+://#i', $guide) ? ($guide[0] === '/' ? $guide : "$directory/$guide") : '']));
        foreach ($logins === [] ? [] : $lists as $file) {
            ($text = $read($file)) === null || $write($file, $this->places($text, $from, $new));
        }
        $setup = "$directory/setup.json";
        if (($text = $read($setup)) !== null && is_array($data = json_decode($text, true))) {
            $moved = $this->recorded($data, $from, $new, $logins, $login);
            $moved === $data || $write($setup, SetupFile::toJson($moved));
        }

        $changes = [];
        foreach ($written as $file => $text) {
            array_push($changes, ...$this->lines($file, (string) $read($file), $text));
        }
        if ($apply) {
            foreach ($written as $file => $text) {
                $this->save($file, $text);
            }
            // The web-server files, made again from the files now written
            foreach (self::WEB as $name => $server) {
                $file = "$directory/web/" . sprintf($name, $this->grid->nick());
                if (is_file($file)) {
                    $before = (string) file_get_contents($file);
                    $after = Snippets::render($server, $this->grid->nick(), $directory, webUrl: $new['site']);
                    $before === $after || (array_push($changes, ...$this->lines($file, $before, $after)) && $this->save($file, $after));
                }
            }
        }

        return ['from' => $from, 'to' => $new, 'changes' => $changes, 'applied' => $apply];
    }

    /**
     * @param array{host?:?string,port?:int|string|null,site?:?string} $to
     * @param array{host:string,port:int,site:string} $from
     * @return array{host:string,port:int,site:string}
     */
    private function checked(array $to, array $from): array
    {
        $host = trim((string) ($to['host'] ?? ''));
        $host === '' || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', $host) === 1
            || throw InvalidValue::because('invalid-request', "'$host' is not a host name");
        $port = $to['port'] ?? null;
        $port === null || $port === '' || (ctype_digit((string) $port) && (int) $port >= 1 && (int) $port <= 65535)
            || throw InvalidValue::because('invalid-request', "'$port' is not a port");
        $site = rtrim(trim((string) ($to['site'] ?? '')), '/');
        $site === '' || (preg_match('#^https?://[^/\s]+#i', $site) === 1 && filter_var($site, FILTER_VALIDATE_URL) !== false)
            || throw InvalidValue::because('invalid-request', "'$site' is not the address of a web site (http:// or https://)");

        return [
            'host' => $host !== '' ? strtolower($host) : $from['host'],
            'port' => $port !== null && $port !== '' ? (int) $port : $from['port'],
            'site' => $site !== '' ? $site : $from['site'],
        ];
    }

    /**
     * The forms the old login URI is written in: with its scheme and its port.
     *
     * @param array{host:string,port:int,site:string} $from
     * @return list<string>
     */
    private function logins(array $from, string $scheme): array
    {
        return array_values(array_unique(["$scheme://{$from['host']}:{$from['port']}", "http://{$from['host']}:{$from['port']}", "https://{$from['host']}:{$from['port']}"]));
    }

    /** The values of an ini text under one of the old addresses, put under the new ones; comments and connection strings left. */
    private function addresses(string $text, array $logins, string $login, string $oldSite, string $newSite): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if (!preg_match('/^(\s*[^;#\s\[][^=]*=\s*"?)([^"]*)("?\s*)$/', $line, $m) || stripos($m[1], 'ConnectionString') !== false) {
                continue;
            }
            $value = $m[2];
            foreach ($logins as $old) {
                $value = self::under($value, $old, $login);
            }
            $oldSite !== '' && $newSite !== '' && ($value = self::under($value, $oldSite, $newSite));
            $value === $m[2] || ($lines[$i] = $m[1] . $value . $m[3]);
        }

        return implode("\n", $lines);
    }

    /** A value that is the old address, or under it (a path, a query), with the new one instead; else the value as it is. */
    private static function under(string $value, string $old, string $new): string
    {
        $length = strlen($old);
        if (strncasecmp($value, $old, $length) !== 0 || !in_array(substr($value, $length, 1), ['', '/', '?'], true)) {
            return $value;
        }
        // The constants already give the new address: the same text
        return $new . substr($value, $length);
    }

    /** ExternalHostName of the regions of a file, when it is SYSTEMIP or the old host: the new host. */
    private function regions(string $text, string $oldHost, string $newHost): string
    {
        return (string) preg_replace_callback(
            '/^(\s*ExternalHostName\s*=\s*"?)([^"\s;]*)("?.*)$/mi',
            static fn(array $m): string => strcasecmp($m[2], 'SYSTEMIP') === 0 || strcasecmp($m[2], $oldHost) === 0 ? $m[1] . $newHost . $m[3] : $m[0],
            $text,
        );
    }

    /**
     * The places of a list of destinations at the old host:port, at the new one (with or without their scheme); its comments left.
     *
     * @param array{host:string,port:int,site:string} $from
     * @param array{host:string,port:int,site:string} $to
     */
    private function places(string $text, array $from, array $to): string
    {
        $old = '/(^|[|\s"\/])' . preg_quote("{$from['host']}:{$from['port']}", '/') . '(?=[:\/|\s"]|$)/mi';
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            preg_match('/^\s*(#|\/\/)/', $line) === 1 || ($lines[$i] = (string) preg_replace($old, '${1}' . "{$to['host']}:{$to['port']}", $line));
        }

        return implode("\n", $lines);
    }

    /**
     * The record of the setup with the new addresses: the host of the grid and of its simulators, the values under the old addresses;
     * its databases left as they are.
     *
     * @param array<mixed> $data
     * @param array{host:string,port:int,site:string} $from
     * @param array{host:string,port:int,site:string} $to
     * @param list<string> $logins
     * @return array<mixed>
     */
    private function recorded(array $data, array $from, array $to, array $logins, string $login): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // A database is where it is, whatever the address of the grid
                $key === 'database' || ($data[$key] = $this->recorded($value, $from, $to, $logins, $login));
            } elseif (is_string($value)) {
                $moved = in_array($key, ['hostname', 'external_host'], true) && strcasecmp($value, $from['host']) === 0 ? $to['host'] : $value;
                foreach ($logins as $old) {
                    $moved = self::under($moved, $old, $login);
                }
                $from['site'] !== '' && ($moved = self::under($moved, $from['site'], $to['site']));
                $data[$key] = $moved;
            } elseif (is_int($value) && $key === 'public_port' && $value === $from['port']) {
                $data[$key] = $to['port'];
            }
        }

        return $data;
    }

    /**
     * The lines that differ between two texts of a file, by number in the text before (the lines added, after the last).
     *
     * @return list<array{file:string,line:int,before:string,after:string}>
     */
    private function lines(string $file, string $before, string $after): array
    {
        $old = explode("\n", $before);
        $new = explode("\n", $after);
        $changes = [];
        foreach ($new as $i => $line) {
            ($old[$i] ?? null) === $line || ($changes[] = ['file' => $file, 'line' => $i + 1, 'before' => $old[$i] ?? '', 'after' => $line]);
        }
        foreach (array_slice($old, count($new), null, true) as $i => $line) {
            $changes[] = ['file' => $file, 'line' => $i + 1, 'before' => $line, 'after' => ''];
        }

        return $changes;
    }

    /** A file written in place: its owner, its group and its mode kept. */
    private function save(string $file, string $text): bool
    {
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            throw new \RuntimeException("$file cannot be written");
        }
        try {
            ftruncate($handle, 0);
            fwrite($handle, $text);
        } finally {
            fclose($handle);
        }

        return true;
    }
}
