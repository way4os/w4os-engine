<?php

declare(strict_types=1);

/**
 * The numbers the engine writes (in a link, a table, an answer) have a dot, whatever the locale of the system or of the request:
 * a French one writes a comma for a decimal point.
 */

use OpenSim\Data\Connections;
use OpenSim\Http\Locale;
use OpenSim\Hypergrid\Destination;
use OpenSim\Models\Grid;
use OpenSim\Runtime\GridMonitor;

/** Run under a French locale for the numbers, the one before given back. */
function under_french_numbers(Closure $run): mixed
{
    $before = (string) setlocale(LC_NUMERIC, '0');
    setlocale(LC_NUMERIC, 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR');
    try {
        return $run();
    } finally {
        setlocale(LC_NUMERIC, $before);
    }
}

$french = static function (): bool {
    $before = (string) setlocale(LC_NUMERIC, '0');
    $found = setlocale(LC_NUMERIC, 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR') !== false && sprintf('%.1f', 1.5) === '1,5';
    setlocale(LC_NUMERIC, $before);

    return $found;
};

describe('The numbers under a French locale', function () {
    test('of the table of the status of a grid have a dot', function () {
        $machine = monitor_machine();
        $db = monitor_database();
        Connections::using(static fn() => $db);

        $text = under_french_numbers(static fn(): string => GridMonitor::text(GridMonitor::of(Grid::find($machine->environment, 'demo'), region_config())));
        Connections::using(null);

        expect($text)->toMatch('/^demo\s+robust\s+up\s+-\s+0\.3\s+1\.2$/m');
    });

    test('of the points of the links have a dot', function () {
        $links = under_french_numbers(static fn(): array => [
            Destination::format('grid.example:8002/Plaza/116.5/136.25/21.75', Destination::HOP),
            Destination::format('grid.example:8002/Plaza/116.5/136/0', Destination::GRID),
            Destination::position('<10.4, 20, 0>'),
        ]);

        expect($links)->toBe([
            'hop://grid.example:8002/Plaza/116.5/136.25/21.75',
            'secondlife://grid.example:8002/secondlife/Plaza/116.5/136/21',
            '10.4,20,21',
        ]);
    });

    test('stay with a dot when a request asks for French: its language is the one of its texts only', function () {
        $before = [$_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null, (string) setlocale(LC_ALL, '0')];
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-FR,fr;q=0.9';
        try {
            Locale::negotiate('fr_FR');
            $number = sprintf('%.1f', 1.5);
        } finally {
            setlocale(LC_ALL, $before[1]);
            if ($before[0] === null) {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            } else {
                $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $before[0];
            }
        }

        expect($number)->toBe('1.5');
    });
})->skip(fn() => !$french(), 'the French locale is not installed');
