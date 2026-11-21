<?php

use W4OS\Engine\Api\Contract;
use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\Models\Avatar;

describe('The banner of a profile', function () {
    test('is the picture, the name, the birth and the role of the avatar, as its form marks them (banner), on every tab', function () {
        $fields = Contract::document()['paths']['/grids/{grid}/avatars/{id}']['get']['x-form']['fields'] ?? [];

        expect(Avatar::itemForm()->banner())->toBe(['profileImage', 'name', 'created', 'role'])
            ->and(array_values(array_column(array_filter($fields, fn (array $field) => ! empty($field['banner'])), 'name')))->toBe(['profileImage', 'name', 'created', 'role'])
            // The role reads without its label, as in a list (`bare`)
            ->and(array_column($fields, 'bare', 'name'))->toBe(['role' => true])
            // The rest of the in-world tab is the About tab
            ->and(Catalog::MESSAGES['profile_tab_about'] ?? null)->toBe('About')
            ->and(Catalog::messages('fr_FR')['profile_tab_about'] ?? null)->toBe('À propos');
    });
});
