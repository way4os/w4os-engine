<?php

declare(strict_types=1);

namespace OpenSim\Assets;

use OpenSim\Config\GridConfig;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Asset;
use OpenSim\Models\Grid;
use OpenSim\Models\Image;
use OpenSim\Network\GridInfoReader;
use OpenSim\Provisioning\ResourceRegistry;
use OpenSim\Support\FileCache;
use OpenSim\Text\Uuid;

/**
 * The assets of a grid for the web: asked by their id, as a picture in the format of a page when they are images, whatever the type (texture, TGA, JPEG: the grid keeps
 * them as JPEG 2000), as they are for the other types. What the grid gave and what was converted are kept for a day; an id that
 * is nothing, or an asset that cannot be had or converted, is the picture of nothing: the one of the kit (w4os's), else a transparent
 * pixel.
 *
 *     $web = WebAssets::for($grid);
 *     ['data' => $bytes, 'mime' => $type] = $web->get('cb2052ae-d161-43e9-b11b-c834217823cd', 'png');
 */
final class WebAssets
{
    /**
     * The id w4os gives the picture of what has none (W4OS_NOTFOUND_IMG): no grid has it, it is the picture of nothing of the kit,
     * found.
     */
    public const PLACEHOLDER = '201ce950-aa38-46d8-a8f1-4396e9d6be00';

    /** The picture of nothing of the kit, in its share folder (the one of w4os). */
    public const PLACEHOLDER_FILE = 'share/images/assets-no-img.png';

    /**
     * The id of the picture of a person who has none (a profile without its picture, in-world or of its real life): no grid has it,
     * it is the silhouette of the kit, found.
     */
    public const PERSON = 'c14a1985-c549-4485-8779-7103d74ef5e0';

    /** The silhouette of a person of the kit, in its share folder. */
    public const PERSON_FILE = 'share/images/assets-no-person.png';

    /** What stands for a picture that is not there, by what it is the picture of: a place (the default), a person. */
    public const DEFAULTS = ['place' => self::PLACEHOLDER_FILE, 'person' => self::PERSON_FILE];

    /** A transparent pixel: what is shown for what has no picture, when nothing better is given. */
    private const NOTHING = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * @param ?\Closure(string):?string $http the body of an address, null when it does not answer (default: curl)
     * @param ?string $placeholder the file of the picture of nothing (default: a transparent pixel)
     * @param ?string $person the file of the picture of a person who has none (default: the picture of nothing)
     */
    public function __construct(
        private readonly Grid $grid,
        private readonly FileCache $converted,
        private readonly FileCache $sources,
        private readonly ConverterInterface $converter,
        private readonly ?\Closure $http = null,
        private readonly ?string $placeholder = null,
        private readonly ?string $person = null,
    ) {}

    /** The cache folder of the assets of a grid of this machine. */
    public static function folder(Grid $grid): string
    {
        $root = $grid->environment()->layout->cacheRoot() ?: sys_get_temp_dir() . '/opensim-cache';

        return $root . '/' . $grid->nick() . '/assets';
    }

    /** The assets of a grid of this machine, kept in its cache folder. */
    public static function for(Grid $grid, ?ConverterInterface $converter = null): self
    {
        $folder = self::folder($grid);

        return new self(
            $grid,
            new FileCache("$folder/images", 86400, $grid->environment()->clock),
            new FileCache("$folder/sources", 86400, $grid->environment()->clock),
            $converter ?? new ImagickConverter(),
            $grid->environment()->http,
            self::placeholderFile(),
            self::placeholderFile('person'),
        );
    }

    /**
     * The external web assets service of the grid: the one it sets (AssetsURL of [Helpers]) when it is not under its helpers, null
     * when the web assets are served here (by the engine, or the helpers of the grid).
     */
    public static function external(Grid $grid): ?string
    {
        $url = (new \OpenSim\Models\Service($grid->environment(), $grid, 'assets'))->url();

        return $url === null || trim($url) === '' || $grid->servedByHelpers($url) ? null : rtrim(trim($url), '/');
    }

    /**
     * Where a picture is asked when nothing here reads JPEG 2000: at the external web assets service of the grid
     * (`<AssetsURL>/<id>.<format>`, as w4os-wp asks it); null when it is converted here, or the grid has no external service, or it
     * is a picture of the kit.
     */
    public static function elsewhere(Grid $grid, string $id, string $format, bool $readsHere): ?string
    {
        $id = strtolower($id);
        if ($readsHere || !Uuid::isValid($id) || in_array($id, [self::PLACEHOLDER, self::PERSON], true)) {
            return null;
        }
        $external = self::external($grid);

        return $external !== null ? $external . '/' . $id . '.' . strtolower(ltrim($format, '.')) : null;
    }

    /** The picture of the kit for what has none, a place or a person (DEFAULTS), null when it is not installed. */
    public static function placeholderFile(string $of = 'place'): ?string
    {
        $file = self::DEFAULTS[$of] ?? self::PLACEHOLDER_FILE;
        foreach (ResourceRegistry::roots() as $root) {
            if (is_file($root . '/' . $file)) {
                return $root . '/' . $file;
            }
        }

        return null;
    }

    /**
     * The asset as a page shows it.
     *
     * @param string $format png, jpg...: for an image of any type, the other types are given as they are
     * @param ?int $width the width in pixels an image is resized to, the height following; null keeps its size
     * @param string $of what a missing picture stands for, `place` or `person` (DEFAULTS): the picture given in its place
     * @return array{data:string,mime:string,found:bool}
     */
    public function get(string $id, string $format = Image::DEFAULT_FORMAT, ?int $width = null, string $of = 'place'): array
    {
        $format = strtolower(ltrim($format, '.'));
        isset(Image::FORMATS[$format]) || ($format = Image::DEFAULT_FORMAT);
        $id = strtolower($id);
        $width = $width !== null && $width > 0 ? $width : null;
        $name = $width !== null ? "$id-$width.$format" : "$id.$format";
        if (!Uuid::isValid($id)) {
            return $this->nothing($of);
        }
        if ($id === self::PLACEHOLDER) {
            return ['found' => true] + $this->nothing();
        }
        if ($id === self::PERSON) {
            return ['found' => true] + $this->nothing('person');
        }
        if (($done = $this->converted->get($name)) !== null) {
            return [
                'data' => $done['data'],
                'mime' => (string) ($done['meta']['mime'] ?? Image::FORMATS[$format]),
                'found' => true,
            ];
        }

        $asset = $this->asset($id);
        if ($asset === null || ($data = $asset->data()) === null) {
            return $this->nothing($of);
        }
        if (!($asset instanceof Image)) {
            return ['data' => $data, 'mime' => $asset->mime(), 'found' => true];
        }
        $picture = $asset->render($this->converter, $format, $width);
        if ($picture === null) {
            return $this->nothing($of);
        }
        $this->converted->put($name, $picture, ['mime' => Image::FORMATS[$format]]);

        return ['data' => $picture, 'mime' => Image::FORMATS[$format], 'found' => true];
    }

    /**
     * The asset, from what the grid gave a while ago, else what another grid gave for its guide, else from its asset service, else
     * from its database.
     */
    private function asset(string $id): ?Asset
    {
        $config =
            $this->grid->directory() !== '' && is_dir($this->grid->directory())
                ? GridConfig::fromFolder($this->grid->directory())
                : new GridConfig();
        if (($kept = $this->sources->get($id) ?? $this->remote($config, $id)) !== null) {
            return $this->rebuilt($config, $id, $kept);
        }
        $http = $this->http ?? GridInfoReader::get(...);
        $asset = null;
        foreach (['asset', 'hg-asset'] as $key) {
            $address = $this->grid->address($key);
            $asset = $address !== null ? Asset::fromService($config, $address, $id, $http) : null;
            if ($asset !== null) {
                break;
            }
        }
        if ($asset === null) {
            try {
                $asset = Asset::find($config, $id);
            } catch (ChannelUnavailable) {
                $asset = null;
            }
        }
        if ($asset !== null && ($data = $asset->data()) !== null) {
            $this->sources->put($id, $data, ['type' => $asset->typeNumber(), 'name' => $asset->name()]);
        }

        return $asset;
    }

    /**
     * A picture another grid gave for the guide (RemotePictures), from the cache of the grid every process of it shares: its grid
     * is not asked when a page shows it.
     *
     * @return ?array{data:string,meta:array<string,mixed>}
     */
    private function remote(GridConfig $config, string $id): ?array
    {
        $image = \OpenSim\Services\RemotePictures::image(\OpenSim\Data\Cache::for($this->grid->environment(), $config), $id);

        return $image === null ? null : ['data' => $image['data'], 'meta' => ['type' => $image['type'], 'name' => $image['name']]];
    }

    /**
     * @param array{data:string,meta:array<string,mixed>} $kept
     */
    private function rebuilt(GridConfig $config, string $id, array $kept): Asset
    {
        $row = [
            'id' => $id,
            'name' => (string) ($kept['meta']['name'] ?? ''),
            'assetType' => (int) ($kept['meta']['type'] ?? 0),
            'data' => $kept['data'],
        ];
        $class = Asset::TYPES[$row['assetType']][2] ?? Asset::class;

        return new $class($config, $row);
    }

    /** @return array{data:string,mime:string,found:bool} the picture of a missing place, or person */
    private function nothing(string $of = 'place'): array
    {
        $file = $of === 'person' ? $this->person ?? $this->placeholder : $this->placeholder;
        if ($file !== null && is_file($file)) {
            $mime =
                ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif'][
                    strtolower(pathinfo($file, PATHINFO_EXTENSION))
                ] ?? 'image/png';

            return ['data' => (string) file_get_contents($file), 'mime' => $mime, 'found' => false];
        }

        return ['data' => (string) base64_decode(self::NOTHING), 'mime' => 'image/png', 'found' => false];
    }
}
