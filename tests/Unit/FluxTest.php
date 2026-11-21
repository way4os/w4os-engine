<?php

declare(strict_types=1);

use W4OS\Engine\Api\Access;
use W4OS\Engine\Flux\FluxImport;
use W4OS\Engine\Flux\Posts;

/**
 * The stream of an avatar (w4os-flux, its first step): its posts, kept by the engine in the database of the profiles, listed newest
 * first, written by the avatar or a manager, deleted by them; and the posts of w4os-wp brought in.
 */

const FLUX_ADA = '11111111-1111-1111-1111-111111111111';

describe('The posts of an avatar', function () {
    test('are kept in the database of the profiles, newest first, plain text of 1000 characters at most', function () {
        $pdo = avatar_database();
        avatars_in($pdo);
        $posts = Posts::for(avatar_grid());
        $posts->add(FLUX_ADA, 'Ada Love', 'First <b>post</b>', 1_700_000_000);
        $second = $posts->add(FLUX_ADA, 'Ada Love', '  Hello https://example.org  ', 1_700_000_100);

        expect(array_column($posts->of(FLUX_ADA), 'content'))
            ->toBe(['Hello https://example.org', 'First post'])
            ->and($posts->count(FLUX_ADA))
            ->toBe(2)
            ->and($second)
            ->toMatchArray(['avatar' => FLUX_ADA, 'name' => 'Ada Love', 'created' => 1_700_000_100])
            ->and($pdo->table_exists(Posts::TABLE))
            ->toBeTrue()
            ->and([Posts::problem(''), Posts::problem(str_repeat('a', 1001)), Posts::problem('ok')])
            ->toBe(['empty', 'too long', null])
            ->and($posts->remove($second['id']))
            ->toBeTrue()
            ->and($posts->count(FLUX_ADA))
            ->toBe(1);
    });

    test('are listed, made and deleted through the API, by the avatar for itself or a manager', function () {
        avatars_in(avatar_database());
        [$router] = access_api();
        $made = $router->post('/grids/demo/avatars/' . FLUX_ADA . '/posts', access_body('posts', ['content' => 'From the API']));
        $empty = $router->post('/grids/demo/avatars/' . FLUX_ADA . '/posts', access_body('posts', ['content' => ' ']));
        $listed = $router->get('/grids/demo/avatars/' . FLUX_ADA . '/posts');
        $id = $made->document['data']['id'] ?? '';
        $other = $router->delete('/grids/demo/avatars/22222222-2222-2222-2222-222222222222/posts/' . $id);
        $deleted = $router->delete('/grids/demo/avatars/' . FLUX_ADA . "/posts/$id");

        expect([$made->status, $made->document['data']['attributes']['content'] ?? null, $made->document['data']['attributes']['name'] ?? null])
            ->toBe([201, 'From the API', 'Ada Love'])
            ->and([$empty->status, $empty->document['code'] ?? null])
            ->toBe([400, 'post-invalid'])
            ->and(array_column(array_column($listed->document['data'], 'attributes'), 'content'))
            ->toBe(['From the API'])
            // A post is deleted under its author only
            ->and([$other->status, $deleted->status])
            ->toBe([404, 204])
            ->and([Access::isSelf('listAvatarPosts'), Access::isSelf('createAvatarPost'), Access::isSelf('deleteAvatarPost')])
            ->toBe([true, true, true])
            ->and([Access::level('createAvatarPost'), Access::level('deleteAvatarPost')])
            ->toBe([100, 100]);
    });
});

describe('The form of a post', function () {
    test('is described by the operation that makes one (x-form): its text, its length, the action that deletes one, their texts in the catalog', function () {
        $form = W4OS\Engine\Api\Contract::document()['paths']['/grids/{grid}/avatars/{id}/posts']['post']['x-form'] ?? null;
        $field = $form['fields'][0] ?? [];
        $delete = array_column($form['actions'] ?? [], null, 'name')['deletePost'] ?? [];

        expect($field)->toMatchArray(['name' => 'content', 'type' => 'text', 'maxLength' => W4OS\Engine\Flux\Posts::MAX, 'required' => true])
            ->and($form['submit'] ?? null)->toBe('action_post')
            ->and($delete)->toMatchArray(['method' => 'DELETE', 'path' => '/grids/{grid}/avatars/{id}/posts/{post}', 'ask' => 'ask_delete_post'])
            // The operation of the action is one of the contract
            ->and(W4OS\Engine\Api\Contract::document()['paths'][$delete['path']]['delete']['operationId'] ?? null)->toBe('deleteAvatarPost');
        foreach ([$field['label'], $field['description'], $form['submit'], $delete['label'], $delete['ask'], W4OS\Engine\I18n\Catalog::doneKey('post'), W4OS\Engine\I18n\Catalog::doneKey('deletePost')] as $key) {
            expect(W4OS\Engine\I18n\Catalog::MESSAGES)->toHaveKey($key)
                ->and(W4OS\Engine\I18n\Catalog::messages('fr_FR'))->toHaveKey($key);
        }
    });
});

describe('The posts of w4os-wp', function () {
    test('are brought in from its database, planned first, once each', function () {
        $pdo = avatar_database();
        avatars_in($pdo);
        $wp = new W4OS\Engine\Data\OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $wp->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type VARCHAR(20), post_status VARCHAR(20), post_content TEXT, post_date_gmt VARCHAR(19))');
        $wp->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INT, meta_key VARCHAR(255), meta_value TEXT)');
        $wp->exec("INSERT INTO wp_posts VALUES (7, 'flux_post', 'publish', '<p>Old news</p>', '2025-03-01 10:00:00'), (8, 'flux_post', 'draft', 'Not yet', '2025-03-02 10:00:00'), (9, 'post', 'publish', 'A page', '2025-03-03 10:00:00'), (10, 'flux_post', 'publish', 'Nobody', '2025-03-04 10:00:00')");
        $wp->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (7, '_avatar_uuid', '" . FLUX_ADA . "'), (7, '_avatar_name', 'Ada Love'), (8, '_avatar_uuid', '" . FLUX_ADA . "')");
        $import = static fn(): FluxImport => FluxImport::prepare(avatar_grid(), $wp, 'wp_');
        $plan = $import()->document();
        $import()->apply();
        $again = $import()->document();

        expect([$plan['posts'], $plan['imported'], $plan['avatars']])
            ->toBe([1, 0, [['avatar' => FLUX_ADA, 'name' => 'Ada Love', 'posts' => 1]]])
            // A published post without an author is said, not brought
            ->and(array_column($plan['skipped'], 'id'))
            ->toBe([10])
            ->and(Posts::for(avatar_grid())->of(FLUX_ADA)[0])
            ->toMatchArray(['content' => 'Old news', 'created' => 1_740_823_200, 'source' => 'w4os-wp:7'])
            ->and([$again['posts'], $again['imported']])
            ->toBe([0, 1]);
    });

    test('are found from the wp-config.php of the site', function () {
        $dir = test_tmp() . '/wp-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/wp-config.php", "<?php\ndefine( 'DB_NAME', 'wordpress' );\ndefine('DB_USER', 'wp');\ndefine( 'DB_PASSWORD', 'a secret' );\ndefine( 'DB_HOST', 'db.example.org:3307' );\n\$table_prefix = 'w4_';\n");

        expect(FluxImport::wordpress("$dir/wp-config.php"))
            ->toBe(['host' => 'db.example.org:3307', 'name' => 'wordpress', 'user' => 'wp', 'pass' => 'a secret', 'prefix' => 'w4_']);
    });
});
