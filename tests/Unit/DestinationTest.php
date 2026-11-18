<?php
/**
 * What the engine does with nothing but its arguments: destinations and teleport links, UUIDs, the text the
 * search tables take.
 */

use OpenSim\Hypergrid\Destination;
use OpenSim\Text\Encoding;
use OpenSim\Text\Uuid;

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
        expect(Destination::parse('ursull.magiiic.dev:8012/Welcome/128/128/0')['pos'])
            ->toBe('128/128/' . Destination::HEIGHT)
            ->and(Destination::HEIGHT)
            ->toBe(21)
            ->and(Destination::parse('grid.example:8002:Plaza/128/64')['pos'])
            ->toBe('128/64/21')
            ->and(Destination::parse('grid.example:8002:Plaza/128/64/2')['pos'])
            ->toBe('128/64/2')
            ->and(Destination::format('hop://ursull.magiiic.dev:8012/Welcome/128/128/0', TPLINK_HOP))
            ->toBe('hop://ursull.magiiic.dev:8012/Welcome/128/128/21')
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
            ->toBe('secondlife://Welcome/116.5/136/21');
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
            $grid = OpenSim\Hypergrid\Destination::format('wyldwoodbayou.com:8002', OpenSim\Hypergrid\Destination::HG);
            $alone = OpenSim\Hypergrid\Destination::format('Welcome', OpenSim\Hypergrid\Destination::HG);
        } finally {
            restore_error_handler();
        }
        $parsed = OpenSim\Hypergrid\Destination::parse('login.osgrid.org:RuthAndRoth');

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

describe('A link made for where it is shown (AUTO)', function () {
    $login = 'http://ursull.magiiic.dev:8012';
    $here = 'ursull.magiiic.dev:8012/Welcome/128/128/25';
    $there = 'hg.example.org:8002/My Region/10/20/30';

    test('in a viewer, its login screen too: the local link for the grid itself, which shows the place before going', function () use ($login, $here) {
        expect(Destination::format($here, Destination::AUTO, context: ['agent' => TP_VIEWER, 'login' => $login]))
            ->toBe('secondlife://Welcome/128/128/25')
            ->and(Destination::format($here, Destination::AUTO, context: ['agent' => TP_VIEWER, 'splash' => true, 'login' => $login]))
            ->toBe('secondlife://Welcome/128/128/25')
            // A region named alone is of the grid itself
            ->and(Destination::format('Welcome/128/128/25', Destination::AUTO, context: ['agent' => TP_VIEWER]))
            ->toBe('secondlife://Welcome/128/128/25');
    });

    test('in a viewer, its login screen too: the teleport of the viewer for another grid', function () use ($login, $there) {
        expect(Destination::format($there, Destination::AUTO, context: ['agent' => TP_VIEWER, 'login' => $login]))
            ->toBe('secondlife:///app/teleport/hg.example.org:8002:My' . Destination::SPACE . 'Region/10/20/30')
            ->and(Destination::format($there, Destination::AUTO, context: ['agent' => TP_VIEWER, 'splash' => true, 'login' => $login]))
            ->toBe('secondlife:///app/teleport/hg.example.org:8002:My' . Destination::SPACE . 'Region/10/20/30');
    });

    test('out of a viewer: hop, for the grid itself too', function () use ($login, $here, $there) {
        expect(Destination::format($here, Destination::AUTO, context: ['agent' => '', 'login' => $login]))
            ->toBe('hop://ursull.magiiic.dev:8012/Welcome/128/128/25')
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

        expect([$viewer, $browser])->toBe(['secondlife://Welcome/128/128/25', 'hop://ursull.magiiic.dev:8012/Welcome/128/128/25']);
    });

    test('of a grid: its login tells its own places', function () {
        $grid = OpenSim\Models\Grid::find((new OpenSim\Testing\Machine())->environment, 'demo');
        $login = (string) Destination::loginParts((string) $grid->loginUrl())['host'];

        expect($grid->link('Welcome/128/128/25', TP_VIEWER))
            ->toBe('secondlife://Welcome/128/128/25')
            ->and($grid->link('hg.example.org:8002/Far/1/2/3', TP_VIEWER))
            ->toBe('secondlife:///app/teleport/hg.example.org:8002:Far/1/2/3')
            ->and($grid->link('Welcome/128/128/25', TP_BROWSER))
            ->toStartWith("hop://$login:");
    });

    test('tell a viewer by its user agent', function () {
        expect(Destination::isViewer(TP_VIEWER))->toBeTrue()->and(Destination::isViewer(TP_BROWSER))->toBeFalse();
    });
});
