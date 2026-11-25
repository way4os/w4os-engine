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

describe('The welcome of a page', function () {
    test('is the message of the grid when it has its own, the avatar or the word for a visitor in it; else names the avatar', function () {
        $environment = (new Machine())->environment;
        $welcome = static fn(?string $message): Grid => Grid::described($environment, ['nick' => 'fowl', 'name' => 'Friendly Fowl', 'remote' => true], new GridConfig(['Robust' => $message === null ? [] : ['LoginService' => ['WelcomeMessage' => $message]]]));
        $fr = Catalog::messages('fr_FR');

        // Its own message, kept for an avatar signed in too
        expect($welcome('Hello <USERNAME>, enjoy')->welcome(' Ada Lovelace '))->toBe('Hello Ada Lovelace, enjoy')
            ->and($welcome('Hello <USERNAME>, enjoy')->welcome())->toBe('Hello Avatar, enjoy')
            ->and($welcome('Bienvenue sous les tropiques')->welcome('Ada Lovelace'))->toBe('Bienvenue sous les tropiques')
            ->and($welcome('Bienvenue sous les tropiques')->welcome())->toBe('Bienvenue sous les tropiques')
            // The example of OpenSimulator's files is no message of the grid
            ->and($welcome(' Welcome, Avatar! ')->welcome('Ada Lovelace'))->toBe('Welcome, Ada Lovelace')
            ->and($welcome('Welcome, Avatar!')->welcome())->toBe('Welcome, Avatar')
            ->and($welcome(null)->welcome('Ada Lovelace'))->toBe('Welcome, Ada Lovelace')
            ->and($welcome(null)->welcome())->toBe('Welcome, Avatar')
            ->and($welcome(null)->welcome('', 'fr_FR'))->toBe(sprintf($fr['motd_avatar'], $fr['motd_avatar_visitor']))
            // The message of the day of the viewers stays what it was
            ->and($welcome(null)->motd())->toBe('Welcome to Friendly Fowl, <USERNAME>!');
    });

    test('is in the API, as plain text, the avatar given or not', function () {
        $router = new W4OS\Engine\Api\Router(new W4OS\Engine\Api\Api((new Machine())->environment));
        $named = $router->get('/grids/demo/welcome', ['avatar' => 'Ada Lovelace']);

        expect($named->status)->toBe(200)
            ->and($named->body())->toBe('Welcome, Ada Lovelace')
            ->and($router->get('/grids/demo/welcome')->body())->toBe('Welcome, Avatar')
            ->and($router->get('/grids/nowhere/welcome')->status)->toBe(404);
    });
});

describe('The welcome message of the grid', function () {
    test('is on the Grid page of the settings, its help saying what <USERNAME> becomes', function () {
        $entry = array_values(array_filter(W4OS\Engine\Config\Settings::entries(), static fn(array $e): bool => $e['key'] === 'WelcomeMessage'))[0];

        expect($entry)->toMatchArray(['section' => 'LoginService', 'name' => 'welcomeMessage', 'type' => 'text', 'admin' => 'grid', 'description' => 'setting_welcome_message_help'])
            ->and(Catalog::MESSAGES['setting_welcome_message_help'])->toBe('<USERNAME> is replaced by the name of the avatar')
            ->and(Catalog::MESSAGES['block_motd_help'])->toBe('A welcome title naming the avatar signed in, else the welcome message of the grid.');
    });
});
