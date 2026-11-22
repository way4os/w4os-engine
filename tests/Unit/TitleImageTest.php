<?php

declare(strict_types=1);

/**
 * The picture of what has none: the image of its title, asked as any picture by its id (TitleImage), drawn once (WebAssets).
 */

use W4OS\Engine\Assets\ImagickTextRenderer;
use W4OS\Engine\Assets\TitleImage;
use W4OS\Engine\Assets\WebAssets;

/** The box of what is drawn on a picture of one color: [x, y, width, height]. */
function title_image_box(string $png): array
{
    $image = new Imagick();
    $image->readImageBlob($png);
    $image->trimImage(0);
    $page = $image->getImagePage();

    return [$page['x'], $page['y'], $image->getImageWidth(), $image->getImageHeight()];
}

describe('The picture of a place', function () {
    test('is its asset when it has one, else the image of its title, an id safe in an address', function () {
        $id = TitleImage::pick(null, 'Hypergrid Safari Tour');

        expect(TitleImage::pick('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Plaza'))->toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->and(TitleImage::pick('00000000-0000-0000-0000-000000000000', 'Plaza'))->toBe(TitleImage::id('Plaza'))
            ->and(TitleImage::pick('', 'Plaza'))->toBe(TitleImage::id('Plaza'))
            ->and($id)->toMatch('#^(?:' . TitleImage::ID_PATTERN . ')$#')
            ->and($id)->toBe(strtolower($id))
            ->and(TitleImage::title($id))->toBe('Hypergrid Safari Tour')
            ->and(TitleImage::title('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'))->toBeNull()
            // A title too long is cut at a word
            ->and(TitleImage::title(TitleImage::id(str_repeat('word ', 60))))->toBe(trim(str_repeat('word ', intdiv(TitleImage::MAX, 5))));
    });

    test('drawn: a square as the snapshots, the title as large as it fits in the band every card shows, its words whole', function () {
        $renderer = new ImagickTextRenderer();
        $short = $renderer->fit('Hang out', TitleImage::SIDE, TitleImage::COLOR, TitleImage::BACKGROUND);
        $long = $renderer->fit('Supercalifragilisticexpialidocious Land of the Hypergrid', TitleImage::SIDE, TitleImage::COLOR, TitleImage::BACKGROUND);
        $image = new Imagick();
        $image->readImageBlob((string) $short);
        [$bandWidth, $bandHeight] = [TitleImage::SIDE * ImagickTextRenderer::BAND[0], TitleImage::SIDE * ImagickTextRenderer::BAND[1]];
        [$x, $y, $width, $height] = title_image_box((string) $short);
        [$lx, $ly, $lwidth, $lheight] = title_image_box((string) $long);

        expect([$image->getImageWidth(), $image->getImageHeight()])->toBe([TitleImage::SIDE, TitleImage::SIDE])
            // Within the band, filling it one way or the other
            ->and($width)->toBeLessThanOrEqual($bandWidth)->and($height)->toBeLessThanOrEqual($bandHeight)
            ->and(max($width / $bandWidth, $height / $bandHeight))->toBeGreaterThan(0.8)
            ->and($y)->toBeGreaterThanOrEqual((TitleImage::SIDE - $bandHeight) / 2 - 1)
            // A word longer than a line makes the text smaller, never cut
            ->and($lwidth)->toBeLessThanOrEqual($bandWidth)->and($lheight)->toBeLessThanOrEqual($bandHeight)
            ->and($lx)->toBeGreaterThan(0);
    });

    test('served as any picture, by its id, in the format asked, drawn once', function () {
        $machine = new W4OS\Engine\Testing\Machine();
        $web = WebAssets::for(W4OS\Engine\Models\Grid::find($machine->environment, 'demo'));
        $id = TitleImage::id('Find out');

        $first = $web->get($id, 'jpg');
        $kept = $web->get($id, 'jpg');
        $image = new Imagick();
        $image->readImageBlob($first['data']);

        expect([$first['found'], $first['mime']])->toBe([true, 'image/jpeg'])
            ->and([$image->getImageWidth(), $image->getImageHeight()])->toBe([TitleImage::SIDE, TitleImage::SIDE])
            ->and($kept['data'])->toBe($first['data'])
            ->and(glob(WebAssets::folder(W4OS\Engine\Models\Grid::find($machine->environment, 'demo')) . '/images/' . $id . '*'))->not->toBe([]);
    });
});
