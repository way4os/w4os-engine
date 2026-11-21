<?php

declare(strict_types=1);

/**
 * The message of the day a grid that sets none gives: one sentence of the catalog in the language asked, the name of the grid and
 * the name of the avatar, or the word for a visitor, in it; for the viewers (no language), the English one with <USERNAME>, which
 * Robust replaces. A message the grid sets is its own, the name or the word for a visitor in it.
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Testing\Machine;

describe('The message of the day', function () {
    test('is one sentence of the catalog in the language asked, the grid and the visitor in it', function () {
        $environment = (new Machine())->environment;
        $grid = Grid::described($environment, ['nick' => 'fowl', 'name' => 'Friendly Fowl', 'remote' => true], new GridConfig(['Robust' => []]));
        $own = Grid::described($environment, ['nick' => 'own', 'name' => 'Own', 'remote' => true], new GridConfig(['Robust' => ['LoginService' => ['WelcomeMessage' => 'Hello <USERNAME>']]]));

        expect($grid->motd())->toBe('Welcome to Friendly Fowl, <USERNAME>!')
            ->and($grid->motd('Ada'))->toBe('Welcome to Friendly Fowl, Ada!')
            ->and($grid->motd('', 'fr_FR'))->toBe(sprintf(Catalog::messages('fr_FR')['motd_welcome'], 'Friendly Fowl', Catalog::messages('fr_FR')['motd_visitor']))
            ->and($grid->motd('', 'fr_FR'))->not->toContain('Welcome')->toContain('Friendly Fowl')
            ->and($grid->motd('Ada', 'de_DE'))->toContain('Ada')->not->toContain('Welcome')
            ->and($own->motd('', 'fr_FR'))->toBe('Hello ' . Catalog::messages('fr_FR')['motd_visitor']);
    });
});
