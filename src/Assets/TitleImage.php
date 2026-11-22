<?php

declare(strict_types=1);

namespace W4OS\Engine\Assets;

use W4OS\Engine\Text\Uuid;

/**
 * The picture of what has none: the image of its title, asked as any picture of the grid by its id (`<assets>/<id>.jpg`), drawn
 * by WebAssets (ImagickTextRenderer::fit()), a square as the snapshots of the parcels. The id holds the title: any page can show it,
 * nothing is kept to find it again. What has a picture or not chooses here only (pick(), Pictures::picture()).
 *
 *     TitleImage::pick($snapshot, 'Hang out');   // its snapshot, else 'title-48616e67206f7574'
 *     TitleImage::title('title-48616e67206f7574');   // 'Hang out'
 */
final class TitleImage
{
    public const PREFIX = 'title-';

    /** The ids a picture of the grid takes in an address: an asset UUID, or the image of a title (a pattern for the routes). */
    public const ID_PATTERN = '[0-9a-fA-F-]{36}|title-(?:[0-9a-f]{2})+';

    /** The longest title drawn, in bytes: a longer one is cut after its last whole word. */
    public const MAX = 120;

    /** The side of the image, the one of the snapshots of the parcels. */
    public const SIDE = 512;

    /** The colors of the image: the ones of the picture of nothing of the kit (WebAssets::PLACEHOLDER_FILE). */
    public const COLOR = '#5a5a5a';
    public const BACKGROUND = '#d0d0d0';

    /** Its picture: the asset when it is one (not empty, not the null key), else the image of its title. */
    public static function pick(?string $asset, string $title): string
    {
        $asset = trim((string) $asset);

        return Uuid::isValid($asset) && !Uuid::isNullKey($asset) ? $asset : self::id($title);
    }

    /** The id of the image of a title; the picture of nothing for no title. */
    public static function id(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if (strlen($title) > self::MAX) {
            $cut = mb_strcut($title, 0, self::MAX + 1);
            $space = strrpos($cut, ' ');
            $title = $space === false ? mb_strcut($title, 0, self::MAX) : substr($cut, 0, $space);
        }

        return $title === '' ? WebAssets::PLACEHOLDER : self::PREFIX . bin2hex($title);
    }

    /** The title of the image of an id, null when it is not one. */
    public static function title(string $id): ?string
    {
        if (preg_match('/^' . self::PREFIX . '((?:[0-9a-f]{2})+)$/', $id, $m) !== 1) {
            return null;
        }
        $title = (string) hex2bin($m[1]);

        return mb_check_encoding($title, 'UTF-8') && strlen($title) <= self::MAX ? $title : null;
    }
}
