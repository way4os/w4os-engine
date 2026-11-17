<?php

declare(strict_types=1);

/**
 * What a region lets change: its roles (the flags a region can be given) and its owner (the owner of its estate). Its name, its place
 * and its size are its file's, changed by its operator. A change goes through the console of the instance that holds it (Robust for
 * the flags, the simulator for the estate) when it can be reached, else to the database when that is safe.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Console\InstanceConsoleService;
use OpenSim\Data\Connections;
use OpenSim\Data\OSPDO;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Models\Region;

const CHANGE_WELCOME = 'aaaaaaaa-0000-4000-8000-000000000001';
const CHANGE_GARDEN = 'aaaaaaaa-0000-4000-8000-000000000002';
const CHANGE_ADA = '11111111-1111-4111-8111-111111111111';
const CHANGE_BOB = '22222222-2222-4222-8222-222222222222';

/**
 * A grid of this machine: its Robust gives Welcome its roles, its simulator demo.alpha runs Welcome and Garden; one database holds the
 * regions, the accounts and the estates (Welcome alone in its estate, Garden with another region in its own).
 *
 * @return array{0:GridConfig,1:OSPDO,2:string} the config, the database, the Robust config file
 */
function change_grid(): array
{
    $dir = test_tmp() . '/change-' . bin2hex(random_bytes(4)) . '/demo';
    mkdir("$dir/sims/demo.alpha/regions", 0o755, true);
    $db = "[DatabaseService]\nStorageProvider = \"OpenSim.Data.MySQL.dll\"\nConnectionString = \"Data Source=localhost;Database=demo;User ID=os;Password=pw;\"\n";
    file_put_contents(
        "$dir/Robust.ini",
        "[GridInfoService]\ngridnick = \"demo\"\n[GridService]\nRegion_Welcome = \"DefaultRegion, Persistent\"\n$db",
    );
    file_put_contents("$dir/sims/demo.alpha.ini", "[Startup]\nregionload_regionsdir = \"$dir/sims/demo.alpha/regions\"\n$db");
    file_put_contents("$dir/sims/demo.alpha/regions/Welcome.ini", "[Welcome]\nRegionUUID = " . CHANGE_WELCOME . "\nLocation = 1000,1000\n");
    file_put_contents("$dir/sims/demo.alpha/regions/Garden.ini", "[Garden]\nRegionUUID = " . CHANGE_GARDEN . "\nLocation = 1001,1000\n");

    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec(
        'CREATE TABLE regions (uuid VARCHAR(36) PRIMARY KEY, regionHandle BIGINT, regionName VARCHAR(128), locX INT, locY INT, sizeX INT DEFAULT 0, sizeY INT DEFAULT 0, serverIP VARCHAR(64), serverPort INT, serverURI VARCHAR(255), flags INT DEFAULT 0, last_seen INT DEFAULT 0, owner_uuid CHAR(36), access INT DEFAULT 21, ScopeID CHAR(36), regionMapTexture VARCHAR(36))',
    );
    // Welcome: DefaultRegion, Persistent, online; Garden: online
    $pdo->exec("INSERT INTO regions (uuid, regionName, locX, locY, serverURI, flags, owner_uuid) VALUES
        ('" . CHANGE_WELCOME . "', 'Welcome', 256000, 256000, 'http://10.0.0.1:9000/', 21, '" . CHANGE_ADA . "'),
        ('" . CHANGE_GARDEN . "', 'Garden', 256256, 256000, 'http://10.0.0.1:9000/', 4, '" . CHANGE_ADA . "')");
    $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), UserLevel INT DEFAULT 0, UserFlags INT DEFAULT 0, Email VARCHAR(64), Created INT DEFAULT 0, UserTitle VARCHAR(64), active INT DEFAULT 1, ScopeID CHAR(36))');
    $pdo->exec("CREATE TABLE GridUser (UserID VARCHAR(255) PRIMARY KEY, HomeRegionID CHAR(36), LastRegionID CHAR(36), Online CHAR(5) DEFAULT 'false', Login CHAR(16) DEFAULT '0', Logout CHAR(16) DEFAULT '0')");
    $pdo->exec('CREATE TABLE userprofile (useruuid CHAR(36) PRIMARY KEY, profileImage CHAR(36), profileAboutText TEXT, profileURL VARCHAR(255), profilePartner CHAR(36), profileWantToText TEXT, profileSkillsText TEXT, profileLanguages TEXT, profileFirstImage CHAR(36), profileFirstText TEXT, profileWantToMask INT DEFAULT 0, profileSkillsMask INT DEFAULT 0)');
    $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName) VALUES ('" . CHANGE_ADA . "', 'Ada', 'Love'), ('" . CHANGE_BOB . "', 'Bob', 'Root')");
    $pdo->exec('CREATE TABLE estate_settings (EstateID INTEGER PRIMARY KEY, EstateName VARCHAR(64), EstateOwner CHAR(36), ParentEstateID INT DEFAULT 0, PublicAccess INT DEFAULT 1)');
    $pdo->exec('CREATE TABLE estate_map (RegionID CHAR(36) PRIMARY KEY, EstateID INT)');
    foreach (['estate_managers', 'estate_users', 'estate_groups'] as $table) {
        $pdo->exec("CREATE TABLE $table (EstateID INT, uuid CHAR(36))");
    }
    $pdo->exec('CREATE TABLE estateban (EstateID INT, bannedUUID CHAR(36), bannedIp VARCHAR(16), bannedIpHostMask VARCHAR(16), bannedNameMask VARCHAR(255))');
    $pdo->exec("INSERT INTO estate_settings (EstateID, EstateName, EstateOwner) VALUES (101, 'Welcome Estate', '" . CHANGE_ADA . "'), (102, 'Shared Estate', '" . CHANGE_ADA . "')");
    $pdo->exec("INSERT INTO estate_map VALUES ('" . CHANGE_WELCOME . "', 101), ('" . CHANGE_GARDEN . "', 102), ('bbbbbbbb-0000-4000-8000-000000000003', 102)");
    Connections::using(static fn() => $pdo);

    return [GridConfig::fromFolder($dir), $pdo, "$dir/Robust.ini"];
}

/**
 * Lines sent to the consoles, by instance; what a console answers (true: reached).
 *
 * @param array<string,list<string>> $sent
 */
function change_consoles(array &$sent, bool $reached): void
{
    InstanceConsoleService::sendThrough(static function (string $instance, string $lines) use (&$sent, $reached): bool {
        $sent[$instance][] = $lines;

        return $reached;
    });
}

afterEach(function () {
    InstanceConsoleService::sendThrough(null);
    Connections::using(null);
});

describe('The roles of a region', function () {
    test('are changed through the console of Robust, the flags it sets itself left as they are', function () {
        [$config] = change_grid();
        $sent = [];
        change_consoles($sent, true);
        $welcome = Region::find($config, 'Welcome');
        $welcome->update(['roles' => ['FallbackRegion', 'Persistent', 'RegionOnline']]);

        expect($sent)->toBe(['demo' => ['set region flags "Welcome" +FallbackRegion,-DefaultRegion']]);
    });

    test('are written in the database of the grid when the console of Robust cannot be reached, as its console does', function () {
        [$config, $pdo] = change_grid();
        $sent = [];
        change_consoles($sent, false);
        Region::find($config, 'Welcome')->update(['roles' => ['FallbackRegion', 'Persistent']]);

        expect(Region::find($config, 'Welcome')->detail()['roles'])
            ->toBe(['FallbackRegion', 'Persistent'])
            ->and(Region::find($config, 'Welcome')->isOnline())
            ->toBeTrue();
    });

    test('are kept in the config of Robust when it gives them, which it reads when it starts: a reminder says so', function () {
        [$config, , $robust] = change_grid();
        $sent = [];
        change_consoles($sent, true);
        $welcome = Region::find($config, 'Welcome');
        $welcome->update(['roles' => ['FallbackRegion', 'Persistent']]);
        $garden = Region::find($config, 'Garden');
        $garden->update(['roles' => ['NoMove']]);

        expect(\OpenSim\Config\ConfigReader::load($robust)['GridService'])
            ->toMatchArray(['Region_Welcome' => 'Persistent, FallbackRegion'])
            ->not->toHaveKey('Region_Garden')
            ->and($welcome->reminders())
            ->toHaveCount(1)
            ->and($welcome->reminders()[0]['code'])
            ->toBe('robust-restart-roles')
            ->and($welcome->reminders()[0]['params'])
            ->toBe(['key' => 'Region_Welcome', 'restart' => 'opensim restart demo'])
            ->and($welcome->reminders()[0]['detail'])
            ->toContain('Robust', 'restart')
            ->and($garden->reminders())
            ->toBe([]);
    });

    test('are given through the console when the config of Robust cannot be written here, the line to add said', function () {
        [$config, , $robust] = change_grid();
        $sent = [];
        change_consoles($sent, true);
        chmod($robust, 0o444);
        try {
            $welcome = Region::find($config, 'Welcome');
            $welcome->update(['roles' => ['FallbackRegion', 'Persistent']]);
        } finally {
            chmod($robust, 0o644);
        }

        expect($sent['demo'] ?? [])
            ->toHaveCount(1)
            // A code and its values for a site to say it in its language, the English text as its detail
            ->and($welcome->reminders()[0])
            ->toMatchArray([
                'code' => 'robust-config-line',
                'params' => ['file' => $robust, 'section' => 'GridService', 'line' => 'Region_Welcome = "Persistent, FallbackRegion"', 'restart' => 'opensim restart demo'],
            ])
            ->and($welcome->reminders()[0]['detail'])
            ->toContain("$robust cannot be written", '[GridService]')
            ->and((string) file_get_contents($robust))
            ->toContain('Region_Welcome = "DefaultRegion, Persistent"');
    });

    test('refuse a flag that is not one, and a change of the name, the place or the size', function () {
        [$config] = change_grid();
        $welcome = Region::find($config, 'Welcome');

        expect(fn() => $welcome->update(['roles' => ['Famous']]))
            ->toThrow(fn(InvalidValue $e) => expect($e->reason)->toBe('flag-unknown'))
            ->and(fn() => $welcome->update(['name' => 'Hello']))
            ->toThrow(fn(InvalidValue $e) => expect($e->reason)->toBe('field-read-only'))
            ->and(fn() => $welcome->update(['location' => '1,1']))
            ->toThrow(fn(InvalidValue $e) => expect($e->reason)->toBe('field-read-only'))
            ->and(fn() => $welcome->update(['size' => 512]))
            ->toThrow(fn(InvalidValue $e) => expect($e->reason)->toBe('field-read-only'))
            ->and(fn() => $welcome->update(['color' => 'red']))
            ->toThrow(fn(InvalidValue $e) => expect($e->reason)->toBe('field-unknown'));
    });
});

describe('Whether a region is enabled', function () {
    test('is refused before anything is touched when its file cannot be renamed here, the folder named', function () {
        [$config] = change_grid();
        $garden = Region::find($config, 'Garden');
        $folder = dirname($garden->file());
        chmod($folder, 0o555);
        try {
            $refused = null;
            try {
                $garden->disable();
            } catch (InvalidValue $e) {
                $refused = $e;
            }
        } finally {
            chmod($folder, 0o755);
        }

        expect($refused?->reason)
            ->toBe('config-not-writable')
            // Forbidden to this process, not a wrong request
            ->and(OpenSim\Api\Problem::from($refused)->status)
            ->toBe(403)
            ->and($refused?->getMessage())
            ->toContain($folder)
            ->and(is_file("$folder/Garden.ini"))
            ->toBeTrue();
    });
});

describe('The owner of a region', function () {
    test('is the owner of its estate, changed through the console of its simulator; the list of the grid follows at once', function () {
        [$config, $pdo] = change_grid();
        $sent = [];
        change_consoles($sent, true);
        Region::find($config, 'Welcome')->update(['owner' => CHANGE_BOB]);

        expect($sent)
            ->toBe(['demo.alpha' => ['estate set owner 101 ' . CHANGE_BOB]])
            ->and(Region::find($config, 'Welcome')->detail()['ownerName'])
            ->toBe('Bob Root');
    });

    test('is written in the database of the estate when the simulator is stopped, which it reads when it starts', function () {
        [$config, $pdo] = change_grid();
        $sent = [];
        change_consoles($sent, false);
        Region::find($config, 'Welcome')->update(['owner' => CHANGE_BOB]);

        expect($pdo->get_var('SELECT EstateOwner FROM estate_settings WHERE EstateID = 101'))
            ->toBe(CHANGE_BOB)
            ->and(Region::find($config, 'Welcome')->owner())
            ->toBe(CHANGE_BOB);
    });

    test('is never written in the database of a running simulator, seen running from the link it was started with', function () {
        [$config, $pdo] = change_grid();
        $machine = new OpenSim\Testing\Machine();
        $sims = dirname((string) $config->file('simulators/demo.alpha'));
        @symlink("$sims/demo.alpha.ini", "$sims/started-as.ini");
        $machine->processes->add("dotnet OpenSim.dll -inifile=$sims/started-as.ini");

        expect(OpenSim\Console\InstanceConsoleService::running("$sims/demo.alpha.ini", $machine->environment))->toBeTrue();
    });

    test('is refused for a region whose estate has others (they would change owner too), and for someone the grid does not know', function () {
        [$config] = change_grid();

        expect(fn() => Region::find($config, 'Garden')->update(['owner' => CHANGE_BOB]))
            ->toThrow(fn(InvalidValue $e) => expect($e->reason)->toBe('estate-shared'))
            ->and(fn() => Region::find($config, 'Welcome')->update(['owner' => '33333333-3333-4333-8333-333333333333']))
            ->toThrow(fn(InvalidValue $e) => expect($e->reason)->toBe('owner-unknown'));
    });
});

describe('The API, for a region', function () {
    test('changes its roles and says what is left to do, and refuses its name with a code', function () {
        $machine = new OpenSim\Testing\Machine();
        $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $pdo->exec(
            'CREATE TABLE regions (uuid VARCHAR(36) PRIMARY KEY, regionHandle BIGINT, regionName VARCHAR(128), locX INT, locY INT, sizeX INT DEFAULT 0, sizeY INT DEFAULT 0, serverIP VARCHAR(64), serverPort INT, serverURI VARCHAR(255), flags INT DEFAULT 0, last_seen INT DEFAULT 0, owner_uuid CHAR(36), access INT DEFAULT 21, ScopeID CHAR(36), regionMapTexture VARCHAR(36))',
        );
        $pdo->exec("INSERT INTO regions (uuid, regionName, locX, locY, serverURI, flags) VALUES ('" . CHANGE_WELCOME . "', 'Welcome', 256000, 256000, 'http://10.0.0.1:9000/', 20)");
        Connections::using(static fn() => $pdo);
        $sent = [];
        change_consoles($sent, false);
        $api = new OpenSim\Api\Api($machine->environment);

        $changed = $api->update('regions', CHANGE_WELCOME, ['roles' => ['FallbackRegion', 'Persistent']], 'demo');
        $named = $api->update('regions', CHANGE_WELCOME, ['name' => 'Hello'], 'demo');

        expect([$changed->status, $changed->document['data']['attributes']['roles']])
            ->toBe([200, ['FallbackRegion', 'Persistent']])
            ->and($changed->document['meta']['reminders'][0]['code'])
            ->toBe('robust-restart-roles')
            ->and($changed->document['meta']['reminders'][0]['detail'])
            ->toContain('Region_Welcome', 'opensim restart demo')
            ->and([$named->status, $named->document['code']])
            ->toBe([400, 'field-read-only']);
    });
});

describe('The estate of a region', function () {
    test('is changed through the console of its simulator, by the number of the estate or its id', function () {
        [$config] = change_grid();
        $sent = [];
        change_consoles($sent, true);
        Region::find($config, 'Welcome')->update(['estate' => 'demo.alpha:102']);

        expect($sent)->toBe(['demo.alpha' => ['estate link region 102 ' . CHANGE_WELCOME]]);
    });

    test('is written in the map of the estates when the simulator is stopped, which it reads when it starts', function () {
        [$config, $pdo] = change_grid();
        $sent = [];
        change_consoles($sent, false);
        $welcome = Region::find($config, 'Welcome');
        $welcome->update(['estate' => '102']);

        expect($pdo->get_var('SELECT EstateID FROM estate_map WHERE RegionID = :r', ['r' => CHANGE_WELCOME]))
            ->toBe(102)
            ->and(fn() => Region::find($config, 'Welcome')->update(['estate' => '999']))
            ->toThrow(OpenSim\Exceptions\InvalidValue::class, 'estate');
    });
});

describe('A new estate', function () {
    test('is made by the console of a simulator that runs, else in the database of its estates with the defaults of OpenSimulator', function () {
        [$config, $pdo] = change_grid();
        foreach (['AbuseEmailToEstateOwner', 'DenyAnonymous', 'ResetHomeOnTeleport', 'FixedSun', 'DenyTransacted', 'BlockDwell', 'DenyIdentified', 'AllowVoice', 'UseGlobalTime', 'PricePerMeter', 'TaxFree', 'AllowDirectTeleport', 'RedirectGridX', 'RedirectGridY', 'SunPosition', 'EstateSkipScripts', 'BillableFactor', 'DenyMinors', 'AllowLandmark', 'AllowParcelChanges', 'AllowSetHome'] as $column) {
            $pdo->exec("ALTER TABLE estate_settings ADD COLUMN $column INT DEFAULT 0");
        }
        $pdo->exec("ALTER TABLE estate_settings ADD COLUMN AbuseEmail VARCHAR(255) DEFAULT ''");
        $sent = [];
        change_consoles($sent, true);
        // The console makes it in the database, as OpenSimulator does
        OpenSim\Console\InstanceConsoleService::sendThrough(static function (string $instance, string $lines) use (&$sent, $pdo): bool {
            $sent[$instance][] = $lines;
            $pdo->exec("INSERT INTO estate_settings (EstateID, EstateName, EstateOwner) VALUES (103, 'By Console', '" . CHANGE_BOB . "')");

            return true;
        });
        $console = OpenSim\Models\Estate::create($config, ['name' => 'By Console', 'owner' => CHANGE_BOB, 'simulator' => 'demo.alpha']);
        change_consoles($sent, false);
        $written = OpenSim\Models\Estate::create($config, ['name' => 'Written', 'owner' => CHANGE_BOB, 'simulator' => 'demo.alpha']);
        $row = $pdo->get_results("SELECT * FROM estate_settings WHERE EstateName = 'Written'", [], \PDO::FETCH_ASSOC)[0];

        expect($sent['demo.alpha'][0])
            ->toBe('estate create ' . CHANGE_BOB . ' By Console')
            ->and([$console->number(), $console->owner(), $written->number()])
            ->toBe([103, CHANGE_BOB, 104])
            ->and(array_intersect_key($row, array_flip(['ParentEstateID', 'PublicAccess', 'AllowVoice', 'PricePerMeter', 'AllowDirectTeleport', 'AllowLandmark'])))
            ->toBe(['ParentEstateID' => 1, 'PublicAccess' => 1, 'AllowVoice' => 1, 'PricePerMeter' => 1, 'AllowDirectTeleport' => 1, 'AllowLandmark' => 1])
            ->and(fn() => OpenSim\Models\Estate::create($config, ['name' => '', 'owner' => CHANGE_BOB, 'simulator' => 'demo.alpha']))
            ->toThrow(OpenSim\Exceptions\InvalidValue::class);
    });
});
