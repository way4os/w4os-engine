<?php

declare(strict_types=1);

namespace OpenSim\I18n;

use OpenSim\Api\Problem;

/**
 * The texts the websites of a grid show its people (registration, sign-in, account, lost password, email confirmation, avatars
 * of the grid, and one per problem code of the API), and their translations: one source for every bridge (the Grav plugin, w4os).
 * The engine itself does not translate them: it ships them as gettext files, that a bridge turns into its own format.
 *
 * Each text has a stable key (the `msgctxt` of the gettext files), the English text (`msgid`) and its translation (`msgstr`).
 * The text of a problem code is `error_` and the code with `_` for `-` (`error_name_taken`).
 *
 *     Catalog::messages('fr_FR');   // ['register_title' => 'Créer mon avatar', ...], the translated texts only
 *     Catalog::messages('en');      // the English texts
 *     Catalog::file('fr_FR');       // share/languages/opensim-engine-fr_FR.po
 *     Catalog::statusKey('pending'); // status_pending; roleKey('god-like'): role_god_like, the texts of an avatar's status and role
 *
 * To change a text, edit MESSAGES, then run `composer translations` (the .pot, and the .po files merged with it).
 */
final class Catalog
{
    public const DOMAIN = 'opensim-engine';

    /** The language of the source texts. */
    public const SOURCE = 'en';

    /** The languages of the catalog. */
    public const LOCALES = ['cy_GB', 'de_DE', 'fr_FR', 'it_IT', 'nl_NL', 'pt_BR'];

    /** The texts, by key, in English. Placeholders are the ones of printf (%s, %1$s). */
    public const MESSAGES = [
        // Home
        'welcome_title' => 'Welcome to %s',
        'guide_title' => 'Destinations guide',

        // Registration
        'register_title' => 'Create My Avatar',
        'register_text' => 'Choose a name below. This is how people will see you in-world.',
        'register_button' => 'Create My Avatar',
        // The link to the registration page (menu, sign-in page)
        'register' => 'Register',
        'have_avatar' => 'Already have an avatar?',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'password' => 'Password',
        'password_confirm' => 'Confirm password',
        'password_help' => '6 to 16 characters, without double quote. The same password logs you in here and in-world.',
        'model' => 'Choose the first outfit for your avatar.',
        'model_help' => 'You can always change your appearance in-world.',
        'terms_agree' => 'I agree to the terms on page %s.',
        'registered' => 'Avatar %s created successfully. Log in with its name or your email.',
        'registered_confirm' => 'Almost there: click the link of the mail sent to your address to create %s.',
        'registered_not_sent' => 'Almost there, but the confirmation mail could not be sent. Ask for a new one below to create %s, or contact the managers of the grid.',
        'registered_test' => 'Almost there. Test site, no mail: create %1$s with this link: %2$s',

        // Email confirmation
        'confirm_title' => 'Confirm your email',
        'confirm_text' => 'Your avatar is created once you click the link of the mail we sent you. Nothing within a few minutes? Have a look in the spam folder.',
        'confirmed' => 'Your email is confirmed, %s is ready. You can log in now, here and in-world.',
        'resend_title' => 'Get a new link',
        'resend_button' => 'Send a new link',
        'confirm_resent' => 'A new link is on its way.',
        'confirm_resent_test' => 'Test site, no mail: confirm with this link: %s',
        'avatar_name' => 'Avatar Name',

        // Log in, lost password
        'login_title' => 'Login',
        'log_in' => 'Log in',
        'logout' => 'Logout',
        'login' => 'Avatar name or email',
        // The headline of a refused sign-in, above the reason (error_account_disabled, error_email_unconfirmed...)
        'sign_in_failed' => 'Login failed',
        'forgot_title' => 'Forgotten password',
        'forgot_text' => 'Type the name of your avatar or your email: you will get a link to choose a new password.',
        'forgot_button' => 'Send me a link',
        'forgot_sent' => 'If an avatar has this name or this email, a link is on its way to its address.',
        'forgot_test' => 'Test site, no mail: the link of a new password is not shown here, the engine API gives it (send: false).',
        'reset_title' => 'Reset Password',
        'reset_text' => 'Choose the new password of your avatar.',
        'reset_no_token' => 'This page opens from the link of the mail you received.',
        'reset_button' => 'Save the new password',
        'reset_done' => 'Your password is changed. You can log in with it now.',
        'new_password' => 'New password',
        'current_password' => 'Current password',

        // My avatar
        'account_title' => 'Avatar Profile',
        'born' => 'Born',
        'profile_title' => 'Profile',
        'profile_text' => 'What other people see of you in-world (the picture and the interests are set in the viewer).',
        'about' => 'About',
        'wants' => 'Wants to',
        'skills' => 'Skills',
        // The check boxes of a profile, as the API names them (Avatar::profileFlags()): want_ or skill_ and the name, - as _
        'want_build' => 'Build',
        'want_explore' => 'Explore',
        'want_meet' => 'Meet',
        'want_group' => 'Group',
        'want_buy' => 'Buy',
        'want_sell' => 'Sell',
        'want_be_hired' => 'Be Hired',
        'want_hire' => 'Hire',
        'skill_textures' => 'Textures',
        'skill_architecture' => 'Architecture',
        'skill_event_planning' => 'Event Planning',
        'skill_modeling' => 'Modeling',
        'skill_scripting' => 'Scripting',
        'skill_custom_characters' => 'Custom Characters',
        'languages' => 'Languages',
        'first_life' => 'Real Life',
        'profile_url' => 'Web page',
        'edit_profile' => 'Edit profile',
        // The free text under the check boxes of the wants and the skills
        'profile_other' => 'Other',
        // The tabs of a profile, as the legacy profile of Firestorm has them
        'profile_tab_second_life' => '2nd Life',
        'profile_tab_web' => 'Web',
        'profile_tab_interests' => 'Interests',
        'profile_tab_picks' => 'Picks',
        'profile_tab_classifieds' => 'Classifieds',
        'profile_tab_first_life' => '1st Life',
        'profile_tab_notes' => 'My Notes',
        'interests_removed' => 'The viewer no longer shows this section (removed in version %s).',
        'save_button' => 'Save',
        'profile_saved' => 'Your profile is saved.',
        'password_title' => 'Password',
        'password_button' => 'Change the password',
        'password_saved' => 'Your password is changed.',
        'email_pending' => 'Your profile is saved. A link is on its way to %s: click it to make it your email.',
        'email_pending_test' => 'Your profile is saved. Test site, no mail: confirm %1$s with this link: %2$s',
        'email_changed' => 'Your email is now %s.',

        // Avatars of the grid (managers)
        'menu_admin' => 'Admin',
        'manage_title' => 'Avatars',
        'search_avatars' => 'Name or email',
        'search' => 'Search',
        'no_avatars' => 'No result',
        'avatars_count' => '%s avatars',
        'status' => 'Status',
        'last_seen' => 'Last Seen',
        'created' => 'Created',
        'actions' => 'Actions',
        'god' => 'God',
        'online' => 'Online',
        // The status of an avatar, as the API says it (Avatar::STATUSES): status_ and the status
        'status_active' => 'Active',
        'status_disabled' => 'Disabled',
        'status_pending' => 'Pending',
        // The role of an avatar, as the API says it (Avatar::ROLES): role_ and the role, - as _
        'role' => 'Role',
        'role_resident' => 'Resident',
        'role_god_like' => 'God-like',
        'role_customer_service' => 'Customer Service',
        'role_liaison' => 'Liaison',
        'role_god' => 'God',
        'action_enable' => 'Enable',
        'action_disable' => 'Disable',
        'action_reset' => 'Send a password reset link',
        'action_view' => 'View profile',
        'action_edit' => 'Edit',
        'edit_title' => 'Edit %s',
        'manage_enable' => '%s is enabled.',
        'manage_disable' => '%s is disabled.',
        'manage_reset' => 'A link to choose a new password is on its way to %s.',
        'manage_saved' => '%s is saved.',

        // What the forms check
        'error_required' => 'Please fill in every field.',
        'error_password_match' => 'The two passwords are not the same.',
        'error_terms' => 'You must agree to the terms on page %s.',
        'error_current_password' => 'The current password is wrong.',
        'error_forbidden' => 'This is not allowed.',
        'error_nonce' => 'This page has expired, please try again.',
        'error_too_many_registrations' => 'Too many registrations from this address. Try again in %s minutes.',

        // The problem codes of the API (Problem::CODES)
        'error_not_found' => 'There is no such avatar.',
        'error_invalid_request' => 'This cannot be done.',
        'error_invalid_document' => 'This cannot be done.',
        'error_type_conflict' => 'This cannot be done.',
        'error_unauthorized' => 'The grid cannot be reached right now. Please try again later.',
        'error_method_not_allowed' => 'This cannot be done.',
        'error_channel_unavailable' => 'The grid cannot be reached right now. Please try again later.',
        'error_instance_failed' => 'The grid cannot be reached right now. Please try again later.',
        'error_internal_error' => 'The grid cannot be reached right now. Please try again later.',
        'error_sign_in_refused' => 'The name or the password is wrong.',
        'error_account_disabled' => 'This avatar is disabled. Contact the managers of the grid.',
        'error_email_unconfirmed' => 'This avatar is not active yet: confirm your email with the link of the mail we sent you.',
        'error_name_invalid' => 'The first and the last name are made of letters, digits and hyphens, without accents.',
        'error_name_reserved' => 'This name is reserved, please choose another one.',
        'error_name_taken' => 'An avatar already has this name, please choose another one.',
        'error_password_required' => 'Please fill in every field.',
        'error_password_invalid' => 'The password must have 6 to 16 characters, without double quote.',
        'error_email_invalid' => 'This email address is not valid.',
        'error_email_required' => 'This avatar has no email address.',
        'error_model_unknown' => 'This outfit is not available, please choose another one.',
        'error_link_invalid' => 'This cannot be done.',
        'error_field_unknown' => 'This cannot be done.',
        'error_role_unknown' => 'This role does not exist.',
        'error_role_level_conflict' => 'The level does not match the role.',
        'error_token_refused' => 'This link does not work: it has expired or it was already used. Ask for a new one.',
        'error_no_confirmation_pending' => 'This avatar does not wait for the confirmation of its email.',
        'error_mail_failed' => 'The mail could not be sent. Please try again later.',
    ];

    /** The key of the text of a problem code. */
    public static function errorKey(string $code): string
    {
        return 'error_' . str_replace('-', '_', $code);
    }

    /** The key of the text of the status of an avatar (active, disabled, pending). */
    public static function statusKey(string $status): string
    {
        return 'status_' . $status;
    }

    /** The key of the text of the role of an avatar (resident, god-like, customer-service, liaison, god). */
    public static function roleKey(string $role): string
    {
        return 'role_' . str_replace('-', '_', $role);
    }

    /** The text of a problem code in English (the one of `invalid-request` for a code it does not know). */
    public static function error(string $code): string
    {
        return self::MESSAGES[self::errorKey($code)] ?? self::MESSAGES[self::errorKey('invalid-request')];
    }

    /** The folder of the gettext files. */
    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/share/languages';
    }

    /** The template of the translations (.pot). */
    public static function template(): string
    {
        return self::directory() . '/' . self::DOMAIN . '.pot';
    }

    /** The translations of a language (.po), whether it exists or not. */
    public static function file(string $locale): string
    {
        return self::directory() . '/' . self::DOMAIN . '-' . self::locale($locale) . '.po';
    }

    /**
     * The texts in a language, by key: the translated ones only (a bridge falls back on the English ones). An unknown language
     * gives none; `en` gives the English texts.
     *
     * @param bool $fuzzy with the fuzzy ones (a translation whose English changed since)
     * @return array<string,string>
     */
    public static function messages(string $locale, bool $fuzzy = true): array
    {
        if (self::locale($locale) === self::SOURCE) {
            return self::MESSAGES;
        }
        $file = self::file($locale);
        if (!is_file($file)) {
            return [];
        }
        $messages = [];
        foreach (PoFile::read((string) file_get_contents($file)) as $entry) {
            if ($entry['context'] !== '' && $entry['translation'] !== '' && ($fuzzy || !$entry['fuzzy']) && isset(self::MESSAGES[$entry['context']])) {
                $messages[$entry['context']] = $entry['translation'];
            }
        }

        return $messages;
    }

    /** A language as the files name it: fr, fr-FR, fr_FR.UTF-8 give fr_FR; pt-br gives pt_BR; en and en_US give en. */
    public static function locale(string $locale): string
    {
        $locale = preg_replace('/[.@].*/', '', str_replace('-', '_', trim($locale))) ?? '';
        [$language, $region] = array_pad(explode('_', $locale, 2), 2, '');
        $language = strtolower($language);
        if ($language === self::SOURCE) {
            return self::SOURCE;
        }
        if ($region !== '') {
            return $language . '_' . strtoupper($region);
        }
        foreach (self::LOCALES as $known) {
            if (str_starts_with($known, $language . '_')) {
                return $known;
            }
        }

        return $language;
    }

    /** The template of the translations, as gettext writes it. */
    public static function pot(): string
    {
        $header = PoFile::entry('', '', implode("\n", [
            'Project-Id-Version: ' . self::DOMAIN,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
        ]));
        $entries = [];
        foreach (self::MESSAGES as $key => $text) {
            $entries[] = PoFile::entry($key, $text, '', str_contains($text, '%') ? ['php-format'] : []);
        }

        return "# The texts of the websites of a grid. Made from OpenSim\\I18n\\Catalog::MESSAGES by `composer translations`.\n"
            . "# This file is distributed under the same license as opensim-engine (AGPL-3.0-or-later).\n"
            . "#\n"
            . "#, fuzzy\n"
            . $header . "\n" . implode("\n", $entries);
    }

    /** The codes of the problems the catalog has no text for (none, unless a code was added to Problem::CODES alone). @return list<string> */
    public static function missingCodes(): array
    {
        return array_values(array_filter(
            array_keys(Problem::CODES),
            static fn(string $code): bool => !isset(self::MESSAGES[self::errorKey($code)]),
        ));
    }
}
