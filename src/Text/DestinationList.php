<?php

declare(strict_types=1);

namespace OpenSim\Text;

use OpenSim\Network\Http;

/**
 * The destinations of a guide, written as lines "Name|Region URL" in a file or at a URL; a name alone starts a category. The same
 * lines serve the boards of the scripts (a dash alone is a new column, a pipe alone an empty row: layout hints, not destinations),
 * and the older format that gives the grid and the region in the 4th and 5th columns. A third column of `x,y,z` is the place in the
 * region.
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
     * The lines of a list of destinations: a category by its name alone, then its destinations as "Name|Region URL". What
     * parse() reads back, and what the boards of the scripts read.
     *
     * @param array<string,list<array{name:string,url:string}>> $destinations
     */
    public static function format(array $destinations): string
    {
        $lines = [];
        foreach ($destinations as $category => $places) {
            $lines[] = $category;
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
     * The destinations of a text, by category, in the order of the text.
     *
     * @param ?string $first the title of the destinations that come before any category
     * @param bool $layout for the boards (format()): the place in the region kept apart (`landing`, x,y,z), the layout hints kept
     *        (`marker`); else the place is at the end of the address, the hints left out (a guide)
     * @return array<string,list<array{name:string,url:string,landing?:string}|array{marker:string}>>
     */
    public static function parse(string $text, ?string $first = null, bool $layout = false): array
    {
        $destinations = [];
        $category = $first ?? _('Destinations Guide');
        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            $line = trim($line);
            if ($layout && in_array($line, self::MARKERS, true)) {
                $destinations[$category][] = ['marker' => $line];

                continue;
            }
            // Comments, empty lines, the layout hints of the boards
            if ($line === '' || $line[0] === '#' || str_starts_with($line, '//') || $line === '-') {
                continue;
            }
            $parts = array_map('trim', explode('|', rtrim($line, '|')));
            if ($parts[0] === '') {
                continue;
            }
            $name = $parts[0];
            $landing = '';
            if (($parts[3] ?? '') !== '') {
                // The older format: the grid and the region are the 4th and 5th columns
                $url = $parts[3] . '/' . ($parts[4] ?? '');
            } else {
                $url = $parts[1] ?? '';
                // The place in the region: 128,128,30
                if (
                    $url !== '' &&
                    preg_match('/^\d+(?:\.\d+)?,\d+(?:\.\d+)?(?:,\d+(?:\.\d+)?)?$/', $parts[2] ?? '') === 1
                ) {
                    $layout ? ($landing = $parts[2]) : ($url .= '/' . str_replace(',', '/', $parts[2]));
                }
            }
            if ($url === '') {
                $category = $name;
            } else {
                $destinations[$category][] = ['name' => $name, 'url' => $url] + ($landing !== '' ? ['landing' => $landing] : []);
            }
        }

        return $destinations;
    }
}
