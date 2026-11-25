<?php

declare(strict_types=1);

namespace W4OS\Engine\Services;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Cache;
use W4OS\Engine\Hypergrid\Destination;
use W4OS\Engine\Hypergrid\Gatekeeper;
use W4OS\Engine\Models\Asset;
use W4OS\Engine\Network\GridInfoCache;
use W4OS\Engine\Runtime\ClockInterface;
use W4OS\Engine\Text\Uuid;

/**
 * The pictures of the hypergrid destinations of a guide that the search has none of, asked to their own grid: its gatekeeper gives
 * the simulator of the region (link_region, get_region), the land service of the simulator the parcel at the landing point and its
 * picture (`land_data`, what a viewer asks for the place of a landmark of another grid), and the asset service of the grid the
 * picture itself, kept where the pages of the grid find it
 * (WebAssets). A task of the scheduler asks (refresh()), a guide only reads what was kept (lookup()): a viewer never waits for
 * another grid. A picture is asked again after a day (CHECK), what was not found after a few hours (RETRY), and both are kept a
 * month (KEEP): a grid that does not answer for a while keeps the picture it gave. The grids are asked their info too (GridInfoCache),
 * once a day, so that the cards can say the names they give themselves. A destination that is a grid alone (no region) gets the region
 * its gatekeeper lands on (link_region without a name, then get_region), kept the same way: its link names it (Grid::place(), which
 * asks it itself, within a second or two, when it is not known yet: resolveLanding()).
 *
 *     (new RemotePictures($cache, $http, $config, $login))->refresh($grid->guide());
 *     RemotePictures::lookup($cache, 'far.example.net:8002/Far/10/20/30', 'Far beach');   // '<uuid>'
 */
final class RemotePictures
{
    /** Seconds before a picture found is asked again. */
    public const CHECK = 86400;

    /** Seconds before a picture not found is asked again. */
    public const RETRY = 21600;

    /** Seconds a picture and what was asked are kept, the grid answering or not. */
    public const KEEP = 2_592_000;

    /**
     * @param Cache $cache where what was asked is kept, the pictures too (the cache of the grid, which every process of the grid
     *        shares: the task runs as the web server, the pages as the account of the instances)
     * @param \Closure(string):?string $http the body of an address, null when it does not answer
     * @param string $login the login address of the grid: its own places are the search's
     * @param ?GridInfoCache $grids where what the grids of the destinations say of themselves is kept (their names, for the cards):
     *        each is asked once a day, here
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly \Closure $http,
        private readonly GridConfig $config,
        private readonly string $login = '',
        private readonly ?ClockInterface $clock = null,
        private readonly ?GridInfoCache $grids = null,
    ) {}

    /** @var array<string,string> missed(): the destinations asked that gave no picture, with the step that gave nothing */
    private array $missed = [];

    /** @var string the step of ask() that gave nothing */
    private string $step = '';

    /**
     * The destinations of the last refresh() that gave no picture, each with the step that gave nothing.
     *
     * @return array<string,string> name => the step (the gatekeeper gives no region, the simulator does not answer...)
     */
    public function missed(): array
    {
        return $this->missed;
    }

    /**
     * The picture a page can show of a destination: the one given for a place of this grid (its own assets have it; a region named
     * alone is one), for a place
     * of another grid only when its picture is kept here (refresh() asked its grid); null otherwise, the image of its title then.
     */
    public static function shown(Cache $cache, string $login, string $url, ?string $picture): ?string
    {
        $at = Destination::parse($url);
        // A region named alone is one of the grid
        if ($picture === null || ($at['host'] ?? '') === '' || self::isLocal(Destination::loginParts($login), $at)) {
            return $picture;
        }

        return self::image($cache, $picture) !== null ? $picture : null;
    }

    /**
     * Whether a destination is on the grid of a login address (its own places are the search's).
     *
     * @param ?array<string,mixed> $own Destination::loginParts()
     * @param array<string,mixed> $at Destination::parse()
     */
    private static function isLocal(?array $own, array $at): bool
    {
        return $own !== null && strtolower((string) $own['host']) === strtolower((string) ($at['host'] ?? '')) && (int) ($own['port'] ?? 80) === (int) ($at['port'] ?? 0);
    }

    /** The key of what is kept of a destination: one for every spelling of its address (host:port:Region, host:port/Region, hop://). */
    public static function key(string $url, string $name): string
    {
        $uri = Destination::uri($url);

        return self::rawKey($uri !== null && $uri !== '' ? $uri : $url, $name);
    }

    /** The key of an address as it is written, the one kept before the keys had one form. */
    private static function rawKey(string $url, string $name): string
    {
        return 'guide.picture.' . md5(strtolower(trim($url)) . '|' . strtolower(trim($name)));
    }

    /** Keep the picture another grid gave, for the pages of the grid (WebAssets), as long as what was asked. */
    public static function keep(Cache $cache, string $uuid, string $data, int $type, string $name): void
    {
        $cache->set('guide.image.' . strtolower($uuid), ['data' => base64_encode($data), 'type' => $type, 'name' => $name], self::KEEP);
    }

    /**
     * The picture another grid gave, as it was kept: its data, its type and its name; null when it is not kept.
     *
     * @return ?array{data:string,type:int,name:string}
     */
    public static function image(Cache $cache, string $uuid): ?array
    {
        $kept = $cache->get('guide.image.' . strtolower($uuid));
        $data = is_array($kept) ? base64_decode((string) ($kept['data'] ?? ''), true) : false;

        return $data === false || $data === '' ? null : ['data' => $data, 'type' => (int) ($kept['type'] ?? 0), 'name' => (string) ($kept['name'] ?? '')];
    }

    /**
     * The region a grid alone lands on (`host:port`), as refresh() kept it from its gatekeeper; null when it is not known. Asks
     * nothing.
     */
    public static function landing(Cache $cache, string $grid): ?string
    {
        $kept = $cache->get(self::landingKey($grid));

        return is_array($kept) && is_string($kept['region'] ?? null) && $kept['region'] !== '' ? $kept['region'] : null;
    }

    /** Seconds a page waits for the gatekeeper of a grid alone (resolveLanding()). */
    public const PAGE_TIMEOUT = 2;

    /** Seconds before the region of a grid alone that was not found is asked again: a gatekeeper slow once is asked soon. */
    public const LANDING_RETRY = 1800;

    /**
     * The region a grid alone lands on, asked to its gatekeeper when it is time to (never asked, after a day when it answered, after
     * half an hour when it did not: LANDING_RETRY) within $timeout seconds, and kept; the one kept when it does not answer, null when none is.
     */
    public static function resolveLanding(Cache $cache, string $grid, int $now, int $timeout = Gatekeeper::TIMEOUT): ?string
    {
        $key = self::landingKey($grid);
        $kept = $cache->get($key);
        $region = is_array($kept) && is_string($kept['region'] ?? null) && $kept['region'] !== '' ? $kept['region'] : null;
        $due = is_array($kept) ? (int) ($kept['checked'] ?? 0) + ($region !== null && !($kept['failed'] ?? false) ? self::CHECK : self::LANDING_RETRY) : 0;
        if ($due > $now) {
            return $region;
        }
        $before = Gatekeeper::$timeout;
        Gatekeeper::$timeout = min($before, $timeout);
        try {
            $asked = Gatekeeper::region($grid);
        } finally {
            Gatekeeper::$timeout = $before;
        }
        $name = is_array($asked) ? trim((string) ($asked['region_name'] ?? '')) : '';
        $region = $name !== '' ? $name : $region;
        $cache->set($key, ['region' => $region, 'checked' => $now, 'failed' => $name === ''], self::KEEP);

        return $region;
    }

    /** The key of what is kept of the region a grid alone lands on. */
    private static function landingKey(string $grid): string
    {
        return 'guide.landing.' . strtolower(trim($grid, " /"));
    }

    /** The picture of a hypergrid destination, as it was kept, null when none was found. Asks nothing. */
    public static function lookup(Cache $cache, string $url, string $name = ''): ?string
    {
        // Else what was kept by the address as it is written, before the keys had one form (kept KEEP seconds at most)
        $kept = $cache->get(self::key($url, $name)) ?? $cache->get(self::rawKey($url, $name));

        return is_array($kept) && is_string($kept['picture'] ?? null) ? $kept['picture'] : null;
    }

    /**
     * Ask the grids of the destinations that have no picture, when it is time to.
     *
     * @param array<string,array{picture:?string,destinations:list<array{name:string,url:string,picture:?string}>}> $guide
     *        DestinationPictures::of()
     * @return int how many pictures were found in this run
     */
    public function refresh(array $guide): int
    {
        Gatekeeper::forget();
        $this->missed = [];
        $own = Destination::loginParts($this->login);
        $found = 0;
        foreach ($guide as $section) {
            foreach ($section['destinations'] as $destination) {
                $at = Destination::parse($destination['url']);
                $local = self::isLocal($own, $at);
                // What the grid says of itself (its name, for the cards), once a day
                $local || ($at['host'] ?? '') === '' || $this->grids?->login((string) $at['gatekeeper'], true);
                // A grid alone: the region its gatekeeper lands on, so that its link names one
                if (!$local && ($at['host'] ?? '') !== '' && ($at['region'] ?? '') === '') {
                    self::resolveLanding($this->cache, (string) $at['region_uri'], $this->now());
                    continue;
                }
                // A picture of another grid is asked until it is kept here: the one its search names, this host has not
                $given = $destination['picture'] ?? null;
                if (($given !== null && self::image($this->cache, $given) !== null) || $local || ($at['region'] ?? '') === '' || ($at['host'] ?? '') === '') {
                    continue;
                }
                $key = self::key($destination['url'], $destination['name']);
                $kept = $this->cache->get($key);
                $picture = is_array($kept) && is_string($kept['picture'] ?? null) ? $kept['picture'] : null;
                // Never asked, or found and its picture not kept any more: now; else after a day when it was found, after a few
                // hours when it was not
                $due = is_array($kept) && ($picture === null || self::image($this->cache, $picture) !== null)
                    ? (int) ($kept['checked'] ?? 0) + ($picture !== null && !($kept['failed'] ?? false) ? self::CHECK : self::RETRY)
                    : 0;
                if ($due > $this->now()) {
                    continue;
                }
                $asked = $this->ask($at);
                $asked === null && ($this->missed[$destination['name']] = $this->step);
                // A grid that does not answer keeps the picture it gave
                $failed = $asked === null;
                $asked ??= $picture;
                $asked !== null && $found++;
                $this->cache->set($key, ['picture' => $asked, 'checked' => $this->now(), 'failed' => $failed], self::KEEP);
            }
        }

        return $found;
    }

    /**
     * The picture of a destination, asked to its grid and kept, null when its grid or its simulator does not answer, or it has none.
     *
     * @param array<string,mixed> $at Destination::parse()
     */
    private function ask(array $at): ?string
    {
        $region = Gatekeeper::region((string) $at['region_uri']);
        $server = is_array($region) ? (string) ($region['server_uri'] ?? '') : '';
        if ($server === '') {
            $this->step = 'the gatekeeper gives no region';

            return null;
        }
        // The land of the simulator at the point, as a viewer asks it the place of a landmark (the snapshot a simulator sends a
        // search is not asked: it is sent on conditions, to a search that may not be ours, and does not hold the picture itself)
        [$picture, $owner, $answered] = $this->landPicture($server, $region, (string) ($at['pos'] ?? ''));
        if ($picture === null) {
            $this->step = $answered ? 'the parcel has no picture' : 'the simulator does not answer (land_data)';

            return null;
        }
        // The picture kept, for a while again; else the asset service of its grid gives it
        $kept = self::image($this->cache, $picture);
        if ($kept !== null) {
            self::keep($this->cache, $picture, $kept['data'], $kept['type'], $kept['name']);

            return $picture;
        }
        $gatekeeper = (string) $at['gatekeeper'];
        $asset = Asset::fromService($this->config, $gatekeeper, $picture, $this->http);
        // A grid whose gatekeeper serves no asset (OSGrid): the asset service its user agents give for one of its users, the owner
        // of the parcel, as a simulator asks it for a visitor of that grid (get_server_urls, SRV_AssetServerURI)
        if ($asset === null && $owner !== null && ($service = $this->assetService($gatekeeper, $owner)) !== null && $service !== $gatekeeper) {
            $asset = Asset::fromService($this->config, $service, $picture, $this->http);
        }
        $data = $asset?->data();
        if ($asset === null || $data === null) {
            $this->step = 'the asset service does not give the picture';

            return null;
        }
        self::keep($this->cache, $picture, $data, $asset->typeNumber(), $asset->name());

        return $picture;
    }

    /**
     * The asset service of a grid as its user agents give it for one of its users, null when they do not answer: the address of
     * the user agents its grid info says (`uas`), else its gatekeeper.
     */
    private function assetService(string $gatekeeper, string $user): ?string
    {
        $uas = trim((string) ($this->grids?->saved($gatekeeper)['uas'] ?? ''));
        $urls = Gatekeeper::request($uas !== '' ? $uas : $gatekeeper, 'get_server_urls', ['userID' => $user]);
        $service = is_array($urls) ? trim((string) ($urls['SRV_AssetServerURI'] ?? '')) : '';

        return $service === '' ? null : rtrim($service, '/');
    }

    /**
     * The picture of the parcel at a point of a region, from the land service of its simulator (`land_data` of OpenSimulator, which
     * the simulators of the grids answer for the place of a landmark of another grid): the region by its handle on its own grid
     * (its corner in meters, `x << 32 | y`, as get_region gives it), the point the one of the destination, else the middle. Null
     * when the simulator does not answer or the parcel has no picture; with the owner of the parcel, a user of that grid.
     *
     * @param array<string,mixed> $region Gatekeeper::region()
     * @return array{0:?string,1:?string,2:bool} the picture, the owner, whether the simulator answered
     */
    private function landPicture(string $server, array $region, string $pos): array
    {
        if (!is_numeric($region['x'] ?? null) || !is_numeric($region['y'] ?? null)) {
            return [null, null, false];
        }
        $handle = ((int) $region['x'] << 32) | (int) $region['y'];
        $point = array_map(static fn($v): string => (string) (int) round((float) $v), array_slice(explode('/', $pos), 0, 2));
        [$x, $y] = count($point) === 2 ? $point : ['128', '128'];
        $land = Gatekeeper::request($server, 'land_data', ['region_handle' => (string) $handle, 'x' => $x, 'y' => $y]);
        $picture = strtolower(trim((string) (is_array($land) ? $land['SnapshotID'] ?? '' : '')));
        $owner = strtolower(trim((string) (is_array($land) ? $land['OwnerID'] ?? '' : '')));

        return [
            Uuid::isValid($picture) && !in_array($picture, Uuid::NULL_KEYS, true) ? $picture : null,
            Uuid::isValid($owner) && !in_array($owner, Uuid::NULL_KEYS, true) ? $owner : null,
            is_array($land),
        ];
    }

    private function now(): int
    {
        return (int) ($this->clock?->time() ?? time());
    }
}
