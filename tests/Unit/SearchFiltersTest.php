<?php

declare(strict_types=1);

/**
 * What a search asks, written once for the search of the viewers and the web search (SearchFilters): the same terms whoever asks.
 */

use W4OS\Engine\Services\SearchFilters;

describe('The filters of a search', function () {
    test('read the ratings a viewer asks by its bits', function () {
        expect(SearchFilters::ratings((1 << 24) | (1 << 26)))->toBe(['general', 'adult'])
            ->and(SearchFilters::ratings(4 | 8, SearchFilters::CLASSIFIED_BITS))->toBe(['general', 'moderate'])
            ->and(SearchFilters::ratings(0))->toBe([]);
    });

    test('give the terms of places, land, events and classifieds, under a table alias', function () {
        [$places, $values] = SearchFilters::places('quiet beach', 9, ['general', 'moderate'], 'p');
        [$land, $landValues] = SearchFilters::land('estate', 0, 512, ['adult']);
        [$events] = SearchFilters::events('u', 0, [], '', 1_700_000_000);
        [$classifieds, $classifiedValues] = SearchFilters::classifieds('', 2, ['moderate']);

        expect($places)->toBe(['(p.parcelname LIKE :text OR p.description LIKE :text)', 'p.searchcategory = :category', "(p.mature = 'PG' OR p.mature = 'Mature')"])
            ->and($values)->toBe(['text' => '%quiet%beach%', 'category' => 9])
            // A price of 0 is asked as one: free land only; no area limit is none asked
            ->and($land)->toBe(['parentestate <> 1', 'saleprice <= :price', 'area >= :area', "(mature = 'Adult')"])
            ->and($landValues)->toBe(['price' => 0, 'area' => 512])
            ->and(SearchFilters::land()[0])->toBe([])
            ->and($events)->toBe(['dateUTC + duration * 60 >= 1700000000'])
            ->and($classifieds)->toBe(['(classifiedflags & 8)', 'category = :category'])
            ->and($classifiedValues)->toBe(['category' => 2])
            ->and(SearchFilters::classifieds('', now: 1_700_000_000)[0])->toBe(['expirationdate > 1700000000']);
    });
});
