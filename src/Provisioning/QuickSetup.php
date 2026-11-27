<?php

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Config\ConnectionString;
use W4OS\Engine\Exceptions\SetupFailedException;
use W4OS\Engine\Forms\FieldSet;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Result;
use W4OS\Engine\Runtime\Core;
use W4OS\Engine\Runtime\CoreReleases;
use W4OS\Engine\Runtime\Environment;
use W4OS\Engine\Runtime\ShellService;

/**
 * The quick setup: a few answers (the type, the name, the login address, the hypergrid, the avatar that owns it, its password and its email) and
 * the whole setup they stand for, with the proposals of the planners for everything else, in the format of the setup files
 * (see SetupFile). What is wrong or missing is told by the name of the question. Nothing is written: whoever asks shows the
 * setup, asks again what is wrong, and applies it.
 *
 * A grid is made of its Robust and a first simulator, `Welcome`, with its region. A standalone is one simulator that is the
 * whole grid: it has the name that was given, and so does its region.
 */
final class QuickSetup
{
    public const TYPES = ['grid', 'standalone'];
    /** Setup operations clients can request explicitly, before sending a provisioning command. */
    public const CAPABILITIES = ['prepare', 'apply_prepared', 'existing_region'];

    /**
     * The options of the form, in the order they are shown. They are the only choices of a quick setup: everything else is on (the
     * search module, the offline messages, the profiles, the destination guide, the grid status). `plan` says what an option sets in
     * the plan of the grid, from its value and from all of them (nothing yet for SSL), `implemented` false that the setup does not
     * apply it yet.
     *
     * @return array<string,array{label:string,default:bool,implemented:bool,plan:?\Closure,uses?:list<string>}>
     */
    private static function options(Environment $environment): array
    {
        // The ones a first install asks (shown), then the ones that keep their default without a question
        $options = [
            'profiles' => ['label' => _('profiles'), 'default' => true, 'implemented' => true, 'plan' => null, 'shown' => true, 'presets' => ['profiles']],
            'groups' => ['label' => _('groups'), 'default' => true, 'implemented' => true, 'plan' => null, 'shown' => true, 'presets' => ['groups-hypergrid', 'groups-local', 'groups-local-hypergrid', 'groups-remote']],
            'search_module' => ['label' => _('search'), 'default' => true, 'implemented' => true, 'plan' => null, 'shown' => true, 'presets' => ['search']],
            'offline' => ['label' => _('offline messages'), 'default' => true, 'implemented' => true, 'plan' => null, 'shown' => true, 'presets' => ['offline-robust', 'offline-helpers']],
            'map' => ['label' => _('3D map'), 'default' => false, 'implemented' => true, 'plan' => null, 'shown' => true, 'presets' => ['map-warp3d']],
            'ssl' => ['label' => 'SSL', 'default' => false, 'implemented' => false, 'plan' => null],
            'hypergrid' => [
                'label' => 'Hypergrid',
                'default' => true,
                'implemented' => true,
                'plan' => static fn(bool $on): array => ['hypergrid' => $on],
            ],
            'helpers' => [
                'label' => 'Helpers',
                'default' => true,
                'implemented' => true,
                'plan' => static fn(bool $on): array => ['helpers' => $on],
            ],
        ];
        // The others are presets that say they are offered (see OptionTemplate): the administrator adds one by adding a folder
        foreach ((new Presets([], $environment->layout->etcRoot()))->options() as $name => $template) {
            if ($template->offered() && !isset($options[$name])) {
                $options[$name] = [
                    'label' => $template->label(),
                    'default' => $template->default(),
                    'implemented' => true,
                    'plan' => null,
                    'uses' => $template->uses(),
                ];
            }
        }

        return $options;
    }

    /**
     * What can be set up: the grid and the standalone, a simulator that joins another grid, and the presets (the ones planned stay in
     * the list, the ones this machine has can be set up). The kind says how: a grid, a standalone, a simulator that joins a grid
     * given by its address (remote), or one that joins the grid of a preset.
     *
     * @return list<array{name:string,label:string,implemented:bool,kind:string}>
     */
    private static function types(Environment $environment): array
    {
        $types = [
            ['name' => 'grid', 'label' => 'Grid', 'implemented' => true, 'kind' => 'grid'],
            ['name' => 'standalone', 'label' => 'Standalone', 'implemented' => true, 'kind' => 'standalone'],
            [
                'name' => 'simulator',
                'label' => 'Simulator (join other grid)',
                'implemented' => true,
                'kind' => 'remote',
            ],
            // The grid of another install migrated into this one (GridImport)
            ['name' => 'existing', 'label' => 'Existing install (migrate its grid)', 'implemented' => true, 'kind' => 'import'],
        ];
        $presets = new Presets([], $environment->layout->etcRoot());
        $found = $presets->names();
        foreach (['speculoos' => 'Speculoos', 'osgrid' => 'OSGrid'] + $found as $name => $grid) {
            $types[] = [
                'name' => (string) $name,
                'label' => $grid,
                'implemented' => isset($found[$name]) && $presets->describe((string) $name) !== null,
                'kind' => 'preset',
            ];
        }

        return $types;
    }

    /** How a type is set up (grid, standalone, remote, preset), empty for a type that is not one. */
    private static function kindOf(Environment $environment, string $type): string
    {
        return array_column(self::types($environment), 'kind', 'name')[$type] ?? '';
    }

    /**
     * The test doubles of the work that needs a machine: what a grid says of itself (Closure(string $host, int $port): array).
     */
    private static ?\Closure $says = null;

    /** The questions the planners' fields answer, by the name of the field. */
    private const QUESTIONS = [
        'name' => 'name',
        'hostname' => 'login_uri',
        'public_port' => 'login_uri',
        'new_name' => 'avatar',
        'new_password' => 'password',
        'new_email' => 'email',
        'estate_owner' => 'avatar',
    ];

    /**
     * @param array<string,mixed> $answers type, name, hostname (destination proposal), login_uri, avatar, password, email and the options (ssl, hypergrid, search,
     *        gloebit, ossl): all optional, a missing one is proposed or, when it cannot be, told in the problems
     * @return array{valid:bool,problems:array<string,string>,corrected:array<string,string>,identity:string,setup:array<string,mixed>,port_bindings:list<array{port:int,protocol:string,use:string,host_ip:?string}>,rows:list<array{section:string,label:string,value:string}>,types:list<array{name:string,label:string,implemented:bool}>,options:list<array{name:string,label:string,default:bool,value:bool,implemented:bool}>}
     */
    public static function plan(Environment $environment, array $answers): array
    {
        if (
            in_array(
                self::kindOf($environment, strtolower(trim((string) ($answers['type'] ?? '')))),
                ['remote', 'preset'],
                true,
            )
        ) {
            return self::planRemote($environment, $answers);
        }
        $built = self::build($environment, $answers);
        $setup = SetupFile::withSimulator(SetupFile::withGrid([], $built['plan']), $built['simulator']);

        return [
            'valid' => $built['problems'] === [],
            'problems' => $built['problems'],
            'corrected' => $built['corrected'],
            'identity' => $built['standalone'] ? $built['simulator']->slug : $built['plan']->gridNick,
            'setup' => $setup,
            'port_bindings' => self::portBindings($setup),
            'rows' => self::rows(
                $built['plan'],
                $built['simulator'],
                $built['standalone'],
                trim((string) ($answers['email'] ?? '')),
                $built['chosen'],
                $built['labels'],
            ),
            'types' => self::publicTypes($built['types']),
            // What its address is now: said before Start, the person goes on or changes it
            'warnings' => self::addressWarnings($built['plan']->baseHostname),
            'options' => array_map(
                static fn(string $name, array $option): array => [
                    'name' => $name,
                    'label' => $option['label'],
                    'default' => $option['default'],
                    'value' => $built['chosen'][$name],
                    'implemented' => $option['implemented'],
                ],
                array_keys($built['options']),
                array_values($built['options']),
            ),
        ];
    }

    /** The types as the API tells them: the kind is the business of the scenario. */
    private static function publicTypes(array $types): array
    {
        return array_map(static fn(array $type): array => array_diff_key($type, ['kind' => 1]), $types);
    }

    /**
     * The plans the answers stand for, and what is wrong with them.
     *
     * @param array<string,mixed> $answers
     * @return array{problems:array<string,string>,corrected:array<string,string>,plan:GridPlan,simulator:SimulatorPlan,standalone:bool,chosen:array<string,bool>,types:list<array{name:string,label:string,implemented:bool,kind:string}>,options:array<string,array{label:string,default:bool,implemented:bool,plan:?\Closure}>}
     */
    private static function build(Environment $environment, array $answers): array
    {
        $problems = [];
        $corrected = [];
        $type = strtolower(trim((string) ($answers['type'] ?? '')));
        $types = self::types($environment);
        if ($type !== '' && !in_array($type, self::TYPES, true)) {
            $problems['type'] = in_array($type, array_column($types, 'name'), true)
                ? _('not implemented')
                : _('invalid');
        }
        $standalone = $type === 'standalone';
        if (trim((string) ($answers['existing_region'] ?? ($answers['setup']['simulators'][0]['regions'][0]['source'] ?? ''))) !== '') {
            $problems['existing_region'] = _('Use a simulator setup to adopt a region of an existing grid.');
        }

        // The options: what is chosen, and what each sets in the plan of the grid
        $options = self::options($environment);
        foreach (
            (new FieldSet(
                array_map(
                    static fn(string $name): array => ['name' => $name, 'type' => 'boolean'],
                    array_keys($options),
                ),
            ))->problems($answers)
            as $name => $message
        ) {
            $problems[$name] = $message;
        }
        $chosen = [];
        foreach ($options as $name => $option) {
            $given = $answers[$name] ?? null;
            $chosen[$name] = Result::isTrue($given) || (Result::isFalse($given) ? false : $option['default']);
        }
        $fromOptions = [];
        foreach ($options as $name => $option) {
            if ($option['plan'] === null && isset($option['presets'])) {
                // The presets it stands for: on as their defaults have them, off by name (the standard ones off after, recorded)
                if (!$chosen[$name] || $name === 'map') {
                    foreach ($option['presets'] as $preset) {
                        $fromOptions['options'][$preset] = $chosen[$name];
                    }
                }

                continue;
            }
            if ($option['plan'] === null) {
                // A preset: it is on or off by name, and what needs the helpers cannot be on without them
                $fromOptions['options'][$name] = $chosen[$name];
                $chosen[$name] &&
                    !$chosen['helpers'] &&
                    in_array('helpers', $option['uses'] ?? [], true) &&
                    ($problems[$name] = _('needs Helpers'));

                continue;
            }
            $fromOptions = $option['plan']($chosen[$name], $chosen) + $fromOptions;
        }

        // The grid. What is right or wrong, and what is corrected, is said by the fields of the models: the answers go to them as given,
        // and the plan is made of the corrected ones (the person confirms a correction by sending the form again).
        $dbGiven = array_intersect_key(
            $answers,
            array_flip(['database_driver', 'database_host', 'database_name', 'database_user', 'database_password']),
        );
        $gridDatabase = $standalone ? $dbGiven : array_diff_key($dbGiven, ['database_driver' => true]);
        $gridAnswers = ['standalone' => $standalone] + self::portValues($answers, ['private_port' => 'private_port', 'console_port' => 'grid_console_port']) + $gridDatabase + $fromOptions;
        // The web services (screenWeb()): as they are given; the planner gives what is not, and 2do.directory's without the helpers
        $gridAnswers += array_intersect_key($answers, array_flip([...self::WEB, 'web_site', 'web_url', 'center']));
        trim((string) ($answers['name'] ?? '')) !== '' && ($gridAnswers['name'] = trim((string) $answers['name']));
        [$uri, $uriProblem] = Grid::parseLoginUri((string) ($answers['login_uri'] ?? ''));
        $uriProblem !== null && ($problems['login_uri'] = $uriProblem);
        $gridAnswers += $uri;
        if (!isset($gridAnswers['hostname']) && trim((string) ($answers['hostname'] ?? '')) !== '') {
            $gridAnswers['hostname'] = (string) $answers['hostname'];
        }
        if (is_array($answers['setup'] ?? null)) {
            $gridAnswers = array_replace($gridAnswers, SetupFile::gridValues($answers['setup']));
        }
        $planner = GridPlanner::forMachine($environment);
        $fixes = self::review(
            $planner->fields(),
            $planner->problems($gridAnswers),
            $gridAnswers,
            $problems,
            $corrected,
            ['console_port' => 'grid_console_port'],
        );
        $plan = $planner->plan($fixes + $gridAnswers);
        // A new grid: the name of one that exists is taken (its regions, its ports are not the business of this setup)
        $taken = Grid::find($environment, $plan->gridNick) !== null;
        $taken && ($problems['name'] = _('taken'));

        // Its first simulator, with its region, and the avatar that owns the estate
        $ui = new NullUi();
        $database = new DatabaseService($ui);
        $grid = Grid::planned($environment, $plan);
        $region = $standalone ? Grid::standaloneRegionName($plan->gridName) : 'Welcome';
        $simulators = SimulatorPlanner::forGrid($grid, $database, $ui, null, $standalone ? $region : null);
        $owner =
            [
                'estate_owner' => '+',
                'new_name' => trim((string) ($answers['avatar'] ?? '')),
                'new_password' => (string) ($answers['password'] ?? ''),
                'new_email' => trim((string) ($answers['email'] ?? '')),
            ] + array_diff_key($dbGiven, ['database_name' => 1]);
        $owner += self::portValues($answers, ['http_port' => 'http_port', 'console_port' => 'console_port']);
        if (is_array($answers['setup'] ?? null)) {
            $owner = array_replace($owner, SetupFile::simulatorValues($answers['setup'], 0));
        }
        $fixes = self::review($simulators->fields(), $simulators->problems($owner), $owner, $problems, $corrected);
        $simulator = $simulators->plan($fixes + $owner);
        $simulator->dbDriver = strtolower(trim((string) ($answers['database_driver'] ?? $plan->dbDriver)));
        if ($standalone && $simulator->httpPort !== $plan->publicPort) {
            $problems['http_port'] = _('must match the standalone Login URI port');
        }
        $regions = RegionPlanner::forSimulator($simulator, $grid, $database, defaultName: $region);
        $regionAnswers = is_array($answers['setup'] ?? null) ? SetupFile::regionValues($answers['setup'], 0, 0) : ['name' => $region];
        $regionAnswers += self::portValues($answers, ['port' => 'region_port']);
        $taken ||
            self::review(
                $regions->fields(),
                $regions->problems($regionAnswers),
                $regionAnswers,
                $problems,
                $corrected,
                ['port' => 'region_port'],
            );
        $regions->apply($simulator, $regionAnswers);

        if ($environment->occupiedPorts !== null) {
            $selected = [
                'login_uri' => $plan->publicPort,
                'private_port' => $plan->privatePort,
                'grid_console_port' => $plan->consolePort,
                'http_port' => $simulator->httpPort,
                'console_port' => $simulator->consolePort,
                'region_port' => $simulator->regionPort,
            ];
            foreach ($selected as $field => $port) {
                if (in_array($port, $environment->occupiedPorts, true)) {
                    $problems[$field] = _('already in use on the selected host');
                }
            }
        }

        return [
            'problems' => $problems,
            'corrected' => $corrected,
            'plan' => $plan,
            'simulator' => $simulator,
            'standalone' => $standalone,
            'chosen' => $chosen,
            'labels' => array_map(
                static fn(OptionTemplate $t): string => $t->label(),
                array_filter(
                    (new Presets([], $environment->layout->etcRoot()))->options(),
                    static fn(OptionTemplate $t): bool => $t->shown(),
                ),
            ),
            'types' => $types,
            'options' => $options,
        ];
    }

    /**
     * The scenario of the quick setup, one step at a time: a client sends back the screen it was given, filled, and gets the next
     * screen, the same one with what is wrong, or an action to do (quit with a message, abort with an error). It knows nothing else:
     * the order of the screens, the checks, the corrections, Back and Quit are here, so that a terminal, a web page or an app run
     * the same setup. Nothing is kept here: the state is a string the client sends back as it got it.
     *
     * The screens are `menu` (a choice), `form` (fields), `checks` (check boxes) and `review` (what the setup will do, to confirm). The
     * last one, confirmed, makes the folders and writes the files of the grid and of its first simulator.
     *
     * @param array{state?:string,button?:string,answers?:array<string,mixed>,mode?:string,occupied_ports?:list<int>} $request mode: apply (default) or prepare
     * @return array<string,mixed> screen, quit, abort or prepared (configuration, including secret answers)
     */
    public static function next(Environment $environment, array $request): array
    {
        if (array_key_exists('occupied_ports', $request)) {
            if (!is_array($request['occupied_ports']) || !array_is_list($request['occupied_ports'])) {
                throw new \InvalidArgumentException('Occupied ports must be a list');
            }
            $environment = $environment->onHostWithOccupiedPorts($request['occupied_ports']);
        }
        $mode = $request['mode'] ?? 'apply';
        if (!in_array($mode, ['apply', 'prepare'], true)) {
            throw new \InvalidArgumentException('Unknown setup mode');
        }
        if (array_key_exists('configuration', $request)) {
            if ($mode !== 'apply' || !is_array($request['configuration'])) {
                throw new \InvalidArgumentException('A prepared configuration requires apply mode');
            }
            return self::applyConfiguration($environment, $request['configuration']);
        }
        $prepare = $mode === 'prepare';
        $result = self::advance($environment, $request, $prepare);
        if ($prepare) {
            $result['mode'] = 'prepare';
        }
        if ($prepare && in_array($result['kind'] ?? '', ['review', 'form'], true)) {
            if (($result['kind'] ?? '') === 'review') {
                $result['buttons']['next'] = _('Continue');
                $result['wait'] = '';
            } elseif (($result['title'] ?? '') === _('Database')) {
                $result['buttons']['next'] = _('Save configuration');
                $result['wait'] = '';
            }
        }
        return $result;
    }

    /** Advance the questionnaire, optionally ending with collected answers instead of provisioning. */
    private static function advance(Environment $environment, array $request, bool $prepare): array
    {
        $state = self::decode((string) ($request['state'] ?? ''));
        $button = in_array($request['button'] ?? 'next', ['next', 'back', 'quit', 'show', 'edit', 'failed'], true)
            ? $request['button'] ?? 'next'
            : 'next';
        $answers = $state['answers'];
        $given = is_array($request['answers'] ?? null) ? $request['answers'] : [];
        // An install of before the kit here, when the client asks (kickstart): its scenario first, the choice of what to do with it
        if ($state['step'] === '' && Result::isTrue($request['existing'] ?? false)) {
            $legacy = (self::$legacy ?? Import\LegacyInstall::here(...))();
            // Its answers described, or given all at once (nobody there): the run without its screens
            if (Result::isTrue($request['keys'] ?? false)) {
                return ['action' => 'keys', 'keys' => MigrationScenario::keys($legacy->found() ? $legacy : null)];
            }
            if (Result::isTrue($request['unattended'] ?? false)) {
                return $legacy->found()
                    ? MigrationScenario::unattended($environment, $legacy, $given)
                    : ['action' => 'abort', 'code' => 2, 'error' => sprintf(_('No pre-existing install in %s.'), $legacy->root)];
            }
        }
        if (str_starts_with($state['step'], MigrationScenario::PREFIX)) {
            $legacy = self::legacyOf($answers);
            // What to do with it chosen: what this machine misses first (its folders; a core, for a migration), as for a new grid
            $choice = (string) ($given['existing'] ?? '');
            if ($state['step'] === MigrationScenario::PREFIX && $button === 'next' && in_array($choice, ['migrate', 'in-place'], true)
                && ($missing = self::machine($environment, ['then' => $choice] + $answers)) !== null) {
                return $missing;
            }
            $next = MigrationScenario::advance($environment, $state['step'], $answers, $button, $given, $request, $legacy);
            // Back, or a new grid beside it: the first menu
            return $next === null ? self::screenType($environment, [], '') : self::withLocation($next, $legacy);
        }
        if ($state['step'] === '') {
            return self::screenType($environment, $answers, '');
        }
        // The website of a grid: its line in the sites of Apache answered, its step run, the summary
        if ($state['step'] === 'website-left') {
            return match ((string) ($given['website'] ?? 'quit')) {
                'install' => self::websiteAddress($answers, '', false),
                'other' => self::screenType($environment, [], ''),
                default => self::stopped($answers),
            };
        }
        // The address of a website left, asked again: kept alone (no other address of the grid follows it), its line of Apache with it
        if ($state['step'] === 'website-address') {
            if ($button === 'back') {
                return self::stopped($answers);
            }
            try {
                $site = \W4OS\Engine\Models\Grid::validWebsite((string) ($given['website'] ?? $answers['site']));
            } catch (\Throwable $error) {
                return self::websiteAddress($answers, $error->getMessage(), false);
            }
            $site !== '' || ($site = (string) $answers['site']);
            $changed = $site !== $answers['site'];
            $answers = array_replace($answers, ['site' => $site, 'done' => [sprintf('Website    %s', $site), sprintf('Admin      %slogin', $site)]]);
            $files = MigrationScenario::vhostFiles($site);
            if ($files === []) {
                return self::websiteAddress($answers, sprintf(_('No site of Apache serves %s.'), (string) parse_url($site, PHP_URL_HOST)), false);
            }
            if ($files !== null && ($changed || !isset($given['vhosts']))) {
                return self::websiteAddress($answers, '', true);
            }
            if ($files !== null && !Result::isTrue($given['vhosts'] ?? 'yes')) {
                return ['action' => 'quit', 'message' => implode("\n", [...(array) $answers['head'], 'Website    not installed (it needs its line in the sites of Apache)'])];
            }
            $grid = \W4OS\Engine\Models\Grid::find($environment, (string) $answers['grid']);
            $grid === null || \W4OS\Engine\Models\Grid::keepSiteAddress($grid->directory() . '/' . $grid->nick() . '.conf', $site);

            return self::websiteStep($answers);
        }
        if ($state['step'] === 'website-line') {
            if ($button === 'back' || ($given['vhosts'] ?? '') === 'quit') {
                return self::stopped($answers);
            }

            return self::install($environment, array_replace($answers, ['vhosts' => Result::isTrue($given['vhosts'] ?? 'yes') ? 'yes' : 'no']));
        }
        if ($state['step'] === 'website') {
            return self::websiteRun($answers, $button, $given, $request);
        }
        if ($button === 'quit') {
            return self::stopped($answers);
        }
        $kind = self::kindOf($environment, (string) ($answers['type'] ?? ''));
        $remote = in_array($kind, ['remote', 'preset'], true);

        switch ($state['step']) {
            case 'type':
                if ($button === 'back') {
                    return ($answers['menu'] ?? '') === 'join'
                        ? self::screenType($environment, array_diff_key($answers, ['menu' => true]), '')
                        : self::stopped($answers);
                }
                unset($answers['menu']);
                // What is here already: the install of before (what to do with it), a grid of this machine (what is left of it)
                $legacy = (self::$legacy ?? Import\LegacyInstall::here(...))();
                $chosen = (string) ($given['type'] ?? '');
                if ($legacy->found() && ($chosen === 'found' || in_array($given['existing'] ?? '', ['migrate', 'in-place', 'new', 'abort'], true))) {
                    return isset($given['existing'])
                        ? MigrationScenario::advance($environment, MigrationScenario::PREFIX, [], 'next', $given, $request, $legacy) ?? self::screenType($environment, [], '')
                        : MigrationScenario::first($legacy, $environment);
                }
                if (str_starts_with($chosen, 'here:')) {
                    return self::here($environment, substr($chosen, 5), $legacy);
                }
                if ($chosen === 'join') {
                    return self::screenType($environment, ['menu' => 'join'] + $answers, '');
                }
                if ($chosen === 'location') {
                    return self::screenLocation($answers, '');
                }
                $type = strtolower(trim((string) ($given['type'] ?? ($answers['type'] ?? ''))));
                $answers['type'] = $type;
                $answers = array_replace($answers, array_intersect_key($given, array_flip(['hostname', 'login_uri', 'oar', 'dump', 'show_passwords'])));
                if (isset($given['existing_region'])) {
                    $answers['existing_region'] = (string) $given['existing_region'];
                    if ($answers['existing_region'] !== '') {
                        try {
                            $existing = RegionAdoption::describe($answers['existing_region']);
                            $answers += ['name' => $existing['name']];
                        } catch (\InvalidArgumentException $error) {
                            return ['action' => 'abort', 'error' => $error->getMessage()];
                        }
                    }
                }
                $known = array_column(self::types($environment), 'implemented', 'name');
                if (($known[$type] ?? false) !== true) {
                    return self::screenType($environment, $answers, self::notImplemented($environment));
                }
                if (self::kindOf($environment, $type) === 'import') {
                    return self::screenImport($answers, '');
                }

                // What this machine misses first (its folders, a core), then the questions of the type
                return ($prepare ? null : self::machine($environment, $answers)) ?? self::typed($environment, $answers, $prepare);

            case 'machine':
                // The folders made as root by the client
                if ($button === 'failed') {
                    return ['action' => 'abort', 'error' => sprintf(isset($answers['runtime_asked']) ? _('The .NET runtime was not installed: %s') : _('The folders of w4os were not made: %s'), trim((string) ($given['error_text'] ?? '')))];
                }

                return self::machine($environment, $answers) ?? self::readied($environment, $answers, $prepare, $request);

            case 'core':
                if ($button === 'back') {
                    return self::screenType($environment, [], '');
                }
                $ui = new NullUi(live: true);
                try {
                    $ui->stage(1, 1, _('OpenSimulator'));
                    self::releases($environment)->install((string) ($given['core'] ?? ''), $environment->layout->coreRoot(), static fn(string $said) => $ui->note($said));
                } catch (\RuntimeException $error) {
                    return self::screenCore($environment, $answers, $error->getMessage());
                }

                // Said once done, by the client, with what follows
                return ['done' => sprintf('OpenSimulator %s', (string) ($given['core'] ?? ''))] + (self::machine($environment, $answers) ?? self::readied($environment, $answers, $prepare, $request));

            case 'location':
                $answers = self::keep($answers, $given, ['bin']);
                if ($button === 'back') {
                    return self::screenType($environment, [], '');
                }
                $bin = rtrim(trim((string) ($answers['bin'] ?? '')), '/');
                if ($bin === '' || !is_dir($bin) || (!is_file("$bin/OpenSim.dll") && !is_file("$bin/OpenSim.exe"))) {
                    return self::screenLocation($answers, sprintf(_('Not an OpenSim bin folder (no OpenSim.dll): %s'), $bin));
                }
                // The Robust config found from it, to confirm or give
                $robust = Import\LegacyInstall::robustIn($bin);

                return self::screenRobust(['bin' => $bin, 'robust' => $robust] + $answers, $robust === '' ? sprintf(_('No Robust.HG.ini nor Robust.ini in %s.'), $bin) : '');

            case 'location-robust':
                $answers = self::keep($answers, $given, ['robust']);
                if ($button === 'back') {
                    return self::screenLocation($answers, '');
                }
                try {
                    $legacy = Import\LegacyInstall::locate((string) ($answers['bin'] ?? ''), (string) ($answers['robust'] ?? ''));
                } catch (\InvalidArgumentException $error) {
                    return self::screenRobust($answers, $error->getMessage());
                }

                return self::withLocation(MigrationScenario::first($legacy, $environment), $legacy);

            case 'db-admin':
                if ($button === 'back') {
                    return self::screenType($environment, [], '');
                }
                $answers = self::keep($answers, $given, ['admin_user', 'admin_password']);
                $answers['admin_user'] = trim((string) $answers['admin_user']);
                if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $answers['admin_user']) !== 1) {
                    return self::screenAdmin($answers, _('User: letters, digits and _ only.'));
                }
                if ((string) $answers['admin_password'] === '') {
                    return self::screenAdmin($answers, _('Password: needed.'));
                }
                // The one of the environment, as it works: kept; any other made or checked as root, and kept for the commands
                if ($answers['admin_user'] === getenv('OPENSIM_DB_ADMIN_USER') && $answers['admin_password'] === getenv('OPENSIM_DB_ADMIN_PASS') && self::manages($environment, $answers)) {
                    return self::screenForm($environment, $answers + ['admin_checked' => true], '');
                }

                return [
                    'action' => 'step',
                    'step' => ['name' => 'db-admin', 'root' => true, 'label' => _('DB admin user'), 'args' => ['user' => $answers['admin_user'], 'password' => $answers['admin_password']], 'skippable' => false, 'number' => 1, 'count' => 1],
                    'labels' => [_('DB admin user')],
                    'notice' => '',
                    'state' => self::encode('db-admin-run', $answers),
                ];

            case 'db-admin-run':
                if ($button === 'failed') {
                    return self::screenAdmin($answers, trim((string) ($given['error_text'] ?? '')) ?: _('The DB admin user was not made.'));
                }

                return self::manages($environment, $answers)
                    ? self::screenForm($environment, $answers + ['admin_checked' => true, 'admin_made' => true], '')
                    : self::screenAdmin($answers, sprintf(_('%s: its password is refused.'), $answers['admin_user']));

            case 'db-user':
                if ($button === 'back') {
                    return self::screenForm($environment, $answers, '');
                }
                $chosen = (string) ($given['database_user'] ?? 'opensim');
                if ($chosen === 'other') {
                    return self::screenDbUserForm($environment, $answers, '');
                }
                // Another user: its password made again, or the one the grids of this machine use for it
                ($answers['database_user'] ?? '') === $chosen || ($answers = array_diff_key($answers, ['database_password' => true, 'database_password_source' => true]));
                $answers['database_user'] = $chosen;

                return self::userExists($environment, $answers) === true
                    ? self::screenDbUserForm($environment, array_diff_key($answers, ['database_password' => true]), '')
                    : self::screenOptions($environment, $answers, '');

            case 'db-user-form':
                if ($button === 'back') {
                    return self::screenDbUser($environment, $answers, '');
                }
                $answers = self::keep($answers, $given, ['database_user', 'database_password']);
                $answers['database_user'] = trim((string) $answers['database_user']);
                if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $answers['database_user']) !== 1) {
                    return self::screenDbUserForm($environment, $answers, _('User: letters, digits and _ only.'));
                }
                if ((string) ($answers['database_password'] ?? '') === '') {
                    return self::screenDbUserForm($environment, $answers, _('Password: needed.'));
                }
                $answers['database_password_source'] = 'given';

                return self::screenOptions($environment, $answers, '');

            case 'import':
                $answers = self::keep($answers, $given, ['robust_config', 'simulator_configs', 'nick']);
                if ($button === 'back') {
                    return self::screenType($environment, $answers, '');
                }
                try {
                    return self::screenImportReview(self::gridImport($environment, $answers), $answers);
                } catch (\InvalidArgumentException $error) {
                    return self::screenImport($answers, $error->getMessage());
                }

            case 'import-review':
                if ($button === 'back') {
                    return self::screenImport($answers, '');
                }
                try {
                    $import = self::gridImport($environment, $answers);
                    $import->apply($environment, new NullUi(), self::$dumper);
                } catch (\InvalidArgumentException $error) {
                    return self::screenImport($answers, $error->getMessage());
                }
                $document = $import->document();

                return [
                    'action' => 'quit',
                    'message' => implode("\n", [
                        sprintf(_('Grid %s written in %s, disabled: the old install still runs.'), $document['name'], dirname($document['config'])),
                        sprintf(_('Report: %s'), $document['report']),
                        '',
                        ...array_map(static fn(array $notice): string => (string) $notice['detail'], $document['reminders']),
                    ]),
                ];

            case 'form':
                $answers = self::fromShort(self::keep($answers, $given, [...self::editable(self::fields($environment, $answers)), ...self::SHORT]));
                if ($button === 'back') {
                    return self::screenType($environment, $answers, '');
                }
                if (($screen = self::checkForm($environment, $answers)) !== null) {
                    return $screen;
                }

                // What a simulator joins is the grid's: there are no options to choose; a grid's name known, its database user
                return match (true) {
                    $remote => self::screenReview($environment, self::pinned($environment, $answers)),
                    ($answers['database_driver'] ?? 'mysql') === 'sqlite' => self::screenOptions($environment, $answers, ''),
                    default => self::screenDbUser($environment, $answers, ''),
                };

            case 'options':
                $options = self::options($environment);
                $answers = self::keep($answers, $given, array_keys($options), true);
                if ($button === 'back') {
                    return ($answers['database_driver'] ?? 'mysql') === 'sqlite' ? self::screenForm($environment, $answers, '') : self::screenDbUser($environment, $answers, '');
                }
                // What is served by the helpers puts them on, and the person is told
                foreach ($options as $name => $option) {
                    if (
                        Result::isTrue($answers[$name] ?? $option['default']) &&
                        in_array('helpers', $option['uses'] ?? [], true) &&
                        !Result::isTrue($answers['helpers'] ?? true)
                    ) {
                        $answers['helpers'] = true;

                        return self::screenOptions(
                            $environment,
                            $answers,
                            sprintf(_('%s needs the helpers: Helpers is on.'), $option['label']),
                        );
                    }
                }
                if (($screen = self::checkForm($environment, $answers)) !== null) {
                    return $screen;
                }

                // The web services the helpers give, when they are on; without them, 2do.directory's (said on the review)
                return Result::isTrue($answers['helpers'] ?? true)
                    ? self::screenWeb($environment, $answers, '')
                    : self::screenReview($environment, self::pinned($environment, $answers));

            case 'web':
                $answers = self::fromShort(self::keep($answers, $given, [...self::WEB, 'website']));
                if ($button === 'back') {
                    return self::screenOptions($environment, $answers, '');
                }

                return self::screenReview($environment, self::pinned($environment, $answers));

            case 'database':
                $answers = self::keep($answers, $given, array_keys(self::databaseFields()));
                if ($button === 'back') {
                    return self::screenReview($environment, $answers);
                }

                return $prepare ? self::prepared($environment, $answers) : self::install($environment, $answers);

            case 'review':
                // Lines of the review changed: checked as if they had been asked, then the review again with what is wrong
                if ($button === 'edit') {
                    $answers = self::fromShort(self::keep($answers, $given, self::reviewNames($environment, $answers)));
                    $quick = self::plan($environment, self::answersOf(self::pinned($environment, $answers)));
                    $answers = array_replace($answers, $quick['corrected']);
                    $labels = array_column(self::reviewRows($environment, $answers), 'label', 'name');
                    $lines = [];
                    foreach ($quick['problems'] as $question => $message) {
                        $lines[] = sprintf('Error: %s %s', $labels[$question] ?? $question, $message);
                    }
                    ($wrong = self::folderProblem($environment, $answers)) === '' || ($lines[] = $wrong);

                    return self::screenReview($environment, $answers, implode("\n", $lines));
                }
                // The passwords of the accounts shown or masked again, nothing else changed
                if ($button === 'show') {
                    $answers['show_passwords'] = !Result::isTrue($answers['show_passwords'] ?? false);

                    return self::screenReview($environment, $answers);
                }
                if ($button === 'back') {
                    return match (true) {
                        $remote => self::screenForm($environment, $answers, ''),
                        Result::isTrue($answers['helpers'] ?? true) => self::screenWeb($environment, $answers, ''),
                        default => self::screenOptions($environment, $answers, ''),
                    };
                }

                $answers = self::keep($answers, $given, [
                    'database_driver',
                    'database_host',
                    'database_user',
                    'database_password',
                    'admin_user',
                    'admin_password',
                ]);

                if ($prepare) {
                    $plan = $remote
                        ? self::buildRemote($environment, self::answersOf($answers))['simulator']->databasePlan()
                        : self::build($environment, self::answersOf($answers))['plan'];
                    if ($plan->dbDriver === 'sqlite') {
                        return self::prepared($environment, $answers);
                    }

                    return self::screenDatabase($plan, $answers, '');
                }

                // The folders changed in the review: made first, as root by the client, w4os.conf saying them from there
                if ($button === 'failed') {
                    return self::screenReview($environment, $answers, sprintf(_('The folders were not made: %s'), trim((string) ($given['error_text'] ?? ''))));
                }
                if (self::folderProblem($environment, $answers) !== '') {
                    return self::screenReview($environment, $answers, self::folderProblem($environment, $answers));
                }
                if (($folders = self::folderStep($environment, $answers, self::encode('review', $answers))) !== null) {
                    return $folders;
                }

                return self::websiteLine($environment, $answers) ?? self::install($environment, $answers);
        }

        return self::screenType($environment, [], '');
    }

    /** The collected answers and their preview; no database, account or file is created. */
    private static function prepared(Environment $environment, array $answers): array
    {
        $answers = self::pinned($environment, $answers);
        $planned = self::plan($environment, self::answersOf($answers));
        if (!$planned['valid']) {
            return self::screenForm($environment, $answers, implode("\n", $planned['problems']));
        }
        return [
            'action' => 'prepared',
            'configuration' => [
                'version' => 1,
                'identity' => $planned['identity'],
                'answers' => $answers,
                'setup' => $planned['setup'],
                'port_bindings' => $planned['port_bindings'],
            ],
            'message' => _('Setup configuration prepared.'),
        ];
    }

    /**
     * The host publications required by a prepared setup, preserving the controller access policy.
     *
     * @param array<string,mixed> $setup
     * @return list<array{port:int,protocol:string,use:string,host_ip:?string}>
     */
    private static function portBindings(array $setup): array
    {
        $bindings = [];
        $add = static function (mixed $port, string $protocol, string $use) use (&$bindings): void {
            if (!is_numeric($port) || (int) $port < 1 || (int) $port > 65535 || $use === 'off') {
                return;
            }
            $port = (int) $port;
            $key = "$port/$protocol";
            $binding = [
                'port' => $port,
                'protocol' => $protocol,
                'use' => $use,
                'host_ip' => $use === 'public' ? null : '127.0.0.1',
            ];
            if (!isset($bindings[$key]) || $use === 'public') {
                $bindings[$key] = $binding;
            }
        };

        $grid = is_array($setup['grid'] ?? null) ? $setup['grid'] : [];
        $add($grid['public_port'] ?? null, 'tcp', 'public');
        $add($grid['private_port'] ?? null, 'tcp', 'private');
        ($grid['console'] ?? '') === 'rest' && $add($grid['console_port'] ?? null, 'tcp', 'console');

        foreach (is_array($setup['simulators'] ?? null) ? $setup['simulators'] : [] as $simulator) {
            if (!is_array($simulator)) {
                continue;
            }
            $add($simulator['http_port'] ?? null, 'tcp', 'public');
            ($simulator['console'] ?? '') === 'rest' && $add($simulator['console_port'] ?? null, 'tcp', 'console');
            foreach (is_array($simulator['regions'] ?? null) ? $simulator['regions'] : [] as $region) {
                is_array($region) && $add($region['port'] ?? null, 'udp', 'public');
            }
        }

        uasort($bindings, static fn(array $a, array $b): int => [$a['port'], $a['protocol']] <=> [$b['port'], $b['protocol']]);

        return array_values($bindings);
    }

    /** Apply a confirmed, versioned configuration without repeating the questionnaire. */
    private static function applyConfiguration(Environment $environment, array $configuration): array
    {
        if (($configuration['version'] ?? null) !== 1 || !is_array($configuration['answers'] ?? null) || !is_array($configuration['setup'] ?? null)) {
            return ['action' => 'abort', 'error' => _('Invalid prepared setup configuration.')];
        }
        try {
            $setup = SetupFile::parse(json_encode($configuration['setup'], JSON_THROW_ON_ERROR));
        } catch (\InvalidArgumentException | \JsonException $error) {
            return ['action' => 'abort', 'error' => $error->getMessage()];
        }
        $answers = $configuration['answers'];
        $answers['setup'] = $setup;
        $planned = self::plan($environment, self::answersOf($answers));
        if (!$planned['valid']) {
            $problems = [];
            foreach ($planned['problems'] as $field => $message) {
                $problems[] = "$field: $message";
            }
            return ['action' => 'abort', 'error' => implode("\n", $problems)];
        }
        foreach (['grid', 'simulator', 'region'] as $section) {
            $given = match ($section) {
                'grid' => SetupFile::gridValues($setup),
                'simulator' => SetupFile::simulatorValues($setup, 0),
                'region' => SetupFile::regionValues($setup, 0, 0),
            };
            $effective = match ($section) {
                'grid' => SetupFile::gridValues($planned['setup']),
                'simulator' => SetupFile::simulatorValues($planned['setup'], 0),
                'region' => SetupFile::regionValues($planned['setup'], 0, 0),
            };
            foreach ($given as $field => $value) {
                if ($value !== ($effective[$field] ?? null)) {
                    return ['action' => 'abort', 'error' => sprintf(_('The prepared setting %s changed on the target; prepare the setup again.'), "$section.$field")];
                }
            }
        }
        if ($planned['identity'] !== ($configuration['identity'] ?? null)) {
            return ['action' => 'abort', 'error' => _('The prepared instance identity does not match its settings.')];
        }
        $result = self::install($environment, $answers);
        // Its website step, nobody there to choose when it fails
        if (($result['action'] ?? '') === 'step') {
            $result['state'] = self::encode('website', ['unattended' => true] + self::decode((string) $result['state'])['answers']);
        }
        if (($result['action'] ?? '') === 'screen') {
            return ['action' => 'abort', 'error' => (string) ($result['notice'] ?? _('The prepared setup could not be applied.'))];
        }
        return $result;
    }

    /** The first screen: what can be set up. */
    private static function screenType(Environment $environment, array $answers, string $notice): array
    {
        // The simulators that join another grid, in a menu of their own: the grids of the presets, and one given by its address
        if (($answers['menu'] ?? '') === 'join') {
            $items = [];
            foreach (self::types($environment) as $type) {
                if (in_array($type['kind'], ['preset', 'remote'], true)) {
                    $items[] = [
                        'key' => $type['name'],
                        'label' => $type['kind'] === 'remote' ? _('Other grid (its address)') : $type['label'],
                        'note' => $type['implemented'] ? '' : _('not implemented'),
                    ];
                }
            }
            $remote = array_shift($items);

            return [
                'action' => 'screen',
                'kind' => 'menu',
                'name' => 'type',
                'title' => _('New simulator'),
                'text' => '',
                'notice' => $notice,
                'items' => [...$items, $remote],
                'value' => '',
                'buttons' => ['next' => _('OK'), 'back' => _('Back')],
                'state' => self::encode('type', $answers),
            ];
        }
        $found = self::found($environment);
        $new = [
            ['key' => 'grid', 'label' => _('New grid'), 'note' => ''],
            ['key' => 'standalone', 'label' => _('New standalone'), 'note' => ''],
            ['key' => 'join', 'label' => _('New simulator (connected to another grid)'), 'note' => ''],
            ['key' => 'location', 'label' => _('Specify existing installation path'), 'note' => ''],
        ];

        return [
            'action' => 'screen',
            'kind' => 'menu',
            'name' => 'type',
            'title' => _('OpenSim setup'),
            // dialog needs a line above a menu: what was found, else that nothing was
            'text' => $found === [] ? _('No pre-existing installation found.') : sprintf(ngettext('Found %d pre-existing installation:', 'Found %d pre-existing installations:', count($found)), count($found)),
            'notice' => $notice,
            'items' => [...$found, ...$new],
            // Always on the first item, coming back too: the answers are kept, not the place in the menu
            'value' => '',
            'buttons' => ['next' => _('OK'), 'back' => _('Quit')],
            'state' => self::encode('type', array_diff_key($answers, ['menu' => true])),
        ];
    }

    /**
     * What is here already, by its folder: the grids of this machine, and the install of before them (/opt/opensim) while its grid is
     * not one of them; each with its address and whether it runs.
     *
     * @return list<array{key:string,label:string,note:string}>
     */
    private static function found(Environment $environment): array
    {
        $found = [];
        $nicks = [];
        foreach (\W4OS\Engine\Models\Grid::all($environment)->items() as $grid) {
            $nicks[] = $grid->nick();
            $state = ($grid->robust()?->isRunning() ?? false) ? _('active') : _('inactive');
            $found[$grid->directory()] = ['key' => 'here:' . $grid->nick(), 'label' => sprintf('%s (%s:%d, %s)', $grid->directory(), $grid->baseHostname(), $grid->publicPort(), $state), 'note' => ''];
        }
        $legacy = (self::$legacy ?? Import\LegacyInstall::here(...))();
        if ($legacy->found()) {
            $robust = $legacy->robusts()[0] ?? null;
            $nick = $robust === null ? '' : $legacy->nick($robust);
            if ($nick === '' || !in_array($nick, $nicks, true)) {
                $said = array_filter([MigrationScenario::login($legacy), $legacy->running() !== [] ? _('active') : _('inactive')]);
                $found[$legacy->root] = ['key' => 'found', 'label' => sprintf('%s (%s)', $legacy->root, implode(', ', $said)), 'note' => ''];
            }
        }
        ksort($found);

        return array_values($found);
    }

    /**
     * A stop the person asked for: one line, not an error (code 4), with what stays of the run when something does (the DB admin
     * user made).
     *
     * @return array<string,mixed>
     */
    public static function stopped(array $answers = []): array
    {
        $stays = isset($answers['admin_made']) ? ' ' . sprintf(_('The DB admin user %s stays, kept for the next run.'), (string) ($answers['admin_user'] ?? '')) : '';

        return ['action' => 'abort', 'code' => 4, 'stopped' => true, 'error' => _('Stopped at your request.') . $stays];
    }

    /**
     * What a new grid needs of this machine before its questions, null when it is all there: its folders (made as root by the
     * client when they are missing), then a core (a release chosen, downloaded and unpacked) when its root has none.
     *
     * @return ?array<string,mixed>
     */
    private static function machine(Environment $environment, array $answers): ?array
    {
        $layout = $environment->layout;
        // Missing: its place cannot be written, or root runs a setup no config of the install was ever written for (no package made
        // its account and folders)
        $unmade = $layout->file() === null && (self::$region['root'] ?? (function_exists('posix_geteuid') && posix_geteuid() === 0));
        if (SystemLayout::needed($layout) || $unmade) {
            if (isset($answers['folders_asked'])) {
                return ['action' => 'abort', 'error' => sprintf(_('Cannot write in %s: its folders were not made.'), $layout->etcRoot())];
            }

            return [
                'action' => 'step',
                'step' => ['name' => 'give-ownership', 'root' => true, 'label' => _('Folders'), 'done' => _('Folders'), 'args' => [], 'skippable' => false, 'number' => 1, 'count' => 1],
                'labels' => [_('Folders')],
                'notice' => '',
                'state' => self::encode('machine', ['folders_asked' => true] + $answers),
            ];
        }
        // An install taken where it is runs on its own core: none of ours needed
        if (($answers['then'] ?? '') === 'in-place') {
            return null;
        }
        $core = Core::latest($layout->coreRoot()) ?? Core::at($layout->coreDirectory());
        if (!$core->exists()) {
            return $layout->coreRoot() === '' ? null : self::screenCore($environment, $answers, '');
        }
        // The runtime the core targets, installed by the client where it can be for the whole system (Linux, as root); elsewhere
        // what is missing is said by the start
        if (PHP_OS_FAMILY === 'Linux' && !isset($answers['runtime_asked']) && $environment->probe->problem($core, $core->executable('OpenSim')) !== null) {
            return [
                'action' => 'step',
                'step' => ['name' => 'install-dotnet', 'root' => true, 'label' => _('.NET runtime'), 'done' => _('.NET runtime'), 'args' => [], 'skippable' => false, 'number' => 1, 'count' => 1],
                'labels' => [_('.NET runtime')],
                'notice' => '',
                'state' => self::encode('machine', ['runtime_asked' => true] + $answers),
            ];
        }

        return null;
    }

    /** What follows once the machine is ready: the scenario of the install chosen (migrate, in place), else the questions of the type. */
    private static function readied(Environment $environment, array $answers, bool $prepare, array $request): array
    {
        $then = (string) ($answers['then'] ?? '');
        if (!in_array($then, ['migrate', 'in-place'], true)) {
            return self::typed($environment, $answers, $prepare);
        }
        $legacy = self::legacyOf($answers);
        $kept = array_diff_key($answers, array_flip(['then', 'folders_asked', 'runtime_asked']));
        $next = MigrationScenario::advance($environment, MigrationScenario::PREFIX, $kept, 'next', ['existing' => $then], $request, $legacy);

        return $next === null ? self::screenType($environment, [], '') : self::withLocation($next, $legacy);
    }

    /** The releases of OpenSimulator (null: the site's, kept in the cache of the install). */
    private static function releases(Environment $environment): CoreReleases
    {
        return isset(self::$region['releases'])
            ? (self::$region['releases'])()
            : new CoreReleases(rtrim($environment->layout->cacheRoot() ?: sys_get_temp_dir(), '/') . '/cores');
    }

    /** The release of OpenSimulator to install, when this machine has no core: the newest proposed. */
    private static function screenCore(Environment $environment, array $answers, string $notice): array
    {
        $releases = self::releases($environment)->available();
        if ($releases === []) {
            return ['action' => 'abort', 'error' => sprintf(_('No OpenSim core in %s, and the list of the releases cannot be had from %s.'), $environment->layout->coreRoot(), CoreReleases::URL)];
        }

        return [
            'action' => 'screen',
            'kind' => 'menu',
            'name' => 'core',
            'title' => _('OpenSimulator version'),
            'text' => sprintf(_('No OpenSimulator core in %s yet.'), $environment->layout->coreRoot()),
            'notice' => $notice,
            'items' => array_map(static fn(array $release): array => ['key' => $release['version'], 'label' => $release['version'], 'note' => ''], $releases),
            'value' => $releases[0]['version'],
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'wait' => _('Downloading and unpacking OpenSimulator: this can take a few minutes…'),
            'state' => self::encode('core', $answers),
        ];
    }

    /** The questions of the type chosen, with what the engine proposes: the DB admin user first, then its form. */
    private static function typed(Environment $environment, array $answers, bool $prepare): array
    {
        if (in_array(self::kindOf($environment, (string) ($answers['type'] ?? '')), ['remote', 'preset'], true)) {
            $answers += ['name' => 'Welcome'];
        } else {
            // A centre of its own, drawn once and kept with the answers: two grids rarely share a place
            $answers += ['center' => LocationFinder::proposedCenter(self::centres($environment))];
            // The proposals of the engine are what the form starts with
            $proposed = self::plan($environment, $answers)['setup']['grid'];
            $answers += [
                'name' => $proposed['name'],
                'login_uri' => $proposed['hostname'] . ':' . $proposed['public_port'],
            ];
        }

        // The administrator of the database server first: it makes the databases and their users later
        return $prepare || isset($answers['admin_checked']) ? self::screenForm($environment, $answers, '') : self::screenAdmin($answers, '');
    }

    /** The folders of the install a review shows, by their setting of w4os.conf: what each is called. */
    public const FOLDERS = [
        'EtcRoot' => 'Config files',
        'VarRoot' => 'State',
        'DataRoot' => 'Data files',
        'LogsRoot' => 'Logs',
        'CacheRoot' => 'Cache',
        'BackupRoot' => 'Backups',
        'CoreRoot' => 'Cores',
    ];

    /** The folder a review shows for a setting: the one answered there, else the one of the install. */
    private static function folderOf(Environment $environment, array $answers, string $key): string
    {
        return rtrim(trim((string) ($answers["folder_$key"] ?? $environment->layout->get($key))), '/');
    }

    /**
     * The folders of the install as rows of a review: each changed there while no grid is on this machine (they are the machine's,
     * not a grid's), shown as they are once one is; the cores of the packages stay where the packages put them.
     *
     * @return list<array<string,mixed>>
     */
    public static function folderRows(Environment $environment, array $answers): array
    {
        $open = \W4OS\Engine\Models\Grid::all($environment)->items() === [];
        $rows = [];
        foreach (self::FOLDERS as $key => $label) {
            $value = self::folderOf($environment, $answers, $key);
            if ($value === '') {
                continue;
            }
            $row = ['section' => _('Folders'), 'label' => _($label), 'value' => $value];
            $packaged = $key === 'CoreRoot' && rtrim($environment->layout->coreRoot(), '/') === SystemLayout::PACKAGED_CORES;
            $rows[] = $open && !$packaged ? $row + ['name' => "folder_$key", 'current' => $value] : $row;
        }

        return $rows;
    }

    /** What is wrong with the folders answered, '' for nothing: each a full path of its own, never the root of the system. */
    public static function folderProblem(Environment $environment, array $answers): string
    {
        foreach (self::FOLDERS as $key => $label) {
            $value = self::folderOf($environment, $answers, $key);
            if (isset($answers["folder_$key"]) && (preg_match('#^/[^/\s][^\s]*$#', $value) !== 1 || str_contains($value, '/../') || str_ends_with($value, '/..'))) {
                return sprintf(_('Error: %s is not a full path of a folder: %s'), _($label), (string) $answers["folder_$key"]);
            }
        }

        return '';
    }

    /**
     * The folders answered that are not the install's yet, by their setting.
     *
     * @return array<string,string>
     */
    public static function foldersChanged(Environment $environment, array $answers): array
    {
        $changed = [];
        foreach (array_keys(self::FOLDERS) as $key) {
            $value = self::folderOf($environment, $answers, $key);
            isset($answers["folder_$key"]) && $value !== rtrim($environment->layout->get($key), '/') && ($changed[$key] = $value);
        }

        return $changed;
    }

    /** The step that makes the folders changed in a review (as root, by the client), null when none is. */
    private static function folderStep(Environment $environment, array $answers, string $state): ?array
    {
        $changed = self::foldersChanged($environment, $answers);

        return $changed === [] ? null : [
            'action' => 'step',
            'step' => ['name' => 'set-folders', 'root' => true, 'label' => _('Folders'), 'done' => _('Folders'), 'args' => ['roots' => $changed], 'skippable' => false, 'number' => 1, 'count' => 1],
            'labels' => [_('Folders')],
            'notice' => '',
            'state' => $state,
        ];
    }

    /**
     * The database user of the first grid of the machine kept beside w4os.conf (database.rc, read by the commands before a
     * person's own file): the one a simulator added later is given, without any environment. Only for the install of the system,
     * never over a file that is there.
     */
    private static function keepAccount(Environment $environment, GridPlan $database): void
    {
        $file = dirname(\W4OS\Engine\Config\Layout::systemFile()) . '/database.rc';
        if ($database->dbDriver === 'sqlite' || $environment->layout->file() !== \W4OS\Engine\Config\Layout::systemFile() || is_file($file) || !is_writable(dirname($file))) {
            return;
        }
        $lines = ['# The database user of the grids of this machine, kept by the setup'];
        foreach (['OPENSIM_DB_HOST' => $database->dbHost, 'OPENSIM_DB_USER' => $database->dbUser, 'OPENSIM_DB_PASS' => $database->dbPass] as $name => $value) {
            $lines[] = "export $name=" . escapeshellarg($value);
        }
        @touch($file) && @chmod($file, 0o640) && @file_put_contents($file, implode("\n", $lines) . "\n");
    }

    /** An existing install given by its path: its OpenSim bin folder first. */
    private static function screenLocation(array $answers, string $notice): array
    {
        return [
            'action' => 'screen',
            'kind' => 'form',
            'name' => 'location',
            'title' => _('Specify existing installation path'),
            'text' => '',
            'notice' => $notice,
            'fields' => [['name' => 'bin', 'label' => _('OpenSim bin folder'), 'value' => (string) ($answers['bin'] ?? ''), 'secret' => false]],
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('location', $answers),
        ];
    }

    /** The Robust config of the install given, as it was found from its bin folder (empty when none was), to confirm or change. */
    private static function screenRobust(array $answers, string $notice): array
    {
        return [
            'action' => 'screen',
            'kind' => 'form',
            'name' => 'location-robust',
            'title' => _('Specify existing installation path'),
            'text' => '',
            'notice' => $notice,
            'fields' => [['name' => 'robust', 'label' => _('Robust.ini file'), 'value' => (string) ($answers['robust'] ?? ''), 'secret' => false]],
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('location-robust', $answers),
        ];
    }

    /** The install of the scenario: the location given (kept in its state), else the one of this machine. */
    private static function legacyOf(array $answers): Import\LegacyInstall
    {
        $location = $answers['location'] ?? null;
        if (is_array($location) && ($location['bin'] ?? '') !== '') {
            try {
                return Import\LegacyInstall::locate((string) $location['bin'], (string) ($location['robust'] ?? ''));
            } catch (\InvalidArgumentException) {
            }
        }

        return (self::$legacy ?? Import\LegacyInstall::here(...))();
    }

    /** The next screen or step of the scenario of a location given, the location kept in its state for the requests after it. */
    private static function withLocation(array $next, Import\LegacyInstall $legacy): array
    {
        if ($legacy->location() === [] || !isset($next['state'])) {
            return $next;
        }
        $state = self::decode((string) $next['state']);
        $next['state'] = self::encode($state['step'], ['location' => $legacy->location()] + $state['answers']);

        return $next;
    }

    /** The administrator of the database server: the one of the environment, else opensim_admin with a password made at random. */
    private static function screenAdmin(array $answers, string $notice): array
    {
        $answers['admin_user'] ??= (getenv('OPENSIM_DB_ADMIN_USER') ?: 'opensim_admin');
        $answers['admin_password'] ??= (getenv('OPENSIM_DB_ADMIN_PASS') ?: bin2hex(random_bytes(16)));

        return [
            'action' => 'screen',
            'kind' => 'form',
            'name' => 'db-admin',
            'title' => _('Databases admin user'),
            'text' => _('w4os needs credentials with admin privileges to create the databases and users.'),
            'notice' => $notice,
            'fields' => [
                ['name' => 'admin_user', 'label' => _('User'), 'value' => (string) $answers['admin_user'], 'secret' => false],
                ['name' => 'admin_password', 'label' => _('Password'), 'value' => (string) $answers['admin_password'], 'secret' => true],
            ],
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('db-admin', $answers),
        ];
    }

    /** Whether the administrator answered manages the server (null: DatabaseService::manages()). */
    private static function manages(Environment $environment, array $answers): bool
    {
        // The server the install names (DataSource), this machine's when it names none
        $host = (string) ($answers['database_host'] ?? ($environment->layout->dataSource() ?: 'localhost'));

        return isset(self::$region['manages'])
            ? (bool) (self::$region['manages'])((string) $answers['admin_user'], (string) $answers['admin_password'])
            : DatabaseService::manages($host, (string) $answers['admin_user'], (string) $answers['admin_password']);
    }

    /** Whether the database user answered exists on the server, null when it cannot be told (no DB admin user checked). */
    private static function userExists(Environment $environment, array $answers): ?bool
    {
        if (!isset($answers['admin_checked'])) {
            return null;
        }
        $plan = self::build($environment, self::answersOf($answers))['plan'];
        isset($answers['admin_user']) && DatabaseService::rememberAdmin($plan->dbHost, (string) $answers['admin_user'], (string) ($answers['admin_password'] ?? ''));
        $hosts = isset(self::$region['accountHosts']) ? (self::$region['accountHosts'])($plan) : (new DatabaseService(new NullUi()))->accountHosts($plan);

        return $hosts === null ? null : $hosts !== [];
    }

    /** The database user of the grid, its name known: OpenSimulator's, w4os's (its nick) or another. */
    private static function screenDbUser(Environment $environment, array $answers, string $notice): array
    {
        $plan = self::build($environment, self::answersOf($answers))['plan'];
        $nick = $plan->gridNick !== '' ? $plan->gridNick : 'opensim';
        $items = [['key' => 'opensim', 'label' => _("opensim (OpenSimulator's default)"), 'note' => '']];
        $nick === 'opensim' || ($items[] = ['key' => $nick, 'label' => sprintf(_('%s (w4os default)'), $nick), 'note' => '']);
        $items[] = ['key' => 'other', 'label' => _('Other'), 'note' => ''];
        $current = (string) ($answers['database_user'] ?? '');
        $keys = array_column($items, 'key');
        $value = match (true) {
            $current !== '' => in_array($current, $keys, true) ? $current : 'other',
            // OpenSimulator's, unless another install has it already
            self::userExists($environment, ['database_user' => 'opensim'] + $answers) === true => $nick,
            default => 'opensim',
        };

        return [
            'action' => 'screen',
            'kind' => 'menu',
            'name' => 'database_user',
            'title' => sprintf(_('%s database user'), (string) ($answers['name'] ?? $nick)),
            'text' => '',
            'notice' => $notice,
            'items' => $items,
            'value' => $value,
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('db-user', $answers),
        ];
    }

    /** A database user given, or one that exists: its name and its password. */
    private static function screenDbUserForm(Environment $environment, array $answers, string $notice): array
    {
        return [
            'action' => 'screen',
            'kind' => 'form',
            'name' => 'db-user-form',
            'title' => sprintf(_('%s database user'), (string) ($answers['name'] ?? '')),
            'text' => '',
            'notice' => $notice,
            'fields' => [
                ['name' => 'database_user', 'label' => _('User'), 'value' => (string) ($answers['database_user'] ?? ''), 'secret' => false],
                ['name' => 'database_password', 'label' => _('Password'), 'value' => (string) ($answers['database_password'] ?? ''), 'secret' => true],
            ],
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('db-user-form', $answers),
        ];
    }

    /** The questions of the import of an existing install: its Robust config, the configs of its simulators, its nick here. */
    private static function screenImport(array $answers, string $notice): array
    {
        return [
            'action' => 'screen',
            'kind' => 'form',
            'title' => _('Existing install'),
            'text' => _('The grid of the install is migrated: the same database, its config made here, the old install never changed.'),
            'notice' => $notice,
            'fields' => [
                ['name' => 'robust_config', 'label' => _('Its Robust config (Robust.HG.ini)'), 'value' => (string) ($answers['robust_config'] ?? ''), 'secret' => false, 'readonly' => false],
                ['name' => 'simulator_configs', 'label' => _('Its simulators (their OpenSim.ini, separated by commas)'), 'value' => (string) ($answers['simulator_configs'] ?? ''), 'secret' => false, 'readonly' => false],
                ['name' => 'nick', 'label' => _('Its nick here (empty: the one of its config)'), 'value' => (string) ($answers['nick'] ?? ''), 'secret' => false, 'readonly' => false],
            ],
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('import', $answers),
        ];
    }

    /** What the import of an existing install would write, to confirm. */
    private static function screenImportReview(Import\GridImport $import, array $answers): array
    {
        $document = $import->document();
        $rows = [
            ['section' => 'grid', 'label' => _('Grid'), 'value' => "{$document['name']} ({$document['grid']})"],
            ['section' => 'grid', 'label' => _('From'), 'value' => $document['source']],
            ['section' => 'grid', 'label' => _('Database'), 'value' => "{$document['database']['name']} @ {$document['database']['host']} (user {$document['database']['user']}), kept as it is"],
            ['section' => 'grid', 'label' => _('Ports'), 'value' => "{$document['ports']['public']}, {$document['ports']['private']}"],
            ['section' => 'grid', 'label' => _('Config'), 'value' => $document['config']],
            ['section' => 'grid', 'label' => _('Settings kept'), 'value' => (string) count($document['settings'])],
        ];
        foreach ($document['simulators']['steps'] ?? [] as $step) {
            $rows[] = ['section' => 'simulator', 'label' => _('Simulator'), 'value' => "{$step['simulator']} ({$step['slug']})"];
        }

        return [
            'action' => 'screen',
            'kind' => 'review',
            'title' => _('Review'),
            'text' => implode("\n", array_values($document['problems'])),
            'notice' => '',
            'rows' => $rows,
            'buttons' => ['next' => _('Import'), 'back' => _('Back')],
            'wait' => _('Importing, this can take a few minutes…'),
            'state' => self::encode('import-review', $answers),
        ];
    }

    /** The import the answers ask for. */
    private static function gridImport(Environment $environment, array $answers): Import\GridImport
    {
        $simulators = array_values(array_map(
            static fn(string $file): array => ['file' => $file],
            array_filter(array_map('trim', explode(',', (string) ($answers['simulator_configs'] ?? ''))), static fn(string $file): bool => $file !== ''),
        ));

        return Import\GridImport::prepare(
            $environment,
            trim((string) ($answers['robust_config'] ?? '')),
            trim((string) ($answers['nick'] ?? '')) ?: null,
            null,
            $simulators,
        );
    }

    /** The questions of the type chosen, filled with the answers so far. */
    private static function screenForm(Environment $environment, array $answers, string $notice): array
    {
        $type = (string) ($answers['type'] ?? '');
        $setup = self::plan($environment, self::answersOf($answers))['setup'];
        $grid = $setup['grid'] ?? [];
        $simulator = $setup['simulators'][0] ?? [];
        $proposals = [
            'private_port' => $grid['private_port'] ?? null,
            'grid_console_port' => $grid['console_port'] ?? null,
            'http_port' => $simulator['http_port'] ?? null,
            'console_port' => $simulator['console_port'] ?? null,
            'region_port' => $simulator['regions'][0]['port'] ?? null,
        ];
        $short = self::shortForm($environment, $answers, $proposals);
        if ($short !== null) {
            return [
                'action' => 'screen',
                'kind' => 'form',
                'title' => array_column(self::types($environment), 'label', 'name')[$type] ?? _('Grid'),
                'text' => '',
                'notice' => $notice,
                'fields' => $short,
                'buttons' => ['next' => _('OK'), 'back' => _('Back')],
                'state' => self::encode('form', $answers),
            ];
        }
        $fields = [];
        foreach (self::fields($environment, $answers) as $name => [$label, $secret, $readonly, $fixed]) {
            $value = $answers[$name] ?? $fixed ?? '';
            if (trim((string) $value) === '' && isset($proposals[$name])) {
                $value = $proposals[$name];
            }
            $fields[] = [
                'name' => $name,
                'label' => $label,
                'value' => $readonly ? ($fixed ?? '') : (string) $value,
                'secret' => $secret,
                'readonly' => $readonly,
            ];
            if ($name === 'database_driver') {
                $fields[array_key_last($fields)]['options'] = [
                    'mysql' => _('MySQL or MariaDB'),
                    'sqlite' => _('SQLite'),
                ];
            }
        }
        $labels = array_column(self::types($environment), 'label', 'name');

        return [
            'action' => 'screen',
            'kind' => 'form',
            'title' => $labels[$type] ?? _('Grid'),
            'text' => '',
            'notice' => $notice,
            'fields' => $fields,
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('form', $answers),
        ];
    }

    /** The options, each with its proposal or the last choice. */
    private static function screenOptions(Environment $environment, array $answers, string $notice): array
    {
        $items = [];
        foreach (self::options($environment) as $name => $option) {
            if (!($option['shown'] ?? false) || !$option['implemented']) {
                continue;
            }
            $given = $answers[$name] ?? null;
            $items[] = [
                'name' => $name,
                'label' => $option['label'],
                'checked' => Result::isTrue($given) || (Result::isFalse($given) ? false : $option['default']),
                'note' => $option['implemented'] ? '' : _('not implemented'),
            ];
        }

        return [
            'action' => 'screen',
            'kind' => 'checks',
            'title' => _('Options'),
            'text' => '',
            'notice' => $notice,
            'items' => $items,
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('options', $answers),
        ];
    }

    /** The answers of the screen of the web services (screenWeb()). */
    private const WEB = ['site', 'site_path', 'helpers_path', 'register', 'search', 'web_search', 'events_url', 'economy_path'];

    /**
     * The web services of the grid, when its helpers are on: the Quick Start website and its folder, the path of the helpers, where
     * the regions register, the in-world and the web search, the calendar of the events, the economy. Each with what the planner
     * proposes, or the last answer.
     */
    private static function screenWeb(Environment $environment, array $answers, string $notice): array
    {
        $grid = self::plan($environment, self::answersOf($answers))['setup']['grid'] ?? [];
        $value = static fn(string $name, string $proposed): string => (string) ($answers[$name] ?? $proposed);
        $helpersPath = $value('helpers_path', (string) ($grid['helpers_path'] ?? '/helpers'));
        // Folded: the address of the website alone, its proposals for the rest (the review shows them, and changes them)
        if (in_array(self::kindOf($environment, (string) ($answers['type'] ?? '')), ['grid', 'standalone'], true)) {
            return [
                'action' => 'screen',
                'kind' => 'form',
                'title' => _('Web'),
                'text' => '',
                'notice' => $notice,
                'fields' => [[
                    'name' => 'website',
                    'label' => _('Website address'),
                    'value' => self::websiteOf($answers, $grid),
                    'secret' => false,
                    'readonly' => false,
                ]],
                'buttons' => ['next' => _('OK'), 'back' => _('Back')],
                'state' => self::encode('web', $answers),
            ];
        }
        $fields = [
            ['name' => 'site', 'label' => _('Quick Start website'), 'value' => Result::isTrue($answers['site'] ?? (($grid['web_site'] ?? '') === 'website')) ? 'yes' : 'no', 'options' => ['yes' => _('Yes'), 'no' => _('No')]],
            ['name' => 'site_path', 'label' => _('Path'), 'value' => $value('site_path', (string) ($grid['site_path'] ?? '/'))],
            ['name' => 'helpers_path', 'label' => _('Helpers path'), 'value' => $helpersPath],
            ['name' => 'register', 'label' => _('Register the regions'), 'value' => $value('register', (string) ($grid['register'] ?? 'both')), 'options' => ['both' => _('Helpers and 2do.directory'), 'helpers' => _('Helpers only'), '2do' => _('2do.directory only')]],
            ['name' => 'search', 'label' => _('In-world search'), 'value' => $value('search', (string) ($grid['search'] ?? 'internal')), 'options' => ['internal' => _('Helpers'), 'shared' => '2do.directory']],
            ['name' => 'web_search', 'label' => _('Web search'), 'value' => $value('web_search', (string) ($grid['web_search'] ?? 'internal')), 'options' => array_intersect_key(['internal' => _('Helpers'), 'shared' => '2do.directory'], array_flip(GridPlanner::webSearches()))],
            ['name' => 'events_url', 'label' => _('Events'), 'value' => $value('events_url', (string) ($grid['events_url'] ?? \W4OS\Engine\Services\SearchTasks::EVENTS_SOURCE))],
            ['name' => 'economy_path', 'label' => _('Economy path'), 'value' => $value('economy_path', (string) (($grid['economy_path'] ?? '') ?: $helpersPath))],
        ];

        return [
            'action' => 'screen',
            'kind' => 'form',
            'title' => _('Web services'),
            'text' => '',
            'notice' => $notice,
            'fields' => array_map(static fn(array $field): array => $field + ['secret' => false, 'readonly' => false], $fields),
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('web', $answers),
        ];
    }

    /** What the setup will do, to confirm. */
    private static function screenReview(Environment $environment, array $answers, string $notice = ''): array
    {
        $answers = self::pinned($environment, $answers);
        $quick = self::plan($environment, self::answersOf($answers));
        $shown = Result::isTrue($answers['show_passwords'] ?? false);

        $short = in_array(self::kindOf($environment, (string) ($answers['type'] ?? '')), ['grid', 'standalone'], true);

        return [
            'action' => 'screen',
            'kind' => 'review',
            'title' => _('Review'),
            'text' => implode("\n", $quick['warnings'] ?? []),
            'notice' => $notice,
            // What the setup set by itself beside the answers, each line changed as in a form; the others say what is made
            'rows' => $short ? self::reviewRows($environment, $answers) : [...self::shortRows($quick['rows']), ...self::accountRows($environment, $answers)],
            'buttons' => [
                'next' => ($answers['existing_region'] ?? '') !== '' ? _('Stage import') : _('Start'),
                'back' => _('Back'),
            ] + ($short ? [] : ['show' => $shown ? _('Hide passwords') : _('Show passwords')]),
            // The live line of the run says where it is: a sentence before it for the others only
            ...$short ? [] : ['wait' => _('Installing, this can take a few minutes…')],
            'state' => self::encode('review', $answers),
        ];
    }

    /**
     * The options every grid has (the profiles, the groups, the offline messages) the person unchecked: turned off in the grid just
     * written, and recorded so (OptionsOff), never laid again by a repair.
     */
    private static function turnOff(Environment $environment, string $nick, array $answers): void
    {
        $grid = Grid::find($environment, $nick);
        if ($grid === null) {
            return;
        }
        $options = new GridOptions($grid);
        foreach (self::options($environment) as $name => $option) {
            if (!isset($option['presets']) || $name === 'map' || !Result::isFalse($answers[$name] ?? null)) {
                continue;
            }
            foreach (array_intersect($option['presets'], $options->enabled()) as $preset) {
                $options->disable($preset);
            }
        }
    }

    /** The answers the short screens give, which stand for others (fromShort()). */
    private const SHORT = ['address', 'ports'];

    /**
     * The form of a grid or a standalone in a few lines: its name, its address, its ports (proposed free), its owner, the password and
     * the email of the owner. The rest takes what is proposed, which the review shows and changes. Null for the other kinds.
     *
     * @param array<string,mixed> $proposals the ports proposed, by the name of their answer
     * @return ?list<array<string,mixed>>
     */
    private static function shortForm(Environment $environment, array $answers, array $proposals): ?array
    {
        $kind = self::kindOf($environment, (string) ($answers['type'] ?? ''));
        if (!in_array($kind, ['grid', 'standalone'], true)) {
            return null;
        }
        $fields = self::fields($environment, $answers);
        $login = (string) ($answers['login_uri'] ?? '');
        $host = (string) preg_replace('#^[a-z]+://#i', '', (string) preg_replace('#:\d+$#', '', $login));
        $public = preg_match('#:(\d+)$#', $login, $m) === 1 ? $m[1] : '';
        $private = (string) ($answers['private_port'] ?? $proposals['private_port'] ?? '');
        $field = static fn(string $name, string $label, string $value, bool $secret = false): array => ['name' => $name, 'label' => $label, 'value' => $value, 'secret' => $secret, 'readonly' => false];

        return [
            $field('name', _('Name'), (string) ($answers['name'] ?? $fields['name'][3] ?? '')),
            $field('address', _('Address'), $host),
            $field('ports', _('Ports'), trim($kind === 'standalone' ? $public : "$public $private")),
            $field('avatar', _('Owner'), (string) ($answers['avatar'] ?? $fields['avatar'][3] ?? '')),
            $field('password', _('Password'), (string) ($answers['password'] ?? $fields['password'][3] ?? ''), true),
            $field('email', _('Email'), (string) ($answers['email'] ?? '')),
        ];
    }

    /**
     * The answers of the short screens as the setup takes them: the address and the ports as its login and private port, the address
     * of the website as its site, its folder and its web address, the ports of the simulator as its own. Nothing given, nothing changed.
     */
    private static function fromShort(array $answers): array
    {
        if (isset($answers['address']) || isset($answers['ports'])) {
            $login = (string) ($answers['login_uri'] ?? '');
            $host = trim((string) ($answers['address'] ?? preg_replace('#:\d+$#', '', (string) preg_replace('#^[a-z]+://#i', '', $login))));
            $host = (string) preg_replace('#^[a-z]+://#i', '', rtrim($host, '/'));
            $ports = preg_split('/[\s,;]+/', trim((string) ($answers['ports'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $public = $ports[0] ?? (preg_match('#:(\d+)$#', $login, $m) === 1 ? $m[1] : '');
            $answers['login_uri'] = $public !== '' ? "$host:$public" : $host;
            isset($ports[1]) && $answers['private_port'] = $ports[1];
        }
        if (array_key_exists('website', $answers)) {
            $website = trim((string) $answers['website']);
            if ($website === '' || in_array(strtolower($website), ['no', 'none', '-'], true)) {
                $answers['site'] = false;
            } else {
                $website = preg_match('#^[a-z]+://#i', $website) === 1 ? $website : "https://$website";
                $answers['site'] = true;
                $answers['web_url'] = (string) preg_replace('#^([a-z]+://[^/]+).*$#i', '$1', $website);
                $answers['site_path'] = '/' . trim((string) (parse_url($website, PHP_URL_PATH) ?? ''), '/');
            }
        }
        if (isset($answers['simulator_ports'])) {
            [$http, $udp, $console] = array_pad(preg_split('/[\s,;]+/', trim((string) $answers['simulator_ports']), -1, PREG_SPLIT_NO_EMPTY) ?: [], 3, '');
            $http === '' || $answers['http_port'] = $http;
            $udp === '' || $answers['region_port'] = $udp;
            $console === '' || $answers['console_port'] = $console;
        }
        unset($answers['address'], $answers['ports'], $answers['website'], $answers['simulator_ports']);

        return $answers;
    }

    /** The address of the Quick Start website as it is asked: the one answered, else its proposal (the root of the web site). */
    private static function websiteOf(array $answers, array $grid): string
    {
        if (array_key_exists('site', $answers) && !Result::isTrue($answers['site'])) {
            return '';
        }
        $web = rtrim((string) ($answers['web_url'] ?? $grid['web_url'] ?? ''), '/');
        $path = Web\Services::normalize((string) ($answers['site_path'] ?? $grid['site_path'] ?? '/'));

        return $web === '' ? '' : $web . ($path === '/' ? '/' : "$path/");
    }

    /** The answers the review changes, by the name its lines give. @return list<string> */
    private static function reviewNames(Environment $environment, array $answers): array
    {
        return [...array_values(array_filter(array_column(self::reviewRows($environment, $answers), 'name'))), 'site', 'site_path', 'web_url'];
    }

    /**
     * The review of a grid or a standalone: every value the setup set by itself beside the answers, under short headings (Grid,
     * Simulator, Web, Database), one value a line; a line that names its answer (`name`, its value to change `current`) is changed as
     * in a form, a password masked with where it is kept.
     *
     * @return list<array<string,mixed>>
     */
    private static function reviewRows(Environment $environment, array $answers): array
    {
        $built = self::build($environment, self::answersOf($answers));
        $plan = $built['plan'];
        $simulator = $built['simulator'];
        $standalone = $built['standalone'];
        $rows = [];
        $row = static function (string $section, string $label, string $value, ?string $name = null, ?string $current = null, array $more = []) use (&$rows): void {
            $rows[] = ['section' => $section, 'label' => $label, 'value' => $value] + ($name === null ? [] : ['name' => $name, 'current' => $current ?? $value]) + $more;
        };
        $email = trim((string) ($answers['email'] ?? ''));
        $grid = $standalone ? _('Standalone') : _('Grid');
        $row($grid, _('Name'), $standalone ? $simulator->regionName : "{$plan->gridName} ({$plan->gridNick})", 'name', $standalone ? $simulator->regionName : $plan->gridName);
        $row($grid, _('Login'), "{$plan->baseHostname}:{$plan->publicPort}", 'login_uri');
        // Where its regions are placed from, its first one there
        $row($grid, _('Centre'), $plan->center, 'center');
        if (!$standalone) {
            $row($grid, _('Private port'), (string) $plan->privatePort, 'private_port');
            $row($grid, _('Console port'), (string) $plan->consolePort, 'grid_console_port');
        }
        $row($grid, _('Owner'), $simulator->estateOwner . ($email !== '' ? " <$email>" : ''), 'avatar', $simulator->estateOwner);
        $simulatorSection = _('Simulator');
        $standalone || $row($simulatorSection, _('Name'), "{$simulator->simulatorName} ({$simulator->slug})");
        $row(
            $simulatorSection,
            _('Ports'),
            sprintf('%d http, %d udp, %d console', $simulator->httpPort, $simulator->regionPort, $simulator->consolePort),
            'simulator_ports',
            "{$simulator->httpPort} {$simulator->regionPort} {$simulator->consolePort}",
        );
        $row($simulatorSection, _('Region'), sprintf('%s at %s', $simulator->regionName, $simulator->regionLocation));
        if ($plan->helpers) {
            $web = _('Web');
            $site = $plan->webSite === 'website' ? rtrim($plan->webUrl, '/') . Web\Services::normalize($plan->sitePath) : '';
            $site === '' || str_ends_with($site, '/') || $site .= '/';
            $row($web, _('Website'), $site !== '' ? $site : _('none'), 'website', $site);
            $row($web, _('Helpers'), $plan->helpersPath, 'helpers_path');
            $row($web, _('Economy'), $plan->economyPath !== '' ? $plan->economyPath : $plan->helpersPath, 'economy_path');
            $registers = ['both' => _('helpers and 2do.directory'), 'helpers' => _('helpers'), '2do' => '2do.directory'];
            $row($web, _('Search, register'), $registers[$plan->register] ?? $plan->register, 'register', $plan->register, ['options' => $registers]);
            $plan->search === 'internal' && $row($web, _('Events'), $plan->eventsUrl, 'events_url');
        } else {
            // Without the helpers, the search and the registration of the regions are the ones of 2do.directory
            $row(_('Web'), _('Search, register'), '2do.directory');
        }
        // The options that keep their default without a question (the ones asked are not said again), each on or off
        $onOff = ['yes' => _('on'), 'no' => _('off')];
        foreach (self::options($environment) as $name => $option) {
            if (!empty($option['shown']) || !$option['implemented']) {
                continue;
            }
            $given = $answers[$name] ?? null;
            $on = Result::isTrue($given) || (Result::isFalse($given) ? false : $option['default']);
            $row(_('Options'), $option['label'], $on ? $onOff['yes'] : $onOff['no'], $name, $on ? 'yes' : 'no', ['options' => $onOff]);
        }
        $database = _('Database');
        $sqlite = $simulator->databasePlan()->dbDriver === 'sqlite';
        $row($database, _('Server'), $sqlite ? 'SQLite' : "{$plan->dbHost} (MariaDB)", $sqlite ? null : 'database_host', $plan->dbHost);
        $names = array_values(array_unique(array_filter([$standalone ? '' : $plan->dbName, $simulator->databasePlan()->dbName])));
        $sqlite || $row($database, _('Databases'), implode(', ', $names));
        // The database user and its password as a name and a value each, the password masked until asked; the DB admin user
        if (!$sqlite) {
            $row($database, _('User'), $plan->dbUser, 'database_user', $plan->dbUser);
            $row($database, _('Password'), Result::isTrue($answers['show_passwords'] ?? false) ? $plan->dbPass : '********');
            $admin = (string) ($answers['admin_user'] ?? (getenv('OPENSIM_DB_ADMIN_USER') ?: ''));
            $admin === '' || $row($database, _('DB admin user'), $admin);
        }

        return [...$rows, ...self::folderRows($environment, $answers)];
    }

    /** The rows of the review a person reads: what is made (the grid, its login, its website, its simulator and region, its owner). */
    private const SHORT_ROWS = [
        'Grid name' => 'Grid',
        'Grid' => 'Grid',
        'Login URI' => 'Login',
        'Quick Start website' => 'Website',
        'Search' => 'Search',
        'Simulator' => 'Simulator',
        'Region' => 'Region',
        'Taken over' => 'Taken over',
        'Archive' => 'Archive',
        'Database from' => 'Database from',
        'Location' => 'Location',
        'Address' => 'Address',
        'Avatar' => 'Owner',
    ];

    /**
     * @param list<array{section:string,label:string,value:string}> $rows
     * @return list<array{section:string,label:string,value:string}> the ones of SHORT_ROWS, by their short label
     */
    private static function shortRows(array $rows): array
    {
        $short = [];
        foreach ($rows as $row) {
            isset(self::SHORT_ROWS[$row['label'] ?? '']) && ($short[] = ['label' => self::SHORT_ROWS[$row['label']]] + $row);
        }

        return $short;
    }

    /**
     * What is wrong with the answers of the form, as a screen to show again: the form with the corrections made and the errors told,
     * or nothing when it is right.
     */
    private static function checkForm(Environment $environment, array $answers): ?array
    {
        $quick = self::plan($environment, self::answersOf($answers));
        $answers = array_replace($answers, $quick['corrected']);
        $fields = self::fields($environment, $answers);
        $labels =
            // The short form says the address and the ports where the setup has a login and a private port
            (in_array(self::kindOf($environment, (string) ($answers['type'] ?? '')), ['grid', 'standalone'], true) ? ['login_uri' => _('Address'), 'private_port' => _('Ports'), 'avatar' => _('Owner'), 'name' => _('Name')] : []) +
            array_map(static fn(array $field): string => $field[0], $fields) +
            array_map(static fn(array $o): string => $o['label'], self::options($environment));
        $lines = [];
        foreach ($quick['problems'] as $question => $message) {
            $lines[] = sprintf('Error: %s %s', $labels[$question] ?? $question, $message);
        }
        if (array_key_exists('password2', $answers) && (string) ($answers['password'] ?? '') !== (string) $answers['password2']) {
            $lines[] = 'Error: Passwords differ';
            $answers['password'] = $answers['password2'] = '';
        }

        return $lines === [] ? null : self::screenForm($environment, $answers, implode("\n", $lines));
    }

    /**
     * What replaces the work that needs a machine, for the tests: how the databases are checked and made (Closure(GridPlan, UiInterface):
     * int, a DatabaseService code), how the account of the avatar is made (Closure(Grid, SimulatorPlan, UiInterface): bool) and what a
     * grid says of itself (Closure(string $host, int $port): array<string,string>), and what the planner of a region of a grid on
     * another machine asks it (the context of RegionPlanner: remoteHas, freePlace, registeredUuid, registeredName; registeredRecord, what
     * it keeps of a region, RobustGrid::region()).
     *
     * @param array{database?:?\Closure,account?:?\Closure,says?:?\Closure,region?:array<string,mixed>,dumper?:?\Closure,loader?:?\Closure} $doubles none: the real work
     */
    public static function through(array $doubles = []): void
    {
        self::$database = $doubles['database'] ?? null;
        self::$account = $doubles['account'] ?? null;
        self::$says = $doubles['says'] ?? null;
        self::$region = $doubles['region'] ?? [];
        self::$dumper = $doubles['dumper'] ?? null;
        self::$loader = $doubles['loader'] ?? null;
        self::$resolve = $doubles['resolve'] ?? null;
        self::$legacy = $doubles['legacy'] ?? null;
        MigrationScenario::$importer = $doubles['importer'] ?? null;
        MigrationScenario::$vhosts = $doubles['vhosts'] ?? null;
        MigrationScenario::$modules = $doubles['modules'] ?? static fn(string $site): array => [];
        MigrationScenario::$siteTool = $doubles['siteTool'] ?? static fn(): bool => true;
    }

    /** @return list<string> the centres of the grids of this machine ("x,y") */
    private static function centres(Environment $environment): array
    {
        return array_values(array_filter(array_map(static fn(Grid $grid): string => $grid->center(), Grid::all($environment)->items())));
    }

    /** The install of before the kit of this machine (null: LegacyInstall::here()). */
    private static ?\Closure $legacy = null;

    /** The addresses a name resolves to (null: gethostbynamel()). */
    private static ?\Closure $resolve = null;

    /**
     * What the name of a grid resolves to, in a line when it cannot work yet: no address (its DNS is not ready), or an address of
     * the network that is not this machine. A public address may be this machine behind its router: nothing is said of it.
     *
     * @return list<string>
     */
    private static function addressWarnings(string $host): array
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }
        // Asked once a name: each screen plans the setup again
        static $known = [];
        $ips = self::$resolve !== null ? (self::$resolve)($host) : ($known[$host] ??= gethostbynamel($host) ?: []);
        if ($ips === []) {
            return [sprintf(_('%s does not resolve yet: the grid will not answer at this address until its DNS does.'), $host)];
        }
        $own = array_merge(gethostbynamel((string) gethostname()) ?: [], self::localAddresses());
        foreach ($ips as $ip) {
            $private = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
            if (!$private || str_starts_with($ip, '127.') || in_array($ip, $own, true)) {
                return [];
            }
        }

        return [sprintf(_('%s points to %s, not to this machine.'), $host, $ips[0])];
    }

    /** The addresses of the interfaces of this machine (ip -o addr), none where it cannot tell. @return list<string> */
    private static function localAddresses(): array
    {
        $said = function_exists('shell_exec') ? (string) @shell_exec('ip -o -4 addr show 2>/dev/null') : '';
        preg_match_all('/inet (\d+\.\d+\.\d+\.\d+)/', $said, $m);

        return $m[1];
    }

    /** How the database of a simulator is made from a dump (null: DatabaseDump::intoEmpty()). */
    private static ?\Closure $loader = null;

    /** The dump of a database for the backup of an import (null: DatabaseDump::write()). */
    private static ?\Closure $dumper = null;

    /** @var array<string,mixed> */
    private static array $region = [];

    private static ?\Closure $database = null;
    private static ?\Closure $account = null;

    /**
     * Make the setup: the databases are checked and made, the folders and the files are written, a grid is started, the account of the
     * avatar is made, the simulator is written and started. The end of the scenario.
     */
    private static function install(Environment $environment, array $answers): array
    {
        $answers = self::pinned($environment, $answers);
        $remote = in_array(self::kindOf($environment, (string) ($answers['type'] ?? '')), ['remote', 'preset'], true);
        if ($remote) {
            $built = self::buildRemote($environment, self::answersOf($answers));
            $plan = null;
            $grid = $built['grid'];
        } else {
            $built = self::build($environment, self::answersOf($answers));
            $plan = $built['plan'];
            $grid = null;
        }
        $simulator = $built['simulator'];
        if ($built['problems'] !== [] || $simulator === null) {
            return self::screenForm(
                $environment,
                $answers,
                _('The answers are not right any more: look at them again.'),
            );
        }
        $gridDir = $plan?->gridDir ?? $simulator->gridDir;
        $binDir = $plan?->binDir ?? $simulator->binDir();
        if ($binDir === '' || !is_dir($binDir)) {
            return [
                'action' => 'abort',
                'error' => sprintf(_('No OpenSim core found in %s.'), $environment->layout->coreRoot()),
            ];
        }
        // The place must be one the setup can write in: the nearest folder that exists
        $existing = $gridDir;
        while ($existing !== dirname($existing) && !file_exists($existing)) {
            $existing = dirname($existing);
        }
        if (SystemLayout::needed($environment->layout)) {
            return [
                'action' => 'abort',
                'error' => _('The OpenSim system layout is missing: install w4os-engine before running setup.'),
            ];
        }
        if (!is_dir($existing) || !is_writable($existing)) {
            return [
                'action' => 'abort',
                'error' => sprintf(
                    _(
                        'Cannot write in %s: run the setup as a user who can, or set EtcRoot in w4os.conf to a folder that can be written.',
                    ),
                    $existing,
                ),
            ];
        }

        // The notes are told as they come: the install takes minutes, a client shows its progress from the error output
        $ui = new NullUi(live: true);
        // Nothing is written before the databases work: a problem of access is asked again, with the reasons
        $databases = [];
        if ($simulator->existingRegionConfig === '') {
            $databases = $plan === null ? [$simulator->databasePlan()] : ($plan->standalone ? [$plan] : [$plan, $simulator->databasePlan()]);
            if (isset($answers['admin_user'])) {
                DatabaseService::rememberAdmin($databases[0]->dbHost, (string) $answers['admin_user'], (string) ($answers['admin_password'] ?? ''));
            }
            DatabaseService::remember($databases[0]->dbHost, $databases[0]->dbUser, $databases[0]->dbPass);
        }
        $check =
            self::$database ??
            static fn(GridPlan $db, UiInterface $said): int => (new DatabaseService($said))->ensure($db);
        // The steps of the run, numbered on the live line; the website, its last one, is the client's (kickstart)
        $steps = 3 + ($plan !== null && $plan->webSite === 'website' ? 1 : 0);
        $ui->stage(1, $steps, _('Databases'));
        foreach ($databases as $i => $db) {
            $ui->note(sprintf('%s (%d/%d)', $db->dbName, $i + 1, count($databases)));
            $code = $check($db, $ui);
            if ($code === DatabaseService::OK) {
                continue;
            }
            $said = implode("\n", array_unique(array_column($ui->said, 1)));
            if ($code === DatabaseService::ABORT) {
                return [
                    'action' => 'abort',
                    'error' =>
                        $said . "\n" . _('Stopped, nothing was changed: OpenSim cannot run without its database.'),
                ];
            }

            return self::screenDatabase($db, $answers, $said);
        }
        $databases === [] || self::keepAccount($environment, $databases[0]);

        // The database of the simulator made from the dump of the one its region comes from
        if ($simulator->regionDump !== '') {
            try {
                (self::$loader ?? static fn(SimulatorPlan $made): int => \W4OS\Engine\Archives\DatabaseDump::intoEmpty(
                    ['host' => $made->dbHost, 'name' => $made->dbName, 'user' => $made->dbUser, 'pass' => $made->dbPass],
                    $made->regionDump,
                ))($simulator);
            } catch (\RuntimeException $error) {
                return ['action' => 'abort', 'error' => sprintf(_('The database %s cannot be made from %s: %s'), $simulator->dbName, $simulator->regionDump, $error->getMessage())];
            }
            $ui->note(sprintf(_('Database %s made from %s.'), $simulator->dbName, $simulator->regionDump));
        }

        // What the commands say (a log, the owners) is kept, never printed: the answer of the API is a document
        ShellService::showOutputWith(static function (string $chunk) use ($ui): void {
            trim($chunk) !== '' && $ui->note(trim($chunk));
        });
        try {
            // Everything written first, then each instance started once: Robust (for the account of the avatar) reads the roles of
            // the region at its first start, never started again for them; a standalone makes the account itself when it starts
            $later = $plan !== null && !$plan->standalone && $simulator->existingRegionConfig === '';
            $ui->stage(2, $steps, _('Config'));
            if ($plan !== null) {
                $plan->enable = true;
                $plan->start = !$later;
                (new GridProvisioner($ui))->apply($plan, $environment);
                self::turnOff($environment, $plan->gridNick, $answers);
            }
            if ($simulator->existingRegionConfig === '') {
                $simulator->enable = true;
                $simulator->start = !$later;
            }
            $simulator->createOwner = false;
            $provisioner = new SimulatorProvisioner($ui);
            $provisioner->apply($simulator, $environment);
            // Robust started for the account of the avatar, then the simulator: the start of the grid
            $ui->stage(3, $steps, _('Start'));
            if ($plan !== null) {
                // The account of the avatar: Robust starts for it (GridAccounts), and makes it
                if (!$plan->standalone) {
                    $found = Grid::find($environment, $plan->gridNick);
                    $made =
                        self::$account ??
                        static function (Grid $grid, SimulatorPlan $sim, UiInterface $said): bool {
                            $accounts = new GridAccounts(new DatabaseService($said), $said);
                            // The first account of a grid is its administrator, a god (FIRST_LEVEL), as a simulator setup makes it
                            $level = GridAccounts::levelFor($accounts->names($grid));

                            return $accounts->create($grid, $sim->estateOwner, $sim->ownerPassword, $sim->ownerEmail, $level);
                        };
                    if ($found === null || !$made($found, $simulator, $ui)) {
                        throw new SetupFailedException('account');
                    }
                }
            }
            $later && $provisioner->start($simulator, $environment);
        } catch (SetupFailedException $failed) {
            // The errors once each: the last errors the start told, the one of the step with where its log is
            $errors = array_values(array_unique(array_column(array_filter($ui->said, static fn(array $said): bool => $said[0] === 'error'), 1)));

            return ['action' => 'abort', 'error' => implode("\n", $errors ?: [$failed->getMessage()])];
        } finally {
            ShellService::showOutputWith(null);
        }
        // Written is written: the files are there, or the setup says they are not
        $missing = array_values(
            array_filter(
                [
                    $plan !== null ? (new GridConf())->path($plan) : '',
                    $plan !== null && !$plan->standalone ? $plan->robustIni() : '',
                    $simulator->iniPath(),
                ],
                static fn(string $file): bool => $file !== '' && !is_file($file),
            ),
        );
        if ($missing !== []) {
            return [
                'action' => 'abort',
                'error' => sprintf(_('These files were not written: %s'), implode(', ', $missing)),
            ];
        }
        $notes = array_column(array_filter($ui->said, static fn(array $said): bool => $said[0] === 'warn'), 1);
        if ($simulator->existingRegionConfig !== '') {
            return [
                'action' => 'quit',
                'message' => sprintf(_('Simulator %s imported and left disabled. Restore its database and files, stop the source, then enable and start it.'), $simulator->slug),
            ];
        }

        // What there is now, in a few lines: the grid, its login, its website and the way in of its owner when it has one
        $site = $plan !== null && $plan->webSite === 'website' ? rtrim($plan->webUrl, '/') . Web\Services::normalize($plan->sitePath) : '';
        $site === '' || str_ends_with($site, '/') || $site .= '/';
        $head = [
            ...$notes,
            ...$notes === [] ? [] : [''],
            sprintf('Grid       %s (%s)', $plan?->gridName ?? $grid?->name(), $plan?->gridNick ?? $grid?->nick()),
            sprintf('Login      http://%s:%d', $plan?->baseHostname ?? $grid?->baseHostname(), $plan?->publicPort ?? $grid?->publicPort()),
        ];
        if ($site === '') {
            return ['action' => 'quit', 'message' => implode("\n", [...$head, sprintf('Owner      %s', $simulator->estateOwner)])];
        }
        $done = [sprintf('Website    %s', $site), sprintf('Admin      %slogin (%s)', $site, $simulator->estateOwner)];
        // The website, the last step of the run: the client's, as root, by its name; left out beside Apache without its line
        $left = self::lineLeft($site, $answers);

        return $left !== ''
            ? ['action' => 'quit', 'message' => implode("\n", [...$head, "Website    not installed ($left)"])]
            : self::websiteStep(['grid' => (string) $plan?->gridNick, 'site' => $site, 'head' => $head, 'done' => $done, 'number' => $steps, 'count' => $steps]);
    }

    /** Why the website is left out beside Apache ('' when it is not): no site serving its host, its line refused or not answered. */
    private static function lineLeft(string $site, array $answers): string
    {
        if (!MigrationScenario::siteTool()) {
            return _('w4os-site is not installed here');
        }
        $files = MigrationScenario::vhostFiles($site);
        if ($files === null) {
            return '';
        }
        if ($files === []) {
            return sprintf(_('no site of Apache serves %s'), (string) parse_url($site, PHP_URL_HOST));
        }

        return match ($answers['vhosts'] ?? null) {
            'yes' => '',
            'no' => _('it needs its line in the sites of Apache'),
            default => _('no answer for vhosts'),
        };
    }

    /** The one line the website needs in each site of Apache serving its host, asked before the start; null when nothing is asked. */
    private static function websiteLine(Environment $environment, array $answers): ?array
    {
        if (isset($answers['vhosts'])) {
            return null;
        }
        $plan = self::build($environment, self::answersOf(self::pinned($environment, $answers)))['plan'] ?? null;
        $site = $plan !== null && $plan->webSite === 'website' ? rtrim($plan->webUrl, '/') . Web\Services::normalize($plan->sitePath) : '';
        $files = $site === '' ? null : MigrationScenario::vhostFiles($site);
        if ($files === null) {
            return null;
        }
        $state = self::encode('website-line', $answers);
        if ($files === []) {
            return [
                'action' => 'screen', 'kind' => 'menu', 'name' => 'vhosts', 'title' => _('Website'),
                'text' => sprintf(_('No site of Apache serves %s.'), (string) parse_url($site, PHP_URL_HOST)), 'notice' => '',
                'items' => [['key' => 'no', 'label' => _('Go on without the website'), 'note' => ''], ['key' => 'quit', 'label' => _('Quit'), 'note' => '']],
                'value' => 'no', 'buttons' => ['next' => _('OK'), 'back' => _('Quit')], 'state' => $state,
            ];
        }

        return [
            'action' => 'screen', 'kind' => 'form', 'title' => _('Website'), 'text' => '', 'notice' => '',
            'fields' => [['name' => 'vhosts', 'label' => MigrationScenario::lineQuestion($files, $site), 'value' => 'yes', 'secret' => false, 'readonly' => false, 'options' => ['yes' => _('Yes'), 'no' => _('No')]]],
            'buttons' => ['next' => _('OK'), 'back' => _('Quit')], 'state' => $state,
        ];
    }

    /** The address of a website left, prefilled, and beside Apache the line it needs in the sites serving its host. */
    private static function websiteAddress(array $website, string $notice, bool $line): array
    {
        $fields = [['name' => 'website', 'label' => _('Website address'), 'value' => (string) $website['site'], 'secret' => false, 'readonly' => false]];
        $files = $line ? MigrationScenario::vhostFiles((string) $website['site']) : null;
        $files !== null && $files !== [] && ($fields[] = ['name' => 'vhosts', 'label' => MigrationScenario::lineQuestion($files, (string) $website['site']), 'value' => 'yes', 'secret' => false, 'readonly' => false, 'options' => ['yes' => _('Yes'), 'no' => _('No')]]);

        return [
            'action' => 'screen', 'kind' => 'form', 'title' => _('Website'), 'text' => '', 'notice' => $notice,
            'fields' => $fields, 'buttons' => ['next' => _('OK'), 'back' => _('Quit')],
            'state' => self::encode('website-address', $website),
        ];
    }

    /** The step of the website for the client, as root by its name, the summary kept for after it. */
    private static function websiteStep(array $website): array
    {
        $files = MigrationScenario::vhostFiles((string) $website['site']);

        return [
            'action' => 'step',
            'step' => [
                'name' => 'install-website', 'root' => true, 'label' => 'Website', 'skippable' => true,
                'args' => ['grid' => $website['grid']] + ($files !== null ? ['edit_vhosts' => true] : []),
                'number' => (int) ($website['number'] ?? 1), 'count' => (int) ($website['count'] ?? 1),
            ],
            'labels' => [],
            'notice' => '',
            'state' => self::encode('website', $website),
        ];
    }

    /** The website step done (the summary), or failed: again, or the summary without it. */
    private static function websiteRun(array $website, string $button, array $given, array $request): array
    {
        $choice = (string) ($request['answers']['error'] ?? '');
        if ($choice === 'retry') {
            return self::websiteStep($website);
        }
        if ($button === 'failed' && ($website['unattended'] ?? false)) {
            return ['action' => 'abort', 'code' => 1, 'error' => sprintf('[%d/%d] Website: %s. %s', (int) ($website['number'] ?? 1), (int) ($website['count'] ?? 1), rtrim(trim((string) ($request['answers']['error_text'] ?? _('the step failed'))), '.'), _('The grid is set up; a rerun installs its website.'))];
        }
        if ($button === 'failed' || $choice === 'skip' || $choice === 'abort') {
            if ($button === 'failed') {
                return [
                    'action' => 'screen', 'kind' => 'menu', 'name' => 'error', 'title' => _('Website failed'),
                    'text' => (string) ($request['answers']['error_text'] ?? _('the step failed')), 'notice' => '',
                    'items' => [['key' => 'retry', 'label' => _('Retry'), 'note' => ''], ['key' => 'skip', 'label' => _('Skip'), 'note' => '']],
                    'value' => 'retry', 'buttons' => ['next' => _('OK'), 'back' => _('Skip')], 'state' => self::encode('website', $website),
                ];
            }

            return ['action' => 'quit', 'message' => implode("\n", [...(array) ($website['head'] ?? []), 'Website    not installed (rerun to install it)'])];
        }

        return ['action' => 'quit', 'message' => implode("\n", [...(array) ($website['head'] ?? []), ...(array) ($website['done'] ?? [])])];
    }

    /**
     * A grid of this machine, chosen in the first menu: what is left of it (its website left by a run before, its address kept), or
     * that it is here already.
     *
     * @return array<string,mixed>
     */
    private static function here(Environment $environment, string $nick, Import\LegacyInstall $legacy): array
    {
        $grid = \W4OS\Engine\Models\Grid::find($environment, $nick);
        if ($grid === null) {
            return self::screenType($environment, [], '');
        }
        // The grid of the install of before, migrated or run in place: what is left of it, its scenario's
        $robust = $legacy->found() ? ($legacy->robusts()[0] ?? null) : null;
        if ($robust !== null && $legacy->nick($robust) === $nick) {
            return MigrationScenario::first($legacy, $environment);
        }
        $site = $grid->siteAddress();
        $head = [sprintf('Grid       %s (%s)', $grid->name(), $grid->nick()), sprintf('Login      http://%s:%d', $grid->baseHostname(), $grid->publicPort())];
        if ($site === '' || \W4OS\Engine\Models\Grid::inPlace($environment, $nick) !== null || MigrationScenario::siteInstalled($nick)) {
            return ['action' => 'quit', 'message' => implode("\n", [sprintf(_('%s is here already.'), $nick), '', ...$head, ...($site === '' ? [] : [sprintf('Website    %s', $site)])])];
        }

        return [
            'action' => 'screen', 'kind' => 'menu', 'name' => 'website', 'title' => _('Website'),
            'text' => sprintf(_('%s is here, its website not installed yet.'), $nick), 'notice' => '',
            'items' => [['key' => 'install', 'label' => _('Install it'), 'note' => $site], ['key' => 'other', 'label' => _('Set up another grid'), 'note' => '']],
            'value' => 'install', 'buttons' => ['next' => _('OK'), 'back' => _('Quit')],
            'state' => self::encode('website-left', ['grid' => $nick, 'site' => $site, 'head' => $head, 'done' => [sprintf('Website    %s', $site), sprintf('Admin      %slogin', $site)]]),
        ];
    }

    /** The access to the database, asked again: what the server said, and the settings with the account of its administrator. */
    private static function screenDatabase(GridPlan $plan, array $answers, string $notice): array
    {
        $values = $answers + [
            'database_host' => $plan->dbHost,
            'database_name' => $plan->dbName,
            'database_user' => $plan->dbUser,
            'database_password' => $plan->dbPass,
        ];
        $fields = [];
        foreach (self::databaseFields() as $name => [$label, $secret]) {
            $fields[] = [
                'name' => $name,
                'label' => $label,
                'value' => (string) ($values[$name] ?? ''),
                'secret' => $secret,
            ];
        }

        return [
            'action' => 'screen',
            'kind' => 'form',
            'title' => _('Database'),
            'text' => _('The administrator of the server is only needed to make the database or the user.'),
            'notice' => $notice,
            'fields' => $fields,
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'wait' => _('Checking the database, then installing: this can take a few minutes…'),
            'state' => self::encode('database', $answers),
        ];
    }

    /** The settings of the database, by the name of the answer: the label and whether it is typed hidden. */
    private static function databaseFields(): array
    {
        return [
            'database_host' => [_('Database host'), false],
            'database_name' => [_('Database name'), false],
            'database_user' => [_('Database user'), false],
            'database_password' => [_('Database password'), true],
            'admin_user' => [_('Administrator'), false],
            'admin_password' => [_('Administrator password'), true],
        ];
    }

    /**
     * The password of the database account is made at random, once: the answers keep it, so that the review and the setup agree
     * (nothing is kept between two steps but what the client sends back).
     */
    private static function pinned(Environment $environment, array $answers): array
    {
        // The account the command line has (the ~/.opensimrc of kickstart): the one the setup uses, said where it comes from
        $user = getenv('OPENSIM_DB_USER');
        isset($answers['database_user']) || !is_string($user) || $user === '' || ($answers['database_user'] = $user);
        // Its password is the one of that account alone: another user chosen takes none of it
        $password = ($answers['database_user'] ?? $user) === $user ? getenv('OPENSIM_DB_PASS') : false;
        if (!isset($answers['database_password']) && is_string($password) && $password !== '') {
            $answers['database_password'] = $password;
            $answers['database_password_source'] = 'environment';
        }
        if (!isset($answers['database_password'])) {
            $answers['database_password_source'] = 'generated';
            $remote = in_array(
                self::kindOf($environment, (string) ($answers['type'] ?? '')),
                ['remote', 'preset'],
                true,
            );
            $answers['database_password'] = $remote
                ? self::buildRemote($environment, self::answersOf($answers))['simulator']?->dbPass ?? ''
                : self::build($environment, self::answersOf($answers))['plan']->dbPass;
            $answers['database_password'] === '' && ($answers['database_password'] = bin2hex(random_bytes(8)));
        }

        return $answers;
    }

    /**
     * The questions of the type chosen, by the name of the answer: the label, whether it is typed hidden, whether it is only shown and
     * the value it is shown with (when it is not an answer).
     *
     * @return array<string,array{0:string,1:bool,2:bool,3:?string}>
     */
    private static function fields(Environment $environment, array $answers): array
    {
        $type = (string) ($answers['type'] ?? '');
        $kind = self::kindOf($environment, $type);
        $avatar_name = getenv('DEFAULT_OWNER_NAME') ?: null;
        if (empty($avatar_name)) {
            $avatar_name = trim(
                sprintf(
                    '%s %s',
                    ucfirst(strtok(gethostname(), '.') ?: ucfirst($kind)),
                    in_array($kind, ['preset', 'remote']) ? _('Resident') : _('God'),
                ),
            );
        }
        $avatar_password = getenv('DEFAULT_OWNER_PASSWORD') ?: null;
        $region_name = sprintf(_("%s's Place"), strtok($avatar_name, ' '));
        $adoptionFields = trim((string) ($answers['existing_region'] ?? '')) !== ''
            ? ['database_name' => [_('Existing database'), false, false, null]]
            : [];
        if ($kind === 'preset') {
            $said = (new Presets([], $environment->layout->etcRoot()))->describe($type) ?? [];

            return [
                'preset_grid' => [_('Grid'), false, true, (string) ($said['name'] ?? $type)],
                'preset_login' => [
                    _('Login URI'),
                    false,
                    true,
                    ($said['host'] ?? '') . ':' . ($said['publicPort'] ?? ''),
                ],
                'preset_hypergrid' => [_('Hypergrid'), false, true, $said['hypergrid'] ?? true ? 'yes' : 'no'],
                'name' => [$adoptionFields !== [] ? _('Simulator name') : _('Region name'), false, false, $region_name],
            ] + ($adoptionFields === [] ? ['existing' => [_('Take over the region of this name the grid has already (yes or no)'), false, false, 'no']] : []) + [
                'avatar' => [_('Avatar name'), false, false, $avatar_name],
            ] + $adoptionFields + self::databaseDriverField($kind) + self::portFields($adoptionFields !== []);
        }
        if ($kind === 'remote') {
            return [
                'login_uri' => [_('Login URI of the grid'), false, false, null],
                'private_port' => [_('Grid private port'), false, false, null],
                'name' => [$adoptionFields !== [] ? _('Simulator name') : _('Region name'), false, false, $region_name],
            ] + ($adoptionFields === [] ? ['existing' => [_('Take over the region of this name the grid has already (yes or no)'), false, false, 'no']] : []) + [
                'avatar' => [_('Avatar name'), false, false, $avatar_name],
            ] + $adoptionFields + self::databaseDriverField($kind) + self::portFields($adoptionFields !== []);
        }

        return [
            'name' => [$kind === 'standalone' ? _('Region name') : _('Grid name'), false, false, null],
            'login_uri' => [_('Login URI'), false, false, null],
            'avatar' => [_('Avatar name'), false, false, $avatar_name],
            'password' => [_('Password'), true, false, $avatar_password],
            'password2' => [_('Password again'), true, false, $avatar_password],
            'email' => [_('Email'), false, false, null],
        ] + ($kind === 'standalone' ? [] : [
            'private_port' => [_('Grid private port'), false, false, null],
            'grid_console_port' => [_('Grid console port'), false, false, null],
        ]) + self::databaseDriverField($kind) + self::portFields(standalone: $kind === 'standalone');
    }

    /** The name of the owner the grid keeps for a region, '' when it has none or does not answer. */
    private static function registeredOwner(Grid $grid, string $region): string
    {
        $registered = isset(self::$region['registered']) ? (self::$region['registered'])($region) : RegionPlanner::registeredIn($grid, $region);
        if (!is_array($registered) || $registered['owner'] === '') {
            return '';
        }
        $name = isset(self::$region['accountName'])
            ? (self::$region['accountName'])($registered['owner'])
            : RobustGrid::accountName($grid->baseHostname(), $grid->privatePort(), $registered['owner']);

        return is_string($name) ? $name : '';
    }

    /**
     * The database accounts the setup is about to use, before anything is made: each by its name and host, whether it exists (when an
     * administrator access tells), what is done with it, and where its password comes from, masked unless asked.
     *
     * @return list<array{section:string,label:string,value:string}>
     */
    private static function accountRows(Environment $environment, array $answers): array
    {
        $remote = in_array(self::kindOf($environment, (string) ($answers['type'] ?? '')), ['remote', 'preset'], true);
        $built = $remote ? self::buildRemote($environment, self::answersOf($answers)) : self::build($environment, self::answersOf($answers));
        $plans = array_filter([$remote ? null : ($built['plan'] ?? null), ($built['simulator'] ?? null)?->databasePlan()]);
        $shown = Result::isTrue($answers['show_passwords'] ?? false);
        $mask = static fn(string $password): string => $shown ? $password : '********';
        $rows = [];
        $seen = [];
        foreach ($plans as $plan) {
            if ($plan->dbDriver === 'sqlite' || isset($seen["{$plan->dbUser}@{$plan->dbHost}"])) {
                continue;
            }
            $seen["{$plan->dbUser}@{$plan->dbHost}"] = true;
            $host = ConnectionString::isLocal($plan->dbHost) ? 'localhost' : '%';
            $hosts = isset(self::$region['accountHosts'])
                ? (self::$region['accountHosts'])($plan)
                : (new DatabaseService(new NullUi()))->accountHosts($plan);
            $state = match (true) {
                $hosts === null || $hosts === [] => '',
                in_array($host, $hosts, true) => ' ' . _('(existing)'),
                default => ' ' . sprintf(_('(exists for %s only)'), implode(', ', $hosts)),
            };
            $rows[] = ['section' => 'database', 'label' => _('Database user'), 'value' => $plan->dbUser . $state];
            $rows[] = ['section' => 'database', 'label' => _('Database password'), 'value' => $mask($plan->dbPass)];
        }
        $admin = (string) ($answers['admin_user'] ?? (getenv('OPENSIM_DB_ADMIN_USER') ?: ''));
        if ($admin !== '') {
            $rows[] = ['section' => 'database', 'label' => _('DB admin user'), 'value' => $admin];
        }

        return $rows;
    }

    /** SQLite is a simulator option; for a standalone it is also the grid's only database. */
    private static function databaseDriverField(string $kind): array
    {
        return ['database_driver' => [$kind === 'standalone' ? _('Database driver') : _('Simulator database driver'), false, false, 'mysql']];
    }

    /** Optional listener overrides; an empty answer keeps the planner's proposal. */
    private static function portFields(bool $existingRegion = false, bool $standalone = false): array
    {
        $fields = [
            'http_port' => [_('Simulator HTTP port'), false, false, null],
            'console_port' => [_('Simulator console port'), false, false, null],
        ];
        if ($standalone) {
            unset($fields['http_port']);
        }
        if (!$existingRegion) {
            $fields['region_port'] = [_('Region UDP port'), false, false, null];
        }
        return $fields;
    }

    /** Nonempty port answers, using the field names of the corresponding planner. */
    private static function portValues(array $answers, array $fields): array
    {
        $values = [];
        foreach ($fields as $field => $question) {
            if (trim((string) ($answers[$question] ?? '')) !== '') {
                $values[$field] = $answers[$question];
            }
        }
        return $values;
    }

    /**
     * The names of the questions a person answers (not the ones that are only shown).
     *
     * @return list<string>
     */
    private static function editable(array $fields): array
    {
        return array_keys(array_filter($fields, static fn(array $field): bool => !$field[2]));
    }

    /** The answers a client gave, among the ones it may give, added to the ones kept. */
    private static function keep(array $answers, array $given, array $names, bool $flags = false): array
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $given)) {
                $answers[$name] = $flags ? Result::isTrue($given[$name]) : (string) $given[$name];
            }
        }

        return $answers;
    }

    /** The answers the planning takes (the confirmation of the password is the scenario's). */
    private static function answersOf(array $answers): array
    {
        unset($answers['password2']);

        return $answers;
    }

    private static function notImplemented(Environment $environment): string
    {
        $done = array_column(
            array_filter(self::types($environment), static fn(array $type): bool => $type['implemented']),
            'label',
        );

        return sprintf(_('Not implemented yet: only %s for now.'), implode(', ', $done));
    }

    /** The state a client holds between two steps: the step and the answers so far, as one string (the other scenarios' too). */
    public static function encode(string $step, array $answers): string
    {
        return rtrim(
            strtr(base64_encode((string) json_encode(['step' => $step, 'answers' => $answers])), '+/', '-_'),
            '=',
        );
    }

    /** @return array{step:string,answers:array<string,mixed>} the start for a state that is not one */
    public static function decode(string $state): array
    {
        $decoded = $state === '' ? null : json_decode((string) base64_decode(strtr($state, '-_', '+/')), true);

        return is_array($decoded) && is_string($decoded['step'] ?? null) && is_array($decoded['answers'] ?? null)
            ? ['step' => $decoded['step'], 'answers' => $decoded['answers']]
            : ['step' => '', 'answers' => []];
    }

    /**
     * The plan of a simulator that joins a grid, given by its address or by a preset: the reply of plan() for it. The grid is not made
     * here, so there are no options.
     */
    private static function planRemote(Environment $environment, array $answers): array
    {
        $built = self::buildRemote($environment, $answers);
        $simulator = $built['simulator'];
        $setup = $simulator !== null ? SetupFile::withSimulator([], $simulator) : [];

        return [
            'valid' => $built['problems'] === [],
            'problems' => $built['problems'],
            'corrected' => $built['corrected'],
            'identity' => $simulator?->slug ?? '',
            'setup' => $setup,
            'port_bindings' => self::portBindings($setup),
            'rows' => $simulator !== null ? self::remoteRows($built['grid'], $simulator, $built['preset']) : [],
            'types' => self::publicTypes(self::types($environment)),
            'options' => [],
            'warnings' => $built['warnings'],
        ];
    }

    /**
     * The grid a simulator joins and the plan of the simulator, with its first region, and what is wrong with the answers.
     *
     * @return array{problems:array<string,string>,corrected:array<string,string>,grid:?Grid,simulator:?SimulatorPlan,preset:string,warnings:list<string>}
     */
    private static function buildRemote(Environment $environment, array $answers): array
    {
        $problems = [];
        $corrected = [];
        $type = strtolower(trim((string) ($answers['type'] ?? '')));
        $preset = self::kindOf($environment, $type) === 'preset' ? $type : '';
        $presets = new Presets([], $environment->layout->etcRoot());
        $remote = RemoteGridPlanner::with([
            'environment' => $environment,
            'presets' => $presets->names(),
            'describe' => static fn(string $name): ?array => $presets->describe($name),
            'says' => self::$says,
        ]);
        if ($preset !== '') {
            $given = $remote->presetAnswers($preset) ?? [];
        } else {
            $uri = trim((string) ($answers['login_uri'] ?? ''));
            $port = trim((string) ($answers['private_port'] ?? ''));
            if ($uri === '') {
                $problems['login_uri'] = _('required');
            } elseif (\W4OS\Engine\Hypergrid\Destination::loginParts($uri) === null) {
                $problems['login_uri'] = _('invalid (host:port)');
            }
            $port !== '' && ($message = Grid::validatePort($port)) !== null && ($problems['private_port'] = $message);
            $given = ['address' => $uri, 'private_port' => $port];
        }
        $none = [
            'problems' => $problems,
            'corrected' => $corrected,
            'grid' => null,
            'simulator' => null,
            'preset' => $preset,
            'warnings' => [],
        ];
        if (isset($problems['login_uri'])) {
            return $none;
        }
        $result = $remote->plan($given, $preset);
        if ($result['error'] !== null || $result['grid'] === null) {
            $problems['login_uri'] = (string) ($result['error'] ?? _('invalid'));

            return ['problems' => $problems] + $none;
        }
        $grid = $result['grid'];

        // Its first simulator, with its region, and the account of the grid that owns the estate
        $ui = new NullUi();
        $database = new DatabaseService($ui);
        $region = trim((string) ($answers['name'] ?? '')) !== '' ? trim((string) $answers['name']) : 'Welcome';
        // A region the grid has, taken over: the estate is the one of its owner in the grid
        $takeOver = trim((string) ($answers['existing_region'] ?? '')) === '' && Result::isTrue($answers['existing'] ?? false);
        if ($takeOver && ($owner = self::registeredOwner($grid, $region)) !== '') {
            $answers['avatar'] = $owner;
        }
        $simulators = SimulatorPlanner::forGrid($grid, $database, $ui, null, $region)->with(array_intersect_key(self::$region, ['hasAccount' => 1]));
        $owner =
            ['estate_owner' => trim((string) ($answers['avatar'] ?? ''))] +
            array_intersect_key(
                $answers,
                array_flip(['database_driver', 'database_host', 'database_name', 'database_user', 'database_password']),
            );
        $owner += self::portValues($answers, ['http_port' => 'http_port', 'console_port' => 'console_port']);
        if (is_array($answers['setup'] ?? null)) {
            $owner = array_replace($owner, SetupFile::simulatorValues($answers['setup'], 0));
        }
        $fixes = self::review($simulators->fields(), $simulators->problems($owner), $owner, $problems, $corrected);
        $simulator = $simulators->plan($fixes + $owner);
        $simulator->dbDriver = strtolower(trim((string) ($answers['database_driver'] ?? 'mysql')));
        $existingRegion = (string) ($answers['existing_region'] ?? ($answers['setup']['simulators'][0]['regions'][0]['source'] ?? ''));
        if ($existingRegion !== '') {
            if (trim((string) ($answers['database_name'] ?? '')) === '') {
                $problems['database_name'] = _('The existing simulator database name is required.');
            }
            try {
                $description = RegionAdoption::describe($existingRegion);
                $registered = isset(self::$region['registeredUuid'])
                    ? (self::$region['registeredUuid'])($description['name'])
                    : RobustGrid::regionUuid($grid->baseHostname(), $grid->privatePort(), $description['name']);
                $registeredName = $description['name'];
                if ($registered === false) {
                    $registeredName = isset(self::$region['registeredName'])
                        ? (self::$region['registeredName'])($description['uuid'])
                        : RobustGrid::regionName($grid->baseHostname(), $grid->privatePort(), $description['uuid']);
                }
                RegionAdoption::apply($simulator, $existingRegion, $registered, $registeredName);
                // A region that names no address of its own keeps the one the grid knows it by, else the grid's name
                if ($simulator->externalHost === '' || strcasecmp($simulator->externalHost, 'SYSTEMIP') === 0) {
                    $simulator->externalHost = self::formerHost($grid, $description['name']);
                }
            } catch (\InvalidArgumentException $error) {
                $problems['existing_region'] = $error->getMessage();
            }
        } else {
            $regions = RegionPlanner::forSimulator(
                $simulator,
                $grid,
                $database,
                defaultName: $region,
                context: self::$region,
            );
            $regionAnswers = is_array($answers['setup'] ?? null) ? SetupFile::regionValues($answers['setup'], 0, 0) : ['name' => $region] + ($takeOver ? ['existing' => true] : []);
            $regionAnswers += self::portValues($answers, ['port' => 'region_port']);
            self::review(
                $regions->fields(),
                $regions->problems($regionAnswers),
                $regionAnswers,
                $problems,
                $corrected,
                ['port' => 'region_port'],
            );
            $regions->apply($simulator, $regionAnswers);
        }
        // What the first region is loaded with: its archive once it runs, or the dump the new database is made from
        foreach (['oar' => 'regionArchive', 'dump' => 'regionDump'] as $answer => $field) {
            $file = trim((string) ($answers[$answer] ?? ''));
            if ($file !== '' && (!is_file($file) || !is_readable($file))) {
                $problems[$answer] = sprintf(_('cannot read %s'), $file);
            } elseif ($file !== '' && $existingRegion !== '') {
                $problems[$answer] = _('an adopted region keeps its own database: load its archive with opensim load oar once it runs');
            } else {
                $simulator->$field = $file;
            }
        }
        if ($simulator->regionArchive !== '' && $simulator->regionDump !== '') {
            $problems['oar'] = _('an archive (oar) or the dump of a database, not both');
        }
        if ($simulator->regionDump !== '' && ($problem = SetupApply::dumpRegion($simulator, $grid)) !== null) {
            $problems['dump'] = $problem;
        }

        return [
            'problems' => $problems,
            'corrected' => $corrected,
            'grid' => $grid,
            'simulator' => $simulator,
            'preset' => $preset,
            'warnings' => $result['warnings'],
        ];
    }

    /**
     * What the setup of a simulator that joins a grid will do, by section.
     *
     * @return list<array{section:string,label:string,value:string}>
     */
    private static function remoteRows(Grid $grid, SimulatorPlan $simulator, string $preset): array
    {
        $rows = [];
        $add = static function (string $section, string $label, string $value) use (&$rows): void {
            $rows[] = ['section' => $section, 'label' => $label, 'value' => $value];
        };
        $console =
            $simulator->consoleMode === 'rest'
                ? "remote, port {$simulator->consolePort}, user {$simulator->consoleUser}"
                : 'screen session';

        $add('grid', 'Grid', "{$grid->name()} ({$grid->nick()})" . ($preset !== '' ? " - preset $preset" : ''));
        $add('grid', 'Login URI', "{$grid->baseHostname()}:{$grid->publicPort()}");
        $add('grid', 'Private port', (string) $grid->privatePort());
        $add('grid', 'Hypergrid', $grid->isHypergrid() ? 'yes' : 'no');
        $add('grid', 'Grid folder', $simulator->gridDir);
        $add('simulator', 'Simulator', "{$simulator->simulatorName} ({$simulator->slug})");
        $add('simulator', 'HTTP port', (string) $simulator->httpPort);
        $add('simulator', 'Console', $console);
        $add(
            'simulator',
            'Simulator database',
            "{$simulator->dbName} @ {$simulator->dbHost} (user {$simulator->dbUser})",
        );
        $add('simulator', 'Estate', $simulator->estateName);
        $add('simulator', 'Estate owner', $simulator->estateOwner . ' (an account of the grid)');
        $add('simulator', 'Simulator config', $simulator->iniPath());
        $add('region', 'Region', $simulator->regionName);
        $simulator->regionTakenOver && $add('region', 'Taken over', sprintf(_('the region the grid has (UUID %s), with its place and size'), $simulator->regionUuid));
        $simulator->regionArchive !== '' && $add('region', 'Archive', sprintf(_('%s, loaded once it runs'), $simulator->regionArchive));
        $simulator->regionDump !== '' && $add('region', 'Database from', $simulator->regionDump);
        $add('region', 'Location', $simulator->regionLocation);
        $add('region', 'Port', (string) $simulator->regionPort);
        $simulator->existingRegionConfig !== '' && $add('region', 'Address', self::addressRow($simulator->externalHost));
        $add('owner', 'Avatar', $simulator->estateOwner);

        return $rows;
    }

    /**
     * The address viewers reached a region at, as its grid keeps it (serverIP, else the host of serverURI), when it is a name or a
     * public address; else the host name of the grid; SYSTEMIP (the address of this machine) when neither is known. A private or
     * loopback address is what SYSTEMIP gave on the machine it ran on: never kept.
     */
    private static function formerHost(Grid $grid, string $region): string
    {
        $record = isset(self::$region['registeredRecord'])
            ? (self::$region['registeredRecord'])($region)
            : RobustGrid::region($grid->baseHostname(), $grid->privatePort(), $region);
        foreach (is_array($record) ? [$record['address'] ?? '', (string) parse_url($record['server'] ?? '', PHP_URL_HOST)] : [] as $host) {
            $private = filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
            if ($host !== '' && strcasecmp($host, 'SYSTEMIP') !== 0 && !$private) {
                return $host;
            }
        }

        return $grid->baseHostname() !== '' ? $grid->baseHostname() : 'SYSTEMIP';
    }

    /** What the review says of the address of an adopted region: SYSTEMIP is said for what it is. */
    private static function addressRow(string $host): string
    {
        return strcasecmp($host, 'SYSTEMIP') === 0 ? _('SYSTEMIP: the address of this machine, the grid knowing none for it (behind NAT or in a container, give its own)') : $host;
    }

    /**
     * Takes what the fields of a model say of some answers: the problems and the corrections, under the name of the question they
     * answer. Nothing is decided here, the models' fields do.
     *
     * @param array<string,string> $fieldProblems what the planner found, by field
     * @param array<string,mixed> $given
     * @param array<string,string> $problems the problems of the questions, completed
     * @param array<string,string> $corrected the corrections of the questions, completed
     * @return array<string,string> the corrected values, by field
     */
    private static function review(
        FieldSet $fields,
        array $fieldProblems,
        array $given,
        array &$problems,
        array &$corrected,
        array $questions = [],
    ): array {
        foreach ($fieldProblems as $field => $message) {
            $problems[$questions[$field] ?? self::QUESTIONS[$field] ?? $field] ??= $message;
        }
        $fixes = $fields->corrections($given);
        foreach ($fixes as $field => $value) {
            $corrected[$questions[$field] ?? self::QUESTIONS[$field] ?? $field] = $value;
        }

        return $fixes;
    }

    /**
     * Every value the setup defines, in the order a person reads them, by section (grid, simulator, region, owner): what the planners
     * proposed and the answers, so that nothing the setup will do is a surprise.
     *
     * @param array<string,bool> $chosen the options, by name
     * @param array<string,string> $labels the labels of the presets, by name
     * @return list<array{section:string,label:string,value:string}>
     */
    private static function rows(
        GridPlan $plan,
        SimulatorPlan $simulator,
        bool $standalone,
        string $email,
        array $chosen,
        array $labels,
    ): array {
        $rows = [];
        $add = static function (string $section, string $label, string $value) use (&$rows): void {
            $rows[] = ['section' => $section, 'label' => $label, 'value' => $value];
        };
        $yes = static fn(bool $value): string => $value ? 'yes' : 'no';
        $console = static fn(string $mode, int $port, string $user): string => $mode === 'rest'
            ? "remote, port $port, user $user"
            : 'screen session';
        $database = static fn(string $name, string $host, string $user): string => "$name @ $host (user $user)";

        $add('grid', 'Grid name', "{$plan->gridName} ({$plan->gridNick})");
        $add('grid', 'Login URI', "{$plan->baseHostname}:{$plan->publicPort}");
        $add(
            'grid',
            'Region spacing',
            $plan->regionSpacing === 0 ? 'side by side' : "{$plan->regionSpacing} free block(s)",
        );
        $plan->coreDirectory !== '' && $add('grid', 'Core', $plan->coreDirectory);
        $standalone || $add('grid', 'Ports', "public {$plan->publicPort}, private {$plan->privatePort}");
        $standalone || $add('grid', 'Console', $console($plan->consoleMode, $plan->consolePort, $plan->consoleUser));
        $add('grid', 'Web URL', $plan->webUrl);
        $plan->helpers &&
            $add(
                'grid',
                'Web server',
                ['caddy' => 'Caddy', 'apache' => 'Apache', 'nginx' => 'nginx'][$plan->webServer] ?? $plan->webServer,
            );
        $add(
            'grid',
            $standalone ? 'Database' : 'Grid database',
            $database($plan->dbName, $plan->dbHost, $plan->dbUser),
        );
        $standalone || $add('grid', 'Robust config', $plan->robustIni());
        $add('grid', 'Grid folder', $plan->etcDirectory);

        // What the person chose, then what is on whatever the answers (the helpers and what they serve)
        $add('options', 'Hypergrid', $yes($plan->enableHypergrid));
        $add('options', 'SSL', $yes($chosen['ssl'] ?? false) . ' (not implemented)');
        $add('options', 'Helpers', $plan->helpers ? "yes, served at {$plan->webUrl}{$plan->helpersPath}" : 'no');
        // The web services: what the helpers give, or 2do.directory's without them
        if ($plan->helpers) {
            $two = static fn(string $choice): string => $choice === 'shared' ? '2do.directory' : 'the helpers';
            $add('web', 'Quick Start website', $plan->webSite === 'website' ? 'yes, at ' . $plan->webUrl . ($plan->sitePath === '/' ? '/' : $plan->sitePath) : 'no');
            $add('web', 'Register the regions', ['both' => 'the helpers and 2do.directory', 'helpers' => 'the helpers only', '2do' => '2do.directory only'][$plan->register] ?? $plan->register);
            $add('web', 'In-world search', $plan->search === 'disabled' ? 'none' : $two($plan->search));
            $add('web', 'Web search', $plan->webSearch === 'none' ? 'none, the viewers keep their own' : $two($plan->webSearch));
            $plan->search === 'internal' && $add('web', 'Events', $plan->eventsUrl);
            $add('web', 'Economy', $plan->economyPath === '' ? $plan->webUrl . $plan->helpersPath : (str_contains($plan->economyPath, '://') ? $plan->economyPath . ' (another service)' : $plan->webUrl . $plan->economyPath));
        } else {
            $add('web', 'Search', $plan->webSearch === 'shared'
                ? 'the in-world search, the web search and the register of the regions are 2do.directory\'s (it searches the whole hypergrid)'
                : 'the in-world search and the register of the regions are 2do.directory\'s (it searches the whole hypergrid); no web search, the viewers keep their own');
        }
        // The presets that are on, asked or not
        foreach ($plan->options as $name) {
            isset($labels[$name]) && $add('options', $labels[$name], 'yes');
        }
        $add('options', 'Destination guide', $yes($plan->helpers));
        $add('options', 'Grid status', $yes($plan->helpers));

        $add('simulator', 'Simulator', "{$simulator->simulatorName} ({$simulator->slug})");
        $add('simulator', 'HTTP port', (string) $simulator->httpPort);
        $add(
            'simulator',
            'Console',
            $console($simulator->consoleMode, $simulator->consolePort, $simulator->consoleUser),
        );
        $standalone ||
            $add(
                'simulator',
                'Simulator database',
                $database($simulator->dbName, $simulator->dbHost, $simulator->dbUser),
            );
        $add('simulator', 'Estate', $simulator->estateName);
        $add(
            'simulator',
            'Estate owner',
            $simulator->estateOwner . ($simulator->createOwner ? ' (account to create)' : ''),
        );
        $add('simulator', 'Simulator config', $simulator->iniPath());

        $add('region', 'Region', $simulator->regionName);
        $simulator->regionTakenOver && $add('region', 'Taken over', sprintf(_('the region the grid has (UUID %s), with its place and size'), $simulator->regionUuid));
        $simulator->regionArchive !== '' && $add('region', 'Archive', sprintf(_('%s, loaded once it runs'), $simulator->regionArchive));
        $simulator->regionDump !== '' && $add('region', 'Database from', $simulator->regionDump);
        $add('region', 'Location', $simulator->regionLocation);
        $add('region', 'Port', (string) $simulator->regionPort);
        $simulator->existingRegionConfig !== '' && $add('region', 'Address', self::addressRow($simulator->externalHost));
        $simulator->regionRoles !== [] && $add('region', 'Roles', implode(', ', $simulator->regionRoles));

        $add('owner', 'Avatar', $simulator->estateOwner . ($email !== '' ? " <$email>" : ''));

        return $rows;
    }
}
