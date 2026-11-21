<?php

namespace W4OS\Engine\Hypergrid;

/**
 * A destination: a region and a position, as people write them (host:port:Region/x/y/z, hop://, secondlife://...), parsed into the parts, written back as the one canonical uri, or as the links each viewer takes.
 */
final class Destination
{
    /**
     * secondlife://Region%20Name/x/y/z, a region of the grid the viewer is on: in a viewer, its login screen too (where it sets
     * the landing of the login), it shows the place before going (its picture, its description, teleport or show on the map).
     * Tried (2026-11): it works everywhere in a viewer, the only form tried that works on its login screen.
     */
    public const LOCAL = 1;
    /**
     * secondlife://yourgrid.org:8002:Region%20Name/x/y/z (original HG format).
     *
     * @deprecated failed in every place the viewers were tried (2026-11), hop:// won: still given for the callers of 2.x
     *             (TPLINK_HG); before 3.0, dropped or answered with hop:// or secondlife:// (ROADMAP). Ask AUTO.
     */
    public const HG = 2;
    /**
     * secondlife://http|!!yourgrid.org|8002%20Region/x/y/z (v3 HG format, Singularity).
     *
     * @deprecated failed in every place the viewers were tried (2026-11), hop:// won: still given for the callers of 2.x
     *             (TPLINK_V3HG); before 3.0, dropped or answered with hop:// or secondlife:// (ROADMAP). Ask AUTO.
     */
    public const V3HG = 4;
    /**
     * hop://yourgrid.org:8002/Region%20Name/x/y/z, the region after a slash, always: the form the viewer writes itself (Firestorm,
     * in the chat after a teleport: LLSLURL::getSLURLString() of fsslurl.cpp escapes the region after the grid, and its tests read
     * it back), the one of the OpenSimulator wiki (`[hop://]<hg-host>:<hg-port>[/<region-name>][/x/y]`, Hypergrid), and the only
     * one the URL handler of a system passes on (host:port/something). The link out of a viewer. Tried (2026-11): it works from a
     * web browser and the chat of a viewer; the login screen of a viewer takes no hop:// link.
     */
    public const HOP = 8;
    /** yourgrid.org:8002:Region/x/y/z */
    public const TXT = 16;
    /**
     * secondlife:///app/teleport/yourgrid.org:8002:Region%20Name/x/y/z, the region after a colon: the teleport of the viewer to
     * another grid, from its own browser and its login screen too (there it asks, then does nothing: no harm). Tried (2026-11):
     * it works in the browser of a viewer, for another grid.
     */
    public const APPTP = 32;
    /**
     * secondlife:///app/worldmap/yourgrid.org:8002:Region%20Name/x/y/z, the map of the viewer at the place, as llMapDestination()
     * opens it. Firestorm takes `app/worldmap/<region>/<x>/<y>/<z>` (LLWorldMapHandler of llfloaterworldmap.cpp) and asks the
     * simulator for the region by its name as it is, as the search box of the map does (the grid of the link is not read); the
     * simulator links a name with a grid before a colon as a region of that grid (MapSearchModule of OpenSimulator, the names with
     * `:`), so the region of another grid is named `host:port:Region Name`, the one of the grid itself by its name alone, a grid
     * alone by `host:port` (its default region). The colon form exists here, in APPTP and in LSL, never in a hop:// link. Not
     * tried yet; it does nothing before the login (the handler waits for the session, FIRE-17927).
     */
    public const MAP = 64;
    /** all formats */
    public const ALL = 255;
    /** output as array */
    public const AS_ARRAY = 256;
    /**
     * yourgrid.org:8002:Region Name/x/y/z, the region after a colon: the name a script gives llMapDestination() (a region after a
     * slash is read as its x), the one of the teleport boards. Not a link: the colon form exists only here and in APPTP, never in
     * a hop:// link.
     */
    public const LSL = 1024;
    /**
     * secondlife://yourgrid.org:8002/secondlife/Region%20Name/x/y/z, the grid and then its region: a location of a grid, read by
     * the viewer as `secondlife://<Grid>/secondlife/<region>/<x>/<y>/<z>` (the parser of the links of Firestorm, fsslurl.cpp),
     * for the grid it is on and for another one alike. Tried (2026-11): it works in a viewer, for the grid itself and for another
     * grid, and on the login screen of a viewer, where it sets the landing of the login (dispatchRegion() of Firestorm). A region
     * without its grid is of the grid the viewer is on (`secondlife:///secondlife/Region`).
     */
    public const GRID = 2048;
    /**
     * The label of a destination, as a card shows it under its title (title()): text, not a link, its spaces as they are. On the
     * grid itself its region and its point (`Region Name/x/y/z`, the height a little above the sea when it is zero); on another
     * grid its grid (`host:port`), the region being its title. The grid itself is the one of the `login` of the context (label()).
     */
    public const LABEL = 4096;
    /**
     * The link for where it is shown (the `context` of format(), AUTO_LINKS), so that no site tells it again: in a viewer (told by
     * the user agent of the request, isViewer(); any page, its login screen too), GRID for every destination, of the grid itself
     * or of another one; HOP out of a viewer. Grid::link() gives it with the login of the grid.
     */
    public const AUTO = 512;

    /**
     * How a space of the name of a region is written in a hop:// link and a secondlife:// link of a grid: %20, the one the
     * viewers take everywhere (a `+` is not read as a space there).
     */
    public const SPACE = '%20';

    /**
     * The link AUTO gives, by where it is shown (a viewer, any page of it and its login screen alike, or a web browser) and whether
     * the destination is of the grid itself (`local`) or of another one (`other`); 0 gives none, the destination being shown as
     * text.
     */
    public const AUTO_LINKS = [
        'viewer' => ['local' => self::GRID, 'other' => self::GRID],
        'web' => ['local' => self::HOP, 'other' => self::HOP],
    ];

    /** The user agents of the viewers (their own browser, the login screen). */
    public const VIEWER_PATTERN = '/SecondLife|OpenSim|Firestorm|Singularity|Kokua|Alchemy|Catznip/i';

    /** The port of a grid an address does not say (the one of Robust). */
    public const PORT = 8002;

    /**
     * The height a point is landed at when it gives none or zero: a little above the sea, not in the water or under the ground. Zero
     * is the absence of a height; any other height is kept, meant.
     */
    public const HEIGHT = 21;

    /** The format asked when none is: the environment says (TPLINK_DEFAULT), else hop://. */
    public static function defaultFormat(): int
    {
        return (int) (getenv('self::defaultFormat()') ?: self::HOP);
    }

    /**
     * Sanitize a destination URI or URL
     *
     * @param  string  $url				url or uri or tp link (secondlife:// url, hop:// url, region name...)
     * @param  string  $default_gatekeeper	default login uri to add to urls sithout host:port
     * @param  boolean $outputArray		output as array
     * @return string       (default)                   $host:$port $region/$pos
     *           or array                                           array($host, $port, $region, $pos)
     */
    public static function parse($url, $default_gatekeeper = null): array
    {
        $e_scheme = '(.*://)';
        $e_host = '(([A-Za-z0-9_-]+)(\.[A-Za-z0-9\._-]+)+)';
        $e_short_host = '(([A-Za-z0-9_-]+)(\.[A-Za-z0-9\._-]+)*)?';
        $e_port = '[:\|]([0-9]+)';
        $e_float = '(\d+(\.\d+)?)';

        // A host and its region without a port (login.osgrid.org:Region) is the region at the default port of a grid, as 2.x read it
        $normalized_url = preg_replace(
            ['/(\+|%20|_)/'],
            [' '],
            (string) preg_replace_callback(
                [
                    "#^{$e_scheme}?{$e_host}{$e_port}[:\+_ ]#",
                    "#^{$e_scheme}?{$e_host}()[:\+_ ](?=[^0-9])#",
                    "#^{$e_scheme}?{$e_short_host}{$e_port}[:\+_ ]#",
                ],
                static fn(array $m): string => $m[1] . $m[2] . ':' . (($m[5] ?? '') !== '' ? $m[5] : self::PORT) . '/',
                urldecode(trim($url)),
            ),
        );

        // A host is the start of an address, before its port or its region: a point in decimals (21.75) is not one
        if (!preg_match("#^$e_scheme#", $url) && preg_match("#^\\s*(?:$e_host|$e_short_host{$e_port})(?=[:/ +_|]|$)#", $url)) {
            $parsed = parse_url('http://' . $normalized_url) ?: [];
            unset($parsed['scheme']);
        } else {
            $parsed = parse_url($normalized_url) ?: [];
        }

        if (!empty($parsed['host']) || !empty($default_gatekeeper)) {
            $parsed['host'] ??= empty($default_gatekeeper)
                ? null
                : parse_url($default_gatekeeper, PHP_URL_HOST) ?? null;
            $parsed['host'] = strtolower(trim($parsed['host']));
            $parsed['port'] ??= parse_url($default_gatekeeper ?? '', PHP_URL_PORT) ?? 80;
            $parsed['gatekeeper'] = 'http://' . $parsed['host'] . ':' . $parsed['port'];
        }

        // A part that is "0" is a part (a position at the edge of a region), only an empty one is not
        $split_path = array_values(array_filter(explode('/', $parsed['path'] ?? ''), fn($part) => $part !== ''));

        if (count($split_path) > 0) {
            if (!preg_match("/^$e_float$/", $split_path[0])) {
                $parsed['region'] = array_shift($split_path);
            }

            // Sanitize remaining items for position
            $split_path = array_map(
                'floatval',
                array_slice(array_filter($split_path, fn($item) => is_numeric($item)), 0, 3),
            );
            if (count($split_path) >= 2) {
                $split_path[2] = self::height($split_path[2] ?? null);
                $parsed['pos'] = implode('/', $split_path);
            }
        }

        if (!empty($parsed['host'])) {
            $parsed['region_uri'] =
                $parsed['host'] . (empty($parsed['host']) || empty($parsed['port']) ? '' : ':' . $parsed['port']);
        }
        $parsed['region_uri'] = trim(
            ($parsed['region_uri'] ?? '') . (empty($parsed['region']) ? '' : '/') . ($parsed['region'] ?? ''),
            ":/ \n\r\t\v\x00",
        );
        $parsed['dest_uri'] = $parsed['region_uri'] . (empty($parsed['pos']) ? '' : "/$parsed[pos]");

        // Every key is always there, for who extracts them; `key` identifies a region in a cache
        $parsed += ['host' => '', 'port' => '', 'region' => '', 'pos' => '', 'gatekeeper' => ''];
        $parsed['key'] = strtolower("$parsed[host]:$parsed[port]/$parsed[region]");

        return $parsed;
    }

    /**
     * Sanitize a destination URI or URL
     *
     * @param  string  $url				url or uri or tp link (secondlife:// url, hop:// url, region name...)
     * @param  string  $default_gatekeeper	default login uri to add to urls sithout host:port
     * @return string					the canonical uri, host:port/Region Name/x/y/z (the name as it is); host:port:Region is read too
     */
    public static function uri($url, $default_gatekeeper = null): string
    {
        $parsed = self::parse($url, $default_gatekeeper);
        return $parsed['dest_uri'] ?? '';
    }

    /**
     * Sanitize a destination URI or URL
     *
     * @param  string  $url                 url or uri or tp link (secondlife:// url, hop:// url, region name...)
     * @param  string  $default_gatekeeper  default login uri to add to urls without host:port, the one of the engine without it
     * @param  boolean $outputArray         output as array
     * @return string|array                 $host:$port $region/$pos, or the array of its parts (host, port, region, pos,
     *                                      gatekeeper, region_uri, dest_uri, key)
     */
    public static function sanitize($url, $default_gatekeeper = null, $outputArray = false): string|array
    {
        if ($outputArray) {
            return self::parse($url, $default_gatekeeper);
        }

        return self::uri($url, $default_gatekeeper);
    }

    /**
     * The login address of a grid as its people write it (`play.example.org:8002`, `http://play.example.org:3405/`) in its parts. The
     * port is the one the address gives and nothing else: each grid chooses its own, the engine does not. An address without one has
     * the port of its scheme, as every URL does (80 for http, 443 for https), which is for the one who connects to say.
     *
     * @return ?array{scheme:string,host:string,port:?int} null when it is no address
     */
    public static function loginParts(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }
        // A teleport link of the grid gives its login too: hop://host:port/..., secondlife://host:port/...
        $address = (string) preg_replace('#^(?:hop|secondlife)://#i', 'http://', $address);
        $parts = parse_url(preg_match('#^https?://#i', $address) === 1 ? $address : 'http://' . $address);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        return [
            'scheme' => strtolower($parts['scheme'] ?? 'http'),
            'host' => $parts['host'],
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
        ];
    }

    /** The classes of the clients a link is made for: a viewer (its own browser, its login screen), a web browser. */
    public const CLIENTS = ['viewer', 'browser'];

    /**
     * The class of a client, `viewer` or `browser` (CLIENTS), by its user agent (isViewer()): what a site keeps a page or a block
     * for, rather than for each user agent.
     *
     * @param ?string $agent the user agent, the one of the request served when null
     */
    public static function client(?string $agent): string
    {
        return self::isViewer($agent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) ? 'viewer' : 'browser';
    }

    /**
     * The label of a destination, as a card shows it (LABEL).
     *
     * @param ?string $login the login address of the grid itself: its places are labelled by their region and their point
     */
    public static function label(string $destination, ?string $login = null): string
    {
        $login = trim((string) $login);
        $parsed = self::parse($destination, $login !== '' ? $login : null);
        if (self::isLocal($parsed, $login)) {
            return trim($parsed['region'] . ($parsed['pos'] === '' ? '' : '/' . $parsed['pos']), '/');
        }

        return "$parsed[host]:$parsed[port]";
    }

    /**
     * The title of a destination, as a card shows it above its label: the name the list gives it on the grid itself, its region
     * on another grid (the name of a list is often the one of the grid there), else that name for a grid without a region.
     *
     * @param ?string $login the login address of the grid itself
     */
    public static function title(string $destination, string $name, ?string $login = null): string
    {
        $login = trim((string) $login);
        $parsed = self::parse($destination, $login !== '' ? $login : null);

        return self::isLocal($parsed, $login) || $parsed['region'] === '' ? $name : $parsed['region'];
    }

    /** Whether a destination is on the grid of a login: it names no grid, or the one of the login. */
    public static function isHere(string $destination, ?string $login): bool
    {
        $login = trim((string) $login);

        return self::isLocal(self::parse($destination, $login !== '' ? $login : null), $login);
    }

    /**
     * Whether a destination is of the grid itself: no grid of its own, or the one of the login.
     *
     * @param array<string,mixed> $parsed parse()
     */
    private static function isLocal(array $parsed, string $login): bool
    {
        $own = self::loginParts($login);

        return ($parsed['host'] ?? '') === '' ||
            ($own !== null && strtolower((string) $own['host']) === $parsed['host'] && (int) ($own['port'] ?? 80) === (int) $parsed['port']);
    }

    /** Whether a user agent is the one of a viewer (its own browser, its login screen). */
    public static function isViewer(string $userAgent): bool
    {
        return preg_match(self::VIEWER_PATTERN, $userAgent) === 1;
    }

    /**
     * The link of a destination for where it is shown (AUTO).
     *
     * @param array{agent?:?string,client?:string,login?:?string} $context
     */
    private static function auto(string $destination, int $array, string $sep, array $context): string|array
    {
        $login = trim((string) ($context['login'] ?? ''));
        $parsed = self::parse($destination, $login !== '' ? $login : null);
        $local = self::isLocal($parsed, $login);
        $client = in_array($context['client'] ?? null, self::CLIENTS, true)
            ? $context['client']
            : self::client(array_key_exists('agent', $context) ? (string) $context['agent'] : null);
        $format = self::AUTO_LINKS[$client === 'viewer' ? 'viewer' : 'web'][$local ? 'local' : 'other'];
        if ($format === 0) {
            return $array ? [] : '';
        }
        $uri = $format === self::LOCAL ? $parsed['region'] . ($parsed['pos'] === '' ? '' : '/' . $parsed['pos']) : $parsed['dest_uri'];

        return self::format($uri, $format | $array, $sep);
    }

    /** The height a point is landed at: the one it gives, HEIGHT when it gives none or zero. */
    public static function height(mixed $z): float
    {
        $z = is_numeric($z) ? (float) $z : 0.0;

        return $z == 0.0 ? (float) self::HEIGHT : $z;
    }

    /**
     * A point in a region as it is written (`x/y/z`, `x,y,z`, or `<x, y, z>` as the scripts write a vector, given as `x,y,z`), its
     * height HEIGHT when it gives none or zero; empty stays empty (the landing point of the region).
     */
    public static function position(string $position): string
    {
        $position = trim($position);
        if (preg_match('/^<(.*)>$/', $position, $vector) === 1) {
            $position = (string) preg_replace('/\s+/', '', $vector[1]);
        }
        $parts = preg_split('#[/,]#', trim($position, '/,')) ?: [];
        if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return $position;
        }
        $z = $parts[2] ?? '';
        $parts[2] = is_numeric($z) && (float) $z != 0.0 ? $z : (string) self::HEIGHT;

        return implode(str_contains($position, ',') ? ',' : '/', array_slice($parts, 0, 3));
    }

    /** An address as it is written, the point at its end landed as position() does. */
    public static function withHeight(string $url): string
    {
        return (string) preg_replace_callback(
            '#^(.*[^/\d.][^/]*)((?:/-?\d+(?:\.\d+)?){2,3})/?$#',
            static fn(array $m): string => $m[1] . '/' . self::position(ltrim($m[2], '/')),
            $url,
        );
    }

    /**
     * The login URI of a grid, `host:port`, the form people give to a viewer and a site shows, from any form it is written in
     * (`host:port`, `http://host:port/`, `https://...`, `hop://host:port/...`); null when it is no address. The URL the engine calls
     * is loginUrl().
     */
    public static function loginUri(string $address): ?string
    {
        $parts = self::loginParts($address);

        return $parts === null ? null : $parts['host'] . ($parts['port'] !== null ? ':' . $parts['port'] : '');
    }

    /** The login URL of a grid from the address its people write, `scheme://host[:port]` as it gives them; null when it is no address. */
    public static function loginUrl(string $address): ?string
    {
        $parts = self::loginParts($address);

        return $parts === null
            ? null
            : "{$parts['scheme']}://{$parts['host']}" . ($parts['port'] !== null ? ":{$parts['port']}" : '');
    }

    /**
     * The links that add a grid to the list of grids of a viewer and select it (`app/gridmanager/addgrid` of Firestorm, its login URI
     * `host:port` URL-encoded, `https://` before it for a grid served over https): `hop` the form OpenSimWorld gives, opened by Windows and macOS, the viewer running or not; `secondlife` the one
     * every system hands to the viewer. Null for an address that is not one of a grid.
     *
     * @return array{hop:string,secondlife:string}|null
     */
    public static function addGrid(string $login): ?array
    {
        $parts = self::loginParts($login);
        if ($parts === null) {
            return null;
        }
        // The login URI, the scheme only for a grid served over https: a viewer takes http for one without
        $uri = ($parts['scheme'] === 'https' ? 'https://' : '') . self::loginUri($login);
        $command = 'app/gridmanager/addgrid/' . rawurlencode($uri);

        return ['hop' => "hop:///$command", 'secondlife' => "secondlife:///$command"];
    }

    /**
     * A link with its spaces as pluses, for the formats that take neither `%20` nor `_` (`host:port+Region+Name`), the characters
     * a URL cannot have removed.
     */
    public static function sanitizePlus(string $url): string
    {
        return (string) filter_var(str_replace(' ', '+', trim($url)), FILTER_SANITIZE_URL);
    }

    /**
     * Format destination uri as a valid local or hypergrid link url
     *
     * @param  string  $destination      Destination uri, as "host:port:Region Name" or already formatted URL
     * @param  integer $format  The desired format as binary flags. Several values can be specified with an addition
     *                          e.g. TPLINK_V3HG + TPLINK_APPTP
     *                          TPLINK_LOCAL or 1:   secondlife://Region Name/x/y/z
     *                          TPLINK_HG or 2:      original HG format (obsolete?)
     *                          TPLINK_V3HG or 4:    v3 HG format (Singularity)
     *                          TPLINK_HOP or 8:     hop:// format (FireStorm)
     *                          TPLINK_TXT or 16:    host:port Region Name
     *                          TPLINK_APPTP or 32:  secondlife:///app/teleport link
     *                          TPLINK_MAP or 64:    (map, not implemented)
     * 							128:                 (for future use, not implemented)
     *                          TPLINK or 255:       output all formats
     *                          TPLINK_ARRAY or 256: output all formats as array
     *                          AUTO or 512:         the link for where it is shown (see AUTO), with `$context`
     *
     * The region of a hop:// or secondlife:// link of a grid comes after a colon, its spaces as SPACE
     * (hop://yourgrid.org:8002:Region%20Name/x/y/z): a viewer takes that form in its own browser, not a slash.
     *
     * @param  string  $sep      Separator for multiple formats, default new line
     * @param  array{agent?:?string,client?:string,login?:?string} $context where AUTO is shown: the user agent of the request
     *         (`agent`, the one of the request served when none is given) or the class of the client (`client`, CLIENTS: it wins
     *         over the agent), the login
     *         address of the grid itself (`login`)
     * @return string
     */
    public static function format($destination, $format = null, $sep = "\n", array $context = []): string|array
    {
        if (empty($destination)) {
            return $format & self::AS_ARRAY ? [] : '';
        }
        if ($format !== null && $format & self::AUTO) {
            return self::auto((string) $destination, $format & self::AS_ARRAY, $sep, $context);
        }
        if ($format !== null && $format & self::LABEL) {
            $label = self::label((string) $destination, $context['login'] ?? null);

            return $format & self::AS_ARRAY ? [self::LABEL => $label] : $label;
        }
        // TODO: allow Region, Destination or Event classes
        // if (is_object($destination) && !empty($destination->uri)) {
        // 	$destination = $destination->uri;
        // }
        $format ??= self::defaultFormat();

        $parsed = self::parse($destination);
        extract($parsed);

        $post_split = explode('/', $pos ?? '');
        if (count($post_split) >= 2) {
            $pos_x = $post_split[0];
            $pos_y = $post_split[1];

            // set $pos_sl to $pos only if $pos_x and $pos_y < 256
            $pos_sl = $pos_x < 256 && $pos_y < 256 ? $pos : null;
        }

        $region_urlencode = urlencode($region ?? '');
        $region_percentencode = str_replace('+', '%20', $region_urlencode);

        $links = [];
        if (empty($host) && empty($region)) {
            return $format & self::AS_ARRAY ? [] : '';
        }

        if ($format & self::TXT) {
            $links[self::TXT] = trim(
                (empty($host) ? '' : join(':', [$host, $port])) . ' ' . ($region ?? '') . (empty($pos) ? '' : "/$pos"),
            );
        }
        // A region of this grid, by its name alone; the link of a grid without a region ends at its port
        $local = "secondlife://$region_percentencode" . (empty($pos_sl) ? '' : "/$pos_sl");
        $named = empty($region) ? '' : ':' . str_replace('%20', self::SPACE, $region_percentencode);
        if ($format & self::LOCAL) {
            // Web only, do not use for in-world messages
            $links[self::LOCAL] = $local;
        }
        if ($format & self::HG) {
            // Web only, do not use for in-world messages
            $links[self::HG] = empty($host)
                ? $local
                : "secondlife://$host:$port$named" . (empty($pos_sl) ? '' : "/$pos_sl");
        }
        if ($format & self::V3HG) {
            // Web only, do not use for in-world messages
            $links[self::V3HG] =
                (empty($host) ? $local : "secondlife://http|!!$host|$port" . (empty($region) ? '' : "%20$region_percentencode")) .
                (empty($pos_sl) || empty($host) ? '' : "/$pos_sl");
        }
        if ($format & self::HOP) {
            // Web and in-world, the region after a slash, never a colon. Position (/x/y/z) is
            // - optional for web browser links (viewer applies default)
            // - required for in-world messages
            $links[self::HOP] = empty($gatekeeper)
                ? ''
                : "hop://$host:$port" . (empty($region) ? '' : '/' . str_replace('%20', self::SPACE, $region_percentencode)) . (empty($pos) ? '' : "/$pos");
        }
        if ($format & self::GRID) {
            // In a viewer: secondlife://speculoos.world:8002/secondlife/Grand%20Place/128/128/21
            $links[self::GRID] = empty($region)
                ? ''
                : 'secondlife://' . (empty($host) ? '' : "$host:$port") . '/secondlife/' . str_replace('%20', self::SPACE, $region_percentencode) . (empty($pos) ? '' : "/$pos");
        }
        if ($format & self::LSL) {
            // The name of a region for llMapDestination(): host:port:Region Name, the point apart
            $links[self::LSL] = (empty($host) ? '' : "$host:$port:") . ($region ?? '') . (empty($pos) ? '' : "/$pos");
        }
        if ($format & self::APPTP) {
            // In a viewer: secondlife:///app/teleport/speculoos.world:8002:Grand%20Place/128/128/21
            $tp_region = str_replace('%20', self::SPACE, $region_percentencode);
            $links[self::APPTP] =
                (empty($host)
                    ? "secondlife:///app/teleport/$tp_region/"
                    : "secondlife:///app/teleport/$host:$port:$tp_region/") .
                (!empty($pos_sl) ? "$pos_sl/" : '');
        }
        if ($format & self::MAP) {
            // The map of the viewer: secondlife:///app/worldmap/speculoos.world:8002:Grand%20Place/128/128/21
            $named = implode(':', array_filter([empty($host) ? '' : "$host:$port", str_replace('%20', self::SPACE, $region_percentencode)]));
            $links[self::MAP] = $named === '' ? '' : "secondlife:///app/worldmap/$named" . (empty($pos) || empty($region) ? '' : "/$pos");
        }

        // clean up trailing/leading non-alphanumeric characters
        $links = preg_replace('#^[^[:alnum:]]*|[^[:alnum:]]+$#', '', $links);

        if ($format & self::AS_ARRAY) {
            return $links;
        }
        return join($sep, $links);
    }
}
