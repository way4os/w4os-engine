<?php

declare(strict_types=1);

/**
 * The address of a page of a site built for the page shown (Url): the base, the language prefix and the arguments that carry over
 * kept, whatever the framework that gives them.
 */

use W4OS\Engine\Http\Url;

/** The guide of a site under /site, in French, asked by a viewer with its source and an argument of its own. */
function url_page(): Url
{
    return new Url(
        base: 'https://example.org/site',
        language: 'fr',
        languages: ['en', 'fr', 'de'],
        path: '/guide',
        query: ['source' => 'https://2do.directory/destinations/guide', 'category' => 'Music', 'debug' => '1'],
        keep: ['source'],
    );
}

describe('The address of a page', function () {
    test('keeps the base and the language of the page, the arguments declared to carry over and no others', function () {
        $url = url_page();

        expect($url->build('/search', ['q' => 'plaza']))
            ->toBe('https://example.org/site/fr/search?source=https%3A%2F%2F2do.directory%2Fdestinations%2Fguide&q=plaza')
            ->and($url->build('search'))->toBe('https://example.org/site/fr/search?source=https%3A%2F%2F2do.directory%2Fdestinations%2Fguide')
            ->and($url->build('/'))->toBe('https://example.org/site/fr/?source=https%3A%2F%2F2do.directory%2Fdestinations%2Fguide')
            // The page shown, another argument
            ->and($url->build('?category=Art'))->toBe('https://example.org/site/fr/guide?source=https%3A%2F%2F2do.directory%2Fdestinations%2Fguide&category=Art');
    });

    test('gives a target that has a language prefix or the base already the same address, never twice', function () {
        $url = url_page();

        expect($url->build('/fr/search'))->toStartWith('https://example.org/site/fr/search?')
            ->and($url->build('/site/fr/search'))->toStartWith('https://example.org/site/fr/search?')
            ->and($url->build('/site/search'))->toStartWith('https://example.org/site/fr/search?')
            // Another language of the site is kept: a link to the page in that language
            ->and($url->build('/en/search'))->toStartWith('https://example.org/site/en/search?')
            ->and($url->build('https://example.org/site/fr/search'))->toStartWith('https://example.org/site/fr/search?');
    });

    test('leaves an address of another host as it is, its own arguments added only', function () {
        $url = url_page();

        expect($url->build('https://2do.directory/events?lang=fr'))->toBe('https://2do.directory/events?lang=fr')
            ->and($url->build('https://2do.directory/events?lang=fr', ['page' => 2]))->toBe('https://2do.directory/events?lang=fr&page=2')
            ->and($url->build('hop://grid.example.org:8002/Welcome'))->toBe('hop://grid.example.org:8002/Welcome');
    });

    test('takes an argument out with null or empty, keeps the fragment, and has no base or language when none is given', function () {
        $url = url_page();
        $bare = new Url(path: '/guide', query: ['gk' => 'http://grid.example.org:8002'], keep: ['gk']);

        expect($url->build('/search#results', ['source' => null]))->toBe('https://example.org/site/fr/search#results')
            ->and($bare->build('/search'))->toBe('/search?gk=http%3A%2F%2Fgrid.example.org%3A8002')
            ->and($bare->build('?category=Art'))->toBe('/guide?gk=http%3A%2F%2Fgrid.example.org%3A8002&category=Art')
            ->and(Url::withQuery('https://example.org/a?x=1#top', ['y' => 2, 'x' => '']))->toBe('https://example.org/a?y=2#top');
    });
});
