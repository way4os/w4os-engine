<?php

declare(strict_types=1);

/**
 * The link of what a viewer can be sent to is asked of it, always (Reachable): `$grid->tpLink()`, `$region->tpLink()`,
 * `$parcel->tpLink()`, `$grid->place($url)->tpLink()`, a format given or AUTO; nothing else builds one.
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Hypergrid\Destination;
use W4OS\Engine\Hypergrid\Place;
use W4OS\Engine\Hypergrid\Reachable;
use W4OS\Engine\Models\Parcel;
use W4OS\Engine\Models\Region;

/** A grid at play.example.org:8002 with a region, Quiet Harbour, in a file of its simulator. */
function teleport_grid(): GridConfig
{
    $dir = test_tmp() . '/teleport-' . bin2hex(random_bytes(4));
    mkdir("$dir/sims/alpha/regions", 0o755, true);
    file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n PublicPort = \"8002\"\n");
    file_put_contents("$dir/sims/alpha/regions/Harbour.ini", "[Quiet Harbour]\nRegionUUID = aaaaaaaa-0000-4000-8000-000000000001\nLocation = 1000,1000\n");

    return GridConfig::fromFolder($dir);
}

/** Whether PHP code calls Destination::format(), its comments and strings aside. */
function teleport_calls_format(string $code): bool
{
    $tokens = array_values(array_filter(token_get_all($code), static fn($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    foreach ($tokens as $i => $token) {
        $name = is_array($token) ? $token[1] : $token;
        if (preg_match('/(^|\\\\)Destination$/', $name) === 1 && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON
            && strcasecmp((string) ($tokens[$i + 2][1] ?? ''), 'format') === 0 && ($tokens[$i + 3] ?? null) === '(') {
            return true;
        }
    }

    return false;
}

describe('The link of a grid, a region, a parcel or a place', function () {
    test('is asked of it, in the format given, AUTO without one', function () {
        $config = teleport_grid();
        $region = Region::inDirectory(dirname((string) glob(test_tmp() . '/teleport-*/sims/alpha/regions/Harbour.ini')[0] ?? ''), $config)[0] ?? null;
        $parcel = new Parcel($config, ['UUID' => 'p9', 'RegionUUID' => 'aaaaaaaa-0000-4000-8000-000000000001', 'regionName' => 'Quiet Harbour',
            'UserLocationX' => 64, 'UserLocationY' => 27, 'UserLocationZ' => 30]);
        $place = new Place('far.example.org:8002/Far Away/1/2/3', 'http://play.example.org:8002');
        $space = Destination::SPACE;

        expect([$region, $parcel, $place])->each->toBeInstanceOf(Reachable::class)
            ->and($region->tpUri())->toBe('play.example.org:8002/Quiet Harbour')
            ->and($region->tpLink(Destination::HOP))->toBe("hop://play.example.org:8002/Quiet{$space}Harbour")
            ->and($region->teleport())->toBe($region->tpLink(Destination::HOP))
            ->and($parcel->tpUri())->toBe('play.example.org:8002/Quiet Harbour/64/27/30')
            ->and($parcel->tpLink(Destination::HOP))->toBe("hop://play.example.org:8002/Quiet{$space}Harbour/64/27/30")
            ->and($parcel->tpLink(Destination::GRID))->toBe("secondlife://play.example.org:8002/secondlife/Quiet{$space}Harbour/64/27/30")
            // AUTO: the link for where it is shown, a viewer or a browser
            ->and($parcel->tpLink(context: ['client' => 'viewer']))->toBe($parcel->tpLink(Destination::GRID))
            ->and($parcel->tpLink(context: ['client' => 'browser']))->toBe($parcel->tpLink(Destination::HOP))
            ->and($place->tpUri())->toBe('far.example.org:8002/Far Away/1/2/3')
            ->and($place->tpLink(Destination::HOP))->toBe("hop://far.example.org:8002/Far{$space}Away/1/2/3")
            ->and((new Place(''))->tpLink())->toBeNull();
    });

    test('of a grid alone is the grid itself, a place of it named by its region alone', function () {
        $grid = W4OS\Engine\Models\Grid::find((new W4OS\Engine\Testing\Machine())->environment, 'demo');
        $login = (string) $grid->loginUri();

        expect($grid)->toBeInstanceOf(Reachable::class)
            ->and($grid->tpUri())->toBe($login)
            ->and($grid->tpLink(Destination::HOP))->toBe("hop://$login")
            ->and($grid->tpLink(Destination::TXT))->toBe($login)
            ->and($grid->place('Welcome/128/128/25')->tpUri())->toBe("$login/Welcome/128/128/25")
            ->and($grid->place('Welcome/128/128/25')->tpLink(Destination::HOP))->toBe("hop://$login/Welcome/128/128/25");
    });
});

describe('A teleport link', function () {
    test('is made by the objects only: Destination::format() is called nowhere else', function () {
        $root = dirname(__DIR__, 2) . '/src';
        $allowed = ["$root/Hypergrid/Destination.php", "$root/Hypergrid/TeleportLinks.php"];
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, '.php') && !in_array($path, $allowed, true) && teleport_calls_format((string) file_get_contents($path))) {
                $found[] = substr($path, strlen($root) + 1);
            }
        }

        expect($found)->toBe([]);
    });
});
