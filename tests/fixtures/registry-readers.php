<?php

declare(strict_types=1);

/**
 * What the readers of the settings give for a grid that sets most of them: the addresses of its services (Service::address(), as
 * getAddresses shows them, the grid and a simulator) and the health of its settings (Health::grid(), Health::describe()). Kept in
 * registry-readers.json; the readers of the registry give the same.
 *
 *     php tests/fixtures/registry-readers.php > tests/fixtures/registry-readers.json
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Health\Health;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Models\Service;
use W4OS\Engine\Testing\Machine;

/** @return array<string,mixed> */
function registry_readers(): array
{
    $machine = new Machine();
    $machine->write(
        $machine->grid . '/Robust.HG.ini',
        file_get_contents($machine->grid . '/Robust.HG.ini') .
            "\n[Const]\n    WebURL = \"https://play.example.org\"\n[GridInfoService]\n    economy = \"https://play.example.org/helpers/\"\n    search = \"https://play.example.org/search\"\n    GridStatusRSS = \"https://play.example.org/status.rss\"\n    about = \"https://play.example.org/about\"\n    gridname = \"Demo\"\n[LoginService]\n    MessageUrl = \"https://play.example.org/helpers/motd.php\"\n    DestinationGuide = \"https://play.example.org/guide\"\n    SearchURL = \"https://play.example.org/websearch\"\n",
    );
    $machine->write(
        $machine->grid . '/OpenSim.ini',
        "[Search]\n    SearchURL = \"https://play.example.org/helpers/query.php\"\n[DataSnapshot]\n    DATA_SRV_demo = \"https://play.example.org/helpers/register.php\"\n    DATA_SRV_2do = \"https://2do.directory/helpers/register.php\"\n[Messaging]\n    OfflineMessageModule = OfflineMessageModule\n    OfflineMessageURL = \"https://play.example.org/helpers/offline.php\"\n[Economy]\n    economymodule = DTLNSLMoneyModule\n",
    );
    $machine->write($machine->grid . '/MoneyServer.ini', "[MoneyServer]\n    MoneyScriptAccessKey = \"k3y\"\n");
    $machine->write(
        $machine->grid . '/Helpers.ini',
        "[Helpers]\n    AssetsURL = \"https://assets.example.org/a\"\n    GridStatusURL = \"https://play.example.org/helpers/status-page\"\n    DirectoryURL = \"https://play.example.org/directory\"\n",
    );
    $grid = Grid::find($machine->environment, 'demo');
    $simulator = $grid->simulators()[0];
    $services = [];
    foreach (Service::keys() as $key) {
        $service = new Service($grid->environment(), $grid, $key);
        $own = new Service($grid->environment(), $grid, $key, $simulator);
        $services[$key] = $service->address() + [
            'exists' => $service->exists(),
            'pair' => $service->setting(),
            'simulator' => [$own->url(), $own->source()],
        ];
    }
    $config = GridConfig::fromFolder($machine->grid);
    $in = static fn(array $more): array => $more + [
        'config' => $config,
        'live' => ['gridname' => 'Demo', 'login' => 'http://localhost:8002/', 'economy' => 'https://play.example.org/helpers/', 'mood' => 'free'],
        'login' => 'http://localhost:8002/',
        'owner' => 'Ada.Lovelace',
        'servedByHelpers' => static fn(string $url): bool => str_starts_with($url, 'https://play.example.org/helpers'),
    ];
    $robustOnly = test_folder("[GridInfoService]\n    login = \"http://localhost:8002/\"\n[Messaging]\n    OfflineMessageModule = \"Offline Message Module V2\"\n");
    $health = [
        'full' => Health::grid($in([])),
        'not live' => Health::grid($in(['live' => []])),
        'no config' => Health::grid($in(['config' => null])),
        'V2, no money server' => Health::grid($in(['config' => GridConfig::fromFolder($robustOnly), 'live' => []])),
    ];
    $described = [];
    foreach ($health as $case => $groups) {
        $described[$case] = Health::describe([
            ...$groups,
            ['file' => 'pages', 'title' => '', 'rows' => [['label' => 'search', 'level' => 'error', 'url' => 'https://other.example.org/search'], ['label' => 'helpers', 'level' => 'ok']]],
            ['file' => 'cache', 'title' => '', 'rows' => [['label' => 'assets', 'level' => 'ok']]],
        ], static fn(string $url): bool => str_starts_with($url, 'https://play.example.org/helpers'));
    }

    // The paths of the machine change from one run to the next
    $json = (string) json_encode(['services' => $services, 'health' => $described, 'names' => Health::names($config)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return json_decode(str_replace([$machine->root, $robustOnly], ['<machine>', '<folder>'], $json), true);
}

/** A grid folder with a Robust.ini of this text. */
function test_folder(string $robust): string
{
    $dir = test_tmp() . '/readers-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    file_put_contents("$dir/Robust.ini", $robust);

    return $dir;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require_once dirname(__DIR__) . '/Pest.php';
    echo json_encode(registry_readers(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}
