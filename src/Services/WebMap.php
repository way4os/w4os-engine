<?php

declare(strict_types=1);

namespace W4OS\Engine\Services;

use W4OS\Engine\Assets\WebAssets;
use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Hypergrid\Destination;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Models\Region;
use W4OS\Engine\Network\Http;
use W4OS\Engine\Provisioning\ResourceRegistry;
use W4OS\Engine\Support\FileCache;

/**
 * The web map of a grid: the tiles Robust's MapImageService makes (`map-<zoom>-<x>-<y>-objects.jpg`, x and y in regions of 256 m,
 * a tile of zoom z covering 2^(z-1) regions on each side), and its regions for a browser.
 *
 * A tile is read from the folder of the tiles when the files of the grid are here, else asked of Robust once and kept: Robust's map
 * handler answers one request at a time, a browser asks dozens. Where the grid has no region the tile is water, Robust never asked.
 */
final class WebMap
{
    /** The zooms Robust makes: 1, one region by tile, to 8, 128 by 128 regions. */
    public const ZOOMS = [1, 8];

    /** How long a tile is kept, here and by the browsers: the tiles change when a region is saved or restarted. */
    public const TTL = 86400;

    /** How long the water given for a tile Robust did not answer is kept by the browsers. */
    public const RETRY = 60;

    /** The water tile of the kit, the colour Robust gives where there is no region (MapImageService, 29,72,96). */
    public const WATER_FILE = 'share/images/map-water.jpg';

    /** The scope of the tiles of a grid: Robust keeps them in a folder by scope, the grid's is the null UUID. */
    private const SCOPE = '00000000-0000-0000-0000-000000000000';

    /** @var list<Region>|false|null the regions of the grid, false when its database cannot be read */
    private array|false|null $regions = null;

    public function __construct(private readonly Grid $grid) {}

    /** Whether the grid has a map: its Robust serves the tiles, or their folder is here. */
    public function has(): bool
    {
        return $this->grid->mapUrl() !== null || $this->folder() !== null;
    }

    /**
     * A tile as a browser is given it, null when there is none: no map, a zoom Robust does not make, a place off the grid.
     *
     * @return ?array{data:string,water:bool,maxAge:int} its JPEG, whether it is the water tile, how long it may be kept
     */
    public function tile(int $z, int $x, int $y): ?array
    {
        if ($z < self::ZOOMS[0] || $z > self::ZOOMS[1] || $x < 0 || $y < 0 || !$this->has()) {
            return null;
        }
        if ($this->isSea($x, $y, 2 ** ($z - 1))) {
            return $this->water(self::TTL);
        }
        $name = "map-$z-$x-$y-objects.jpg";
        $folder = $this->folder();
        if ($folder !== null) {
            // Robust gives water for a tile it has not made yet
            $data = is_file("$folder/$name") ? (string) file_get_contents("$folder/$name") : '';

            return $data !== '' ? ['data' => $data, 'water' => false, 'maxAge' => self::TTL] : $this->water(self::TTL);
        }
        $cache = new FileCache($this->cacheFolder(), self::TTL, $this->grid->environment()->clock);
        $kept = $cache->get($name);
        if ($kept !== null) {
            return ['data' => $kept['data'], 'water' => false, 'maxAge' => self::TTL];
        }
        $data = $this->fetch(rtrim((string) $this->grid->mapUrl(), '/') . "/$name");
        if ($data === null || $data === '') {
            return $this->water(self::RETRY);
        }
        $cache->put($name, $data);

        return ['data' => $data, 'water' => false, 'maxAge' => self::TTL];
    }

    /**
     * The regions of the map, the links to other grids left out: each `uuid`, `name`, `x` and `y` (in regions of 256 m, from the
     * bottom left), `sizeX` and `sizeY` (in meters), `online`, `tpLinks` (by client, as the results of the search give them:
     * `viewer` and `browser`, each by Destination format), and what the world map of the viewers says of it: `type` (its RegionType
     * as the grid wrote it), `estate` (its name) and `owner` (the name of the owner of its estate, else of the region), each null
     * where it is not known here; the `bounds` of the grid in regions (the max excluded), the `center` (the middle of the default
     * region, else of the grid), null for both without a region. Null when the grid has no map.
     *
     * @return ?array{regions:list<array<string,mixed>>,bounds:?array{minX:int,minY:int,maxX:int,maxY:int},center:?array{x:float,y:float}}
     */
    public function regions(): ?array
    {
        if (!$this->has()) {
            return null;
        }
        $regions = $this->known() ?: [];
        [$types, $estates] = $this->described();
        $list = [];
        $bounds = null;
        $default = null;
        foreach ($regions as $region) {
            [$x, $y, $width, $height] = $this->rectangle($region);
            $list[] = [
                'uuid' => $region->uuid(),
                'name' => $region->name(),
                'x' => $x,
                'y' => $y,
                'sizeX' => $region->width(),
                'sizeY' => $region->height(),
                'online' => $region->isOnline(),
                'tpLinks' => array_combine(Destination::CLIENTS, array_map(static fn(string $client): array => $region->tpLinks($client), Destination::CLIENTS)),
                'type' => $types[strtolower((string) $region->uuid())] ?? null,
                'estate' => ($estate = $estates[strtolower((string) $region->uuid())] ?? null)?->name(),
                'owner' => $estate?->ownerName() ?? $region->ownerName(),
            ];
            $bounds = [
                'minX' => min($bounds['minX'] ?? $x, $x),
                'minY' => min($bounds['minY'] ?? $y, $y),
                'maxX' => max($bounds['maxX'] ?? $x + $width, $x + $width),
                'maxY' => max($bounds['maxY'] ?? $y + $height, $y + $height),
            ];
            $default ??= $region->isDefault() ? ['x' => $x + $width / 2, 'y' => $y + $height / 2] : null;
        }
        $center = $default ?? ($bounds === null ? null : ['x' => ($bounds['minX'] + $bounds['maxX']) / 2, 'y' => ($bounds['minY'] + $bounds['maxY']) / 2]);

        return [
            'regions' => $list,
            'bounds' => $bounds,
            'center' => $center === null ? null : ['x' => (float) $center['x'], 'y' => (float) $center['y']],
        ];
    }

    /**
     * The types of the regions as their files say them, and their estates, by UUID: what the files and the databases here know.
     *
     * @return array{0:array<string,string>,1:array<string,\W4OS\Engine\Models\Estate>}
     */
    private function described(): array
    {
        $config = $this->grid->config();
        if ($config === null) {
            return [[], []];
        }
        $types = [];
        foreach (Region::configured($config) as $local) {
            $local->uuid() === null || $local->type() === null || ($types[strtolower($local->uuid())] = $local->type());
        }
        $estates = [];
        try {
            foreach (\W4OS\Engine\Models\Estate::all($config)->items() as $estate) {
                foreach ($estate->regions() as $uuid) {
                    $estates[$uuid] ??= $estate;
                }
            }
        } catch (\Throwable) {
            // No database of estates here: none said
        }

        return [$types, $estates];
    }

    /** Whether no region of the grid is in the square of regions from x,y: false when its regions cannot be read. */
    private function isSea(int $x, int $y, int $span): bool
    {
        $regions = $this->known();
        if ($regions === false) {
            return false;
        }
        foreach ($regions as $region) {
            [$rx, $ry, $width, $height] = $this->rectangle($region);
            if ($rx < $x + $span && $rx + $width > $x && $ry < $y + $span && $ry + $height > $y) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0:int,1:int,2:int,3:int} where a region is and its size, in regions */
    private function rectangle(Region $region): array
    {
        return [$region->x(), $region->y(), (int) ceil($region->width() / Region::SIZE), (int) ceil($region->height() / Region::SIZE)];
    }

    /** @return list<Region>|false the regions of the grid, false when its database cannot be read */
    private function known(): array|false
    {
        if ($this->regions !== null) {
            return $this->regions;
        }
        $config = $this->grid->config();
        try {
            return $this->regions = $config === null ? false : array_values(Region::all($config)->items());
        } catch (\Throwable) {
            return $this->regions = false;
        }
    }

    /**
     * The folder of the tiles of the grid when its files are here (`TilesStoragePath` of `[MapImageService]`, under the folder of
     * Robust's programs when it is relative), null otherwise.
     */
    private function folder(): ?string
    {
        $config = $this->grid->config();
        if ($config === null || $this->grid->isRemote() || $config->find('MapImageService', 'LocalServiceModule') === null) {
            return null;
        }
        $const = $config->section('Const') + ['DataDirectory' => $this->grid->dataDirectory(), 'CacheDirectory' => $this->grid->cacheDirectory()];
        $path = trim(ConfigReader::replaceConstants($config->find('MapImageService', 'TilesStoragePath') ?? 'maptiles', $const), " \t\"");
        if (!str_starts_with($path, '/')) {
            $bin = trim((string) ($const['BinDirectory'] ?? ''), " \t\"");
            if ($bin === '' || str_contains($bin, '${')) {
                return null;
            }
            $path = "$bin/$path";
        }
        $folder = "$path/" . self::SCOPE;

        return !str_contains($folder, '${') && is_dir($folder) ? $folder : null;
    }

    /** The cache of the tiles asked of Robust, next to the assets of the grid. */
    private function cacheFolder(): string
    {
        return dirname(WebAssets::folder($this->grid)) . '/map';
    }

    /** The answer of Robust, null when it gives none (its map handler busy: 503). */
    private function fetch(string $url): ?string
    {
        $http = $this->grid->environment()->http;

        return $http !== null ? $http($url) : Http::get($url, 10);
    }

    /** @return array{data:string,water:bool,maxAge:int} the water tile, kept as long as said */
    private function water(int $maxAge): array
    {
        static $water = null;
        if ($water === null) {
            $water = '';
            foreach (ResourceRegistry::roots() as $root) {
                if (is_file($root . '/' . self::WATER_FILE)) {
                    $water = (string) file_get_contents($root . '/' . self::WATER_FILE);
                    break;
                }
            }
        }

        return ['data' => $water, 'water' => true, 'maxAge' => $maxAge];
    }
}
