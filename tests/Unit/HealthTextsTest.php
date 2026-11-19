<?php

declare(strict_types=1);

/**
 * What the health says is whole sentences of the catalog, their values as placeholders (`health_*`): each line gives the key and
 * the values of its texts (`texts`, by field), for a site to say them in its language (Health::translate()).
 */

use OpenSim\Health\Health;
use OpenSim\I18n\Catalog;

describe('The texts of the health', function () {
    test('are sentences of the catalog, their key and their values given with each line', function () {
        $folder = test_tmp() . '/health-texts-' . bin2hex(random_bytes(4));
        mkdir($folder);
        $groups = Health::withTexts([['file' => 'cache', 'title' => '', 'rows' => [
            Health::writable('tasks', $folder),
            Health::jpeg2000(false, null),
            Health::row('x', false),
        ]]]);
        [$writable, $jp2, $missing] = $groups[0]['rows'];

        expect($writable['status'])->toBe('writable')
            ->and($writable['texts']['status'])->toBe(['key' => 'health_writable', 'params' => []])
            ->and($jp2['texts']['detail']['key'])->toBe('health_jp2_missing_detail')
            ->and($jp2['texts']['detail']['params'][0])->toContain('[Helpers]')
            ->and($missing['texts']['status']['key'])->toBe('health_missing');

        return $groups;
    });

    test('are said in the language of a site', function (array $groups) {
        $french = Health::translate($groups, 'fr_FR')[0]['rows'];
        $fr = Catalog::messages('fr_FR', false);

        expect($french[0]['status'])->toBe($fr['health_writable'])->not->toBe('writable')
            ->and($french[1]['detail'])->toBe(sprintf($fr['health_jp2_missing_detail'], $groups[0]['rows'][1]['texts']['detail']['params'][0]))
            ->and(Health::translate($groups, 'en')[0]['rows'][0]['status'])->toBe('writable');
    })->depends('are sentences of the catalog, their key and their values given with each line');

    test('are all in every language', function () {
        $keys = array_filter(array_keys(Catalog::MESSAGES), static fn(string $key): bool => str_starts_with($key, 'health_'));
        $missing = [];
        foreach (['fr_FR', 'de_DE', 'it_IT', 'nl_NL', 'pt_BR', 'cy_GB'] as $locale) {
            foreach ($keys as $key) {
                (Catalog::messages($locale, false)[$key] ?? '') === '' && ($missing[] = "$locale $key");
            }
        }

        expect(count($keys))->toBeGreaterThan(30)->and($missing)->toBe([])
            // No text of the health left outside the catalog
            ->and(preg_match_all('/\b_\(/', file_get_contents(dirname(__DIR__, 2) . '/src/Health/Health.php') . file_get_contents(dirname(__DIR__, 2) . '/src/Health/HealthCheck.php')))->toBe(0);
    });
});
