<?php

use OpenSim\Config\GridConfig;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Region;

/**
 * A region as the config of the simulators describes it: the same object as the one of the grid, what differs is what it can answer.
 */

/**
 * A grid folder with two simulators, each with its regions folder.
 *
 * @return array{0:string,1:GridConfig} the folder and its config
 */
function region_config_grid(): array
{
    $dir = test_tmp() . '/regions-' . bin2hex(random_bytes(4));
    mkdir("$dir/sims/alpha/regions", 0o755, true);
    mkdir("$dir/sims/beta/regions", 0o755, true);
    file_put_contents("$dir/sims/alpha.ini", "[Startup]\nregionload_regionsdir = \"$dir/sims/alpha/regions\"\n");
    file_put_contents("$dir/sims/beta.ini", "[Startup]\nregionload_regionsdir = \"$dir/sims/beta/regions\"\n");
    file_put_contents(
        "$dir/sims/alpha/regions/Plaza.ini",
        "[Plaza]\nRegionUUID = 607C44E9-3D01-45EB-A07E-937DC72DBADB\nLocation = 1200,1201\nSizeX = 512\nInternalPort = 8015\nExternalHostName = \"play.example.org\"\n",
    );
    file_put_contents(
        "$dir/sims/alpha/regions/Far.ini.disabled",
        "[Far]\nRegionUUID = 11111111-2222-4333-8444-555555555555\nLocation = 10,10\n",
    );
    file_put_contents("$dir/sims/beta/regions/Shore.ini", "[Shore]\nLocation = 5,6\n");

    return [$dir, GridConfig::fromFolder($dir)];
}

describe('regions of a simulator', function () {
    test('are the ones of the simulators asked, by the address the grid has of them, named as their files here say', function () {
        [, $config] = region_config_grid();
        $pdo = new \OpenSim\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $pdo->exec(
            'CREATE TABLE regions (uuid VARCHAR(36) PRIMARY KEY, regionHandle BIGINT, regionName VARCHAR(128), locX INT, locY INT, sizeX INT DEFAULT 0, sizeY INT DEFAULT 0, serverIP VARCHAR(64), serverPort INT, serverURI VARCHAR(255), flags INT DEFAULT 0, last_seen INT DEFAULT 0, PrincipalID CHAR(36), access INT DEFAULT 21, ScopeID CHAR(36), regionMapTexture VARCHAR(36))',
        );
        $pdo->exec("INSERT INTO regions (uuid, regionName, locX, locY, serverURI, flags) VALUES
            ('607c44e9-3d01-45eb-a07e-937dc72dbadb', 'Plaza', 307200, 307456, 'http://10.0.0.1:9000/', 0),
            ('aaaaaaaa-0000-0000-0000-000000000001', 'Welcome', 256000, 256000, 'http://10.0.0.2:9010/', 0),
            ('aaaaaaaa-0000-0000-0000-000000000002', 'Far', 0, 0, 'http://other.example.org:8002/', 512)");
        \OpenSim\Data\Connections::using(static fn() => $pdo);
        try {
            $names = static fn(string $simulator) => array_column(
                Region::all($config, ['filters' => ['simulator' => $simulator]])->array(),
                'name',
            );
            $all = Region::all($config);

            expect([$names('10.0.0.1:9000'), $names('10.0.0.2:9010'), $names('10.0.0.1:9000,10.0.0.2:9010'), $names('nowhere:1')])
                ->toBe([['Plaza'], ['Welcome'], ['Plaza', 'Welcome'], []])
                // The simulators there are, the one whose files are here by their name: what the filter offers
                ->and($all->available()['simulator'])
                ->toBe([['value' => '10.0.0.2:9010', 'label' => '10.0.0.2:9010'], ['value' => '10.0.0.1:9000', 'label' => 'alpha']])
                ->and(array_column($all->array(), 'serverName', 'name'))
                ->toBe(['Plaza' => 'alpha', 'Welcome' => '10.0.0.2:9010'])
                ->and(array_column($all->array(), 'server', 'name'))
                ->toBe(['Plaza' => '10.0.0.1:9000', 'Welcome' => '10.0.0.2:9010']);
        } finally {
            \OpenSim\Data\Connections::using(null);
        }
    });
});

describe('region of the config', function () {
    test('is read from its file: name, place, size, port, address, simulator', function () {
        [, $config] = region_config_grid();
        $plaza = array_values(
            array_filter(Region::configured($config), static fn(Region $r): bool => $r->name() === 'Plaza'),
        )[0];

        expect($plaza->sources())
            ->toBe(['config'])
            ->and($plaza->in('grid'))
            ->toBeFalse()
            ->and($plaza->uuid())
            ->toBe('607c44e9-3d01-45eb-a07e-937dc72dbadb')
            ->and([$plaza->x(), $plaza->y(), $plaza->width(), $plaza->height()])
            ->toBe([1200, 1201, 512, 512])
            ->and($plaza->internalPort())
            ->toBe(8015)
            ->and($plaza->externalHost())
            ->toBe('play.example.org')
            ->and($plaza->simulator())
            ->toBe('alpha')
            ->and($plaza->slug())
            ->toBe('plaza')
            ->and($plaza->isEnabled())
            ->toBeTrue()
            // What only the grid knows is not known
            ->and($plaza->flags())
            ->toBe(0)
            ->and($plaza->isOnline())
            ->toBeFalse()
            ->and($plaza->detail())
            ->toMatchArray(['simulator' => 'alpha', 'enabled' => true, 'sources' => ['config']]);
    });

    test('are all the files of the simulators, the disabled ones too, or the ones of a simulator', function () {
        [, $config] = region_config_grid();

        expect(array_map(static fn(Region $r): string => $r->name(), Region::configured($config)))
            ->toBe(['Far', 'Plaza', 'Shore'])
            ->and(array_map(static fn(Region $r): string => $r->name(), Region::configured($config, 'beta')))
            ->toBe(['Shore'])
            ->and(array_map(static fn(Region $r): bool => $r->isEnabled(), Region::configured($config, 'alpha')))
            ->toBe([false, true])
            // A region without a UUID in its file has none, and an id made of its place
            ->and(Region::configured($config, 'beta')[0]->uuid())
            ->toBeNull()
            ->and(Region::configured($config, 'beta')[0]->id())
            ->toBe('beta:Shore');
    });

    test('is disabled by renaming its file, and enabled again, never over a region of the same name', function () {
        [$dir, $config] = region_config_grid();
        $plaza = Region::configured($config, 'alpha')[1];

        $plaza->disable();
        expect(is_file("$dir/sims/alpha/regions/Plaza.ini.disabled"))
            ->toBeTrue()
            ->and($plaza->isEnabled())
            ->toBeFalse();

        $plaza->enable();
        expect(is_file("$dir/sims/alpha/regions/Plaza.ini"))->toBeTrue()->and($plaza->isEnabled())->toBeTrue();

        // Another file took its name while it was disabled
        $plaza->disable();
        file_put_contents("$dir/sims/alpha/regions/Plaza.ini", "[Plaza]\n");
        expect(fn() => $plaza->enable())->toThrow(ChannelUnavailable::class);
    });

    test('that the config does not know cannot be enabled or disabled', function () {
        [, $config] = region_config_grid();
        $region = Region::fromSearch($config, [
            'regionname' => 'Elsewhere',
            'regionuuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
        ]);

        expect($region->sources())
            ->toBe(['search'])
            ->and($region->name())
            ->toBe('Elsewhere')
            ->and($region->uuid())
            ->toBe('aaaaaaaa-0000-4000-8000-000000000001')
            ->and(fn() => $region->disable())
            ->toThrow(ChannelUnavailable::class)
            ->and(fn() => $region->enable())
            ->toThrow(ChannelUnavailable::class);
    });
});

describe('regions of the grid and the config together', function () {
    test(
        'are the ones the grid knows with their file, then the ones only the config knows; the reason when the grid cannot be read',
        function () {
            [, $config] = region_config_grid();
            $merged = Region::merged($config);

            // No database to read in this folder: only what is configured here is known, and it is said
            expect($merged->problem())
                ->not->toBeNull()
                ->and($merged->total())
                ->toBe(3)
                ->and(array_map(static fn(Region $r): string => $r->name(), $merged->items()))
                ->toBe(['Far', 'Plaza', 'Shore'])
                ->and(
                    array_map(
                        static fn(Region $r): string => $r->name(),
                        Region::merged($config, ['simulator' => 'beta'])->items(),
                    ),
                )
                ->toBe(['Shore'])
                ->and(
                    array_map(
                        static fn(Region $r): string => $r->name(),
                        Region::merged($config, ['search' => 'plaz'])->items(),
                    ),
                )
                ->toBe(['Plaza'])
                // A filter on what only the grid knows (it is online) leaves none of the ones it does not know
                ->and(Region::merged($config, ['filters' => ['status' => 'online']])->total())
                ->toBe(0)
                ->and(count(Region::merged($config, ['perPage' => 2, 'page' => 2])->items()))
                ->toBe(1);
        },
    );
});

describe('region by its key', function () {
    test('is found in the config by its UUID or by its simulator and name', function () {
        [, $config] = region_config_grid();

        expect(Region::locate($config, '607C44E9-3D01-45EB-A07E-937DC72DBADB')?->name())
            ->toBe('Plaza')
            ->and(Region::locate($config, 'beta:Shore')?->simulator())
            ->toBe('beta')
            ->and(Region::locate($config, 'beta:Nowhere'))
            ->toBeNull();
    });
});
