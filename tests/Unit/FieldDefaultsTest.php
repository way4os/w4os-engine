<?php

declare(strict_types=1);

/**
 * What an empty field of a page of settings shows: its default, the value the grid takes when it is left empty, shown as it is; an
 * example, never taken, shown as one (e.g.: YC$); a text that is neither (an avatar picker). Never one in the place of the other.
 * The grids of provided_grid() of ProvideChoicesTest, address_patch() of ProvideAddressesTest.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Economy\CurrencySettings;
use OpenSim\I18n\Catalog;
use OpenSim\Models\AvatarModel;
use OpenSim\Models\GridSettings;
use OpenSim\Services\SearchTasks;

/** @return array<string,array<string,mixed>> the fields of every page of settings, by page and name, the parts of a database too */
function field_hints(): array
{
    $hints = [];
    foreach (GridSettings::groups() as $page) {
        foreach (GridSettings::form($page)['fields'] as $field) {
            $hints[$page][$field['name']] = array_intersect_key($field, ['default' => 1, 'example' => 1, 'placeholder' => 1]);
            foreach ($field['fields'] ?? [] as $part) {
                $hints[$page][$field['name'] . '.' . $part['name']] = array_intersect_key($part, ['default' => 1, 'example' => 1, 'placeholder' => 1]);
            }
        }
    }

    return array_map(static fn(array $fields): array => array_filter($fields), $hints);
}

/** A grid whose Helpers.ini has these lines. */
function blank_grid(string $helpers): GridConfig
{
    $dir = test_tmp() . '/blank-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n");
    file_put_contents("$dir/Helpers.ini", "[Helpers]\n$helpers");

    return GridConfig::fromFolder($dir, ['Helpers' => 'Helpers.ini']);
}

describe('An empty field of a page of settings', function () {
    test('shows the default the grid takes as it is, an example as one, never one in the place of the other', function () {
        $hints = field_hints();

        expect($hints['economy'])->toMatchArray([
            'currencySymbol' => ['example' => 'YC$'],
            'currencyRate' => ['default' => 10.0],
            'gloebitOwnerName' => ['example' => 'Banker Name'],
            'gloebitOwnerEmail' => ['example' => 'banker@example.org'],
            'moneyBanker' => ['example' => '00000000-0000-0000-0000-000000000000'],
            'moneyDatabase.port' => ['default' => 3306],
            'podexMessage' => ['default' => CurrencySettings::PODEX_MESSAGE],
            // Required: the region of the terminals of the grid, an example only
            'podexUrl' => ['example' => 'secondlife://Podex Exchange/128/128/21'],
        ])
            ->and($hints['search'])->toMatchArray([
                'eventsUrl' => ['default' => SearchTasks::EVENTS_SOURCE],
                'searchExternalUrl' => ['default' => 'http://2do.directory/helpers/query.php'],
                'include2do' => ['default' => true],
                'searchDatabase.port' => ['default' => 3306],
            ])
            ->and($hints['guide'])->toMatchArray(['guideSource' => ['default' => 'https://2do.directory/destinations/guide']])
            ->and($hints['offline'])->toMatchArray(['mailSender' => ['example' => 'no-reply@example.org']])
            ->and($hints['assets'])->toMatchArray(['assetsUrl' => ['example' => 'https://example.org/assets']])
            ->and($hints['models'])->toMatchArray(['pattern' => ['default' => 'Default'], 'list' => ['placeholder' => 'models_list_placeholder']])
            // One of them at most, a text of the catalog for the one that is neither
            ->and(array_merge(...array_values($hints)))->each->toHaveCount(1)
            ->and(array_filter(array_merge(...array_values($hints)), static fn(array $hint): bool => isset($hint['placeholder']) && !isset(Catalog::MESSAGES[$hint['placeholder']])))->toBe([]);
    });

    test('says an example as one in every language: e.g.: YC$', function () {
        $example = static fn(string $locale): string => sprintf(Catalog::messages($locale, false)['example_value'] ?? '', 'YC$');

        expect(sprintf(Catalog::MESSAGES['example_value'], 'YC$'))->toBe('e.g.: YC$')
            ->and($example('fr_FR'))->toBe('ex. : YC$');
        foreach (Catalog::LOCALES as $locale) {
            expect($example($locale))->toEndWith(' YC$')->not->toBe(' YC$');
        }
    });

    test('left empty, is its default where the grid reads it: the rate, the message of Podex, the events, the pattern of the models', function () {
        $blank = blank_grid("CurrencyRate = \"\"\nPodexMessage = \"\"\nHypEventsURL = \"\"\n");
        $currency = CurrencySettings::fromConfig($blank);

        expect([$currency->rate, $currency->podexMessage, SearchTasks::eventsSource($blank)])->toBe([10.0, CurrencySettings::PODEX_MESSAGE, SearchTasks::EVENTS_SOURCE])
            ->and(SearchTasks::eventsSource(blank_grid("HypEventsURL = \"https://events.example.org/\"\n")))->toBe('https://events.example.org')
            ->and(AvatarModel::rule($blank, ['pattern' => ''])[1])->toBe('Default');
    });

    test('left empty with Provide off, the search of another server is the one of 2do.directory', function () {
        [, $router] = provided_grid();

        $saved = address_patch($router, 'search', ['provide' => false, 'searchExternalUrl' => '']);

        expect($saved->status)->toBe(200)
            ->and($saved->document['data']['attributes']['searchExternalUrl'] ?? null)->toBe('http://2do.directory/helpers/query.php');
    });
});
