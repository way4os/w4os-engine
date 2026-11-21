<?php

declare(strict_types=1);

/**
 * The form that makes an avatar, for the managers of a grid: the fields of the creation of w4os-wp (v1/profile.php, the form
 * "Create Avatar"), in its words, described by the operation that makes one (`x-form` of createAvatar) and opened from the list of
 * the avatars (`create` of its `x-list`).
 */

use W4OS\Engine\Api\Access;
use W4OS\Engine\Api\Contract;
use W4OS\Engine\I18n\Catalog;

/** @return array<string,mixed> the operation that makes an avatar */
function creation_operation(): array
{
    return Contract::document()['paths']['/grids/{grid}/avatars']['post'];
}

describe('The form that makes an avatar', function () {
    test('has the fields of w4os-wp: the first and the last name, the email, the password, the model', function () {
        $form = creation_operation()['x-form'] ?? [];
        $fields = array_column($form['fields'] ?? [], null, 'name');
        $name = ['type' => 'string', 'required' => true, 'pattern' => '[A-Za-z][A-Za-z0-9]*', 'maxLength' => 32];

        expect(array_keys($fields))
            ->toBe(['firstName', 'lastName', 'email', 'password', 'model'])
            ->and($fields['firstName'])
            ->toMatchArray($name + ['label' => 'first_name', 'description' => 'first_name_help'])
            ->and($fields['lastName'])
            ->toMatchArray($name + ['label' => 'last_name', 'description' => 'last_name_help'])
            // The email is not needed to make one
            ->and($fields['email'])
            ->toBe(['name' => 'email', 'label' => 'email', 'type' => 'email'])
            ->and($fields['password'])
            ->toMatchArray(['type' => 'secret', 'required' => true, 'description' => 'password_help'])
            // The models side by side with their picture, as w4os-wp shows them; none chosen: the default outfit
            ->and($fields['model'])
            ->toMatchArray([
                'label' => 'model',
                'type' => 'select',
                'widget' => 'radio',
                'description' => 'model_help',
                'source' => ['path' => '/grids/{grid}/avatar-models', 'value' => 'uuid', 'label' => 'label', 'picture' => 'picture', 'empty' => 'models_none'],
            ])
            ->and($fields['model'])
            ->not->toHaveKey('required')
            ->and([$form['title'] ?? null, $form['description'] ?? null, $form['submit'] ?? null])
            ->toBe(['action_new_avatar', ['register_text'], 'action_create_avatar']);

        return $form;
    });

    test('sends what the operation takes', function (array $form) {
        $taken = creation_operation()['requestBody']['content']['application/vnd.api+json']['schema']['properties']['data']['properties']['attributes']['properties'];

        expect(array_diff(array_column($form['fields'], 'name'), array_keys($taken)))->toBe([]);
    })->depends('has the fields of w4os-wp: the first and the last name, the email, the password, the model');

    test('says it in the words of w4os-wp, in every language', function (array $form) {
        $keys = [$form['title'], $form['submit'], ...$form['description'], ...array_merge(...array_map(
            static fn(array $field): array => array_values(array_filter([$field['label'], $field['description'] ?? null, $field['source']['empty'] ?? null])),
            $form['fields'],
        ))];
        $en = Catalog::MESSAGES;
        $fr = Catalog::messages('fr_FR');

        expect(array_diff($keys, array_keys($en)))
            ->toBe([])
            ->and([$en['action_new_avatar'], $en['action_create_avatar'], $en['first_name'], $en['model'], $en['first_name_help']])
            ->toBe([
                'New Avatar',
                'Create Avatar',
                'First name',
                'Choose the first outfit for your avatar.',
                'First name must start with a letter and contain only letters and numbers',
            ])
            ->and([$fr['action_new_avatar'], $fr['action_create_avatar']])
            ->toBe(['Nouvel avatar', 'Créer un avatar']);
        // Its own texts are translated in every language (the others may read the same in some: Email)
        $own = ['action_new_avatar', 'action_create_avatar', 'first_name_help', 'last_name_help', 'model', 'model_help'];
        foreach (Catalog::LOCALES as $language) {
            $texts = Catalog::messages($language);
            expect(array_filter($own, static fn(string $key): bool => ($texts[$key] ?? $en[$key]) === $en[$key]))->toBe([]);
        }
    })->depends('has the fields of w4os-wp: the first and the last name, the email, the password, the model');
});

describe('The list of the avatars', function () {
    test('opens the form that makes one, by its action New Avatar, for the customer service and above', function () {
        $create = Contract::document()['paths']['/grids/{grid}/avatars']['get']['x-list']['create'] ?? null;

        expect($create)
            ->toBe(['label' => 'action_new_avatar', 'method' => 'POST', 'path' => '/grids/{grid}/avatars', 'operation' => 'createAvatar'])
            ->and(creation_operation()['operationId'])
            ->toBe('createAvatar')
            ->and(Access::level('createAvatar'))
            ->toBe(100)
            // Not an action of a row
            ->and(array_column(Contract::document()['paths']['/grids/{grid}/avatars']['get']['x-list']['actions'], 'name'))
            ->not->toContain('create');
    });
});
