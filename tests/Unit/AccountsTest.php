<?php
/**
 * The accounts made from a list, directly in the database of a grid.
 */

use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\AccountImporter;
use W4OS\Engine\Provisioning\AccountList;
use W4OS\Engine\Provisioning\AccountWriter;
use W4OS\Engine\Provisioning\GridPlan;
use W4OS\Engine\Testing\EnvironmentFake;
use W4OS\Engine\Exceptions\ChannelUnavailable;

/** A writer with ids, a time and a salt that follow one another, so the statements can be compared. */
function accounts_writer(): AccountWriter
{
    $n = 0;

    return new AccountWriter(
        function () use (&$n): string {
            $n++;

            return sprintf('00000000-0000-4000-8000-%012d', $n);
        },
        static fn(): int => 1700000000,
        static fn(): string => str_repeat('ab', 16),
    );
}

describe('A list of accounts', function () {
    test('reads a CSV, with or without header', function () {
        $with = AccountList::parse("First Name;Last Name;E-mail;Password\nJane;Doe;jane@example.org;pw1\nJohn;Roe;;\n");
        $without = AccountList::parse("Jane,Doe,jane@example.org,pw1\n\"John\",Roe\n");

        expect($with['errors'])->toBe([]);
        expect($with['accounts'][0])->toBe([
            'first' => 'Jane',
            'last' => 'Doe',
            'email' => 'jane@example.org',
            'password' => 'pw1',
            'password_hash' => '',
            'password_salt' => '',
            'line' => 2,
            'groups' => [],
        ]);
        expect($with['accounts'][1]['email'])->toBe('');
        expect(array_column($without['accounts'], 'last'))->toBe(['Doe', 'Roe']);
    });

    test('reads a CSV of a spreadsheet: its columns in any order, quoted fields over lines, accents, semicolons, the line of each', function () {
        $csv = "email;Last;First;password;password_hash;password_salt\n"
            . "jose@example.org;Perez;Jose;café crème;;\n"
            . "\"anne@example.org\";\"Oneil\";\"Anne\";;5f4dcc3b5aa765d61d8327deb882cf99;abc\n"
            . ";Doe;Jane;\"secret; over\ntwo lines\";;\n"
            . ";Pérez;José;;;\n"
            . ";Roe;;;;\n";
        $list = AccountList::parse($csv);
        $accounts = array_column($list['accounts'], null, 'first');

        expect(array_keys($accounts))->toBe(['Jose', 'Anne', 'Jane'])
            ->and($accounts['Jose'])->toMatchArray(['last' => 'Perez', 'email' => 'jose@example.org', 'password' => 'café crème', 'line' => 2])
            ->and($accounts['Anne'])->toMatchArray(['last' => 'Oneil', 'password_hash' => '5f4dcc3b5aa765d61d8327deb882cf99', 'password_salt' => 'abc', 'line' => 3])
            // A field quoted over two lines is one field: the next record is on line 6
            ->and($accounts['Jane'])->toMatchArray(['password' => "secret; over\ntwo lines", 'line' => 4])
            // The names OpenSimulator takes: letters, digits and -; each line refused told by its number
            ->and($list['errors'])->toHaveCount(2)
            ->and($list['errors'][0])->toStartWith('line 6: name "José Pérez"')
            ->and($list['errors'][1])->toStartWith('line 7: name');
    });

    test('reads JSON, a list or an object', function () {
        $list = AccountList::parse('[{"firstname":"Jane","lastname":"Doe","mail":"jane@example.org"}]');
        $object = AccountList::parse('{"accounts":[{"first":"John","last":"Roe","password":"x"}]}');

        expect($list['accounts'][0]['first'])->toBe('Jane');
        expect($list['accounts'][0]['email'])->toBe('jane@example.org');
        expect($object['accounts'][0]['password'])->toBe('x');
    });

    test('tells each line that cannot be used, and keeps the others', function () {
        $list = AccountList::parse(
            "Jane,Doe\nBad\"Name,Doe\nJohn,Roe,not an email\nJANE,doe\nOk,Fine,ok@example.org\n",
        );

        expect(array_column($list['accounts'], 'first'))->toBe(['Jane', 'Ok']);
        expect($list['errors'])->toHaveCount(3);
        expect($list['errors'][0])->toContain('line 2');
        expect($list['errors'][2])->toContain('twice');
    });

    test('ignores the BOM of a spreadsheet', function () {
        $list = AccountList::parse("\xEF\xBB\xBFfirst,last\nJane,Doe\n");

        expect($list['accounts'][0]['first'])->toBe('Jane');
    });
});

describe('statements of an account', function () {
    test('write it with the tables Robust has, in one transaction', function () {
        $made = accounts_writer()->statements(
            'Jane',
            'Doe',
            'jane@example.org',
            'secret',
            '607c44e9-3d01-45eb-a07e-937dc72dbadb',
        );

        expect($made['id'])->toBe('00000000-0000-4000-8000-000000000001');
        expect($made['sql'])->toStartWith('START TRANSACTION;');
        expect($made['sql'])->toEndWith('COMMIT;');
        foreach (['UserAccounts', 'auth', 'GridUser', 'inventoryfolders', 'inventoryitems'] as $table) {
            expect($made['sql'])->toContain("INSERT INTO $table ");
        }
        expect($made['sql'])->toContain("'Jane', 'Doe', 'jane@example.org'");
        expect($made['sql'])->toContain("'607c44e9-3d01-45eb-a07e-937dc72dbadb', '<128,128,22>'");
    });

    test('moves the home Robust gives from the sea floor, and no other', function () {
        $sql = W4OS\Engine\Provisioning\GridAccounts::raiseHomeSql('11111111-2222-3333-4444-555555555555');

        expect($sql)->toBe(
            "UPDATE GridUser SET HomePosition = '<128,128,22>' WHERE UserID = '11111111-2222-3333-4444-555555555555' AND HomePosition = '<128,128,0>'",
        );
    });

    test('of the first account of a grid is the one of a god, the others and the unknown ones a user', function () {
        expect(W4OS\Engine\Provisioning\GridAccounts::levelFor([]))->toBe(200)
            ->and(W4OS\Engine\Provisioning\GridAccounts::levelFor(['Jane Doe']))->toBe(0)
            ->and(W4OS\Engine\Provisioning\GridAccounts::levelFor(null))->toBe(0);
    });

    test('gives the first account the level of an administrator', function () {
        expect(W4OS\Engine\Provisioning\GridAccounts::levelSql('Jane', 'Doe', W4OS\Engine\Provisioning\GridAccounts::FIRST_LEVEL))->toBe(
            "UPDATE UserAccounts SET UserLevel = 200 WHERE FirstName = 'Jane' AND LastName = 'Doe'",
        );
    });

    test('publishes the first account: a new profile is public ("show in search")', function () {
        expect(W4OS\Engine\Provisioning\GridAccounts::publishSql('Jane', 'Doe'))->toBe(
            "UPDATE UserAccounts SET UserFlags = UserFlags | 1 WHERE FirstName = 'Jane' AND LastName = 'Doe'",
        );
    });

    test('keeps the password as Robust does', function () {
        $salt = str_repeat('ab', 16);
        $made = accounts_writer()->statements('Jane', 'Doe', '', 'secret');

        expect(AccountWriter::passwordHash('secret', $salt))->toBe(md5(md5('secret') . ':' . $salt));
        expect($made['sql'])->toContain("'" . md5(md5('secret') . ':' . $salt) . "', '$salt'");
        expect($made['sql'])->not->toContain("'secret'");
    });

    test('made through the console of Robust: any password, one the console cannot take set in the database after', function () {
        [$plain] = W4OS\Engine\Provisioning\GridAccounts::consoleLine('Jane Doe', 'it is mine', 'jane@example.org');
        [$line, $typed] = W4OS\Engine\Provisioning\GridAccounts::consoleLine('Jane Doe', 'say "hi"', 'jane@example.org');
        $salt = str_repeat('cd', 16);

        expect($plain)->toBe("create user Jane Doe \"it is mine\" jane@example.org\n\n\n\n")
            // The console ends a quoted value at a double quote and has no escape: a password of its own, replaced after
            ->and($line)->not->toContain('say "hi"')->toContain(" $typed ")
            ->and($typed)->toMatch('/^[A-Za-z0-9]{16}$/')
            // A line break would end the command
            ->and(W4OS\Engine\Provisioning\GridAccounts::consoleLine('Jane Doe', "two\nlines", '')[0])->not->toContain("two\nlines")
            ->and(W4OS\Engine\Provisioning\GridAccounts::passwordSql('11111111-1111-1111-1111-111111111111', 'say "hi"', $salt))
            ->toBe("UPDATE auth SET passwordHash = '" . md5(md5('say "hi"') . ':' . $salt) . "', passwordSalt = '$salt' WHERE UUID = '11111111-1111-1111-1111-111111111111'");
    });

    test('makes the inventory', function () {
        $made = accounts_writer()->statements('Jane', 'Doe', '', 'secret');

        expect(substr_count($made['sql'], 'INSERT INTO inventoryfolders'))->toBe(1 + 18 + 2);
        expect($made['sql'])->toContain("'My Inventory', 8");
        expect($made['sql'])->toContain("'Current Outfit', 46");
        expect(substr_count($made['sql'], 'INSERT INTO inventoryitems'))->toBe(12);
        expect($made['sql'])->toContain("'Default Skin'");
        // The links point to the items, with the type of a link
        expect($made['sql'])->toMatch('/VALUES \(\'00000000-0000-4000-8000-0000000000(\d+)\', 24, \'Default Eyes\'/');
    });

    test('have no home when the grid has no default region yet', function () {
        expect(accounts_writer()->statements('Jane', 'Doe', '', 'x')['sql'])->not->toContain('GridUser');
    });

    test('escape what comes from the list', function () {
        expect(AccountWriter::quote("O'Hara\\"))->toBe("'O\\'Hara\\\\'");
        expect(AccountWriter::quote("a\0b"))->toBe("'ab'");
    });
});

describe('import', function () {
    /**
     * An importer over a grid that is a list of names, and a log of what was made.
     *
     * @param list<string> $names The avatars that exist.
     * @param bool $fail Whether the grid refuses to make one.
     * @param list<array<string,mixed>> $log Gets the values of the avatars that were made.
     */
    function accounts_importer(array $names, bool $fail, array &$log): AccountImporter
    {
        return new AccountImporter(
            static fn(Grid $grid): array => $names,
            static fn(Grid $grid): string => '607c44e9-3d01-45eb-a07e-937dc72dbadb',
            function (Grid $grid, array $values) use ($fail, &$log): void {
                $fail && throw ChannelUnavailable::for('create the avatar', 'the database refuses it');
                $log[] = $values;
            },
        );
    }

    function accounts_to_make(): array
    {
        return AccountList::parse("Jane,Doe,,pw1\nJohn,Roe\nAnn,Poe,,\n")['accounts'];
    }

    test('writes nothing without --apply', function () {
        $log = [];
        $grid = Grid::described(EnvironmentFake::with(), []);

        $done = accounts_importer(['jane doe'], false, $log)->run($grid, accounts_to_make(), false);

        expect($log)->toBe([]);
        expect(array_column($done['results'], 'status'))->toBe(['exists', 'would create', 'would create']);
    });

    test('writes each account, makes missing passwords', function () {
        $log = [];

        $done = accounts_importer([], false, $log)->run(
            Grid::described(EnvironmentFake::with(), []),
            accounts_to_make(),
            true,
        );

        expect($log)->toHaveCount(3);
        expect(array_column($done['results'], 'status'))->toBe(['created', 'created', 'created']);
        expect($done['results'][0]['generated'])->toBeFalse();
        expect($done['results'][1]['generated'])->toBeTrue();
        expect(strlen($done['results'][1]['password']))->toBe(12);
        expect($log[1])->toMatchArray([
            'firstName' => 'John',
            'lastName' => 'Roe',
            'password' => $done['results'][1]['password'],
            'home' => '607c44e9-3d01-45eb-a07e-937dc72dbadb',
        ]);
    });

    test('stops at the first failure', function () {
        $log = [];
        $stopped = accounts_importer([], true, $log)->run(
            Grid::described(EnvironmentFake::with(), []),
            accounts_to_make(),
            true,
        );
        $log = [];
        $going = accounts_importer([], true, $log)->run(
            Grid::described(EnvironmentFake::with(), []),
            accounts_to_make(),
            true,
            true,
        );

        expect(array_column($stopped['results'], 'status'))->toBe(['failed', 'not tried', 'not tried']);
        expect(array_column($going['results'], 'status'))->toBe(['failed', 'failed', 'failed']);
    });

    test('gives only the passwords it made', function () {
        $log = [];
        $done = accounts_importer([], false, $log)->run(
            Grid::described(EnvironmentFake::with(), []),
            accounts_to_make(),
            true,
        );

        $csv = AccountImporter::resultCsv($done['results']);

        expect($csv)->toContain('first,last,email,status,password,detail');
        expect($csv)->not->toContain('pw1');
        expect($csv)->toContain($done['results'][1]['password']);
    });

    test('stops when the database of the grid cannot be read', function () {
        $importer = new AccountImporter(
            static fn(Grid $grid): ?array => null,
            static fn(Grid $grid): ?string => null,
            static fn(Grid $grid, array $values) => null,
        );

        $done = $importer->run(Grid::described(EnvironmentFake::with(), []), accounts_to_make(), true);

        expect($done['error'])->not->toBeNull();
        expect($done['results'])->toBe([]);
    });
});

describe('A password kept as Robust keeps it', function () {
    test('is read from a list, with its salt', function () {
        $hash = AccountWriter::passwordHash('secret', 'abc123');
        $list = AccountList::parse(
            (string) json_encode([
                ['first' => 'Ann', 'last' => 'Lee', 'password_hash' => $hash, 'password_salt' => 'abc123'],
                ['first' => 'Bob', 'last' => 'Roe', 'password_hash' => 'not-a-hash', 'password_salt' => 'x'],
            ]),
            'json',
        );

        expect($list['accounts'][0]['password_hash'])
            ->toBe($hash)
            ->and($list['accounts'][0]['password_salt'])
            ->toBe('abc123')
            ->and(count($list['accounts']))
            ->toBe(1)
            ->and($list['errors'][0])
            ->toContain('password_hash');
    });

    test('is written as it is, and the password is not made', function () {
        $hash = AccountWriter::passwordHash('secret', 'abc123');
        $writer = new AccountWriter(fn() => '11111111-2222-3333-4444-555555555555', fn() => 1, fn() => 'randomsalt');
        $sql = $writer->statements('Ann', 'Lee', '', '', null, ['hash' => $hash, 'salt' => 'abc123'])['sql'];

        expect($sql)
            ->toContain("'$hash', 'abc123'")
            ->not->toContain('randomsalt');
    });
});
