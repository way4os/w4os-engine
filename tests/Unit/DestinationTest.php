<?php
/**
 * What the engine does with nothing but its arguments: destinations and teleport links, UUIDs, the text the
 * search tables take.
 */

use W4OS\Engine\Hypergrid\Destination;
use W4OS\Engine\Text\Encoding;
use W4OS\Engine\Text\Uuid;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

const TP_DESTINATION = 'hg.example.org:8002:My Region/128/64/22';

describe('Destinations', function () {
    test('keep a position that has a zero in it', function () {
        expect(Destination::parse('grid.example:8002:Plaza/0/128/30')['pos'])
            ->toBe('0/128/30')
            ->and(Destination::format('grid.example:8002:Plaza/0/0/30', TPLINK_HOP))
            ->toBe('hop://grid.example:8002/Plaza/0/0/30');
    });

    test('are read from the forms people write them', function (string $url) {
        $parsed = Destination::parse($url);

        expect($parsed['gatekeeper'])
            ->toBe('http://grid.example:8002')
            ->and($parsed['region'])
            ->toBe('Region Name')
            ->and($parsed['pos'])
            ->toBe('1/2/3')
            ->and(Destination::uri($url))
            ->toBe('grid.example:8002/Region Name/1/2/3');
    })->with([
        'with colons' => 'grid.example:8002:Region Name/1/2/3',
        'with a space' => 'grid.example:8002 Region Name/1/2/3',
        'with pluses' => 'grid.example:8002+Region+Name/1/2/3',
        'with a scheme' => 'http://grid.example:8002/Region Name/1/2/3',
        'with a scheme and colons' => 'http://grid.example:8002:Region Name/1/2/3',
        'as a viewer link' => 'secondlife://grid.example:8002/Region%20Name/1/2/3',
    ]);

    test('keep the region alone, and an address alone', function () {
        // Every key is there, empty when the address has nothing for it, for who extracts them
        expect(Destination::parse('grid.example:8002:Plaza'))
            ->toMatchArray([
                'region' => 'Plaza',
                'gatekeeper' => 'http://grid.example:8002',
                'pos' => '',
                'key' => 'grid.example:8002/plaza',
            ])
            ->and(Destination::parse('Plaza/10/20/30'))
            ->toMatchArray(['region' => 'Plaza', 'pos' => '10/20/30', 'gatekeeper' => '', 'host' => '']);
    });

    test('land a little above the sea when their height is zero or missing, at the height they give else', function () {
        expect(Destination::parse('dev.w4os.org:8012/Welcome/128/128/0')['pos'])
            ->toBe('128/128/' . Destination::HEIGHT)
            ->and(Destination::HEIGHT)
            ->toBe(21)
            ->and(Destination::parse('grid.example:8002:Plaza/128/64')['pos'])
            ->toBe('128/64/21')
            ->and(Destination::parse('grid.example:8002:Plaza/128/64/2')['pos'])
            ->toBe('128/64/2')
            ->and(Destination::format('hop://dev.w4os.org:8012/Welcome/128/128/0', TPLINK_HOP))
            ->toBe('hop://dev.w4os.org:8012/Welcome/128/128/21')
            ->and(Destination::format('grid.example:8002:Plaza/10/20/0', TPLINK_APPTP))
            ->toBe('secondlife:///app/teleport/grid.example:8002:Plaza/10/20/21')
            // A point written by hand keeps its separator; no point stays none (the landing point of the region)
            ->and(Destination::position('128,128,0'))
            ->toBe('128,128,21')
            ->and(Destination::position('10/20'))
            ->toBe('10/20/21')
            ->and(Destination::position('10/20/0.5'))
            ->toBe('10/20/0.5')
            ->and(Destination::position(''))
            ->toBe('')
            // As the scripts write a vector: its brackets and its spaces left out
            ->and(Destination::position('<10.4, 20, 30.6>'))
            ->toBe('10.4,20,30.6')
            ->and(Destination::position(' < 10, 20, 0 > '))
            ->toBe('10,20,21')
            ->and(Destination::height(0))
            ->toBe(21.0)
            ->and(Destination::height(null))
            ->toBe(21.0)
            ->and(Destination::height(600))
            ->toBe(600.0);
    });

    test('keep their region with a point in decimals, with a host or without', function (string $url, string $host, string $region, string $pos) {
        $parsed = Destination::parse($url);

        expect([$parsed['host'], $parsed['region'], $parsed['pos']])->toBe([$host, $region, $pos]);
    })->with([
        'z in decimals, no host' => ['Welcome/116/136/21.75', '', 'Welcome', '116/136/21.75'],
        'x in decimals, no host' => ['Welcome/116.5/136/21', '', 'Welcome', '116.5/136/21'],
        'y in decimals, no host' => ['Welcome/116/136.25/21', '', 'Welcome', '116/136.25/21'],
        'a name with a space, no host' => ['Welcome Plaza/1.5/2.5/3.5', '', 'Welcome Plaza', '1.5/2.5/3.5'],
        'with a host' => ['grid.example:8002/Welcome/116.5/136.25/21.75', 'grid.example', 'Welcome', '116.5/136.25/21.75'],
        'with a host and colons' => ['grid.example:8002:Welcome/116.5/136/21.75', 'grid.example', 'Welcome', '116.5/136/21.75'],
        'with a host without its port' => ['grid.example:Welcome/1.5/2/3', 'grid.example', 'Welcome', '1.5/2/3'],
    ]);

    test('in decimals make the local link of their region', function () {
        expect(Destination::format('Welcome/116/136/21.75', Destination::LOCAL))
            ->toBe('secondlife://Welcome/116/136/21.75')
            ->and(Destination::format('Welcome/116.5/136/21', Destination::AUTO, context: ['agent' => 'SecondLife/7.1 (Firestorm)']))
            ->toBe('secondlife:///secondlife/Welcome/116.5/136/21');
    });

    test('get the default gatekeeper when they have none', function () {
        expect(Destination::parse('Plaza/1/2/3', 'http://login.example:8002')['gatekeeper'])->toBe(
            'http://login.example:8002',
        );
    });
});

describe('Teleport links', function () {
    test('are made in each format', function (int $format, string $link) {
        expect(Destination::format(TP_DESTINATION, $format))->toBe($link);
    })->with([
        'text' => [TPLINK_TXT, 'hg.example.org:8002 My Region/128/64/22'],
        'viewer link' => [TPLINK_HG, 'secondlife://hg.example.org:8002:My' . Destination::SPACE . 'Region/128/64/22'],
        'viewer v3 link' => [TPLINK_V3HG, 'secondlife://http|!!hg.example.org|8002%20My%20Region/128/64/22'],
        'hop' => [TPLINK_HOP, 'hop://hg.example.org:8002/My' . Destination::SPACE . 'Region/128/64/22'],
        'for the scripts (llMapDestination)' => [Destination::LSL, 'hg.example.org:8002:My Region/128/64/22'],
        'the map of the viewer at the place' => [Destination::MAP, 'secondlife:///app/worldmap/hg.example.org:8002:My' . Destination::SPACE . 'Region/128/64/22'],
        'a location of a grid, in a viewer' => [Destination::GRID, 'secondlife://hg.example.org:8002/secondlife/My' . Destination::SPACE . 'Region/128/64/22'],
        'teleport link' => [TPLINK_APPTP, 'secondlife:///app/teleport/hg.example.org:8002:My' . Destination::SPACE . 'Region/128/64/22'],
    ]);

    test('leave the position out when it is out of a local region', function () {
        expect(Destination::format('hg.example.org:8002:My Region/300/64/22', TPLINK_HG))->toBe(
            'secondlife://hg.example.org:8002:My' . Destination::SPACE . 'Region',
        );
    });

    test('are several, one per line, or an array', function () {
        $both = Destination::format(TP_DESTINATION, TPLINK_TXT + TPLINK_HOP);
        $array = Destination::format(TP_DESTINATION, TPLINK_TXT + TPLINK_HOP + TPLINK_ARRAY);

        expect($both)
            ->toBe("hg.example.org:8002 My Region/128/64/22\nhop://hg.example.org:8002/My" . Destination::SPACE . "Region/128/64/22")
            ->and($array)
            ->toBe([
                TPLINK_TXT => 'hg.example.org:8002 My Region/128/64/22',
                TPLINK_HOP => 'hop://hg.example.org:8002/My' . Destination::SPACE . 'Region/128/64/22',
            ]);
    });

    test('of a grid in a viewer: the grid then its region, the height a little above the sea when it is zero', function () {
        expect(Destination::format('grid.example:8002/Plaza/10/20/0', Destination::GRID))
            ->toBe('secondlife://grid.example:8002/secondlife/Plaza/10/20/21')
            ->and(Destination::format('grid.example:8002:Grand Place/300/20/30.5', Destination::GRID))
            ->toBe('secondlife://grid.example:8002/secondlife/Grand' . Destination::SPACE . 'Place/300/20/30.5')
            // A region alone: of the grid the viewer is on
            ->and(Destination::format('Plaza/10/20/30', Destination::GRID))
            ->toBe('secondlife:///secondlife/Plaza/10/20/30')
            ->and(Destination::format('grid.example:8002', Destination::GRID))
            ->toBe('');
    });

    test('of the map: the region named as the search of the map takes it, its grid before a colon, the height above the sea', function () {
        expect(Destination::format('grid.example:8002/Grand Place/10/20/0', Destination::MAP))
            ->toBe('secondlife:///app/worldmap/grid.example:8002:Grand' . Destination::SPACE . 'Place/10/20/21')
            // A region alone: on the grid the viewer is on
            ->and(Destination::format('Plaza/116.5/136/21.75', Destination::MAP))
            ->toBe('secondlife:///app/worldmap/Plaza/116.5/136/21.75')
            // A grid alone: its default region, as the search of the map links it
            ->and(Destination::format('hop://hg.osgrid.org:80', Destination::MAP))
            ->toBe('secondlife:///app/worldmap/hg.osgrid.org:80');
    });

    test('of a grid alone end at its port, with no colon after it', function () {
        expect(Destination::format('wyldwoodbayou.com:8002', TPLINK_HOP))->toBe('hop://wyldwoodbayou.com:8002');
    });

    test('never have a colon before the region in a hop link: only the scripts and the teleport of the viewer have one', function (string $written) {
        $links = [Destination::format($written, TPLINK_HOP), Destination::format($written, Destination::AUTO, context: ['agent' => ''])];

        expect(array_filter($links, static fn(string $link): bool => preg_match('#^hop://[^/]+:\d+:#', $link) === 1))
            ->toBe([])
            ->and(Destination::format($written, TPLINK_HOP))
            ->toStartWith('hop://grid.example:8002/');
    })->with([
        'with colons' => 'grid.example:8002:Region Name/1/2/3',
        'as a hop link with a colon' => 'hop://grid.example:8002:Region%20Name/1/2/3',
        'as a viewer link' => 'secondlife://grid.example:8002/Region%20Name',
        'with a space' => 'grid.example:8002 Region Name/1/2/3',
    ]);

    test('are none for nothing', function () {
        expect(Destination::format('', TPLINK_HG))
            ->toBe('')
            ->and(Destination::format('', TPLINK_HG + TPLINK_ARRAY))
            ->toBe([]);
    });
});

describe('UUIDs', function () {
    test('are told from other texts', function () {
        expect((bool) Uuid::isValid('9d315475-daf9-4e31-a5e6-d787ddfcbef6'))
            ->toBeTrue()
            ->and((bool) Uuid::isValid('9D315475-DAF9-4E31-A5E6-D787DDFCBEF6'))
            ->toBeTrue()
            ->and((bool) Uuid::isValid('9d315475-daf9-4e31-a5e6'))
            ->toBeFalse()
            ->and((bool) Uuid::isValid("9d315475-daf9-4e31-a5e6-d787ddfcbef6'; DROP TABLE x"))
            ->toBeFalse()
            ->and((bool) Uuid::isValid('nothing'))
            ->toBeFalse();
    });

    test('null and the null key are valid only when asked', function () {
        $null = '00000000-0000-0000-0000-000000000000';

        expect(Uuid::isValid(null))
            ->toBeFalse()
            ->and(Uuid::isValid(null, true))
            ->toBeTrue()
            ->and(Uuid::isValid($null, true))
            ->toBeTrue();
    });

    test('are checked as version 4 when it is asked', function () {
        expect((bool) Uuid::isValid('9d315475-daf9-4e31-a5e6-d787ddfcbef6', false, true))
            ->toBeTrue()
            ->and((bool) Uuid::isValid('9d315475-daf9-1e31-a5e6-d787ddfcbef6', false, true))
            ->toBeFalse();
    });
});

test('search tables take Latin-1: what it cannot hold becomes a question mark', function () {
    expect(Encoding::latin1Only('Café Zoë'))
        ->toBe('Café Zoë')
        ->and(Encoding::latin1Only('Price: 5€ 日本'))
        ->toBe('Price: 5? ??');
});

describe('Login addresses', function () {
    test('are read in their parts, the port being the one the address gives, none when it gives none', function (
        string $address,
        ?string $url,
    ) {
        expect(Destination::loginUrl($address))->toBe($url);
    })->with([
        'a host' => ['play.example.org', 'http://play.example.org'],
        'a host and the usual port' => ['play.example.org:8002', 'http://play.example.org:8002'],
        'a host and a port of its own' => ['w4os.org:3405', 'http://w4os.org:3405'],
        'the port of the web' => ['login.osgrid.org:80', 'http://login.osgrid.org:80'],
        'with a scheme and a slash' => ['https://play.example.org:8002/', 'https://play.example.org:8002'],
        'with spaces around' => ['  play.example.org:9000  ', 'http://play.example.org:9000'],
        'nothing' => ['', null],
        'no address' => ['http://', null],
    ]);

    test('have their parts as numbers and words', function () {
        expect(Destination::loginParts('HTTPS://Play.example.org:8002/x'))->toBe([
            'scheme' => 'https',
            'host' => 'Play.example.org',
            'port' => 8002,
        ]);
    });
});

describe('Links with pluses', function () {
    test('have the pluses of the spaces and nothing a URL cannot have', function () {
        expect(Destination::sanitizePlus(' grid.example:8002 My Region '))
            ->toBe('grid.example:8002+My+Region')
            ->and(Destination::sanitizePlus("hop://grid.example:8002/My Region/1/2/3\n"))
            ->toBe('hop://grid.example:8002/My+Region/1/2/3');
    });
});

describe('A destination of a guide', function () {
    test('of a grid without a region links to the grid, a region of a grid without its port is at 8002, a region alone is local', function () {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $grid = W4OS\Engine\Hypergrid\Destination::format('wyldwoodbayou.com:8002', W4OS\Engine\Hypergrid\Destination::HG);
            $alone = W4OS\Engine\Hypergrid\Destination::format('Welcome', W4OS\Engine\Hypergrid\Destination::HG);
        } finally {
            restore_error_handler();
        }
        $parsed = W4OS\Engine\Hypergrid\Destination::parse('login.osgrid.org:RuthAndRoth');

        expect($grid)
            ->toBe('secondlife://wyldwoodbayou.com:8002')
            ->and([$parsed['host'], $parsed['port'], $parsed['region']])
            ->toBe(['login.osgrid.org', 8002, 'RuthAndRoth'])
            ->and($alone)
            ->toBe('secondlife://Welcome')
            ->and($warnings)
            ->toBe([]);
    });
});

const TP_VIEWER = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 SecondLife/7.1.9 (Firestorm)';
const TP_BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';

describe('The label of a destination, as a card shows it under its name', function () {
    test('is its region and its point on the grid itself, its grid and its region elsewhere, as text', function (string $destination, string $label) {
        expect(Destination::format($destination, Destination::LABEL, context: ['login' => 'http://dev.w4os.org:8012']))
            ->toBe($label)
            ->and(Destination::label($destination, 'http://dev.w4os.org:8012'))
            ->toBe($label);
    })->with([
        'of the grid itself' => ['dev.w4os.org:8012/Welcome/128/128/25', 'Welcome/128/128/25'],
        'of the grid itself, a height of zero' => ['hop://dev.w4os.org:8012/Test%20North/116.5/136/0', 'Test North/116.5/136/21'],
        'a region alone' => ['Welcome', 'Welcome'],
        'another grid: its grid alone, the region is the title' => ['hop://speculoos.world:8002/Grand%20Place/128/128/25', 'speculoos.world:8002'],
        'another grid, its port 80' => ['hop://hg.osgrid.org:80/Lbsa%20Plaza', 'hg.osgrid.org:80'],
        'another grid without a region' => ['hop://hg.osgrid.org:80', 'hg.osgrid.org:80'],
    ]);

    test('has a title: the name in the list on the grid itself, the region on another grid, else the name', function (string $destination, string $name, string $title) {
        expect(Destination::title($destination, $name, 'http://dev.w4os.org:8012'))->toBe($title);
    })->with([
        'of the grid itself' => ['dev.w4os.org:8012/Welcome/128/128/25', 'Our welcome', 'Our welcome'],
        'another grid' => ['hop://speculoos.world:8002/Grand%20Place/128/128/25', 'Speculoos', 'Grand Place'],
        'another grid without a region' => ['hop://hg.osgrid.org:80', 'OSGrid', 'OSGrid'],
    ]);

    test('of a card of a grid: its title, its label and the name its grid gives itself, as it was kept', function () {
        $answers = [];
        $asked = [];
        $machine = grid_info_world($answers, $asked);
        (new W4OS\Engine\Network\GridInfoCache($machine->environment))->save('http://speculoos.world:8002', ['gridname' => 'Speculoos World']);
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');

        expect($grid->card('hop://speculoos.world:8002/Grand%20Place/128/128/25', 'Speculoos'))
            ->toBe(['title' => 'Grand Place', 'label' => 'speculoos.world:8002', 'gridName' => 'Speculoos World'])
            ->and($grid->card('hop://hg.osgrid.org:80/Lbsa%20Plaza', 'OSGrid'))
            ->toBe(['title' => 'Lbsa Plaza', 'label' => 'hg.osgrid.org:80', 'gridName' => null])
            ->and($grid->card('Welcome/10/20/30', 'Our welcome'))
            ->toBe(['title' => 'Our welcome', 'label' => 'Welcome/10/20/30', 'gridName' => $grid->name()])
            // Nothing asked to the grids: what the scheduler kept is read
            ->and($asked)
            ->toBe([]);
    });

    test('of the guide of a grid: its own places by their region and their point', function () {
        $grid = W4OS\Engine\Models\Grid::find((new W4OS\Engine\Testing\Machine())->environment, 'demo');
        $login = (string) $grid->loginUrl();

        expect($grid->label(Destination::uri('Welcome/10/20/30', $login)))
            ->toBe('Welcome/10/20/30')
            ->and($grid->label('hg.example.org:8002/Far/1/2/3'))
            ->toBe('hg.example.org:8002');
    });
});

describe('A link made for where it is shown (AUTO)', function () {
    $login = 'http://dev.w4os.org:8012';
    $here = 'dev.w4os.org:8012/Welcome/128/128/25';
    $there = 'hg.example.org:8002/My Region/10/20/30';

    test('in a viewer, its login screen too: the location with its grid, for the grid itself and another one alike', function () use ($login, $here, $there) {
        expect(Destination::format($here, Destination::AUTO, context: ['agent' => TP_VIEWER, 'login' => $login]))
            ->toBe('secondlife://dev.w4os.org:8012/secondlife/Welcome/128/128/25')
            ->and(Destination::format($here, Destination::AUTO, context: ['agent' => TP_VIEWER, 'splash' => true, 'login' => $login]))
            ->toBe('secondlife://dev.w4os.org:8012/secondlife/Welcome/128/128/25')
            // A region named alone is of the grid itself
            ->and(Destination::format('Welcome/128/128/25', Destination::AUTO, context: ['agent' => TP_VIEWER, 'login' => $login]))
            ->toBe('secondlife://dev.w4os.org:8012/secondlife/Welcome/128/128/25')
            ->and(Destination::format($there, Destination::AUTO, context: ['agent' => TP_VIEWER, 'login' => $login]))
            ->toBe('secondlife://hg.example.org:8002/secondlife/My' . Destination::SPACE . 'Region/10/20/30')
            ->and(Destination::format($there, Destination::AUTO, context: ['agent' => TP_VIEWER, 'splash' => true, 'login' => $login]))
            ->toBe('secondlife://hg.example.org:8002/secondlife/My' . Destination::SPACE . 'Region/10/20/30');
    });

    test('out of a viewer: hop, for the grid itself too', function () use ($login, $here, $there) {
        expect(Destination::format($here, Destination::AUTO, context: ['agent' => '', 'login' => $login]))
            ->toBe('hop://dev.w4os.org:8012/Welcome/128/128/25')
            ->and(Destination::format($there, Destination::AUTO, context: ['agent' => TP_BROWSER, 'login' => $login]))
            ->toBe('hop://hg.example.org:8002/My' . Destination::SPACE . 'Region/10/20/30');
    });

    test('follow the rules of AUTO_LINKS, the one place they are written', function () use ($login, $here, $there) {
        $contexts = ['viewer' => ['agent' => TP_VIEWER], 'splash' => ['agent' => TP_VIEWER, 'splash' => true], 'web' => ['agent' => TP_BROWSER]];
        foreach (Destination::AUTO_LINKS as $where => $links) {
            foreach (['local' => $here, 'other' => $there] as $kind => $destination) {
                $expected = $links[$kind] === 0 ? '' : Destination::format($kind === 'local' && $links[$kind] === Destination::LOCAL ? 'Welcome/128/128/25' : Destination::uri($destination), $links[$kind]);
                expect(Destination::format($destination, Destination::AUTO, context: $contexts[$where] + ['login' => $login]))->toBe($expected);
            }
        }
    });

    test('read the user agent of the request when none is given', function () use ($login, $here) {
        $before = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = TP_VIEWER;
        $viewer = Destination::format($here, Destination::AUTO, context: ['login' => $login]);
        $_SERVER['HTTP_USER_AGENT'] = TP_BROWSER;
        $browser = Destination::format($here, Destination::AUTO, context: ['login' => $login]);
        if ($before === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $before;
        }

        expect([$viewer, $browser])->toBe(['secondlife://dev.w4os.org:8012/secondlife/Welcome/128/128/25', 'hop://dev.w4os.org:8012/Welcome/128/128/25']);
    });

    test('of a grid: its login tells its own places', function () {
        $grid = W4OS\Engine\Models\Grid::find((new W4OS\Engine\Testing\Machine())->environment, 'demo');
        $login = (string) Destination::loginParts((string) $grid->loginUrl())['host'];
        $link = static fn(string $destination, array $context) => $grid->place($destination)->tpLink(Destination::AUTO, $context);

        expect($link('Welcome/128/128/25', ['agent' => TP_VIEWER]))
            ->toStartWith("secondlife://$login:")
            ->toEndWith('/secondlife/Welcome/128/128/25')
            ->and($link('hg.example.org:8002/Far/1/2/3', ['agent' => TP_VIEWER]))
            ->toBe('secondlife://hg.example.org:8002/secondlife/Far/1/2/3')
            ->and($link('Welcome/128/128/25', ['agent' => TP_BROWSER]))
            ->toStartWith("hop://$login:")
            // The class of the client, as a site keeps a block for each
            ->and($link('Welcome/128/128/25', ['client' => 'viewer']))
            ->toStartWith("secondlife://$login:")
            ->and($link('Welcome/128/128/25', ['agent' => TP_VIEWER, 'client' => 'browser']))
            ->toStartWith("hop://$login:");
    });

    test('tell the class of a client, viewer or browser, by its user agent or the one of the request served', function () {
        $before = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = TP_VIEWER;
        $served = Destination::client(null);
        if ($before === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $before;
        }

        expect([Destination::client(TP_VIEWER), Destination::client(TP_BROWSER), Destination::client(''), $served])
            ->toBe(['viewer', 'browser', 'browser', 'viewer']);
    });

    test('take the class of the client, which wins over the user agent', function () use ($login, $here, $there) {
        expect(Destination::format($here, Destination::AUTO, context: ['client' => 'viewer', 'login' => $login]))
            ->toBe('secondlife://dev.w4os.org:8012/secondlife/Welcome/128/128/25')
            ->and(Destination::format($here, Destination::AUTO, context: ['client' => 'browser', 'agent' => TP_VIEWER, 'login' => $login]))
            ->toBe('hop://dev.w4os.org:8012/Welcome/128/128/25')
            ->and(Destination::format($there, Destination::AUTO, context: ['client' => 'viewer', 'agent' => TP_BROWSER, 'login' => $login]))
            ->toBe('secondlife://hg.example.org:8002/secondlife/My' . Destination::SPACE . 'Region/10/20/30');
    });

    test('tell a viewer by its user agent', function () {
        expect(Destination::isViewer(TP_VIEWER))->toBeTrue()->and(Destination::isViewer(TP_BROWSER))->toBeFalse();
    });
});

describe('The login URI of a grid', function () {
    test('is host:port whatever form it is written in, the URL kept apart for the calls', function () {
        $forms = ['dev.w4os.org:8012', 'http://dev.w4os.org:8012/', 'https://dev.w4os.org:8012', 'hop://dev.w4os.org:8012/Welcome/128/128/25', 'secondlife://dev.w4os.org:8012/secondlife/Welcome/128/128/25', ' HTTP://dev.w4os.org:8012 '];

        expect(array_map(Destination::loginUri(...), $forms))
            ->toBe(array_fill(0, count($forms), 'dev.w4os.org:8012'))
            ->and(Destination::loginUrl('hop://dev.w4os.org:8012/Welcome'))
            ->toBe('http://dev.w4os.org:8012')
            ->and(Destination::loginUrl('https://dev.w4os.org:8012/'))
            ->toBe('https://dev.w4os.org:8012')
            ->and(Destination::loginUri(''))
            ->toBeNull();
    });
});

describe('The links that add a grid to a viewer', function () {
    test('are the gridmanager command of Firestorm with the login URI encoded, in hop and secondlife forms', function () {
        expect(Destination::addGrid('http://dev.w4os.org:8012/'))
            ->toBe([
                'hop' => 'hop:///app/gridmanager/addgrid/dev.w4os.org%3A8012',
                'secondlife' => 'secondlife:///app/gridmanager/addgrid/dev.w4os.org%3A8012',
            ])
            ->and(Destination::addGrid('hop://dev.w4os.org:8012/Welcome')['hop'])
            ->toBe('hop:///app/gridmanager/addgrid/dev.w4os.org%3A8012')
            // A viewer takes http for a login URI without a scheme: https is said
            ->and(Destination::addGrid('https://grid.example.org/')['hop'])
            ->toBe('hop:///app/gridmanager/addgrid/https%3A%2F%2Fgrid.example.org')
            ->and(Destination::addGrid(''))
            ->toBeNull();
    });

    test('are given with the info and the addresses of a grid, which give its login URI as host:port', function () {
        $machine = new W4OS\Engine\Testing\Machine();
        $router = new W4OS\Engine\Api\Router(new W4OS\Engine\Api\Api($machine->environment));
        $grid = W4OS\Engine\Models\Grid::find($machine->environment, 'demo');
        $info = $router->get('/grids/demo/info')->document['data']['attributes'];
        $addresses = $router->get('/grids/demo/addresses')->document['data']['attributes'];

        expect($info['addGrid'])
            ->toBe(Destination::addGrid((string) $grid->loginUrl()))
            ->and($info['login'])
            ->toBe($grid->loginUri())
            ->and($info['login'])
            ->toMatch('/^[^\/:]+:\d+$/')
            ->and($info['loginUrl'])
            ->toBe($grid->loginUrl())
            ->and($addresses['login'])
            ->toBe($info['login'])
            ->and($addresses['addGrid']['secondlife'])
            ->toBe('secondlife:///app/gridmanager/addgrid/' . rawurlencode((string) $info['login']))
            ->and(W4OS\Engine\I18n\Catalog::MESSAGES['grid_add_to_viewer'])
            ->toBe('Add this grid to your viewer');
    });
});
