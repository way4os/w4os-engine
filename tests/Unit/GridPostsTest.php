<?php

declare(strict_types=1);

/**
 * The posts of a grid, of every avatar, for the admin list of w4os-wp's Flux (its post type: the post, its Author, its date): read,
 * searched, filtered by their author, sorted, paged, and deleted by the customer service and above.
 */

use W4OS\Engine\Api\Access;
use W4OS\Engine\Api\Api;
use W4OS\Engine\Api\Contract;
use W4OS\Engine\Api\Router;
use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Flux\Posts;
use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\Testing\Machine;

const POSTS_ADA = '11111111-1111-4111-8111-111111111111';
const POSTS_BOB = '22222222-2222-4222-8222-222222222222';

/** @return array{0:Router,1:Posts,2:list<array<string,mixed>>} the API of a grid whose profiles hold three posts */
function posts_grid(): array
{
    $pdo = new W4OS\Engine\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    Connections::using(static fn() => $pdo);
    $machine = new Machine();
    $config = GridConfig::fromFolder($machine->grid);
    $posts = Posts::for($config);
    $written = [
        $posts->add(POSTS_ADA, 'Ada Love', 'Hello world', 1_700_000_000),
        $posts->add(POSTS_BOB, 'Bob Builder', 'A new shop opens', 1_700_000_100),
        $posts->add(POSTS_ADA, 'Ada Love', 'The garden is open', 1_700_000_200),
    ];

    return [new Router(new Api($machine->environment, config: static fn(): GridConfig => $config)), $posts, $written];
}

afterEach(fn() => Connections::using(null));

describe('The posts of a grid', function () {
    test('are listed, the newest first, with their author and its profile', function () {
        [$router] = posts_grid();
        $list = $router->get('/grids/demo/posts');
        $rows = array_column($list->document['data'] ?? [], 'attributes');

        expect($list->status)
            ->toBe(200)
            ->and(array_column($rows, 'content'))
            ->toBe(['The garden is open', 'A new shop opens', 'Hello world'])
            ->and($rows[1])
            ->toMatchArray(['author' => POSTS_BOB, 'name' => 'Bob Builder', 'created' => 1_700_000_100])
            ->and(array_key_exists('authorProfile', $rows[1]))
            ->toBeTrue()
            ->and($list->document['meta']['total'])
            ->toBe(3);
    });

    test('are searched, filtered by their author, sorted and paged', function () {
        [$router] = posts_grid();
        $contents = static fn(array $query): array => array_column(array_column($router->get('/grids/demo/posts', $query)->document['data'] ?? [], 'attributes'), 'content');

        expect([
            $contents(['search' => 'garden']),
            $contents(['search' => 'bob']),
            $contents(['filter' => ['author' => POSTS_ADA]]),
            $contents(['sort' => 'created']),
            $contents(['sort' => 'name']),
            $contents(['page' => ['size' => 2, 'number' => 2]]),
        ])->toBe([
            ['The garden is open'],
            ['A new shop opens'],
            ['The garden is open', 'Hello world'],
            ['Hello world', 'A new shop opens', 'The garden is open'],
            ['The garden is open', 'Hello world', 'A new shop opens'],
            ['Hello world'],
        ]);
    });

    test('are deleted one by one, the one that is not there a 404 of a post', function () {
        [$router, $posts, $written] = posts_grid();

        $deleted = $router->delete('/grids/demo/posts/' . $written[1]['id']);
        $again = $router->delete('/grids/demo/posts/' . $written[1]['id']);

        expect([$deleted->status, $again->status, $again->document['resource'] ?? null])
            ->toBe([204, 404, 'posts'])
            ->and($posts->find($written[1]['id']))
            ->toBeNull()
            ->and($router->get('/grids/demo/posts/' . $written[0]['id'])->document['data']['attributes']['content'] ?? null)
            ->toBe('Hello world');
    });

    test('are a list in the words of w4os-wp: the post, its Author, its date; deleted by the customer service and above', function () {
        $list = Contract::document()['paths']['/grids/{grid}/posts']['get']['x-list'];
        $columns = array_column($list['columns'], null, 'name');
        $en = Catalog::MESSAGES;

        expect(array_keys($columns))
            ->toBe(['content', 'name', 'created'])
            ->and(array_map(static fn(array $column): string => $en[$column['label']], array_values($columns)))
            ->toBe(['Flux Post', 'Author', 'Date'])
            ->and($columns['name'])
            ->toMatchArray(['type' => 'link', 'link' => 'authorProfile', 'mirrors' => 'author', 'sort' => 'name'])
            ->and($columns['created'])
            ->toMatchArray(['type' => 'datetime', 'sort' => 'created'])
            ->and($list['sort']['default'])
            ->toBe('-created')
            ->and(array_column($list['actions'], 'name'))
            ->toBe(['delete'])
            ->and($list['actions'][0])
            ->toMatchArray(['method' => 'DELETE', 'path' => '/grids/{grid}/posts/{id}', 'ask' => 'ask_delete_post'])
            ->and([Access::level('listPosts'), Access::level('getPost'), Access::level('deletePost')])
            ->toBe([100, 100, 100]);
    });
});
