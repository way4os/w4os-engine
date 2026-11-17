<?php

declare(strict_types=1);

/**
 * The destinations of a guide: "Name|Region URL" lines in a file or at a URL, a name alone starts a category.
 */

use OpenSim\Text\DestinationList;

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
