<?php

declare(strict_types=1);

/**
 * The accounts of a list (CSV, JSON or YAML) made in a grid through the API: planned first, the names that exist skipped, a password
 * made for an account given none, and given back only once it is made.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\AccountImporter;
use W4OS\Engine\Testing\Machine;

/** A router whose grid has the avatar Jane Doe, and makes the others, but Bad Luck. */
function accounts_router(Machine $machine, array &$made): Router
{
    $importer = new AccountImporter(
        static fn(Grid $grid): array => ['Jane Doe'],
        static fn(Grid $grid): ?string => null,
        static function (Grid $grid, array $values) use (&$made): void {
            $values['firstName'] === 'Bad' && throw new InvalidArgumentException('the database refused it');
            $made[] = "{$values['firstName']} {$values['lastName']}";
        },
    );

    return new Router(new Api($machine->environment, accounts: $importer));
}

function accounts_import(Router $router, array $attributes)
{
    return $router->post('/setups/imports', ['data' => ['type' => 'imports', 'attributes' => $attributes + ['kind' => 'accounts', 'grid' => 'demo']]]);
}

describe('The accounts of a list, through the API', function () {
    test('are planned: what exists is skipped, nothing is made, no password given', function () {
        $made = [];
        $router = accounts_router(new Machine(), $made);

        $plan = accounts_import($router, ['text' => "first,last,email,password\nJane,Doe,,\nJohn,Roe,john@example.org,\n"]);
        $attributes = $plan->document['data']['attributes'] ?? [];

        expect($plan->status)
            ->toBe(200)
            ->and($attributes['applied'])
            ->toBeFalse()
            ->and(array_column($attributes['results'], 'status'))
            ->toBe([AccountImporter::EXISTS, AccountImporter::WOULD_CREATE])
            // The line of the list each comes from
            ->and(array_column($attributes['results'], 'line'))
            ->toBe([2, 3])
            ->and($attributes['results'][1])
            ->toMatchArray(['first' => 'John', 'last' => 'Roe', 'generated' => true])
            ->not->toHaveKey('password')
            ->and($attributes)
            ->not->toHaveKey('csv')
            ->and($attributes['counts'])
            ->toBe([AccountImporter::EXISTS => 1, AccountImporter::WOULD_CREATE => 1])
            ->and($made)
            ->toBe([]);
    });

    test('are made with mode apply, a password made only for the ones given none, in the result', function () {
        $made = [];
        $router = accounts_router(new Machine(), $made);

        $applied = accounts_import($router, ['mode' => 'apply', 'text' => "John,Roe,john@example.org,\nAnn,Poe,,secret12\n"]);
        $attributes = $applied->document['data']['attributes'] ?? [];
        $john = $attributes['results'][0];

        expect($attributes['applied'])
            ->toBeTrue()
            ->and($made)
            ->toBe(['John Roe', 'Ann Poe'])
            ->and($john['generated'])
            ->toBeTrue()
            ->and(strlen($john['password']))
            ->toBe(12)
            ->and($attributes['results'][1])
            ->not->toHaveKey('password')
            ->and($attributes['csv'])
            ->toContain($john['password'])
            ->not->toContain('secret12');
    });

    test('stop at the first failure, or go on with keepGoing', function () {
        $made = [];
        $router = accounts_router(new Machine(), $made);
        $text = "Bad,Luck,,pw123456\nJohn,Roe,,pw123456\n";

        $stopped = accounts_import($router, ['mode' => 'apply', 'text' => $text])->document['data']['attributes']['results'];
        $kept = accounts_import($router, ['mode' => 'apply', 'text' => $text, 'keepGoing' => true])->document['data']['attributes']['results'];

        expect(array_column($stopped, 'status'))
            ->toBe([AccountImporter::FAILED, AccountImporter::NOT_TRIED])
            ->and($stopped[0]['detail'])
            ->toBe('the database refused it')
            ->and(array_column($kept, 'status'))
            ->toBe([AccountImporter::FAILED, AccountImporter::CREATED]);
    });

    test('tell the lines that cannot be used, and refuse a list without any account to make', function () {
        $made = [];
        $router = accounts_router(new Machine(), $made);

        $some = accounts_import($router, ['text' => "John,Roe\nBad\"Name,Doe\n"])->document['data']['attributes'];
        $none = accounts_import($router, ['text' => "Bad\"Name,Doe\n"]);
        $yaml = accounts_import($router, ['format' => 'yaml', 'text' => "- first: Ann\n  last: Poe\n"])->document['data']['attributes'];

        expect($some['errors'])
            ->toHaveCount(1)
            ->and($some['errors'][0])
            ->toStartWith('line 2:')
            ->and($none->status)
            ->toBe(400)
            ->and(array_column($yaml['results'], 'first'))
            ->toBe(['Ann']);
    });

    test('say why when the database of the grid cannot be read', function () {
        $importer = new AccountImporter(static fn(): ?array => null, static fn(): ?string => null, static function (): void {});
        $router = new Router(new Api((new Machine())->environment, accounts: $importer));

        $refused = accounts_import($router, ['text' => "John,Roe\n"]);

        expect($refused->status)
            ->toBe(503)
            ->and($refused->document['detail'] ?? '')
            ->toContain('the database of the grid cannot be read');
    });

    test('refuse a grid that is not here', function () {
        $made = [];
        $router = accounts_router(new Machine(), $made);

        expect(accounts_import($router, ['grid' => 'nowhere', 'text' => "John,Roe\n"])->status)->toBe(404);
    });
});

describe('The accounts of a list, into a grid this install does not manage', function () {
    test('are planned against the Robust config given, nothing registered', function () {
        $machine = new Machine();
        $robust = $machine->root . '/elsewhere/Robust.HG.ini';
        mkdir(dirname($robust), 0o755, true);
        file_put_contents($robust, "[GridInfoService]\n    gridnick = \"faraway\"\n[DatabaseService]\n    ConnectionString = \"Data Source=db.example.org;Database=faraway;User ID=u;Password=p;\"\n");
        $seen = [];
        $importer = new AccountImporter(
            static function (?Grid $grid, ?W4OS\Engine\Config\GridConfig $config = null) use (&$seen): array {
                $seen[] = $config?->file('Robust');

                return ['Jane Doe'];
            },
            static fn(): ?string => null,
            static function (): void {},
        );
        $router = new Router(new Api($machine->environment, accounts: $importer));

        $plan = $router->post('/setups/imports', ['data' => ['type' => 'imports', 'attributes' => ['kind' => 'accounts', 'robust' => $robust, 'text' => "Jane,Doe\nJohn,Roe\n"]]]);
        $attributes = $plan->document['data']['attributes'] ?? [];

        expect($plan->status)
            ->toBe(200)
            ->and($attributes['grid'])
            ->toBe('faraway')
            ->and(array_column($attributes['results'], 'status'))
            ->toBe([AccountImporter::EXISTS, AccountImporter::WOULD_CREATE])
            ->and($seen)
            ->toBe([realpath($robust)])
            ->and(is_dir($machine->etc . '/grids/faraway'))
            ->toBeFalse();
    });
});
