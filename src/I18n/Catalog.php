<?php

declare(strict_types=1);

namespace W4OS\Engine\I18n;

use W4OS\Engine\Api\Problem;

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
 *     Catalog::file('fr_FR');       // share/languages/w4os-engine-fr_FR.po
 *     Catalog::statusKey('pending'); // status_pending; roleKey('god-like'): role_god_like, the texts of an avatar's status and role
 *     Catalog::plural('fr_FR', 'profile_age_days', 1); // '%d jour': a text that changes with a number, in the form of that number
 *
 * A text that changes with a number has its English singular in SINGULARS, its plural in MESSAGES; the gettext files have it as
 * msgid and msgid_plural, a form for each that the language has (PLURAL_FORMS, the `Plural-Forms` of their header).
 *
 * To change a text, edit MESSAGES, then run `composer translations` (the .pot, and the .po files merged with it).
 */
final class Catalog
{
    public const DOMAIN = 'w4os-engine';

    /** The language of the source texts. */
    public const SOURCE = 'en';

    /** The languages of the catalog. */
    public const LOCALES = ['cy_GB', 'de_DE', 'fr_FR', 'it_IT', 'nl_NL', 'pt_BR'];

    /**
     * How each language picks the form of a number, as gettext says it (`Plural-Forms`). Catalog::pluralIndex() is the same rule.
     */
    public const PLURAL_FORMS = [
        'en' => 'nplurals=2; plural=(n != 1);',
        'cy_GB' => 'nplurals=4; plural=(n==1) ? 0 : (n==2) ? 1 : (n != 8 && n != 11) ? 2 : 3;',
        'de_DE' => 'nplurals=2; plural=(n != 1);',
        'fr_FR' => 'nplurals=2; plural=(n > 1);',
        'it_IT' => 'nplurals=2; plural=(n != 1);',
        'nl_NL' => 'nplurals=2; plural=(n != 1);',
        'pt_BR' => 'nplurals=2; plural=(n > 1);',
    ];

    /** The texts that change with a number, by key: their English singular (MESSAGES has their plural). */
    public const SINGULARS = [
        'profile_age_days' => '%d day',
        'regions_count' => '%d region',
        'avatars_count' => '%d avatar',
        'posts_count' => '%d post',
        'simulators_count' => '%d simulator',
        'filters_active' => '%d active filter',
        'count_avatars_short' => '%d av.',
        'count_prims' => '%d prim',
        'count_scripts' => '%d script',
    ];

    /** The texts, by key, in English. Placeholders are the ones of printf (%s, %1$s, %d). */
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
        'first_name_help' => 'First name must start with a letter and contain only letters and numbers',
        'last_name_help' => 'Last name must start with a letter and contain only letters and numbers',
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
        'real_life' => 'Real life',
        'profile_url' => 'Web page',
        'edit_profile' => 'Edit profile',
        // The free text under the check boxes of the wants and the skills
        'profile_other' => 'Other',
        // The tabs of a profile, in the order of the legacy profile of Firestorm but named for any grid, and what names them for a
        // screen reader
        'profile_sections' => 'Profile sections',
        'profile_tab_inworld' => 'In-world',
        'profile_tab_about' => 'About',
        'profile_tab_web' => 'Web',
        // The stream of the avatar (w4os-flux), the tab the viewers call Feed
        'profile_tab_feed' => 'Feed',
        'post_label' => 'New post',
        'post_help' => 'Plain text, 1000 characters at most.',
        'action_save' => 'Save',
        // The settings of the avatar models, in the words of w4os-wp (its rule, its help in five points, the preview of the models)
        'settings_models' => 'Avatar Models',
        'settings_avatars' => 'Avatar Settings',
        // The page of the grid: what it is, the ways to reach its Robust, its services apart, what the numbers leave out
        // The page of a simulator: its address, its console, its database
        'group_simulator' => 'Simulator',
        'group_console' => 'Console',
        'group_database' => 'Database',
        'setting_simulator_uri' => 'Simulator URI',
        'setting_http_port' => 'HTTP Port',
        'setting_simulator_database' => 'Simulator Database',
        'db_default_opensim' => 'Default: the database of the simulators of the grid, else its main database.',
        'settings_grid' => 'Grid Settings',
        'settings_menu_grid' => 'Grid',
        'group_grid_info' => 'Grid Info',
        'group_robust' => 'Robust',
        'group_services' => 'Services',
        'group_avatars' => 'Avatars',
        'setting_robust_uri' => 'Robust URI',
        'setting_robust_uri_help' => 'The private address of Robust, which the simulators call for the services of the grid.',
        'setting_robust_database' => 'ROBUST Database',
        'setting_console_user' => 'Console User',
        'setting_console_pass' => 'Console Password',
        'setting_console_port' => 'Console Port',
        'setting_console_port_help' => 'The port of the remote console of Robust, what drives it.',
        'setting_assets_service_uri' => 'Assets Service URI',
        'setting_profile_service_uri' => 'Profile Service URI',
        'uri_default_robust' => 'Default: the address of Robust.',
        'uri_default_login' => 'Default: the login URI.',
        'setting_profile_page' => 'Profile Page',
        'setting_exclude_from_stats' => 'Exclude from stats',
        'setting_exclude_from_stats_help' => 'Accounts without email address are usually test or service accounts, created from the console. Uncheck only if you have real avatars without email address.',
        'exclude_models' => 'Models',
        'exclude_nomail' => 'Accounts without mail address',
        'exclude_hypergrid' => 'Hypergrid visitors',
        'setting_allow_multiple_avatars' => 'Allow multiple avatars',
        'setting_allow_multiple_avatars_help' => 'Allow more than one avatar for a single email address.',
        'setting_reserved_names' => 'Forbidden names',
        'setting_reserved_names_help' => 'No new avatar may have one of them as its first or last name, nor the name of the grid.',
        'settings_economy' => 'Economy Settings',
        'settings_search' => 'Search Engine Settings',
        'settings_guide' => 'Destinations Guide Settings',
        'settings_offline' => 'Offline Messages Settings',
        'settings_assets' => 'Web Assets Server',
        'settings_menu_economy' => 'Economy',
        'settings_menu_search' => 'Search Engine',
        'settings_menu_guide' => 'Destination Guide',
        'settings_menu_offline' => 'Offline Messages',
        'settings_menu_assets' => 'Web Assets',
        'settings_saved' => 'Settings saved.',
        'provide_economy' => 'Provide Economy Helper',
        'provide_search' => 'Provide Search',
        'provide_guide' => 'Provide Destinations Guide Service',
        'provide_offline' => 'Provide Offline Messages Service',
        'provide_offline_help' => 'Honor "Email me IMs when I\'m offline" viewer option.',
        'provide_assets' => 'Provide Web Assets Server',
        'provide_assets_help' => 'A web assets server is required to display in-world assets (from the grid) on the website (e.g. profile pictures).',
        'provide_path' => 'Path',
        'provide_offline_help_core' => 'OpenSimulator core offline messages module doesn\'t handle e-mail forwarding option available in the viewer settings.',
        'economy_helper_uri' => 'Economy Helper URI',
        'economy_helper_uri_help' => 'The URL must be set in Robust configuration.',
        'economy_help_helpers' => 'Economy helpers are additional scripts needed if you implement economy on your grid (with real or fake currency).',
        'economy_help_scripts' => 'Helper scripts allow communication between the money server and the grid: current balance update, currency cost estimation, land and object sales, payments...',
        'economy_help_module' => 'This plugin only provides the web helpers required by the money server module. A third-party module must be installed and configured on the simulator, for example:',
        'economy_help_modules' => 'Gloebit (gloebit.com), Podex (podex.info), DTL/NSL Money Server (nsl.tuis.ac.jp)',
        'economy_help_binaries' => 'Ready to use binaries and example config files can be downloaded here: github.com/way4os/w4os-helpers',
        'gloebit_configuration' => 'Gloebit Configuration',
        'gloebit_help_crash' => 'Gloebit module needs to be configured before restarting the region, otherwise it could crash the simulator.',
        'gloebit_help_linux' => 'For Linux, see README-Gloebit.md (github.com/way4os/w4os-helpers) to avoid certificate-related errors.',
        'gloebit_help_register' => 'Register an account or connect on gloebit.com and Follow instructions on dev.gloebit.com to setup an app for your grid/simulator.',
        'gloebit_help_config' => 'Add Gloebit configuration in OpenSim.ini.',
        'gloebit_help_dll' => 'Download the latest dll in your OpenSimulator bin/ folder (rename it Gloebit.dll), from github.com/way4os/opensim-kit or dev.gloebit.com',
        'money_script_access_key' => 'Money Script Access Key',
        'money_script_access_key_help' => 'Choose a unique access key and set it in MoneyServer.ini',
        'search_engine_url' => 'Search Engine URL',
        'search_url_help_viewer' => 'URL of the search engine used by the viewer to provide search results (without arguments).',
        'search_url_help_one' => 'Only one can be set, other lines are alternative examples.',
        'search_url_help_web' => 'Please note that Search URL is different from Web search URL, which is not handled by W4OS currently. Web search is relevant if you have a web search page dedicated to grid content, providing results with in-world URLs (hop:// or secondlife://). It is optional and is referenced here only to disambiguate settings which unfortunately have similar names.',
        'search_register_url' => 'Search Register URL',
        'search_include_2do' => 'Include 2do.directory',
        'search_registers' => 'Other Search Registers',
        'search_register_help_2do' => 'Registering on 2do.directory is recommended: the regions of the grid are then found in the search of the other grids too.',
        'web_search_url' => 'Web Search URL',
        'web_search_help' => 'A web page where people search the places, events and people of the grid; the viewers open it from their search window. The Search Engine URL above is another thing: the simulators call it, nobody sees it.',
        'tip_web_search' => 'User-facing search form',
        'search_register_help_data' => 'Data service, used to register regions, objects or land for sale.',
        'search_register_help_several' => 'You can register to several search engines.',
        'search_register_help_prefix' => 'Each line must have a unique identifier beginning with "DATA_SRV_"',
        'events_help_fetch' => 'HYPEvents Server URL, used to fetch upcoming events and make them available in search.',
        'search_help_local' => 'Enable to use a local search engine, allowing only local results (recommended for private grids).',
        'search_help_external' => 'Disable to use an external search engine like 2do Directory, allowing results from both your grid and other public grids.',
        'search_help_module' => 'Search features require the installation of OpenSimSearch Module on OpenSimulator.',
        'search_help_binary' => 'Ready to use OpenSimSearch.Modules.dll binary can be found here: github.com/way4os/w4os-helpers',
        'guide_url' => 'Destinations Guide URL',
        'guide_source_help_file' => 'A text file with a formatted list of destinations.',
        'guide_source_help_where' => 'The source can be an URL or the full path of a local file your web server can access.',
        'guide_source_help_name' => 'A file name alone is read in the config folder of the grid.',
        'guide_example' => 'Example of a destinations file',
        'snippet_ini' => 'Lines to add to %s',
        'snippet_example' => 'Example',
        // The cards of the dashboard of a site, as the blocks of w4os-wp
        'dashboard_grid_info' => 'Grid Info',
        'dashboard_grid_status' => 'Grid Status',
        // The link of the grid info that adds the grid to the viewer (addGrid of the info of a grid)
        'grid_add_to_viewer' => 'Add this grid to your viewer',
        // The button that adds a grid to the viewer (%1$s its name), and the Connect block of a site: its steps, a button each in the same
        // mood (start_avatar, start_add_grid, start_login); %s the avatar in start_avatar_done
        'viewer_add_grid' => 'Add %1$s to your viewer',
        'viewer_add_grid_other' => 'Other link',
        'viewer_login_uri_by_hand' => 'Or add it in the grid manager of your viewer with its login URI:',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'start_title' => 'Connect',
        'start_avatar' => 'Create your avatar',
        'start_avatar_done' => 'Signed in as %s',
        'start_add_grid' => 'Add the grid',
        'start_login' => 'Log in',
        // The same button for an avatar that has been in the world already
        'start_login_done' => 'Enjoy!',
        'start_done' => 'Done',
        // The link under the last news of a site, to its news page
        'home_all_news' => 'All news',
        // The block of a site for the visitors who have an avatar on another grid (the grid reachable by hypergrid): its title, what to
        // do, the button that takes them there
        'hg_visit_title' => 'Visit from another grid',
        'hg_visit_text' => "Already have an avatar on another OpenSim grid? Visit us without creating one: enter this address in your viewer's map or search.",
        'hg_visit_go' => 'Teleport here',
        // The same with grids an avatar may come from (%s: their names, list_or), when the site names some
        'hg_visit_text_examples' => "Already have an avatar on another OpenSim grid, like %s? Visit us without creating one: enter this address in your viewer's map or search.",
        // The last two of a list of choices (A, B or C: the others before them, joined by a comma)
        'list_or' => '%1$s or %2$s',
        'total' => 'Total',
        // The tips of the fields of the settings pages, under their label: a few words
        'tip_models_match' => 'How a model is recognised',
        'tip_models_pattern' => 'Part of the name of the models',
        'tip_economy_url' => 'Where currency.php and landtool.php are',
        'tip_gloebit_environment' => 'Sandbox to test, production to sell',
        'tip_gloebit_keys' => 'From your Gloebit merchant account',
        'tip_currency_rate' => 'US$ for 1000 in-world units',
        'tip_money_script_key' => 'The same in MoneyServer.ini',
        'tip_podex_message' => 'Shown before the teleport',
        'tip_podex_url' => 'Where the Podex terminals are',
        'tip_search_url' => 'Used directly by the simulators',
        'tip_guide_source' => 'A URL or a file of destinations',
        'tip_guide_url' => 'The page the viewers show',
        'tip_offline_module' => 'V1 by the helpers, V2 by Robust',
        'tip_offline_url' => 'Called by the simulators',
        'tip_assets_url' => 'Where the pictures of the world are served',
        // The message of the day of a grid that sets none (Grid::motd()): the name of the grid, the name of the avatar or the word for a visitor
        'motd_welcome' => 'Welcome to %1$s, %2$s!',
        'motd_visitor' => 'traveler',
        'guide_source_help_pipe' => 'Destination name and teleport URL are separated by a pipe (|) character.',
        'guide_source_help_categories' => 'Lines containing only text are interpreted as categories.',
        'guide_source_help_comments' => 'Lines beginning with "#" or "//" are ignored.',
        'guide_source_help_board' => 'The format is identical to the format used by the in-world object Gudz Teleport Board so the same source URL can be used for both.',
        'guide_help_feature' => 'Destinations guide is a Viewer 3 feature, providing a window with places suggestions.',
        'guide_help_places' => 'While it is not mandatory, and not every avatar will benefit from it, it is a useful way to provide them a list of must-see places, from your own grid or outside.',
        'offline_helper' => 'Offline Messages Helper',
        'offline_helper_help' => 'Set the URL in OpenSimulator configurations.',
        'assets_url' => 'Web Assets Server URL',
        'assets_external_help' => 'If \'Provide Web Assets Server\' is disabled, it is necessary to install a third-party service and enter its URL here to ensure the proper functioning of the plugin.',
        'assets_path_help' => 'Any path under the site: the engine serves the pictures there, whatever the helpers.',
        'models_match' => 'Match',
        'models_match_first' => 'First Name',
        'models_match_any' => 'Any',
        'models_match_last' => 'Last Name',
        'models_match_uuid' => 'Custom list',
        'models_pattern' => 'Name',
        'models_list' => 'Select Models',
        'models_list_placeholder' => 'Select one or more existing avatars',
        'action_save_changes' => 'Save Changes',
        'models_preview' => 'Models Preview',
        'models_none' => 'No models found.',
        'models_help_choice' => 'If avatar models are defined, the avatar creation form offers a choice of them, which determines the initial outfit of the new avatar.',
        'models_help_create' => 'The grid administrator needs to create each model from the ROBUST console, log in with the viewer, customize the outfit, and add a profile picture.',
        'models_help_not_users' => 'The avatars used for the models should never be avatars that people use.',
        'models_help_outfit' => 'The new avatar will wear the same outfit as the model at the time of registration.',
        'models_help_rule' => 'If the model assignment rule is based on the name, each newly created avatar matching that rule will be automatically added to the list.',
        'action_post' => 'Post',
        'action_new_avatar' => 'New Avatar',
        'action_create_avatar' => 'Create Avatar',
        'action_delete_post' => 'Delete',
        'ask_delete_post' => 'Delete this post?',
        'manage_post' => 'Your post is published.',
        'manage_delete_post' => 'Your post is deleted.',
        'profile_tab_interests' => 'Interests',
        'profile_tab_picks' => 'Picks',
        'profile_tab_classifieds' => 'Classifieds',
        'profile_tab_real_life' => 'Real life',
        'profile_tab_notes' => 'My Notes',
        // The tab of the edit page after the viewer's: the email, the role, the password
        'profile_tab_account' => 'Account',
        // The in-world tab of the edit page: the UUID of the avatar, its partner, the box of PUBLISHED_FLAG
        'profile_key' => 'Key',
        'profile_picture' => 'Picture',
        'partner' => 'Partner',
        'show_in_search' => 'Show in search',
        'interests_removed' => 'Viewers no longer show this section since 2023.',
        // The picks and the classifieds, which only a viewer makes and changes, and a tab of a profile that has nothing
        'profile_viewer_only' => 'Created and edited in the viewer.',
        'profile_none' => 'None.',
        // The in-world tab of a profile: the name, the age of the account (profile_age_days changes with the number)
        'profile_name' => 'Name',
        'profile_age_today' => 'Joined today',
        'profile_age_days' => '%d days',
        'profile_groups' => 'Groups',
        // A pick or a classified: what the viewer shows of each, and the end of a classified
        'profile_no_description' => 'No description given.',
        'profile_location' => 'Location',
        'profile_teleport' => 'Teleport',
        'profile_until' => 'Until',
        'save_button' => 'Save',
        'profile_saved' => 'Your profile is saved.',
        'password_title' => 'Password',
        'password_button' => 'Change the password',
        'password_saved' => 'Your password is changed.',
        'email_pending' => 'Your profile is saved. A link is on its way to %s: click it to make it your email.',
        'email_pending_test' => 'Your profile is saved. Test site, no mail: confirm %1$s with this link: %2$s',
        'email_changed' => 'Your email is now %s.',

        // The regions of the grid (managers): the page, its search, how many it lists (regions_count changes with the number), how many
        // are online of all
        'regions_title' => 'Regions',
        'search_regions' => 'Region name',
        'regions_count' => '%d regions',
        'no_regions' => 'No region',
        'regions_online' => '%1$s online of %2$s',

        // The simulators of the machine (managers): the page, its search, how many it lists (simulators_count changes with the number)
        'simulators_title' => 'Simulators',
        'search_simulators' => 'Simulator name',
        'simulators_count' => '%d simulators',
        'no_simulators' => 'No simulator',
        'simulators_running' => '%1$s running of %2$s',
        // The dashboard of a site: its cards, the link to a whole list
        'recent_avatars' => 'Recent Avatars',
        'see_all' => 'See all',
        // Its status, each state saying it all on its own: enabled, running, and the ways the engine reaches it, named as they are
        // (a file, a program: the same in every language) while they are there, with what is wrong when they are not
        // The toolbar of a list: its filters, how many are on (filters_active changes with the number), a label before its value
        'filters' => 'Filters',
        'filters_active' => '%d active filters',
        'label_value' => '%1$s: %2$s',
        // An example in an empty field, never taken: a default is shown as it is
        'example_value' => 'e.g.: %s',
        // The first tab of the page of an item
        'tab_overview' => 'Overview',
        'column_status' => 'Status',
        'state_enabled' => 'Enabled',
        'state_disabled' => 'Disabled',
        'state_running' => 'Running',
        'state_stopped' => 'Stopped',
        'state_unresponsive' => 'Not answering',
        'state_crashed' => 'Crashed',
        'connection_console' => 'Console',
        'connection_console_failed' => 'Console unreachable',
        'connection_screen' => 'Screen',
        'connection_screen_failed' => 'Screen not found',
        'connection_config' => 'OpenSim.ini',
        'connection_config_failed' => 'OpenSim.ini unreadable',
        'connection_regions' => 'Regions .ini',
        'connection_regions_failed' => 'Regions .ini unreadable',
        'connection_mysql' => 'MySQL',
        'connection_mysql_failed' => 'MySQL unreachable',
        'connection_sqlite' => 'SQLite',
        'connection_sqlite_failed' => 'SQLite unreadable',

        // Avatars of the grid (managers)
        'menu_admin' => 'Admin',
        'menu_account' => 'My avatar',
        'dashboard_title' => 'Dashboard',
        'manage_title' => 'Avatars',
        'search_avatars' => 'Name or email',
        'search' => 'Search',
        // The search of the grid as a page and an entry of a menu (a noun; search is the verb of its buttons)
        'search_title' => 'Search',
        'no_avatars' => 'No result',
        // How many avatars the list has (a text that changes with the number)
        'avatars_count' => '%d avatars',
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
        'status_banned' => 'Banned',
        // What an avatar is: a person's, a model of the grid, the account of its services
        'avatar_type_user' => 'Resident',
        'avatar_type_model' => 'Default Model',
        'avatar_type_service' => 'Service Account',
        // A ban of some estates, not of the grid; the bans, a god's
        'state_grid_banned' => 'Banned from the grid',
        'state_estate_banned' => 'Banned from estates',
        'filter_estate_banned' => 'Estate bans',
        'action_ban' => 'Ban',
        'action_unban' => 'Unban',
        'action_estate_ban' => 'Ban from estates',
        'action_estate_unban' => 'Unban from estates',
        'ask_ban' => 'Ban this avatar? It can no longer log in, until it is unbanned.',
        'ask_estate_ban' => 'Ban this avatar from every estate of the grid?',
        // The role of an avatar, as the API says it (Avatar::ROLES): role_ and the role, - as _
        'role' => 'Role',
        'role_resident' => 'Resident',
        'role_god_like' => 'God-like',
        'role_customer_service' => 'Customer Service',
        'role_liaison' => 'Liaison',
        'role_god' => 'God',
        'role_maintenance' => 'Maintenance',
        // The roles a list shows besides the levels: a model of the grid, the account of its services
        'role_model' => 'Model',
        'role_service_account' => 'Service Account',
        'action_enable' => 'Enable',
        'action_disable' => 'Disable',
        'action_start' => 'Start',
        'action_stop' => 'Stop',
        'action_restart' => 'Restart',
        'ask_stop_instance' => 'Stop this simulator? The avatars in its regions are sent away.',
        'ask_restart_instance' => 'Restart this simulator? The avatars in its regions are sent away while it restarts.',
        'action_reset' => 'Send a password reset link',
        'action_view' => 'View profile',
        'action_view_item' => 'View',
        'action_edit' => 'Edit',
        'edit_title' => 'Edit %s',
        'manage_enable' => '%s is enabled.',
        'manage_disable' => '%s is disabled.',
        'manage_start' => '%s is started.',
        'manage_stop' => '%s is stopped.',
        'manage_restart' => '%s is restarted.',
        'manage_ban' => '%s is banned.',
        'manage_unban' => '%s is no longer banned.',
        'manage_delete' => '%s is deleted.',
        'manage_estate_ban' => '%s is banned from the estates.',
        'manage_estate_unban' => '%s is no longer banned from the estates.',
        'manage_reset' => 'A link to choose a new password is on its way to %s.',
        'manage_saved' => '%s is saved.',

        // The lists of the models (Listing): the titles of their columns and of their filters, the values of a filter
        'yes' => 'Yes',
        'no' => 'No',
        // What a filter offers besides its values: none of them chosen, and to take the filter away
        'filter_any' => 'Any',
        'filter_clear' => 'Remove the filter',
        'filter_email' => 'With an email',
        'filter_model' => 'Avatar model',
        'filter_technical' => 'Service account',
        'filter_active' => 'Can log in',
        'column_map' => 'Map',
        'column_region' => 'Region',
        'column_region_name' => 'Region Name',
        'column_presence' => 'Presence',
        'column_prims' => 'Prims',
        'column_scripts' => 'Scripts',
        // Short titles of narrow columns: avatars, prims, scripts
        'column_presence_short' => 'av.',
        'column_prims_short' => 'pr.',
        'column_scripts_short' => 'scr.',
        // A number of avatars in a narrow column: av. for avatars
        'count_avatars_short' => '%d av.',
        'count_prims' => '%d prims',
        'count_scripts' => '%d scripts',
        'column_internal_port' => 'Internal Port',
        'internal_port_help' => 'Not a public port: it only needs to be unique on the machine of its simulator.',
        'last_activity' => 'Last Activity',
        'column_size' => 'Size',
        'column_owner' => 'Owner',
        'column_roles' => 'Roles',
        'column_flags' => 'Flags',
        // Online or offline: a region that runs (its status), an avatar in the world (its presence)
        'offline' => 'Offline',
        // The views of a list (Listing::view()), the row of links above it, in the words of w4os-wp
        'all' => 'All',
        'view_active' => 'Active',
        'view_inactive' => 'Inactive',
        // A grid partly broken (GridMonitor::DEGRADED)
        'degraded' => 'Degraded',
        // The parcels of a region, in the words of w4os-wp (its region page)
        'parcels' => 'Parcels',
        // The page of the shortcodes and blocks of a site, in the words of w4os-wp (its admin page Shortcodes): what each block shows,
        // its options
        'shortcodes_title' => 'Available Shortcodes',
        'shortcodes_help' => 'Shortcodes can be included in any page or articles.',
        'shortcode_options' => 'Options',
        'shortcode_option_title' => 'Bloc title',
        'shortcode_option_level' => 'Title level (h1 to h6)',
        'shortcode_option_name' => 'The name of the avatar (First.Last)',
        'shortcode_option_count' => 'Max Results',
        'shortcode_option_include_hypergrid' => 'Include Hypergrid',
        'shortcode_option_include_landsales' => 'Include Land for Sale',
        'block_grid_info_help' => 'General information (grid name and login uri)',
        'block_grid_status_help' => 'Online avatars, regions, etc.',
        'block_popular_places_help' => 'Most visited regions in your grid.',
        'block_motd_help' => 'The message of the day the viewers show at login.',
        'block_destination_guide_help' => 'Places to visit, the same list the viewers show in their destinations guide.',
        'block_directory_info_help' => 'The other grids whose places can be found in the search of this grid.',
        'block_search_help' => 'A search form for the places, events, classifieds and people of the grid.',
        'block_profile_help' => 'The profile of an avatar.',
        'block_new_avatars_help' => 'The last avatars who joined the grid.',
        // The shortcodes of a site that are not blocks
        'shortcode_home_help' => 'Your text with the popular places, the guide, the grid status and the message of the day.',
        'shortcode_guest_help' => 'Shows its text only to visitors who are not signed in.',
        'shortcode_register_help' => 'Shows its text only when the site has a registration page.',
        'shortcode_text_help' => 'Shows a text of the site in the language of the page.',
        'shortcode_uri_help' => 'The login address of the grid, to copy in a viewer.',
        'shortcode_slogan_help' => 'The slogan of the site.',
        // The settings (the registry, Settings) and the pages of the health (Health::ROWS): setting_ and the name, and what each is for
        'setting_login' => 'Login URI',
        'setting_login_help' => 'The address the viewers log in at, and ask what the grid says of itself (get_grid_info).',
        'setting_gatekeeper' => 'Gatekeeper',
        // The link a page gives a destination, in a web browser and in the viewer, and the formats it can be
        'group_links' => 'Links to the places',
        'setting_link_format_browser' => 'Links in a web browser',
        'setting_link_format_browser_help' => 'hop:// is the format of OpenSimulator, secondlife:// may open a Second Life viewer.',
        'setting_link_format_viewer' => 'Links in the viewer',
        'link_format_grid' => 'Location with its grid (secondlife://grid/secondlife/Region)',
        'link_format_hop' => 'Hypergrid link (hop://)',
        'link_format_apptp' => 'Teleport of the viewer (secondlife:///app/teleport)',
        'link_format_local' => 'Region of the grid the viewer is on (secondlife://Region)',
        // The links of a place in its menu: what each does, then its format
        'link_menu_grid' => 'Teleport (secondlife://)',
        'link_menu_hop' => 'Teleport (hop://)',
        'link_menu_apptp' => 'Teleport (app/teleport)',
        'link_menu_local' => 'Teleport (this grid)',
        'link_menu_map' => 'Show on the map',
        'link_menu_copy' => 'Copy the address',
        'link_menu_addgrid' => 'Add the grid',
        // The web search, its kinds as the viewers have them, their fields and their categories
        'web_search_places' => 'Places',
        'web_search_land' => 'Land Sales',
        'web_search_events' => 'Events',
        'web_search_classifieds' => 'Classifieds',
        'web_search_people' => 'People',
        'web_search_submit' => 'Search',
        'web_search_words' => 'Enter search terms',
        'web_search_name' => 'Name',
        'web_search_min_letters' => 'At least %d letters.',
        'web_search_category' => 'Category',
        'web_search_any_category' => 'Any Category',
        'web_search_ratings' => 'Ratings',
        'web_search_rating_general' => 'General',
        'web_search_rating_moderate' => 'Moderate',
        'web_search_rating_adult' => 'Adult',
        'web_search_land_type' => 'Type',
        'web_search_land_any' => 'All Types',
        'web_search_land_mainland' => 'For Sale - Mainland',
        'web_search_land_estate' => 'For Sale - Estate',
        'web_search_price_max' => 'Price ≤',
        'web_search_area_min' => 'Area ≥',
        'web_search_when' => 'When',
        'web_search_upcoming' => 'In-Progress & Upcoming',
        'web_search_day' => 'Date',
        'web_search_previous_day' => 'Go back a day',
        'web_search_next_day' => 'Go forward a day',
        'web_search_nothing' => 'No results',
        'web_search_price' => 'Price',
        'web_search_area' => 'Area',
        'web_search_traffic' => 'Traffic',
        'web_search_region' => 'Region',
        'web_search_date' => 'Date',
        'web_search_duration' => 'Event Duration',
        'web_search_sort' => 'Sort by',
        'web_search_price_m2' => 'Price/m²',
        'web_search_time' => 'Time',
        'web_search_adult_content' => 'Mature Content',
        'web_search_yes' => 'Yes',
        'web_search_no' => 'No',
        'web_search_organizer' => 'Run By',
        'web_search_location' => 'Location',
        'web_search_entry_fee' => 'Cover Charge',
        'web_search_none' => 'none',
        'web_search_description' => 'Description',
        'web_search_content_type' => 'Content Type',
        'web_search_creation_date' => 'Creation date',
        'web_search_listing_cost' => 'Price for listing',
        'web_search_rating' => 'Rating',
        'web_search_next' => 'Next',
        'web_search_previous' => 'Back',
        'web_search_groups' => 'Groups',
        'web_search_members' => 'Members',
        'web_search_key' => 'Key',
        'web_search_born' => 'Birthdate',
        'web_search_account' => 'Account',
        'web_search_partner' => 'Partner',
        'web_search_about' => 'About',
        'web_search_show_profile' => 'Open Profile',
        'web_search_group_key' => 'Group Key',
        'web_search_founder' => 'Founded by',
        'web_search_charter' => 'Group Charter',
        'web_search_member' => 'Member',
        'web_search_title' => 'Title',
        'web_search_status' => 'Status',
        'web_search_online' => 'Online',
        'web_search_open_enrollment' => 'Anyone can join',
        'web_search_join_fee' => 'Cost to join',
        'web_search_content_general' => 'General Content',
        'web_search_content_moderate' => 'Moderate Content',
        'place_category_arts' => 'Arts & Culture',
        'place_category_business' => 'Business',
        'place_category_educational' => 'Educational',
        'place_category_gaming' => 'Gaming',
        'place_category_hangout' => 'Hangout',
        'place_category_newcomer' => 'Newcomer Friendly',
        'place_category_parks' => 'Parks & Nature',
        'place_category_residential' => 'Residential',
        'place_category_shopping' => 'Shopping',
        'place_category_other' => 'Other',
        'place_category_rental' => 'Rental',
        'event_category_discussion' => 'Discussion',
        'event_category_sports' => 'Sports',
        'event_category_live_music' => 'Live Music',
        'event_category_commercial' => 'Commercial',
        'event_category_nightlife' => 'Nightlife/Entertainment',
        'event_category_games' => 'Games/Contests',
        'event_category_pageants' => 'Pageants',
        'event_category_education' => 'Education',
        'event_category_arts' => 'Arts and Culture',
        'event_category_charity' => 'Charity/Support Groups',
        'event_category_miscellaneous' => 'Miscellaneous',
        'classified_category_shopping' => 'Shopping',
        'classified_category_land_rental' => 'Land Rental',
        'classified_category_property_rental' => 'Property Rental',
        'classified_category_attraction' => 'Special Attraction',
        'classified_category_new_products' => 'New Products',
        'classified_category_employment' => 'Employment',
        'classified_category_wanted' => 'Wanted',
        'classified_category_service' => 'Service',
        'classified_category_personal' => 'Personal',
        // The button beside a teleport link that opens the menu of its other links
        'tp_more' => 'More ways to go there',
        'setting_gatekeeper_help' => 'The address other grids reach this one by (hypergrid).',
        'setting_uas' => 'User agent service',
        'setting_uas_help' => 'Where the avatars of this grid are known when they visit other grids (hypergrid).',
        'setting_welcome' => 'Splash',
        'setting_welcome_help' => 'The welcome page displayed in the viewer with the login form. A short, no-scroll page, with only essential info. It is required, or at least highly recommended.',
        'setting_economy' => 'Economy',
        'setting_economy_help' => 'Currencies and some other services queried by the viewer. The avatars do not open them directly.',
        'setting_about' => 'About this grid',
        'setting_about_help' => 'Detailed info page on your website, via a link displayed on the viewer login page.',
        'setting_register' => 'Registration page',
        'setting_register_help' => 'Link to the registration of an avatar.',
        'setting_help' => 'Help',
        'setting_help_help' => 'Link to a help page on your website.',
        'setting_password' => 'Password recovery',
        'setting_password_help' => 'Link to lost password page.',
        'setting_grid_status' => 'Grid Status',
        'setting_grid_status_help' => 'The page of the status of the grid.',
        'setting_grid_status_rss' => 'Grid status feed',
        'setting_grid_status_rss_help' => 'The feed of the status of the grid the viewers read (RSS).',
        'setting_web_profile' => 'Avatar profile',
        'setting_web_profile_help' => 'The base URL for avatar web profiles.',
        'setting_web_search' => 'Web Search',
        'setting_web_search_help' => 'Web tab of viewer search windows. Relevant if you have a search page providing content from the grid.',
        'setting_destination_guide' => 'Destinations Guide',
        'setting_destination_guide_help' => 'Destinations guide is a Viewer 3 feature, providing a window with places suggestions.',
        'setting_message_url' => 'Message of the day',
        'setting_message_url_help' => 'The message the viewers show at login.',
        'setting_search_service' => 'Search Service',
        'setting_search_service_help' => 'Search service used by the viewer. Search can be provided by the simulator core (limited), or by an external service for additional functionalities (like events). Requires OpenSimSearch.Modules.dll.',
        'setting_data_service' => 'Data service',
        'setting_data_service_help' => 'Where the simulators register their regions, so that the search finds them. Not a page.',
        'setting_offline_messages' => 'Offline messages',
        'setting_offline_messages_help' => 'Needed by viewers to keep messages while an avatar is offline and deliver them when it comes back online. Internal service, the avatars do not open it directly.',
        'setting_offline_module' => 'Offline messages module',
        'setting_offline_module_help' => 'The module of the simulators that keeps the messages of the avatars who are away: V2 by Robust, V1 by the helpers.',
        'setting_money_script_key' => 'Money script key',
        'setting_money_script_key_help' => 'The key the currency services and the money server share: never shown, only whether it is set.',
        'setting_helpers' => 'Helpers base',
        'setting_helpers_help' => 'The base of the pages and services of the helpers.',
        'setting_directory' => 'Directory',
        'setting_directory_help' => 'The page of the grid in the shared directory of the regions.',
        'setting_cache_assets' => 'Picture cache',
        'setting_cache_assets_help' => 'The folder the pictures are kept in: without it, they are made again on every request.',
        'setting_cache_tasks' => 'Task state',
        'setting_cache_tasks_help' => 'The folder of the state of the tasks: without it, no task runs and the search index never fills.',
        'setting_base_url' => 'Base URL',
        'setting_base_hostname' => 'Base Hostname',
        'setting_public_port' => 'Public Port',
        'setting_private_port' => 'Private Port',
        'setting_storage_provider' => 'Storage Provider',
        'setting_database' => 'Database Connection String',
        'setting_grid_name' => 'Grid Name',
        'setting_grid_nick' => 'Grid Nickname',
        'setting_welcome_message' => 'Welcome Message',
        'setting_currency_symbol' => 'Currency Symbol',
        'setting_search_module' => 'Search Module',
        'setting_index_sims' => 'Index Regions',
        'setting_economy_module' => 'Economy Module',
        'setting_economy_module_help' => 'Changed with the options of the grid, which set the module and what it needs.',
        'economy_module_free' => 'Free transactions only',
        'economy_module_gloebit' => 'Gloebit',
        'economy_module_moneyserver' => 'Generic MoneyServer, for fake money or alternate providers.',
        'economy_module_podex' => 'Podex',
        'setting_sell_enabled' => 'Enable Selling',
        'setting_price_upload' => 'Price Upload',
        'setting_price_group_create' => 'Price Group Create',
        'setting_gloebit_enabled' => 'Enable Gloebit',
        'setting_gloebit_environment' => 'Gloebit Environment',
        'setting_gloebit_key' => 'Gloebit App Key',
        'setting_gloebit_secret' => 'Gloebit App Secret',
        'setting_gloebit_owner_name' => 'Banker Name',
        'setting_gloebit_owner_email' => 'Banker Email',
        'gloebit_banker_help' => 'With Gloebit, the banker is the person of reference for Gloebit: Gloebit itself holds and manages all the transactions.',
        'setting_money_banker' => 'Banker Avatar',
        'money_banker_help' => 'With the money server, the banker really owns the printing press: this avatar can create money, buying it from the system at no cost. Zeros: every avatar can; empty: nobody.',
        'money_banker_podex_help' => 'With Podex, the banker is the avatar agreed with Podex, the one of its office and its ATMs.',
        'money_server_warning_title' => 'Security',
        'money_server_warning' => 'The money server and Podex are offered as a convenience, not as a recommendation: the money server can create money, it needs a security audit before it handles real money.',
        'currency_symbol_help' => 'G$ with Gloebit, a global currency; any symbol with the other modules.',
        'setting_currency_provider' => 'Currency Provider',
        'gloebit_sandbox' => 'Sandbox',
        'gloebit_production' => 'Production',
        'setting_guide_source' => 'Source',
        'setting_guide_source_help' => 'A text file with a formatted list of destinations.',
        'setting_search_database' => 'OS Search Database',
        'setting_events_url' => 'Events Server URL',
        'setting_logo_url' => 'Grid Logo URL',
        'setting_mail_sender' => 'Sender e-mail address',
        'setting_mail_sender_help' => 'A no-reply e-mail address used to forward messages for avatars enabling "Email me IMs when I\'m offline" option.',
        'setting_currency_rate' => 'Currency Conversion Rate',
        'setting_currency_rate_help' => 'Amount to pay in US$ for 1000 in-world money units. Used for cost estimation. If not set, the rate will be 10/1000 (1 cent per money unit).',
        'setting_currency_rate_per' => 'Money Units per Rate',
        'setting_gloebit_threshold' => 'Gloebit Conversion Threshold',
        'setting_podex_message' => 'Podex Redirect Message',
        'setting_podex_url' => 'Exchange Teleport URL',
        'setting_assets_url' => 'Web Assets Server URL',
        'setting_assets_url_help' => 'The web assets server of the grid when it is not the one of the helpers: it converts the pictures of the world (JPEG 2000) for the web.',
        'offline_module_v1' => 'By the helpers (V1)',
        'offline_module_v2' => 'By Robust (V2)',
        'offline_database' => 'Offline Messages Database',
        'db_host' => 'Hostname',
        'db_port' => 'Port',
        'db_name' => 'Database name',
        'db_user' => 'User',
        'db_password' => 'Password',
        // The switch of a field that may use the default, what the default is in its description
        'db_use_default' => 'Use default',
        'db_default_main' => 'Default: the main database of the grid.',
        'db_default_simulator' => 'Default: the database of the simulator.',
        'economy_database' => 'Economy Database',
        // What an address of the grid is used for (Service::usage())
        'usage_robust_simulators' => 'Robust service, used by the simulators',
        'usage_robust_viewers' => 'Robust service, used by the viewers and the other grids',
        'usage_helper_simulators' => 'Helper called by the simulators',
        'usage_helper_viewers' => 'Helper called by the viewer',
        'usage_page' => 'Web page, shown by the viewer or the web site',
        'usage_web_tool' => 'Web tool, used with a script in-world',
        'usage_task' => 'Task of the grid, run in the background',
        'usage_web_assets' => 'Web assets, for the web sites',
        // The services of a grid (Service::address()): service_ and the key, and what each is for
        'service_asset' => 'Assets service',
        'service_asset_help' => 'The assets of the grid (textures, sounds, objects...): the simulators read and store them.',
        'service_inventory' => 'Inventory service',
        'service_inventory_help' => 'The inventories of the avatars.',
        'service_grid' => 'Grid service',
        'service_grid_help' => 'The regions of the grid: where each one is, and which simulator runs it.',
        'service_estate' => 'Estate service',
        'service_estate_help' => 'The estates of the regions, their owners and their managers.',
        'service_authentication' => 'Authentication service',
        'service_authentication_help' => 'The passwords of the accounts, and their sessions.',
        'service_avatar' => 'Avatar service',
        'service_avatar_help' => 'The appearance of the avatars: what they wear.',
        'service_agent_preferences' => 'Agent preferences service',
        'service_agent_preferences_help' => 'The preferences of the avatars the grid keeps (language, maturity).',
        'service_presence' => 'Presence service',
        'service_presence_help' => 'Who is in the world now, and where.',
        'service_user_account' => 'User account service',
        'service_user_account_help' => 'The accounts of the avatars: their names, emails and levels.',
        'service_grid_user' => 'Grid user service',
        'service_grid_user_help' => 'The last position and the home of each avatar.',
        'service_friends' => 'Friends service',
        'service_friends_help' => 'The friendships of the avatars.',
        'service_map_image' => 'Map tiles service',
        'service_map_image_help' => 'The tiles of the map of the world: the simulators add them.',
        'service_mute_list' => 'Mute list service',
        'service_mute_list_help' => 'What each avatar blocks.',
        'service_bakes' => 'Baked textures service',
        'service_bakes_help' => 'The baked textures of the avatars, shared between the simulators.',
        'service_groups' => 'Groups service',
        'service_groups_help' => 'The groups of the avatars, their members, roles and notices.',
        'service_profile' => 'Profiles service',
        'service_profile_help' => 'The profiles of the avatars, their picks and their classifieds.',
        'service_get_grid_info' => 'Grid info',
        'service_get_grid_info_help' => 'What the grid says of itself to the viewers (get_grid_info).',
        'service_hg_friends' => 'Hypergrid friends service',
        'service_hg_friends_help' => 'The friendships with the avatars of other grids (hypergrid).',
        'service_hg_asset' => 'Hypergrid assets service',
        'service_hg_asset_help' => 'The assets the other grids ask for (hypergrid).',
        'service_hg_inventory' => 'Hypergrid inventory service',
        'service_hg_inventory_help' => 'The inventories the avatars of this grid reach from other grids (hypergrid).',
        'service_hg_groups' => 'Hypergrid groups service',
        'service_hg_groups_help' => 'The groups the avatars of other grids reach (hypergrid).',
        'service_map' => 'Map service',
        'service_map_help' => 'The tiles of the map the viewers and the other grids read.',
        'service_currency' => 'Currency helper',
        'service_currency_help' => 'The currency of the grid, called by the viewer (currency.php): the balance, the cost of currency.',
        'service_landtool' => 'Land tool helper',
        'service_landtool_help' => 'The land sales the viewer calls (landtool.php).',
        'service_parser' => 'Search parser',
        'service_parser_help' => 'Reads the regions registered with the search: a task of the grid.',
        'service_events_parser' => 'Events parser',
        'service_events_parser_help' => 'Reads the events of the calendar: a task of the grid.',
        'service_text_images' => 'Text to image',
        'service_text_images_help' => 'Makes an image of a text, for a script in-world (textgen.php).',
        'service_assets' => 'Web Assets Server',
        'service_assets_help' => 'A web assets server is required to display in-world assets (from the grid) on the website (e.g. profile pictures).',
        'service_instant_message' => 'Instant messages service',
        'service_instant_message_help' => 'The messages between the avatars of different grids (hypergrid).',
        // A setting of the health that is not given: not served, not asked
        'health_unset' => 'Not set: neither served nor tested.',
        // What the health says of a line (Health::say()): whole sentences, their values as placeholders
        'health_options_all' => 'all the options of the grid',
        'health_options_missing' => 'missing: %s',
        'health_matches_grid_info' => 'matches %s of get_grid_info',
        'health_differs_grid_info' => 'differs from %s of get_grid_info',
        'health_web_search' => 'Web search',
        'health_offline_robust' => 'served by Robust (module V2)',
        'health_set' => 'set',
        'health_missing' => 'missing',
        'health_signed_out' => 'signed out',
        'health_matches_search' => 'matches [GridInfoService] search',
        'health_with_xmlrpc' => 'with valid XMLRPC request',
        'health_with_post' => 'with valid post data',
        'health_with_query' => 'with valid query',
        'health_without_data' => 'without data',
        'health_slow' => 'answered in %d ms, after more than %s s the first time',
        'health_block_missing' => 'the page does not show the block %s',
        'health_sends_elsewhere' => 'sends to %s, not to %s',
        'health_curl_error' => 'cURL error %d',
        'health_ok' => 'OK',
        'health_readable' => 'readable',
        'health_not_readable' => 'not readable',
        'health_helpers' => 'helpers',
        'health_site' => 'site',
        'health_helpers_signed_out' => 'helpers, signed out',
        'health_site_signed_out' => 'site, signed out',
        'health_writable' => 'writable',
        'health_can_be_made' => 'can be made',
        'health_not_writable' => 'not writable',
        'health_jp2_reads' => 'reads JPEG 2000',
        'health_jp2_reads_detail' => 'required by the web assets service: the pictures of the world (JPEG 2000)',
        'health_jp2_external' => 'external web assets service',
        'health_jp2_external_detail' => 'the pictures of the world are converted by the web assets service of the grid (%s)',
        'health_jp2_missing' => 'cannot read JPEG 2000',
        'health_jp2_missing_detail' => 'required by the web assets service, for the pictures of the world (imagick built with OpenJPEG): without it, set an external web assets service (%s)',
        'health_installed' => 'installed %s',
        'health_installed_polyfill' => 'installed (polyfill)',
        'health_for_xmlrpc' => 'extension or polyfill',
        'health_for_imagick' => 'text to image, web assets',
        'health_for_gettext' => 'translations',
        // A service of the health that does not answer and is not served by the helpers of the grid
        'health_third_party' => 'This service requires a separate web application.',
        'presence' => 'Presence',
        // The roles of a region (Region::roleKeys()): region_flag_ and the name of its flag in the config of Robust, in snake case
        'region_flag_default_region' => 'Default',
        'region_flag_default_hg_region' => 'Default HG',
        'region_flag_fallback_region' => 'Fallback',
        'region_flag_no_direct_login' => 'No direct login',
        'region_flag_persistent' => 'Persistent',
        'region_flag_locked_out' => 'Locked out',
        'region_flag_reservation' => 'Reservation',
        'region_flag_no_move' => 'No move',
        'region_flag_authenticate' => 'Authenticate',
        'region_flag_region_online' => 'Online',
        'region_flag_hyperlink' => 'Hyperlink',
        // What each flag means, as OpenSimulator says it (OpenSim.Framework.RegionFlags): a tooltip
        'region_flag_default_region_help' => 'Where avatars arrive when they do not ask for a region; one at random when there are several.',
        'region_flag_fallback_region_help' => 'Where avatars are sent when the region they ask for is down.',
        'region_flag_region_online_help' => 'The region is running: set when it comes online, unset when it leaves the grid.',
        'region_flag_no_direct_login_help' => 'Avatars cannot log in to it directly by its name.',
        'region_flag_persistent_help' => 'Kept in the list of the grid when it leaves.',
        'region_flag_locked_out_help' => 'The region is not allowed to register.',
        'region_flag_no_move_help' => 'The region cannot be moved on the map.',
        'region_flag_reservation_help' => 'An inactive reservation of a place on the map.',
        'region_flag_authenticate_help' => 'Visitors must be authenticated.',
        'region_flag_hyperlink_help' => 'A link to a region of another grid.',
        'region_flag_default_hg_region_help' => 'Where visitors from other grids arrive, for hypergrid teleports only.',
        'filter_simulator' => 'Simulator',
        'column_name' => 'Name',
        'column_nick' => 'Nick',
        'column_slug' => 'Slug',
        // The URI of an item (a grid, a simulator, a region: host:port...), its fixed name; the URL of a web service
        'column_uri' => 'URI',
        'column_url' => 'URL',
        'column_server' => 'Server',
        'column_enabled' => 'Enabled',
        'filter_remote' => 'On another machine',
        'filter_hypergrid' => 'Hypergrid',
        'column_grid' => 'Grid',
        'column_port' => 'Port',
        'column_running' => 'Running',
        'column_service' => 'Service',
        'column_provider' => 'Served by',
        'filter_served' => 'Served',
        'port_private' => 'Private',
        'port_public' => 'Public',
        'port_web' => 'Web',
        'column_file' => 'File',
        'column_kind' => 'Kind',
        'column_date' => 'Date',
        'column_author' => 'Author',
        'avatars_title' => 'Avatars',
        'avatars_menu' => 'Avatars',
        'avatars_search' => 'Search Avatars',
        'regions_title' => 'Regions',
        'regions_menu' => 'Regions',
        'regions_search' => 'Search Regions',
        'flux_title' => 'Flux Posts',
        'flux_menu' => 'Flux',
        'flux_search' => 'Search Flux Posts',
        'flux_empty' => 'No Flux Posts found',
        'list_empty' => 'No items found.',
        'posts_count' => '%d posts',
        'flux_post' => 'Flux Post',
        'kind_iar' => 'Inventory (IAR)',
        'kind_oar' => 'Region (OAR)',
        'kind_backup' => 'Backup',
        'column_area' => 'Area',
        // The units an amount is shown in (Text\Units), the number as %s, and a usage: what it takes of its total
        'unit_km2' => '%s km²',
        'unit_dimensions' => '%1$s x %2$s',
        'unit_mb' => '%s MB',
        'unit_percent' => '%s %%',
        'usage_of' => '%1$s of %2$s',
        // What the process of a simulator takes, in percents of the machine
        'column_cpu' => 'CPU',
        // The people in a region or a simulator: members and visitors of other grids, never a NPC
        'column_avatars' => 'Avatars',
        'column_memory' => 'Memory',
        'column_traffic' => 'Traffic',
        'column_for_sale' => 'For sale',
        'column_in_search' => 'In search',
        'parcel_scripts' => 'Scripts',
        'parcel_build' => 'Build',
        'parcel_public' => 'Public access',
        'column_regions' => 'Regions',
        'column_estates' => 'Estates',
        'parcel' => 'Parcel',
        'column_type' => 'Type',
        'filter_local' => 'Local',
        'filter_temporary' => 'Temporary',
        // The types of the assets (Asset::TYPES): asset_ and the type, - as _
        'asset_texture' => 'Texture',
        'asset_sound' => 'Sound',
        'asset_calling_card' => 'Calling card',
        'asset_landmark' => 'Landmark',
        'asset_clothing' => 'Clothing',
        'asset_object' => 'Object',
        'asset_notecard' => 'Notecard',
        'asset_folder' => 'Folder',
        'asset_script' => 'Script',
        'asset_script_bytecode' => 'Compiled script',
        'asset_texture_tga' => 'Texture (TGA)',
        'asset_body_part' => 'Body part',
        'asset_sound_wav' => 'Sound (WAV)',
        'asset_image_tga' => 'Image (TGA)',
        'asset_image_jpeg' => 'Image (JPEG)',
        'asset_animation' => 'Animation',
        'asset_gesture' => 'Gesture',
        'asset_simstate' => 'Region state',
        'asset_link' => 'Link',
        'asset_link_folder' => 'Folder link',
        'asset_mesh' => 'Mesh',
        'asset_settings' => 'Environment settings',
        'asset_material' => 'Material',

        // What the forms check
        'error_required' => 'Please fill in every field.',
        'error_password_match' => 'The two passwords are not the same.',
        'error_terms' => 'You must agree to the terms on page %s.',
        'error_current_password' => 'The current password is wrong.',
        'error_forbidden' => 'This is not allowed.',
        'error_nonce' => 'This page has expired, please try again.',
        'error_too_many_registrations' => 'Too many registrations from this address. Try again in %s minutes.',

        // The problem codes of the API (Problem::CODES)
        'error_not_found' => 'Not found',
        // What is not there, by the kind of resource the problem names (`resource`)
        'error_not_found_grid' => 'Grid not found',
        'error_not_found_avatar' => 'Avatar not found',
        'error_not_found_avatar_model' => 'Avatar model not found',
        'error_not_found_region' => 'Region not found',
        'error_not_found_simulator' => 'Simulator not found',
        'error_not_found_estate' => 'Estate not found',
        'error_not_found_parcel' => 'Parcel not found',
        'error_not_found_asset' => 'Asset not found',
        'error_not_found_post' => 'Post not found',
        'error_not_found_destination' => 'Destination not found',
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
        'error_account_banned' => 'This avatar is banned.',
        'error_post_invalid' => 'A post has 1 to 1000 characters.',
        'error_email_unconfirmed' => 'This avatar is not active yet: confirm your email with the link of the mail we sent you.',
        'error_name_invalid' => 'The first and the last name are made of letters, digits and hyphens, without accents.',
        'error_name_reserved' => 'This name is reserved, please choose another one.',
        'error_name_taken' => 'An avatar already has this name, please choose another one.',
        'error_email_taken' => 'An avatar already has this email address.',
        'error_password_required' => 'Please fill in every field.',
        'error_password_invalid' => 'The password must have 6 to 16 characters, without double quote.',
        'error_email_invalid' => 'This email address is not valid.',
        'error_email_required' => 'This avatar has no email address.',
        'error_model_unknown' => 'This outfit is not available, please choose another one.',
        'error_link_invalid' => 'This cannot be done.',
        'error_field_unknown' => 'This cannot be done.',
        'error_field_read_only' => 'The name, the place and the size of a region are changed in its file, by its operator.',
        'error_flag_unknown' => 'This flag does not exist.',
        'error_owner_unknown' => 'This avatar does not exist.',
        'error_config_not_writable' => 'This cannot be changed from here: the files of the grid belong to its server.',
        'error_instance_user_required' => 'This cannot be done from here: the simulators are started and stopped on their server.',
        'error_restore_conflict' => 'This backup holds what this server has already: nothing was replaced.',
        'error_source_not_readable' => 'Some files of the older install cannot be read by the server: give it read access to them first.',
        'error_estate_shared' => 'The owner of this region is the owner of its estate, which has other regions: change the owner of the estate.',
        'error_filter_unknown' => 'This list cannot be filtered this way.',
        'error_filter_value_unknown' => 'This list cannot be filtered this way.',
        'error_sort_unknown' => 'This list cannot be sorted this way.',
        'error_role_unknown' => 'This role does not exist.',
        'error_role_level_conflict' => 'The level does not match the role.',
        'error_token_refused' => 'This link does not work: it has expired or it was already used. Ask for a new one.',
        'error_no_confirmation_pending' => 'This avatar does not wait for the confirmation of its email.',
        'error_mail_failed' => 'The mail could not be sent. Please try again later.',

        // What the engine says outside its errors (Text\Notice): notice_ and its code, its values as {name}
        'notice_robust_restart_roles' => 'Robust reads {key} when it starts: restart Robust ({restart}) so it gives the region these roles when it registers again.',
        'notice_robust_config_line' => '{file} cannot be written by this process: set {line} in its [{section}], then restart Robust ({restart}).',
        'notice_robust_config_remove' => '{file} cannot be written by this process: remove {key} from its [{section}], then restart Robust ({restart}).',
        'notice_robust_config_elsewhere' => 'The config of Robust is not on this machine: set {line} in its [{section}], so the region keeps its roles when it registers again.',
        'notice_start_simulator' => 'Start the simulator {slug}: {command}',
        'notice_start_simulator_regions' => 'The simulator {slug} loads its new regions when it starts: {command}',
        'notice_region_roles_later' => 'Robust gives {region} its roles ({roles}) from its config, read when it starts: restart Robust ({restart}) before starting {slug}, or once the region runs: {command}',
        'notice_plan_reserves_nothing' => 'A plan reserves nothing: the places and the ports it proposes are the free ones now, chosen again when it is applied. Apply a plan before planning the next one, or give the regions in one file.',
        'notice_import_read_access' => 'The user that runs the import cannot read {paths}: give it read access to them (setfacl -R -m u:<user>:rX on the etc, var/data and var/cache of the older install) before applying, nothing is written until then.',
        'notice_import_stop_source' => 'The simulator that runs {regions} now keeps running until it is stopped: stop it and keep it from starting again (its cron, its screen) before this one starts, the database is the same.',
        'notice_import_no_region' => 'No region was found for {simulator} in {folder}, the folder its config names: check that it is the one it reads before applying, nothing of its regions would be copied.',
        'notice_import_module_package' => 'The module {module} ({key} of [{section}]) is not installed for this core: the simulator would not load it. Install its package (apt install {package}) and add {name} to EnabledModules of w4os.conf, or leave the setting out.',
        'notice_import_module_missing' => 'The module {module} ({key} of [{section}]) is in neither the core nor the modules of this install: the simulator would not load it. Put a build of it for this core in {folder} and add {name} to EnabledModules of w4os.conf, or leave the setting out.',
        'notice_import_module_found' => 'The module {module} ({key} of [{section}]) is in neither the core nor the modules of this install, the older install has it in {file}: the simulator would not load it. Put a build of it for this core in {folder} (a module is built for a version of OpenSimulator: that file only if both cores are the same version) and add {name} to EnabledModules of w4os.conf, or leave the setting out.',
        'notice_import_enable_start' => 'Then: {enable}, {start}.',
        'notice_restart_grid' => 'Grid restart is required: {restart}.',
        'notice_restart_simulators' => 'Simulator(s) restart is required: {restart}.',
        'notice_import_stop_grid' => 'The old Robust of {grid} keeps running until it is stopped: stop it and keep it from starting again (its service, its cron, its screen), its ports {ports} are the ones of the grid here, its database the same.',
        'notice_in_place_enable' => 'The grid is registered in place, disabled. Stop its own start first (kickstart.sh --in-place does it), or by hand: its cron or its service set aside, its running instances stopped (kill -TERM: a Robust with a remote console has no screen). Then {enable}, then {start}.',
        'notice_in_place_unwritable' => 'Its instances run as the account {account}, which cannot write in {folders} (folders of {owner}). The install is left as it is: they cannot run until {account} can write there.',
        'notice_install_account' => 'The instances run as {account}, the owner of the install, and its site is served as {web}: SystemUser, WebUser and WebGroup of {conf}. The service takes the account with: {command}',
        'notice_import_switch' => 'Then switch: {command} (the grid and its simulators enabled and started here).',
        'notice_restore_estate_missing' => 'The regions {regions} have no estate in {database}, the database of the grid here, which is kept as it is: they would start with none. Restore their estate there, or link them to one (estate link region) before starting.',
        'notice_restore_estate_unchecked' => 'The estates of the regions could not be checked in {database}, the database of the grid here, which is kept as it is: check that they are there before starting.',
        'notice_restore_start' => 'Start {instance} once it is restored: {command}',
        'notice_mail_sent_if_known' => 'When an avatar has this name or this email, a mail was sent to it.',
        'notice_mail_sent' => 'A mail was sent to the avatar.',
        'notice_registered' => 'The avatar {name} is ready: sign in with its name and its password.',
        'notice_registered_confirm' => 'A mail was sent to {email}: the account is made once the link it holds is followed.',
        'notice_registered_mail_failed' => 'The registration is kept, but the mail to {email} could not be sent: ask for it again later.',
        'notice_setting_file_unwritable' => 'This server cannot write {file}, where the grid reads it: set it there by hand.',
        'notice_setting_file_unwritable_unkept' => 'This server cannot write {file}, and the application keeps no settings for the grid: set it there by hand.',
        'notice_setting_no_files' => 'The grid has no files on this machine: it reads this setting in its files, set it there by hand.',
        'notice_setting_no_files_unkept' => 'The grid has no files on this machine, and the application keeps no settings for it.',
        'notice_options_no_files' => 'The grid has no files on this machine: its options are repaired where its config is kept.',
        'notice_database_unsaid' => 'The config of the grid does not say where this database is.',
        'notice_database_unreachable' => 'The database cannot be reached.',
    ];

    /**
     * The key of the text of a problem code; for not-found, the one of the kind of resource the problem names (`resource`, a type
     * of the API: avatars is error_not_found_avatar) when the catalog has it, else the neutral one.
     */
    public static function errorKey(string $code, ?string $resource = null): string
    {
        $key = 'error_' . str_replace('-', '_', $code);
        if ($code !== 'not-found' || $resource === null || $resource === '') {
            return $key;
        }
        $named = $key . '_' . str_replace('-', '_', rtrim($resource, 's'));

        return isset(self::MESSAGES[$named]) ? $named : $key;
    }

    /** The key of the text of the status of an avatar (active, disabled, pending). */
    public static function statusKey(string $status): string
    {
        return 'status_' . $status;
    }

    /** The key of the text of the role of an avatar (resident, god-like, customer-service, liaison, god, maintenance). */
    public static function roleKey(string $role): string
    {
        return 'role_' . str_replace('-', '_', $role);
    }

    /** The key of the message a site says once an action of a list is done: `manage_` and its name in snake case (estateBan: manage_estate_ban). */
    public static function doneKey(string $action): string
    {
        return 'manage_' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $action));
    }

    /** The text of a problem code in English (the one of `invalid-request` for a code it does not know), of its resource for not-found. */
    public static function error(string $code, ?string $resource = null): string
    {
        return self::MESSAGES[self::errorKey($code, $resource)] ?? self::MESSAGES[self::errorKey('invalid-request')];
    }

    /**
     * The text of a notice (Text\Notice) in a language, its values in it ({name}); English when the language has no text for it.
     *
     * @param array<string,string|int> $params
     */
    public static function notice(string $locale, string $code, array $params = []): string
    {
        $key = 'notice_' . str_replace('-', '_', $code);
        $text = (self::messages($locale) + self::MESSAGES)[$key] ?? $code;

        return strtr($text, array_combine(array_map(static fn(string $name): string => '{' . $name . '}', array_keys($params)), array_map('strval', $params)));
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
     * gives none; `en` gives the English texts. A text that changes with a number is in the form of several (ten): the others are
     * Catalog::plurals().
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
            $translation = isset($entry['forms']) ? $entry['forms'][self::pluralIndex($locale, 10)] ?? '' : $entry['translation'];
            if ($entry['context'] !== '' && $translation !== '' && ($fuzzy || !$entry['fuzzy']) && isset(self::MESSAGES[$entry['context']])) {
                $messages[$entry['context']] = $translation;
            }
        }

        return $messages;
    }

    /**
     * The forms of the texts that change with a number, in a language, by key: the translated ones only, in the order of its
     * `Plural-Forms` (PLURAL_FORMS). `en` gives the English singular and plural.
     *
     * @return array<string,list<string>>
     */
    public static function plurals(string $locale, bool $fuzzy = true): array
    {
        $locale = self::locale($locale);
        if ($locale === self::SOURCE) {
            $keys = array_keys(self::SINGULARS);

            return array_combine($keys, array_map(static fn(string $key): array => [self::SINGULARS[$key], self::MESSAGES[$key]], $keys));
        }
        $file = self::file($locale);
        $plurals = [];
        foreach (is_file($file) ? PoFile::read((string) file_get_contents($file)) : [] as $entry) {
            if (isset($entry['forms'], self::SINGULARS[$entry['context']]) && !in_array('', $entry['forms'], true) && ($fuzzy || !$entry['fuzzy'])) {
                $plurals[$entry['context']] = $entry['forms'];
            }
        }

        return $plurals;
    }

    /**
     * A text in the form of a number, in a language: translated, else in English. A text that does not change with a number is
     * the one of Catalog::messages(). The number is not put in: the text is the one to format (sprintf($text, $n)).
     */
    public static function plural(string $locale, string $key, int $n): string
    {
        if (!isset(self::SINGULARS[$key])) {
            return self::messages($locale)[$key] ?? self::MESSAGES[$key] ?? $key;
        }
        $forms = self::plurals($locale)[$key] ?? null;

        return $forms === null
            ? ($n === 1 ? self::SINGULARS[$key] : self::MESSAGES[$key])
            : $forms[self::pluralIndex($locale, $n)] ?? end($forms);
    }

    /** The form a number takes in a language: the rule of its `Plural-Forms` (PLURAL_FORMS), the English one for another. */
    public static function pluralIndex(string $locale, int $n): int
    {
        return match (self::locale($locale)) {
            'cy_GB' => $n === 1 ? 0 : ($n === 2 ? 1 : ($n !== 8 && $n !== 11 ? 2 : 3)),
            'fr_FR', 'pt_BR' => $n > 1 ? 1 : 0,
            default => $n !== 1 ? 1 : 0,
        };
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
            'Plural-Forms: ' . self::PLURAL_FORMS[self::SOURCE],
            '',
        ]));
        $entries = [];
        foreach (self::MESSAGES as $key => $text) {
            $flags = str_contains($text, '%') ? ['php-format'] : [];
            $entries[] = isset(self::SINGULARS[$key])
                ? PoFile::plural($key, self::SINGULARS[$key], $text, ['', ''], $flags)
                : PoFile::entry($key, $text, '', $flags);
        }

        return "# The texts of the websites of a grid. Made from W4OS\\Engine\\I18n\\Catalog::MESSAGES by `composer translations`.\n"
            . "# This file is distributed under the same license as w4os-engine (AGPL-3.0-or-later).\n"
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
