<?php

declare(strict_types=1);

/**
 * The models of the search (SearchPlace, LandSale, Event, Classified) find what the search of the viewers (query.php, SearchQuery)
 * finds, for every criteria a viewer gives; their lists sort and page; their items send to their place by their own links.
 */

use W4OS\Engine\Api\Api;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Hypergrid\Destination;
use W4OS\Engine\Models\Classified;
use W4OS\Engine\Models\Event;
use W4OS\Engine\Models\LandSale;
use W4OS\Engine\Models\SearchPlace;
use W4OS\Engine\Services\SearchFilters;
use W4OS\Engine\Services\SearchQuery;
use W4OS\Engine\Testing\ClockFake;

/** A grid whose search has places, land for sale, events and classifieds of every rating and of several categories. */
function search_models(): array
{
    $dir = test_tmp() . '/search-models-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://grid.example.org\"\n PublicPort = \"8002\"\n[Hypergrid]\n GatekeeperURI = \"\${Const|BaseURL}:\${Const|PublicPort}\"\n[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;\"\n");
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $now = time();
    $pdo->exec('CREATE TABLE regionsregister (regionUUID CHAR(36), regionname VARCHAR(255))');
    $pdo->exec("INSERT INTO regionsregister VALUES ('r1', 'Welcome'), ('r2', 'Plaza')");
    $pdo->exec('CREATE TABLE allparcels (regionUUID CHAR(36), parcelname VARCHAR(255), ownerUUID CHAR(36), groupUUID CHAR(36), landingpoint VARCHAR(255), parcelUUID CHAR(36), infoUUID CHAR(36), parcelarea INT, gatekeeperURL VARCHAR(255))');
    $pdo->exec('CREATE TABLE parcels (parcelUUID CHAR(36), regionUUID CHAR(36), parcelname VARCHAR(255), description TEXT, landingpoint VARCHAR(255), searchcategory INT, mature VARCHAR(10), dwell FLOAT, imageUUID CHAR(36), infouuid CHAR(36), gatekeeperURL VARCHAR(255))');
    $pdo->exec("INSERT INTO parcels VALUES
        ('p1', 'r1', 'Beach house', 'Quiet sand', '10/20/30', 10, 'PG', 12, NULL, 'i1', 'http://grid.example.org:8002'),
        ('p2', 'r1', 'Beach club', 'Night and sand', '1/2/3', 7, 'Mature', 50, NULL, 'i2', 'http://grid.example.org:8002'),
        ('p3', 'r2', 'Club Noir', 'Adults only', '5/5/5', 7, 'Adult', 80, NULL, 'i3', 'http://grid.example.org:8002'),
        ('p4', 'r2', 'Plaza shop', 'Beach wear', '6/6/6', 11, 'PG', 3, NULL, 'i4', 'http://grid.example.org:8002')");
    $pdo->exec('CREATE TABLE parcelsales (regionUUID CHAR(36), parcelname VARCHAR(255), parcelUUID CHAR(36), area INT, saleprice INT, landingpoint VARCHAR(255), infoUUID CHAR(36), dwell INT, parentestate INT, mature VARCHAR(10), gatekeeperURL VARCHAR(255))');
    $pdo->exec("INSERT INTO parcelsales VALUES
        ('r1', 'Lot mainland', 's1', 512, 1000, '5/5/25', 'j1', 0, 1, 'PG', 'http://grid.example.org:8002'),
        ('r1', 'Lot estate', 's2', 1024, 400, '6/6/25', 'j2', 0, 2, 'Mature', 'http://grid.example.org:8002'),
        ('r2', 'Lot adult', 's3', 256, 0, '7/7/25', 'j3', 0, 3, 'Adult', 'http://grid.example.org:8002')");
    $pdo->exec('CREATE TABLE events (owneruuid CHAR(36), name VARCHAR(255), eventid INTEGER PRIMARY KEY, creatoruuid CHAR(36), category INT, description TEXT, dateUTC INT, duration INT, covercharge INT, coveramount INT, simname VARCHAR(255), parcelUUID CHAR(36), globalPos VARCHAR(255), eventflags INT, gatekeeperURL VARCHAR(255), landingpoint VARCHAR(35))');
    $event = static fn(string $name, int $category, string $text, int $at, int $flags, string $sim = 'Welcome'): string => "('o', '$name', NULL, 'c', $category, '$text', $at, 60, 0, 0, '$sim', 'p', '128,128,25', $flags, 'http://grid.example.org:8002', '128/128/25')";
    $pdo->exec('INSERT INTO events VALUES ' . implode(', ', [
        $event('Jazz night', 20, 'Live jazz', $now + 3600, 0),
        $event('Jazz club', 20, 'Late jazz', $now + 7200, 1),
        $event('Adult party', 23, 'Night', $now + 3600, 2),
        $event('Tomorrow talk', 18, 'Discussion', $now + 90000, 0),
        $event('Gone', 19, 'Over', $now - 90000, 0),
        $event('Far away', 18, 'Elsewhere', $now + 3600, 0, 'grid.example.org:8002 Far Island'),
    ]));
    $pdo->exec('CREATE TABLE classifieds (classifieduuid CHAR(36), creatoruuid CHAR(36), creationdate INT, expirationdate INT, category INT, name VARCHAR(255), description TEXT, parceluuid CHAR(36), parentestate INT, snapshotuuid CHAR(36), simname VARCHAR(255), posglobal VARCHAR(255), parcelname VARCHAR(255), classifiedflags INT, priceforlisting INT)');
    $pdo->exec("INSERT INTO classifieds VALUES
        ('c1', 'u', 1, " . ($now + 86400) . ", 1, 'Shoes', 'New shoes', 'p', 1, NULL, 'Welcome', '256010,256020,25', 'Shop', 4, 10),
        ('c2', 'u', 2, " . ($now + 86400) . ", 2, 'Land to rent', 'Big lot', 'p', 1, NULL, 'Plaza', '256010,256020,25', 'Lot', 8, 50),
        ('c3', 'u', 3, " . ($now + 86400) . ", 9, 'Adult shoes', 'Shoes', 'p', 1, NULL, 'Plaza', '256010,256020,25', 'Club', 64, 30),
        ('c4', 'u', 4, " . ($now - 10) . ", 1, 'Old shoes', 'Expired', 'p', 1, NULL, 'Welcome', '256010,256020,25', 'Shop', 4, 99)");
    $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36) PRIMARY KEY, ScopeID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), Email VARCHAR(64), Created INT, UserLevel INT DEFAULT 0, UserFlags INT DEFAULT 0, UserTitle VARCHAR(64) DEFAULT \'\', active INT DEFAULT 1)');
    Connections::using(static fn() => $pdo);
    $clock = new ClockFake();
    $clock->now = (float) $now;

    return [GridConfig::fromFolder($dir), new SearchQuery($pdo, 'regionsregister', profiles: $pdo, clock: $clock)];
}

/** The names of a list of results, in order of name. */
function search_names(array $names): array
{
    $names = array_map('strval', $names);
    sort($names);

    return $names;
}

/** @return list<list<string>> every combination of the ratings, none too */
function search_ratings(): array
{
    return [[], ['general'], ['moderate'], ['adult'], ['general', 'moderate'], ['moderate', 'adult'], ['general', 'moderate', 'adult']];
}

/** The names a model finds for a search and its criteria. */
function model_names(string $class, GridConfig $config, string $search, array $filters): array
{
    return search_names(array_map(static fn($item): string => $item->name(), $class::all($config, ['search' => $search, 'filters' => array_map('strval', array_filter($filters, static fn($v): bool => $v !== null && $v !== ''))])->items()));
}

afterEach(fn() => Connections::using(null));

describe('The models of the search', function () {
    test('find the places the viewers find, by words, category and ratings', function () {
        [$config, $viewer] = search_models();
        foreach (['beach', 'club', 'sand', 'plaza'] as $q) {
            foreach ([0, 7, 10, 11] as $category) {
                foreach (search_ratings() as $ratings) {
                    $bits = array_sum(array_intersect_key(SearchFilters::RATING_BITS, array_flip($ratings)));
                    expect(model_names(SearchPlace::class, $config, $q, ['category' => $category, 'rating' => implode(',', $ratings)]))
                        ->toBe(search_names(array_column($viewer->places(['text' => $q, 'category' => $category, 'flags' => $bits])['data'], 'name')), "places $q $category " . implode(',', $ratings));
                }
            }
        }
    });

    test('find the land for sale the viewers find, by type, price, area and ratings', function () {
        [$config, $viewer] = search_models();
        foreach (['any' => 4294967295, 'mainland' => 8, 'estate' => 16] as $type => $typeBits) {
            foreach ([null, 0, 500] as $price) {
                foreach ([null, 600] as $area) {
                    foreach (search_ratings() as $ratings) {
                        $bits = array_sum(array_intersect_key(SearchFilters::RATING_BITS, array_flip($ratings))) | ($price === null ? 0 : 1 << 20) | ($area === null ? 0 : 1 << 21);
                        expect(model_names(LandSale::class, $config, '', ['type' => $type, 'price' => $price, 'area' => $area, 'rating' => implode(',', $ratings)]))
                            ->toBe(search_names(array_column($viewer->land(['type' => $typeBits, 'flags' => $bits, 'price' => $price ?? 0, 'area' => $area ?? 0])['data'], 'name')), "land $type $price $area " . implode(',', $ratings));
                    }
                }
            }
        }
    });

    test('find the events the viewers find, to come or of a day, by category, ratings and words', function () {
        [$config, $viewer] = search_models();
        foreach (['u', 0, 1, -1] as $day) {
            foreach ([0, 20, 23] as $category) {
                foreach (['', 'jazz', 'late'] as $words) {
                    foreach (search_ratings() as $ratings) {
                        $bits = array_sum(array_intersect_key(SearchFilters::RATING_BITS, array_flip($ratings)));
                        expect(model_names(Event::class, $config, $words, ['day' => (string) $day, 'category' => $category, 'rating' => implode(',', $ratings)]))
                            ->toBe(search_names(array_column($viewer->events(['text' => "$day|$category|$words", 'flags' => $bits])['data'], 'name')), "events $day $category $words " . implode(',', $ratings));
                    }
                }
            }
        }
    });

    test('find the classifieds the viewers find, by words, category and ratings, none expired', function () {
        [$config, $viewer] = search_models();
        foreach (['', 'shoes', 'lot'] as $q) {
            foreach ([0, 1, 2, 9] as $category) {
                foreach (search_ratings() as $ratings) {
                    $bits = array_sum(array_intersect_key(SearchFilters::CLASSIFIED_BITS, array_flip($ratings)));
                    expect(model_names(Classified::class, $config, $q, ['category' => $category, 'rating' => implode(',', $ratings)]))
                        ->toBe(search_names(array_column($viewer->classifieds(['text' => $q, 'category' => $category, 'flags' => $bits])['data'], 'name')), "classifieds $q $category " . implode(',', $ratings))
                        ->not->toContain('Old shoes');
                }
            }
        }
    });

    test('sort as the viewers sort and come by pages', function () {
        [$config] = search_models();
        $names = static fn($collection): array => array_map(static fn($item): string => $item->name(), $collection->items());

        expect($names(SearchPlace::all($config, ['search' => 'beach'])))->toBe(['Beach club', 'Beach house', 'Plaza shop'])
            ->and($names(SearchPlace::all($config, ['search' => 'beach', 'sort' => 'name'])))->toBe(['Beach club', 'Beach house', 'Plaza shop'])
            ->and($names(SearchPlace::all($config, ['search' => 'beach', 'sort' => 'traffic asc'])))->toBe(['Plaza shop', 'Beach house', 'Beach club'])
            ->and($names(LandSale::all($config)))->toBe(['Lot adult', 'Lot estate', 'Lot mainland'])
            ->and($names(Classified::all($config, ['perPage' => 2, 'page' => 2])))->toBe(['Shoes'])
            ->and(Classified::all($config, ['perPage' => 2])->total())->toBe(3);
    });

    test('send to their place by their own links: their region, their point, on the grid of their gatekeeper', function () {
        [$config] = search_models();
        $place = SearchPlace::find($config, 'p1');
        $far = Event::all($config, ['search' => 'elsewhere'])->items()[0];
        $ad = Classified::find($config, 'c1');

        expect($place->tpUri())->toBe('grid.example.org:8002/Welcome/10/20/30')
            ->and($place->detail()['links']['browser'][Destination::HOP]['link'])->toBe('hop://grid.example.org:8002/Welcome/10/20/30')
            ->and($far->tpUri())->toBe('grid.example.org:8002/Far Island/128/128/25')
            ->and($ad->tpUri())->toBe('grid.example.org:8002/Welcome/10/20/25')
            ->and($ad->detail()['location'])->toBe('Shop, Welcome (10, 20, 25)')
            ->and(LandSale::find($config, 's2')->detail()['pricePerMeter'])->toBe(0.39);
    });

    test('are read by anybody through the API, the criteria as filters', function () {
        [$config] = search_models();
        $api = new Api((new W4OS\Engine\Testing\Machine())->environment, config: static fn() => $config);
        $list = $api->list('search/places', ['search' => 'beach', 'filter' => ['rating' => 'general'], 'sort' => '-traffic'], 'demo')->document;
        $one = $api->get('search/places', 'p1', 'demo')->document;
        $refused = $api->list('search/places', ['filter' => ['rating' => 'none']], 'demo');

        expect(array_column(array_column($list['data'], 'attributes'), 'name'))->toBe(['Beach house', 'Plaza shop'])
            ->and($list['data'][0]['links']['self'])->toBe('/grids/demo/search/places/p1')
            ->and($one['data']['attributes']['traffic'])->toBe(12)
            ->and($refused->status)->toBe(400)
            ->and(W4OS\Engine\Api\Access::of('GET', '/grids/{grid}/search/places', 'listSearchPlaces')['level'])->toBeNull();
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');
