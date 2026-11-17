<?php

declare(strict_types=1);

use OpenSim\Api\Access;
use OpenSim\Config\GridConfig;
use OpenSim\Data\OSPDO;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Models\Avatar;

/**
 * The bans of an avatar: hard, on its account (it cannot log in, its name stays taken, its UUID kept), and in the lists of the
 * estates, all of them or some. The bans of a region or a parcel are theirs, not the avatar's.
 */

const BANNED_ADA = '11111111-1111-1111-1111-111111111111';

/** The avatars, and two estates of the grid in the same database (its Robust serves them). */
function ban_world(): array
{
    $pdo = avatar_database();
    $pdo->exec('CREATE TABLE estate_settings (EstateID INTEGER PRIMARY KEY, EstateName VARCHAR(64), EstateOwner CHAR(36), ParentEstateID INT DEFAULT 0, PublicAccess INT DEFAULT 1, DenyAnonymous INT DEFAULT 0, PricePerMeter INT DEFAULT 1, AllowEnviromentOverride INT DEFAULT 0)');
    foreach (['estate_map (RegionID CHAR(36), EstateID INT)', 'estate_managers (EstateID INT, uuid CHAR(36))', 'estate_users (EstateID INT, uuid CHAR(36))', 'estate_groups (EstateID INT, uuid CHAR(36))'] as $table) {
        $pdo->exec("CREATE TABLE $table");
    }
    $pdo->exec('CREATE TABLE estateban (EstateID INT, bannedUUID CHAR(36), bannedIp VARCHAR(16), bannedIpHostMask VARCHAR(16), bannedNameMask VARCHAR(255) DEFAULT NULL)');
    $pdo->exec("INSERT INTO estate_settings (EstateID, EstateName, EstateOwner) VALUES (101, 'Main', '22222222-2222-2222-2222-222222222222'), (102, 'Shore', '22222222-2222-2222-2222-222222222222')");
    avatars_in($pdo);

    return [$pdo, avatar_grid("[ServiceList]\n EstateDataService = \"x\"\n")];
}

/** @return array{0:int,1:int} the UserLevel and the UserFlags of an account */
function ban_columns(OSPDO $pdo, string $uuid): array
{
    $row = $pdo->get_results('SELECT UserLevel, UserFlags FROM UserAccounts WHERE PrincipalID = :id', ['id' => $uuid], \PDO::FETCH_ASSOC)[0];

    return [(int) $row['UserLevel'], (int) $row['UserFlags']];
}

describe('A hard ban', function () {
    test('keeps the account out of the grid with a flag of the engine, lifted by an unban only, its name and its UUID kept', function () {
        [$pdo, $config] = ban_world();
        Avatar::find($config, BANNED_ADA)->update(['banned' => true]);
        $banned = Avatar::find($config, BANNED_ADA);
        $columns = ban_columns($pdo, BANNED_ADA);
        try {
            $banned->update(['status' => 'active']);
        } catch (InvalidValue $e) {
            $enable = $e->reason;
        }
        try {
            Avatar::create($config, ['name' => 'Ada Love', 'email' => 'other@example.org', 'password' => 'a secret']);
        } catch (InvalidValue $e) {
            $again = $e->reason;
        }
        $banned->update(['banned' => false]);

        expect([$banned->detail()['status'], $banned->detail()['banned']])
            ->toBe(['banned', true])
            // Below 0, the login of Robust refuses it; bit 13 of UserFlags tells the ban apart from a disabled account
            ->and([$columns[0] < 0, ($columns[1] & Avatar::BANNED_FLAG) !== 0, Avatar::BANNED_FLAG])
            ->toBe([true, true, 1 << 13])
            ->and([$enable ?? null, $again ?? null])
            ->toBe(['account-banned', 'name-taken'])
            ->and(Avatar::find($config, BANNED_ADA)->detail()['status'])
            ->toBe('active')
            ->and(ban_columns($pdo, BANNED_ADA))
            ->toBe([0, 0]);
    });
});

describe('A banned avatar', function () {
    test('is refused at the sign-in, its UserLevel below 0 in the database while its level stays the one it has back', function () {
        $pdo = signin_database();
        avatars_in($pdo);
        $config = avatar_grid();
        Avatar::find($config, BANNED_ADA)->update(['banned' => true]);
        $refused = (new OpenSim\SignIn\DatabaseAuthenticator($config))->authenticate('Ada Love', 'ada-secret');

        // Robust refuses a UserLevel below MinLoginLevel (0); the API says the level it has once unbanned
        expect([ban_columns($pdo, BANNED_ADA)[0], Avatar::find($config, BANNED_ADA)->detail()['level']])
            ->toBe([-1, 0])
            ->and([$refused->isGranted(), $refused->code])
            ->toBe([false, 'account-banned']);
    });
});

describe('The bans of the estates', function () {
    test('put an avatar in the lists of every estate or of some, and take it out', function () {
        [$pdo, $config] = ban_world();
        $ada = static fn(): Avatar => Avatar::find($config, BANNED_ADA);
        $ada()->update(['estateBanned' => true]);
        $all = [$ada()->detail()['estateBans'], array_column(Avatar::all($config)->array(), 'estateBanned', 'name')];
        $ada()->update(['estateBans' => ['102']]);
        $some = $ada()->detail()['estateBans'];
        $ada()->update(['estateBanned' => false]);

        expect($all)
            ->toBe([['101', '102'], ['Ada Love' => true, 'Bob Root' => false, 'Cy Gone' => false]])
            ->and($some)
            ->toBe(['102'])
            // Its account is not touched: it can still log in, to the regions of no estate that bans it
            ->and([$ada()->detail()['estateBans'], $ada()->detail()['status']])
            ->toBe([[], 'active']);
    });
});

describe('The list of the avatars', function () {
    test('shows a hard ban in red, a ban of estates apart, with their actions for a god, asked first', function () {
        $listing = Avatar::listing();
        $columns = $listing->columns;
        $indicators = array_column(end($columns)['indicators'], null, 'name');
        $actions = array_column($listing->actions, null, 'name');

        expect(array_column($indicators['status']['states'], 'tone', 'value'))
            ->toMatchArray(['banned' => 'failed'])
            ->and(array_column($indicators['estateBanned']['states'], 'tone', 'value'))
            ->toBe([true => 'warning'])
            ->and(array_map(static fn(string $name): array => [$actions[$name]['body'], $actions[$name]['level'] ?? null, isset($actions[$name]['ask'])], ['ban', 'unban', 'estateBan', 'estateUnban']))
            ->toBe([
                [['banned' => true], 200, true],
                [['banned' => false], 200, false],
                [['estateBanned' => true], 200, true],
                [['estateBanned' => false], 200, false],
            ])
            // Enabled again only by an unban
            ->and($actions['enable']['when']['status'])
            ->not->toContain('banned')
            ->and([Access::fieldLevel('avatars', 'banned'), Access::fieldLevel('avatars', 'estateBanned'), Access::fieldLevel('avatars', 'estateBans')])
            ->toBe([200, 200, 200]);
    });

    test('filters the hard bans', function () {
        [, $config] = ban_world();
        Avatar::find($config, BANNED_ADA)->update(['banned' => true]);

        expect(array_column(Avatar::all($config, ['filters' => ['status' => 'banned']])->array(), 'name'))
            ->toBe(['Ada Love'])
            ->and(array_column(Avatar::all($config, ['filters' => ['status' => 'disabled']])->array(), 'name'))
            ->toBe(['Cy Gone']);
    });
});
