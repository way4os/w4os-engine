<?php

declare(strict_types=1);

/**
 * The engine at its best with what it has: a grid a site describes (its login address, its databases over the network), with no
 * folder and none of its ini files, answers every operation of the contract without an error of its own (no 500, no warning of PHP).
 * What can be had from its databases and its answers over HTTP is given; what needs its files is unavailable for that grid, with its
 * reason, and the grid says which of its channels it has, for a site to hide what it cannot do.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Contract;
use OpenSim\Api\Router;
use OpenSim\Config\GridConfig;
use OpenSim\Config\Layout;
use OpenSim\Data\Connections;
use OpenSim\Models\Grid;
use OpenSim\Runtime\Environment;

/** @return array{0:Router,1:string} the API of a site that describes the grid `far`, and the folder of the site */
function site_api(): array
{
    $site = test_tmp() . '/best-effort-' . bin2hex(random_bytes(4));
    mkdir($site);
    $environment = Environment::system(new Layout(['EtcRoot' => "$site/etc", 'CacheRoot' => "$site/cache", 'TasksRoot' => "$site/tasks", 'StateRoot' => "$site/state"]));
    // Nothing answers there: a port of this machine that is closed
    $far = Grid::described($environment, ['nick' => 'far', 'name' => 'Far', 'remote' => true, 'baseHostname' => '127.0.0.1', 'publicPort' => 9, 'loginUri' => 'http://127.0.0.1:9/'], new GridConfig([
        'Robust' => ['DatabaseService' => ['ConnectionString' => 'Data Source=db.example.org;Database=robust;User ID=site;Password=pw;']],
        'Helpers' => ['Helpers' => ['AssetsURL' => 'https://assets.example.org/a']],
    ]));
    $mailer = new class implements OpenSim\Services\MailerInterface {
        public function send(string $to, string $subject, string $html, string $from): bool
        {
            return true;
        }
    };

    return [new Router(new Api($environment, mailer: $mailer, grids: static fn(string $nick): ?Grid => $nick === 'far' ? $far : null)), $site];
}

/**
 * Every operation of the contract on the grid `far`, as a site asks it: its path with an id that is no one's, a body with nothing.
 *
 * @return array<string,array{status:int,document:array<string,mixed>,warnings:list<string>,type:string}> by "METHOD path"
 */
function every_operation(Router $router): array
{
    $answers = [];
    foreach (Contract::document()['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (!is_array($operation) || !isset($operation['operationId'])) {
                continue;
            }
            $asked = strtr($path, [
                '{grid}' => 'far', '{id}' => '00000000-0000-4000-8000-0000000000aa', '{format}' => 'jpg', '{name}' => 'w4os',
                '{group}' => 'models', '{check}' => 'nothing', '{post}' => '00000000-0000-4000-8000-0000000000bb', '{file}' => 'nothing',
            ]);
            $type = explode('/', trim($path, '/'))[2] ?? explode('/', trim($path, '/'))[0];
            $body = in_array($method, ['post', 'patch'], true) ? (string) json_encode(['data' => ['type' => $type, 'attributes' => []]]) : '';
            $warnings = [];
            set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$warnings): bool {
                $warnings[] = "$message ($file:$line)";

                return true;
            });
            try {
                $response = $router->route(strtoupper($method), $asked, [], $body);
            } finally {
                restore_error_handler();
            }
            $answers[strtoupper($method) . " $path"] = ['status' => $response->status, 'document' => $response->document, 'warnings' => $warnings, 'type' => (string) ($response->headers['Content-Type'] ?? '')];
        }
    }

    return $answers;
}

afterEach(fn() => Connections::using(null));

describe('A grid a site describes, without its files', function () {
    test('answers every operation of the contract without an error of its own, nor a warning', function () {
        // Its databases do not answer either
        Connections::using(static fn() => null);
        [$router] = site_api();
        $answers = every_operation($router);
        $errors = array_filter($answers, static fn(array $answer): bool => $answer['status'] >= 500 && $answer['status'] !== 503);
        $warned = array_filter(array_map(static fn(array $answer): array => $answer['warnings'], $answers));
        // A problem says why; the calls in a format of OpenSimulator and the viewers answer in their own
        $silent = array_filter($answers, static fn(array $answer): bool => $answer['status'] >= 400 && str_contains($answer['type'], 'json') && trim((string) ($answer['document']['detail'] ?? '')) === '');

        expect(count($answers))->toBeGreaterThan(60)
            ->and(array_map(static fn(array $answer): string => $answer['status'] . ' ' . ($answer['document']['detail'] ?? ''), $errors))->toBe([])
            ->and($warned)->toBe([])
            ->and(array_keys($silent))->toBe([]);
    });
});

describe('What a grid has, and what an operation needs', function () {
    test('a grid says what the engine reaches of it: its files, its databases, its answers over HTTP', function () {
        [$router] = site_api();
        $far = $router->get('/grids/far');
        $machine = new OpenSim\Testing\Machine();
        $demo = (new Router(new Api($machine->environment)))->get('/grids/demo');

        expect($far->status)->toBe(200)
            ->and($far->document['data']['attributes']['access'])->toBe(['databases', 'http'])
            ->and($demo->document['data']['attributes']['access'])->toContain('files');
    });

    test('what needs the files of a grid is unavailable without them, with its reason, and nothing is written', function () {
        [$router, $site] = site_api();
        $read = $router->get('/grids/far/settings/assets');
        $write = $router->patch('/grids/far/settings/assets', ['data' => ['type' => 'settings', 'id' => 'assets', 'attributes' => ['assetsUrl' => 'https://other.example.org/a']]]);
        $repair = $router->post('/grids/far/options/repair', ['data' => ['type' => 'options', 'attributes' => ['mode' => 'apply']]]);

        expect($read->status)->toBe(200)
            // Read in the config the site gives
            ->and($read->document['data']['attributes']['assetsUrl'])->toBe('https://assets.example.org/a')
            ->and([$write->status, $write->document['code'] ?? null, $repair->status, $repair->document['code'] ?? null])
            ->toBe([503, 'channel-unavailable', 503, 'channel-unavailable'])
            ->and($write->document['detail'])->toContain('no files')
            // Its reason as a notice, for a site to say it in its language
            ->and([$write->document['reason']['code'] ?? null, $repair->document['reason']['code'] ?? null])->toBe(['setting-no-files-unkept', 'options-no-files'])
            ->and(OpenSim\I18n\Catalog::notice('fr_FR', 'options-no-files'))->not->toBe($repair->document['reason']['detail'])
            ->and(is_file('/Helpers.ini'))->toBeFalse()
            ->and(glob("$site/{,*/,*/*/}*.ini", GLOB_BRACE))->toBe([]);
    });

    test('the contract says what an operation needs of a grid, for a site to hide what it cannot do', function () {
        $needs = static function (string $path, string $method): array {
            return Contract::document()['paths'][$path][$method]['x-access']['needs'] ?? [];
        };

        expect([
            $needs('/grids/{grid}/settings/assets', 'patch'),
            $needs('/grids/{grid}/settings/assets', 'get'),
            $needs('/grids/{grid}/options/repair', 'post'),
            $needs('/grids/{grid}/avatars', 'get'),
            $needs('/grids/{grid}/posts/{id}', 'delete'),
            $needs('/grids/{grid}/info', 'get'),
        ])->toBe([['settings'], [], ['files'], ['databases'], ['databases'], []])
            ->and(Contract::document()['info']['description'])->toContain('`needs`');
    });
});
