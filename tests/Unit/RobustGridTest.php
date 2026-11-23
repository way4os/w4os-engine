<?php
/**
 * The answers of the grid service of a Robust to the requests about regions.
 */

use W4OS\Engine\Provisioning\RobustGrid;

describe('RobustGrid answers', function () {
    test('give the place of each region', function () {
        $xml =
            '<?xml version="1.0"?><ServerResponse>' .
            '<region0 type="List"><uuid>a</uuid><locX>256000</locX><locY>256000</locY><sizeX>256</sizeX><sizeY>256</sizeY><regionName>Sim1</regionName></region0>' .
            '<region1 type="List"><uuid>b</uuid><locX>257024</locX><locY>256000</locY><sizeX>512</sizeX><sizeY>512</sizeY><regionName>Big</regionName></region1>' .
            '</ServerResponse>';

        expect(RobustGrid::regions($xml))->toBe([[1000, 1000, 1, 1], [1004, 1000, 2, 2]]);
    });

    test('say no region when the grid has none or does not answer', function () {
        expect(
            RobustGrid::regions('<?xml version="1.0"?><ServerResponse><result>null</result></ServerResponse>'),
        )->toBe([]);
        expect(RobustGrid::regions(null))->toBe([]);
        expect(RobustGrid::regions('not xml'))->toBe([]);
    });
});

describe('RobustGrid region by name', function () {
    test('tells a region that exists from one that does not', function () {
        expect(
            RobustGrid::named(
                '<?xml version="1.0"?><ServerResponse><result type="List"><uuid>a</uuid><regionName>Sim1</regionName></result></ServerResponse>',
            ),
        )->toBeTrue();
        expect(
            RobustGrid::named('<?xml version="1.0"?><ServerResponse><result>null</result></ServerResponse>'),
        )->toBeFalse();
        expect(RobustGrid::named('not xml'))->toBeFalse();
    });
});

test('reads the registered region UUID', function () {
    expect(RobustGrid::uuidOf('<ServerResponse><result type="List"><uuid>aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee</uuid></result></ServerResponse>'))
        ->toBe('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')
        ->and(RobustGrid::uuidOf('<ServerResponse><result>null</result></ServerResponse>'))->toBeFalse()
        ->and(RobustGrid::uuidOf(null))->toBeNull()
        ->and(RobustGrid::uuidOf('invalid XML'))->toBeNull();
});


test('reads the name registered for a UUID', function () {
    expect(RobustGrid::nameOf('<ServerResponse><result type="List"><uuid>aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee</uuid><regionName>Existing Garden</regionName></result></ServerResponse>'))
        ->toBe('Existing Garden')
        ->and(RobustGrid::nameOf('<ServerResponse><result>null</result></ServerResponse>'))->toBeFalse()
        ->and(RobustGrid::nameOf(null))->toBeNull();
});

describe('RobustGrid region record', function () {
    test('is what the grid keeps of a region: UUID, name, place in blocks, size, owner, the simulator that registered it and its address, flags', function () {
        $xml = '<?xml version="1.0"?><ServerResponse><result type="List"><uuid>f38edb72-7eb7-4561-a1bd-5741675b41e3</uuid>'
            . '<locX>2051072</locX><locY>2051328</locY><sizeX>512</sizeX><sizeY>512</sizeY><regionName>Welcome</regionName><flags>1045</flags>'
            . '<serverIP>192.168.8.69</serverIP><serverHttpPort>9010</serverHttpPort><serverURI>http://192.168.8.69:9010/</serverURI>'
            . '<serverPort>9011</serverPort><owner_uuid>a98c9275-c218-4910-a890-2864ab98a183</owner_uuid><Token></Token></result></ServerResponse>';

        expect(RobustGrid::recordOf($xml))->toBe([
            'uuid' => 'f38edb72-7eb7-4561-a1bd-5741675b41e3',
            'name' => 'Welcome',
            'location' => '8012,8013',
            'size' => 512,
            'owner' => 'a98c9275-c218-4910-a890-2864ab98a183',
            'server' => 'http://192.168.8.69:9010/',
            'address' => '192.168.8.69',
            'flags' => 1045,
        ])
            ->and(RobustGrid::recordOf('<?xml version="1.0"?><ServerResponse><result>null</result></ServerResponse>'))->toBeFalse()
            ->and(RobustGrid::recordOf(null))->toBeNull();
    });

    test('says no simulator answers at an address that is not one', function () {
        expect(RobustGrid::serving('not an address'))->toBeFalse();
    });
});
