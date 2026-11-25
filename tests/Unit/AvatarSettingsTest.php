<?php

declare(strict_types=1);

/**
 * The settings of the avatars (`[Avatars]` of Helpers.ini), checked when an avatar is made: more than one avatar for an email
 * (allowed by default), the names no new avatar may have (the name of the grid always). avatars_in() and avatar_creation_database()
 * of AvatarTest.
 */

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\Models\Avatar;
use W4OS\Engine\Models\GridSettings;

/** A grid named My Grid, with these lines in its Helpers.ini. */
function avatar_settings_grid(string $helpers = ''): GridConfig
{
    $dir = test_tmp() . '/avatar-settings-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;\"\n[GridInfoService]\n gridname = \"My Grid\"\n");
    file_put_contents("$dir/Helpers.ini", "[Helpers]\n$helpers");

    return GridConfig::fromFolder($dir, ['Helpers' => 'Helpers.ini']);
}

/** The reason an avatar is refused, null when it is made. */
function avatar_refusal(GridConfig $config, array $values): ?string
{
    try {
        Avatar::create($config, $values + ['password' => 'secret']);
    } catch (InvalidValue $e) {
        return $e->reason;
    }

    return null;
}

describe('The settings of the avatars', function () {
    test('are a page: more than one avatar for an email, on by default; the forbidden names, Default, Test, Admin by default', function () {
        $form = GridSettings::form('avatars');
        $fields = array_column($form['fields'], null, 'name');

        expect(array_keys($fields))->toBe(['registration', 'allowMultipleAvatars', 'reservedNames'])
            ->and($fields['allowMultipleAvatars'])->toMatchArray(['type' => 'boolean', 'default' => true, 'label' => 'setting_allow_multiple_avatars'])
            ->and($fields['reservedNames'])->toMatchArray(['type' => 'string', 'multiple' => true, 'default' => ['Default', 'Test', 'Admin'], 'label' => 'setting_reserved_names'])
            ->and(Catalog::MESSAGES[$form['title']])->toBe('Avatar Settings')
            ->and(Catalog::MESSAGES['setting_allow_multiple_avatars_help'])->toBe('Allow more than one avatar for a single email address.')
            ->and(Catalog::MESSAGES['setting_reserved_names'])->toBe('Forbidden names');
        foreach (Catalog::LOCALES as $locale) {
            expect(Catalog::messages($locale, false))->toHaveKeys(['settings_avatars', 'setting_allow_multiple_avatars', 'setting_allow_multiple_avatars_help', 'setting_reserved_names', 'setting_reserved_names_help', 'error_email_taken']);
        }
    });

    test('allow several avatars for an email by default; off, a second one is refused', function () {
        avatars_in(avatar_creation_database());

        expect(avatar_refusal(avatar_settings_grid(), ['name' => 'Jane Doe', 'email' => 'ada@example.org']))->toBeNull()
            ->and(avatar_refusal(avatar_settings_grid("[Avatars]\nAllowMultipleAvatars = false\n"), ['name' => 'John Doe', 'email' => 'ADA@example.org']))->toBe('email-taken')
            ->and(avatar_refusal(avatar_settings_grid("[Avatars]\nAllowMultipleAvatars = false\n"), ['name' => 'John Doe', 'email' => 'john@example.org']))->toBeNull();
    });

    test('forbid the names of the list, the name of the grid always; Default, Test, Admin when the list is not set', function () {
        avatars_in(avatar_creation_database());
        $staff = avatar_settings_grid("[Avatars]\nReservedNames = \"Staff, Support\"\n");

        expect(avatar_refusal($staff, ['name' => 'Jane Staff']))->toBe('name-reserved')
            ->and(avatar_refusal($staff, ['name' => 'Jane Mygrid']))->toBe('name-reserved')
            ->and(avatar_refusal($staff, ['name' => 'Test Person']))->toBeNull()
            ->and(avatar_refusal(avatar_settings_grid(), ['name' => 'Test Someone']))->toBe('name-reserved');
    });
});

describe('The registration of the grid', function () {
    test('is open by default; closed, the grid announces no registration page, its old one kept and given back when it opens again', function () {
        $machine = new W4OS\Engine\Testing\Machine();
        $robust = $machine->grid . '/Robust.HG.ini';
        $machine->write($robust, str_replace("[Const]\n", "[Const]\n    WebURL = \"https://play.example.org\"\n", (string) file_get_contents($robust)) . "\n[GridInfoService]\n    register = \"https://play.example.org/join\"\n");
        $grid = static fn(): W4OS\Engine\Models\Grid => W4OS\Engine\Models\Grid::find($machine->environment, 'demo');
        $register = static fn(): ?string => W4OS\Engine\Config\ConfigReader::file($robust)['GridInfoService']['register'] ?? null;
        $form = array_column(GridSettings::form('avatars')['fields'], null, 'name')['registration'] ?? [];

        $open = (new GridSettings($grid()))->read('avatars')['registration'];
        $closing = new GridSettings($grid());
        $closing->write('avatars', ['registration' => 'closed']);
        $closed = [(new GridSettings($grid()))->read('avatars')['registration'], $register()];
        (new GridSettings($grid()))->write('avatars', ['registration' => 'open']);
        $reopened = $register();
        // Closed again then opened with no page kept: the register page of the site
        (new GridSettings($grid()))->write('avatars', ['registration' => 'closed']);
        file_put_contents($machine->grid . '/Helpers.ini', preg_replace('/^\s*RegisterPage\s*=.*$/m', '', (string) file_get_contents($machine->grid . '/Helpers.ini')));
        (new GridSettings($grid()))->write('avatars', ['registration' => 'open']);

        expect($form)->toMatchArray(['type' => 'select', 'options' => ['open' => 'registration_open', 'closed' => 'registration_closed']])
            ->and($open)->toBe('open')
            ->and($closed)->toBe(['closed', null])
            ->and(array_column($closing->reminders(), 'code'))->toContain('restart-grid')
            // Written with the constant of the site, as the engine writes its addresses
            ->and(trim((string) $reopened, '"'))->toBe('${Const|WebURL}/join')
            ->and(trim((string) $register(), '"'))->toBe('${Const|WebURL}/register');
    });
});
