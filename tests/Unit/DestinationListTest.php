<?php

declare(strict_types=1);

/**
 * The destinations of a guide: "Name|Region URL" lines in a file or at a URL, a name alone starts a category.
 */

use W4OS\Engine\Text\DestinationList;

const GUIDE_TEXT = <<<'TXT'
# The places we like
Welcome|grid.example.org:8002/Welcome
Gardens
Rose garden|grid.example.org:8002/Roses|128,64,30
Maze|grid.example.org:8002/Maze|not a place
-
|
Old way|Library|1000,1000|grid.example.org:8002|Library
// a comment
TXT;

describe('The destinations of a guide', function () {
    test('are listed by category, a name alone starting one', function () {
        expect(DestinationList::parse(GUIDE_TEXT, 'Start'))->toBe([
            'Start' => [['name' => 'Welcome', 'url' => 'grid.example.org:8002/Welcome']],
            'Gardens' => [
                ['name' => 'Rose garden', 'url' => 'grid.example.org:8002/Roses/128/64/30'],
                ['name' => 'Maze', 'url' => 'grid.example.org:8002/Maze'],
                ['name' => 'Library', 'url' => 'grid.example.org:8002/Library'],
            ],
        ]);
    });

    test('skip the comments and the layout hints of the boards, and have a default first category', function () {
        $list = DestinationList::parse("// nothing\n-\n|\n\nPlace|grid.example.org:8002/Place\r\n");

        expect(array_values($list))
            ->toBe([[['name' => 'Place', 'url' => 'grid.example.org:8002/Place']]])
            ->and(array_keys($list))
            ->toHaveCount(1)
            ->and(DestinationList::parse(''))
            ->toBe([]);
    });

    test('are read from a file, looked for in a folder, or from a URL, null when there is nothing', function () {
        $folder = test_tmp() . '/guide-' . bin2hex(random_bytes(4));
        mkdir($folder);
        file_put_contents("$folder/destinations.txt", "Home|grid.example.org:8002/Home\n");
        $asked = [];
        $http = static function (string $url) use (&$asked): ?string {
            $asked[] = $url;

            return $url === 'https://example.org/guide.txt' ? "Web|grid.example.org:8002/Web\n" : null;
        };

        expect(DestinationList::read('destinations.txt', $folder))
            ->toBe("Home|grid.example.org:8002/Home\n")
            ->and(DestinationList::read("$folder/destinations.txt"))
            ->toBe("Home|grid.example.org:8002/Home\n")
            ->and(DestinationList::read('https://example.org/guide.txt', $folder, $http))
            ->toBe("Web|grid.example.org:8002/Web\n")
            ->and(DestinationList::read('https://example.org/other.txt', null, $http))
            ->toBeNull()
            ->and(DestinationList::read('missing.txt', $folder))
            ->toBeNull()
            ->and(DestinationList::read(null))
            ->toBeNull()
            ->and(DestinationList::read('  '))
            ->toBeNull()
            ->and($asked)
            ->toBe(['https://example.org/guide.txt', 'https://example.org/other.txt']);
    });

    test('are written as the lines the boards of the scripts read, and read back', function () {
        $list = [
            'Popular places' => [['name' => 'Welcome', 'url' => 'grid.example.org:8002/Welcome/128/128/25']],
            'Gardens' => [['name' => 'Roses', 'url' => 'grid.example.org:8002/Roses']],
        ];

        expect(DestinationList::format($list))
            ->toBe(
                "Popular places\nWelcome|grid.example.org:8002/Welcome/128/128/25\nGardens\nRoses|grid.example.org:8002/Roses\n",
            )
            ->and(DestinationList::parse(DestinationList::format($list)))
            ->toBe($list)
            ->and(DestinationList::format([]))
            ->toBe('');
    });
});

describe('The lines of the boards', function () {
    test('keep the place in its own column and the layout hints of the boards, read and written back', function () {
        $list = DestinationList::parse(GUIDE_TEXT, null, true);
        $lines = DestinationList::format($list);

        expect($list['Gardens'][0])
            ->toBe(['name' => 'Rose garden', 'url' => 'grid.example.org:8002/Roses', 'landing' => '128,64,30'])
            ->and($lines)
            ->toContain("Rose garden|grid.example.org:8002/Roses|128,64,30\n", "\n-\n", "\n|\n")
            ->and(DestinationList::parse($lines, null, true))
            ->toBe($list);
    });

    test('are read only when they are text: 1 MB at most, in UTF-8', function () {
        $http = static fn(string $url): ?string => ['https://example.org/big.txt' => str_repeat("A|b\n", 300_000), 'https://example.org/latin.txt' => "Caf\xe9|grid.example.org:8002/Cafe\n"][$url] ?? null;
        $folder = test_tmp() . '/guide-' . bin2hex(random_bytes(4));
        mkdir($folder);
        file_put_contents("$folder/big.txt", str_repeat("A|b\n", 300_000));

        expect(array_map('is_null', [DestinationList::read('https://example.org/big.txt', null, $http), DestinationList::read('https://example.org/latin.txt', null, $http), DestinationList::read('big.txt', $folder)]))
            ->toBe([true, true, true]);
    });
});

describe('The lines as the board reads them', function () {
    test('the old format: the name in the 2nd column, the address in the 4th, the landing point in the 5th; an address once', function () {
        expect(DestinationList::parse("Speculoos|Grand Place|1000,1000|speculoos.world:8002:Grand Place|128,128,25\n"))
            ->toBe(['Destinations Guide' => [['name' => 'Grand Place', 'url' => 'speculoos.world:8002:Grand Place/128/128/25']]])
            ->and(DestinationList::parse("Places\nOne|http://grid.example.org:8002:Sandbox\nTwo|grid.example.org:8002:Sandbox\n"))
            ->toBe(['Places' => [['name' => 'One', 'url' => 'grid.example.org:8002:Sandbox']]]);
    });
});

describe('The sections of a guide', function () {
    test('are its titles: lines of one title follow each other with nothing between them, a spacer or a column break parts them', function () {
        $parse = static fn(string $text): array => array_map(static fn(array $places): array => array_column($places, 'name'), DestinationList::parse($text));

        expect($parse("A long\ntitle on two lines\nOne|grid.example.org:8002:One\n"))
            ->toBe(['A long title on two lines' => ['One']])
            ->and($parse("Spaced\n|\nApart\nOne|grid.example.org:8002:One\n"))
            ->toBe(['Apart' => ['One']])
            ->and($parse("Broken\n-\nApart\nOne|grid.example.org:8002:One\n"))
            ->toBe(['Apart' => ['One']]);
    });

    test('leave out a title without a destination: text of the board, at the end of a column or of the file', function () {
        $text = "First\nOne|grid.example.org:8002:One\nThe end of the column\n-\nNext\nTwo|grid.example.org:8002:Two\nThe end of the file\n";
        $sections = DestinationList::parse($text);

        expect(array_keys($sections))
            ->toBe(['First', 'Next'])
            // The lines of the board keep them all, as they are
            ->and(DestinationList::format(DestinationList::parse($text, null, true)))
            ->toBe($text);
    });
});

describe('The heights of a guide', function () {
    test('are a little above the sea when the line gives zero or none, as given else', function () {
        $text = "Beach|grid.example.org:8002/Beach|128,128,0\nHill|grid.example.org:8002/Hill/10/20/0\nTower|grid.example.org:8002/Tower|5,6,300\n";

        expect(array_column(DestinationList::parse($text)[_('Destinations Guide')], 'url'))
            ->toBe(['grid.example.org:8002/Beach/128/128/21', 'grid.example.org:8002/Hill/10/20/21', 'grid.example.org:8002/Tower/5/6/300'])
            ->and(array_column(DestinationList::parse($text, null, true)[_('Destinations Guide')], 'landing'))
            ->toBe(['128,128,21', '5,6,300']);
    });
});

describe('The pictures of a guide', function () {
    test('are the pictures of the parcels of the destinations, by their name, else their landing point, else the only one of the region', function () {
        $search = new W4OS\Engine\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $search->exec('CREATE TABLE regionsregister (regionname VARCHAR(255), regionUUID CHAR(36) PRIMARY KEY, regionhandle VARCHAR(255), url VARCHAR(255), owner VARCHAR(255), owneruuid CHAR(36), gatekeeperURL VARCHAR(255))');
        $search->exec('CREATE TABLE parcels (parcelUUID CHAR(36), regionUUID CHAR(36), parcelname VARCHAR(255), landingpoint VARCHAR(255), gatekeeperURL VARCHAR(255), imageUUID CHAR(36))');
        $search->exec("INSERT INTO regionsregister (regionname, regionUUID, gatekeeperURL) VALUES ('Welcome', 'r1', 'http://grid.example.org:8002'), ('Far', 'r2', 'http://far.example.net:8002')");
        $search->exec("INSERT INTO parcels VALUES ('p1', 'r1', 'Garden', '128/128/25', 'http://grid.example.org:8002', 'aaaaaaaa-0000-0000-0000-000000000001'), ('p2', 'r1', 'Plaza', '10/10/25', 'http://grid.example.org:8002', 'aaaaaaaa-0000-0000-0000-000000000002'), ('p3', 'r2', 'Far away', '1/1/1', 'http://far.example.net:8002/', 'bbbbbbbb-0000-0000-0000-000000000003')");
        $pictures = new W4OS\Engine\Services\DestinationPictures($search, 'http://grid.example.org:8002');
        $sections = $pictures->of([
            'Visit' => [
                ['name' => 'Nowhere', 'url' => 'grid.example.org:8002/Unknown'],
                ['name' => 'Garden', 'url' => 'grid.example.org:8002/Welcome'],
                ['name' => 'By its landing', 'url' => 'grid.example.org:8002/Welcome/10/10/25'],
                ['name' => 'Hypergrid', 'url' => 'far.example.net:8002/Far'],
            ],
            'Empty' => [['name' => 'Nowhere', 'url' => 'grid.example.org:8002/Unknown']],
        ]);

        expect(array_column($sections['Visit']['destinations'], 'picture', 'name'))
            ->toBe(['Nowhere' => null, 'Garden' => 'aaaaaaaa-0000-0000-0000-000000000001', 'By its landing' => 'aaaaaaaa-0000-0000-0000-000000000002', 'Hypergrid' => 'bbbbbbbb-0000-0000-0000-000000000003'])
            // The picture of a section: the first of its destinations that has one
            ->and([$sections['Visit']['picture'], $sections['Empty']['picture']])
            ->toBe(['aaaaaaaa-0000-0000-0000-000000000001', null]);
    });
});

describe('A list of destinations of another server', function () {
    test('is asked once while it is fresh, its last copy given when the server does not answer, a failure kept too', function () {
        $folder = sys_get_temp_dir() . '/guide-' . bin2hex(random_bytes(4));
        $asked = 0;
        $answer = "Welcome\nPlaza|hop://grid.example:8002/Plaza\n";
        $http = static function (string $url) use (&$asked, &$answer): ?string {
            $asked++;

            return $answer;
        };
        $now = 1_000_000;
        $clock = static function () use (&$now): int { return $now; };

        $first = DestinationList::cached('https://2do.directory/destinations/guide', $folder, $http, $clock);
        $again = DestinationList::cached('https://2do.directory/destinations/guide', $folder, $http, $clock);
        $now += DestinationList::FRESH + 1;
        $answer = null;
        $stale = DestinationList::cached('https://2do.directory/destinations/guide', $folder, $http, $clock);
        $kept = DestinationList::cached('https://2do.directory/destinations/guide', $folder, $http, $clock);
        exec('rm -rf ' . escapeshellarg($folder));

        expect([$first, $again, $stale, $kept])->each->toContain('Plaza|hop://grid.example:8002/Plaza')
            // Asked the first time, then once more when it was old (no answer): never at each page
            ->and($asked)->toBe(2);
    });
});
