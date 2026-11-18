<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Cache;
use OpenSim\Hypergrid\Destination;
use OpenSim\Hypergrid\Gatekeeper;
use OpenSim\Models\Asset;
use OpenSim\Network\GridInfoCache;
use OpenSim\Runtime\ClockInterface;
use OpenSim\Support\FileCache;
use OpenSim\Text\Uuid;

/**
 * The pictures of the hypergrid destinations of a guide that the search has none of, asked to their own grid: its gatekeeper gives
 * the simulator of the region (link_region, get_region), the land service of the simulator the parcel at the landing point and its
 * picture (`land_data`, what a viewer asks for the place of a landmark of another grid), else the snapshot of the simulator its
 * parcels (DataSnapshot, the parcels shown in search: by the name of the destination, else its landing point, else the only
 * parcel of the region), and the asset service of the grid the picture itself, kept where the pages of the grid find it
 * (WebAssets). A task of the scheduler asks (refresh()), a guide only reads what was kept (lookup()): a viewer never waits for
 * another grid. A picture is asked again after a day (CHECK), what was not found after a few hours (RETRY), and both are kept a
 * month (KEEP): a grid that does not answer for a while keeps the picture it gave. The grids are asked their info too (GridInfoCache),
 * once a day, so that the cards can say the names they give themselves.
 *
 *     (new RemotePictures($cache, $images, $http, $config, $login))->refresh($grid->guide());
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

    /** @var array<string,?string> the snapshots of the simulators, by their address, read once a run */
    private array $snapshots = [];

    /**
     * @param Cache $cache where what was asked is kept (the cache of the grid)
     * @param FileCache $images where the pictures are kept for the pages of the grid (WebAssets::remote())
     * @param \Closure(string):?string $http the body of an address, null when it does not answer
     * @param string $login the login address of the grid: its own places are the search's
     * @param ?GridInfoCache $grids where what the grids of the destinations say of themselves is kept (their names, for the cards):
     *        each is asked once a day, here
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly FileCache $images,
        private readonly \Closure $http,
        private readonly GridConfig $config,
        private readonly string $login = '',
        private readonly ?ClockInterface $clock = null,
        private readonly ?GridInfoCache $grids = null,
    ) {}

    /** The key of what is kept of a destination. */
    public static function key(string $url, string $name): string
    {
        return 'guide.picture.' . md5(strtolower(trim($url)) . '|' . strtolower(trim($name)));
    }

    /** The picture of a hypergrid destination, as it was kept, null when none was found. Asks nothing. */
    public static function lookup(Cache $cache, string $url, string $name = ''): ?string
    {
        $kept = $cache->get(self::key($url, $name));

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
        $this->snapshots = [];
        $own = Destination::loginParts($this->login);
        $found = 0;
        foreach ($guide as $section) {
            foreach ($section['destinations'] as $destination) {
                $at = Destination::parse($destination['url']);
                $local = $own !== null && strtolower((string) $own['host']) === strtolower((string) ($at['host'] ?? '')) && (int) ($own['port'] ?? 80) === (int) ($at['port'] ?? 0);
                // What the grid says of itself (its name, for the cards), once a day
                $local || ($at['host'] ?? '') === '' || $this->grids?->login((string) $at['gatekeeper'], true);
                if (($destination['picture'] ?? null) !== null || $local || ($at['region'] ?? '') === '' || ($at['host'] ?? '') === '') {
                    continue;
                }
                $key = self::key($destination['url'], $destination['name']);
                $kept = $this->cache->get($key);
                $picture = is_array($kept) && is_string($kept['picture'] ?? null) ? $kept['picture'] : null;
                // Never asked: now; else after a day when it was found, after a few hours when it was not
                $due = is_array($kept) ? (int) ($kept['checked'] ?? 0) + ($picture !== null && !($kept['failed'] ?? false) ? self::CHECK : self::RETRY) : 0;
                if ($due > $this->now()) {
                    continue;
                }
                $asked = $this->ask($at, $destination['name']);
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
    private function ask(array $at, string $name): ?string
    {
        $region = Gatekeeper::region((string) $at['region_uri']);
        $server = is_array($region) ? (string) ($region['server_uri'] ?? '') : '';
        if ($server === '') {
            return null;
        }
        // The land of the simulator at the point, as a viewer asks it the place of a landmark; else its snapshot
        $picture = $this->landPicture($server, $region, (string) ($at['pos'] ?? ''));
        if ($picture === null) {
            $parcels = $this->parcels($server, (string) $at['region']);
            $picture = strtolower((string) (DestinationPictures::choose($parcels, $name, (string) ($at['pos'] ?? ''))['picture'] ?? ''));
        }
        if (!Uuid::isValid($picture) || in_array($picture, Uuid::NULL_KEYS, true)) {
            return null;
        }
        // The picture kept, for a while again; else the asset service of its grid gives it
        $kept = $this->images->get($picture);
        if ($kept !== null) {
            return $this->images->put($picture, $kept['data'], $kept['meta']) ? $picture : null;
        }
        $asset = Asset::fromService($this->config, (string) $at['gatekeeper'], $picture, $this->http);
        $data = $asset?->data();
        if ($asset === null || $data === null) {
            return null;
        }

        return $this->images->put($picture, $data, ['type' => $asset->typeNumber(), 'name' => $asset->name()]) ? $picture : null;
    }

    /**
     * The picture of the parcel at a point of a region, from the land service of its simulator (`land_data` of OpenSimulator, which
     * the simulators of the grids answer for the place of a landmark of another grid): the region by its handle on its own grid
     * (its corner in meters, `x << 32 | y`, as get_region gives it), the point the one of the destination, else the middle. Null
     * when the simulator does not answer or the parcel has no picture.
     *
     * @param array<string,mixed> $region Gatekeeper::region()
     */
    private function landPicture(string $server, array $region, string $pos): ?string
    {
        if (!is_numeric($region['x'] ?? null) || !is_numeric($region['y'] ?? null)) {
            return null;
        }
        $handle = ((int) $region['x'] << 32) | (int) $region['y'];
        $point = array_map(static fn($v): string => (string) (int) round((float) $v), array_slice(explode('/', $pos), 0, 2));
        [$x, $y] = count($point) === 2 ? $point : ['128', '128'];
        $land = Gatekeeper::request($server, 'land_data', ['region_handle' => (string) $handle, 'x' => $x, 'y' => $y]);
        $picture = strtolower(trim((string) (is_array($land) ? $land['SnapshotID'] ?? '' : '')));

        return Uuid::isValid($picture) && !in_array($picture, Uuid::NULL_KEYS, true) ? $picture : null;
    }

    /**
     * The parcels of a region in the snapshot of its simulator: the ones shown in search.
     *
     * @return list<array{name:string,landing:string,picture:string}>
     */
    private function parcels(string $server, string $region): array
    {
        $url = rtrim($server, '/') . '/?method=collector';
        if (!array_key_exists($url, $this->snapshots)) {
            $xml = ($this->http)($url);
            $this->snapshots[$url] = is_string($xml) && $xml !== '' ? $xml : null;
        }
        $xml = $this->snapshots[$url];
        if ($xml === null) {
            return [];
        }
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }
        $text = static fn(\DOMElement $parent, string $tag): string => trim((string) $parent->getElementsByTagName($tag)->item(0)?->nodeValue);
        foreach ($document->getElementsByTagName('region') as $node) {
            $info = $node->getElementsByTagName('info')->item(0);
            if ($info === null || strcasecmp($text($info, 'name'), $region) !== 0) {
                continue;
            }
            $parcels = [];
            foreach ($node->getElementsByTagName('parcel') as $parcel) {
                $parcels[] = ['name' => $text($parcel, 'name'), 'landing' => $text($parcel, 'location'), 'picture' => $text($parcel, 'image')];
            }

            return $parcels;
        }

        return [];
    }

    private function now(): int
    {
        return (int) ($this->clock?->time() ?? time());
    }
}
