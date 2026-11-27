<?php

declare(strict_types=1);

namespace W4OS\Engine\Text;

use W4OS\Engine\Hypergrid\Destination;
use W4OS\Engine\Network\Http;

/**
 * The destinations of a guide, written as lines "Name|Region URL" in a file or at a URL; a title (a name alone) starts a section,
 * the lines of a title that follow each other with nothing between them (no spacer, no column break, no destination) being one,
 * and a title without a destination before the next column break or the end being text of the board, left out. The same
 * lines serve the boards of the scripts, the Gudz Teleport Board the reference (a dash alone is a new column, a pipe alone an empty
 * row: layout hints, not destinations), and the older format of 5 columns, read as the board reads it: the name in the 2nd column
 * (else the 1st), the address in the 4th, the landing point in the 5th. A third column of `x,y,z` is the place in the region. An
 * address listed already is left out, and `http://` taken off an address, as the board does. The board reads 2048 bytes of a
 * source at most; a guide reads 1 MB (MAX).
 */
final class DestinationList
{
    /** The size of a list, at most (the one of 2.x). */
    public const MAX = 1_048_576;

    /** The layout hints of the boards: a new column, an empty row. */
    public const MARKERS = ['-', '|'];

    /**
     * The text of a source: a URL, or a file (a relative path is looked for in `$folder`); text only, MAX at most, in UTF-8.
     *
     * @param ?\Closure(string):?string $http the body of an address, null when it does not answer (default: Http::text(), which
     *        takes a text/* answer only)
     * @return ?string null when there is no source or it cannot be read
     */
    /** Seconds a list of another server is kept before it is asked again. */
    public const FRESH = 900;

    /**
     * The text of a list of another server, kept in a folder: asked once while it is fresh (FRESH), with a short wait; when the server
     * does not answer, its last copy, and the failure kept as well, so that a page never waits on it again before FRESH.
     *
     * @param ?\Closure(string):?string $http
     * @param ?\Closure():int $clock
     */
    public static function cached(string $source, string $folder, ?\Closure $http = null, ?\Closure $clock = null): ?string
    {
        $now = $clock !== null ? $clock() : time();
        $file = rtrim($folder, '/') . '/' . hash('xxh128', $source) . '.txt';
        $kept = is_file($file) ? (string) @file_get_contents($file) : null;
        if ($kept !== null && (int) @filemtime($file) >= $now - self::FRESH) {
            return $kept !== '' ? $kept : null;
        }
        $fetched = self::read($source, null, $http ?? static fn(string $url): ?string => Http::text($url, self::MAX, 3));
        $text = $fetched ?? $kept ?? '';
        $state = self::state($source, $folder);
        $state = $fetched !== null ? ['source' => $source, 'fetched' => $now, 'failed' => null] : ['source' => $source, 'fetched' => $state['fetched'], 'failed' => $now];
        (is_dir($folder) || @mkdir($folder, 0o775, true)) && @file_put_contents($file, $text) !== false && @touch($file, $now)
            && @file_put_contents("$file.state", json_encode($state));

        return $text !== '' ? $text : null;
    }

    /**
     * What is known of the copy of a list of another server (cached()): its source, when the copy was fetched, when the server last did
     * not answer since; nulls when there is none.
     *
     * @return array{source:string,fetched:?int,failed:?int}
     */
    public static function state(string $source, string $folder): array
    {
        $file = rtrim($folder, '/') . '/' . hash('xxh128', $source) . '.txt.state';
        $state = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return ['source' => $source, 'fetched' => is_int($state['fetched'] ?? null) ? $state['fetched'] : null, 'failed' => is_int($state['failed'] ?? null) ? $state['failed'] : null];
    }

    public static function read(?string $source, ?string $folder = null, ?\Closure $http = null): ?string
    {
        $source = trim((string) $source);
        if ($source === '') {
            return null;
        }
        if (filter_var($source, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $source) === 1) {
            $text = $http !== null ? $http($source) : Http::text($source, self::MAX);
        } else {
            $path = $source[0] === '/' || $folder === null || $folder === '' ? $source : rtrim($folder, '/') . "/$source";
            $text = is_file($path) && filesize($path) <= self::MAX ? @file_get_contents($path) : false;
        }

        return is_string($text) && strlen($text) <= self::MAX && mb_check_encoding($text, 'UTF-8') ? $text : null;
    }

    /**
     * The lines of a list of destinations: a section by its title alone, then its destinations as "Name|Region URL". What
     * parse() reads back, and what the boards of the scripts read.
     *
     * @param array<string,list<array{name:string,url:string}>> $destinations
     */
    public static function format(array $destinations): string
    {
        $lines = [];
        foreach ($destinations as $section => $places) {
            $lines[] = $section;
            foreach ($places as $place) {
                // A layout hint of the boards as it was, the place in the region in its own column
                $lines[] = isset($place['marker'])
                    ? $place['marker']
                    : $place['name'] . '|' . $place['url'] . (($place['landing'] ?? '') !== '' ? '|' . $place['landing'] : '');
            }
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * The destinations of a text, by section, in the order of the text.
     *
     * @param ?string $first the title of the destinations that come before any section
     * @param bool $layout for the boards (format()): the place in the region kept apart (`landing`, x,y,z), the layout hints kept
     *        (`marker`); else the place is at the end of the address, the hints left out (a guide)
     * @return array<string,list<array{name:string,url:string,landing?:string}|array{marker:string}>>
     */
    public static function parse(string $text, ?string $first = null, bool $layout = false): array
    {
        $destinations = [];
        $seen = [];
        $section = $first ?? _('Destinations Guide');
        // The lines of the title that comes, the ones that follow each other with nothing between them (a guide)
        $title = [];
        $adjacent = false;
        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            $line = trim($line);
            if (in_array($line, self::MARKERS, true)) {
                // The board keeps its layout as it is; a guide reads in it only what parts two titles, and a column break ends a
                // title that had no destination (the text of the board at the end of a column)
                $layout && ($destinations[$section][] = ['marker' => $line]);
                $adjacent = false;
                $line === '-' && ($title = []);

                continue;
            }
            // Comments and empty lines: nothing, between two lines of a title too
            if ($line === '' || $line[0] === '#' || str_starts_with($line, '//')) {
                continue;
            }
            $parts = array_map('trim', explode('|', rtrim($line, '|')));
            if ($parts[0] === '') {
                continue;
            }
            $name = $parts[0];
            $landing = '';
            $place = '/^\d+(?:\.\d+)?,\d+(?:\.\d+)?(?:,\d+(?:\.\d+)?)?$/';
            if (($parts[3] ?? '') !== '') {
                // The older format, as the board reads it: the name in the 2nd column, the address in the 4th, the landing point in
                // the 5th (a region there, the way the 2.x guide wrote it, is the end of the address)
                $name = ($parts[1] ?? '') !== '' ? $parts[1] : $parts[0];
                $url = $parts[3];
                $fifth = $parts[4] ?? '';
                if (preg_match($place, $fifth) === 1) {
                    $landing = $fifth;
                } elseif ($fifth !== '') {
                    $url .= '/' . $fifth;
                }
            } else {
                $url = $parts[1] ?? '';
                // The place in the region: 128,128,30
                $url !== '' && preg_match($place, $parts[2] ?? '') === 1 && ($landing = $parts[2]);
            }
            $url = Destination::withHeight((string) preg_replace('#^https?://#i', '', $url));
            // A point without a height, or at zero, lands a little above the sea
            $landing = Destination::position($landing);
            $landing === '' || $layout || ($url .= '/' . str_replace(',', '/', $landing));
            // An address listed already, as the board leaves it out
            if ($url !== '' && isset($seen[strtolower($url)])) {
                continue;
            }
            $url === '' || ($seen[strtolower($url)] = true);
            $layout || ($landing = '');
            if ($url === '' && $layout) {
                // The board keeps each title as it is, the ones without a destination too
                $section = $name;
                $destinations[$section] ??= [];
            } elseif ($url === '') {
                // A title, or the next line of the one before; a title before another one, without a destination, is left out
                $title = $adjacent ? [...$title, $name] : [$name];
                $adjacent = true;
            } else {
                if ($title !== []) {
                    $section = implode(' ', $title);
                    $title = [];
                }
                $adjacent = false;
                $destinations[$section][] = ['name' => $name, 'url' => $url] + ($landing !== '' ? ['landing' => $landing] : []);
            }
        }

        return $destinations;
    }
}
