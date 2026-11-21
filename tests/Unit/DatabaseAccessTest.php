<?php

declare(strict_types=1);

/**
 * The access to the databases an application gives (a site that has the engine in its own folder: its host, its port, its user,
 * its password), applied to every database of a grid, each keeping its own name; never said.
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Config\Layout;
use W4OS\Engine\Data\DatabaseAccess;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Runtime\Environment;

describe('The access to the databases a site gives', function () {
    test('replaces the host, the port and the credentials of a connection string, its database and the rest kept', function () {
        $access = new DatabaseAccess('db.site.example', 3307, 'site', 's3cret');

        expect($access->applyTo('Data Source=localhost;Database=robust;User ID=opensim;Password=old;Old Guids=true;SslMode=None;'))
            ->toBe('Data Source=db.site.example:3307;Database=robust;User ID=site;Password=s3cret;Old Guids=true;SslMode=None;')
            ->and((new DatabaseAccess('db.site.example'))->applyTo('Data Source=localhost:3306;Database=search;User ID=opensim;Password=old;'))
            // What it does not give stays: here the credentials
            ->toBe('Data Source=db.site.example;Database=search;User ID=opensim;Password=old;')
            // A database of a file is no server
            ->and($access->applyTo('URI=file:/var/lib/opensim/regions.db,version=3'))
            ->toBe('URI=file:/var/lib/opensim/regions.db,version=3')
            // Never said
            ->and(print_r($access, true) . var_export($access->safe(), true))
            ->not->toContain('s3cret');
    });

    test('is applied to every database of a grid by its config, whatever the file or the section', function () {
        $site = sys_get_temp_dir() . '/site-' . bin2hex(random_bytes(4));
        $access = new DatabaseAccess('db.site.example', 3307, 'site', 's3cret');
        $environment = Environment::system(new Layout(['EtcRoot' => "$site/etc"]), databases: $access);
        $config = new GridConfig([
            'Robust' => [
                'DatabaseService' => ['ConnectionString' => 'Data Source=localhost;Database=robust;User ID=opensim;Password=old;'],
                'UserProfilesService' => ['ConnectionString' => 'Data Source=localhost;Database=profiles;User ID=opensim;Password=old;'],
            ],
            'OpenSim' => ['DatabaseService' => ['ConnectionString' => 'Data Source=localhost;Database=opensim;User ID=opensim;Password=old;']],
            'Helpers' => ['Helpers' => ['SearchConnectionString' => 'Data Source=localhost;Database=ossearch;User ID=search;Password=old;']],
        ]);
        $applied = Grid::described($environment, ['nick' => 'far', 'remote' => true], $config)->config();
        $mine = Grid::described(Environment::system(new Layout(['EtcRoot' => "$site/etc"])), ['nick' => 'far', 'remote' => true], $config)->config();

        expect($applied->get('Robust.DatabaseService.ConnectionString'))
            ->toBe('Data Source=db.site.example:3307;Database=robust;User ID=site;Password=s3cret;')
            ->and($applied->get('Robust.UserProfilesService.ConnectionString'))
            ->toContain('Database=profiles;User ID=site;')
            ->and($applied->get('OpenSim.DatabaseService.ConnectionString'))
            ->toContain('Data Source=db.site.example:3307;Database=opensim;')
            ->and($applied->get('Helpers.Helpers.SearchConnectionString'))
            ->toContain('Database=ossearch;User ID=site;')
            // Without an access given, the config as it is
            ->and($mine)
            ->toBe($config);
    });
});

describe('The API of a site with its access to the databases', function () {
    test('reads the grids with it, the ones of a folder too', function () {
        $machine = new W4OS\Engine\Testing\Machine();
        $robust = $machine->grid . '/Robust.HG.ini';
        file_put_contents($robust, (string) file_get_contents($robust) . "\n[DatabaseService]\n    ConnectionString = \"Data Source=localhost;Database=robust;User ID=opensim;Password=old;\"\n");
        $environment = new Environment($machine->environment->layout, $machine->environment->processes, $machine->environment->launcher, $machine->environment->consoles, $machine->environment->clock, $machine->environment->reporter, databases: new DatabaseAccess('db.site.example', null, 'site', 's3cret'));
        $grid = Grid::find($environment, 'demo');

        expect($grid->config()->get('Robust.DatabaseService.ConnectionString') ?? '')
            ->toContain('Data Source=db.site.example;')
            ->and((string) $grid->connection())
            ->toContain('User ID=site;');
    });
});
