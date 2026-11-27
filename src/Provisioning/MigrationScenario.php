<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\Import\LegacyInstall;
use W4OS\Engine\Result;
use W4OS\Engine\Runtime\Environment;

/**
 * The scenario of an install of before the kit, screens of the setup (QuickSetup, POST /setups/steps) as any other: the choice of what
 * to do with it (migrate, in place, a new grid beside it), the questions of a migration, its review, then its steps one by one, each
 * done by the engine or, when it needs root, by the client: a step is then a NAME of ROOT_STEPS with its arguments, never a command,
 * a path to run or a package it would not know; the client refuses any other. A step that fails gives the choice to retry, to skip
 * it when the run can go on without it, or to stop; the end is the summary.
 */
final class MigrationScenario
{
    /** The steps of the scenario are named with it. */
    public const PREFIX = 'existing';

    /** The steps a client runs as root, with the arguments it checks: nothing else is run by it. */
    public const ROOT_STEPS = ['stop-old-instances', 'set-aside-starters', 'give-ownership', 'install-packages', 'install-website', 'restart-service'];

    /** The packages a step may install: the ones the choices of a migration name. */
    public const PACKAGES = '/^(opensim-[0-9.]+-maptiles|w4os-site)$/';

    /** How the import is planned, applied and switched (null: GridImport). @var ?\Closure(string, array<string,mixed>): array<string,mixed> */
    public static ?\Closure $importer = null;

    /** The files of the sites of Apache that serve a website, null beside another web server (null: w4os-site --vhosts). @var ?\Closure(string): ?list<string> */
    public static ?\Closure $vhosts = null;

    /** The answers of a run without anybody, by name: what each takes, its default ('' when the plan gives it), what it is. */
    public const KEYS = [
        'existing' => ['values' => ['migrate', 'in-place', 'new'], 'default' => 'migrate', 'label' => 'What to do with the old install'],
        'stop' => ['values' => ['yes', 'no'], 'default' => 'yes', 'label' => 'Stop the old instances (a migration needs it)'],
        'name' => ['values' => 'text', 'default' => '', 'label' => 'Grid name, ASCII (the old one when it is)'],
        'website' => ['values' => 'address', 'default' => '', 'label' => 'Website address (the old one); empty for none'],
        'vhosts' => ['values' => ['yes', 'no'], 'default' => 'no', 'label' => 'Beside Apache, add the line of the website to its sites'],
        'maptiles' => ['values' => ['yes', 'no'], 'default' => 'yes', 'label' => 'Install the 3D map of the core'],
        'restart' => ['values' => ['yes', 'no'], 'default' => 'no', 'label' => 'In place: its instances restarted under the kit now'],
    ];

    /**
     * The answers a run without anybody takes, the defaults of this install filled when there is one.
     *
     * @return list<array{name:string,values:string|list<string>,default:string,label:string}>
     */
    public static function keys(?LegacyInstall $legacy): array
    {
        $plan = [];
        try {
            $legacy !== null && ($plan = self::planned($legacy, []));
        } catch (\Throwable) {
        }
        $keys = [];
        foreach (self::KEYS as $name => $key) {
            $default = match ($name) {
                'name' => (string) ($plan['name'] ?? ''),
                'website' => (string) ($plan['site'] ?? ''),
                default => $key['default'],
            };
            $keys[] = ['name' => $name, 'values' => $key['values'], 'default' => $default, 'label' => $key['label']];
        }

        return $keys;
    }

    /**
     * The run without anybody: its answers checked (an unknown one, a value refused: nothing done), the default of each one missing (no
     * line added to the sites of Apache without an answer), then its steps; a step that fails stops it.
     *
     * @param array<string,mixed> $given
     * @return array<string,mixed>
     */
    public static function unattended(Environment $environment, LegacyInstall $legacy, array $given): array
    {
        $refused = static fn(string $error): array => ['action' => 'abort', 'code' => 2, 'error' => $error];
        $answers = [];
        foreach ($given as $name => $value) {
            $key = self::KEYS[$name] ?? null;
            if ($key === null) {
                return $refused(sprintf(_('Unknown answer: %s (opensim setup --legacy --config-keys lists them).'), $name));
            }
            $value = is_bool($value) ? ($value ? 'yes' : 'no') : trim((string) $value);
            if (is_array($key['values']) && !in_array($value, $key['values'], true)) {
                return $refused(sprintf(_('%s: %s is not one of %s.'), $name, $value, implode(', ', $key['values'])));
            }
            $answers[$name] = $value;
        }
        $answers += ['vhosts' => 'no', 'stop' => 'yes', 'restart' => 'no'];
        isset($given['vhosts']) || ($answers['unanswered'] = ['vhosts']);
        $answers['unattended'] = true;
        // Here already (a run before): what is left of it, without a question
        $here = self::here($environment, $legacy, $answers);
        if ($here !== null) {
            return ($here['kind'] ?? '') === 'review' ? self::start($environment, $legacy, QuickSetup::decode((string) $here['state'])['answers']) : $here;
        }
        $choice = (string) ($answers['existing'] ?? 'migrate');
        if ($choice === 'in-place') {
            unset($answers['existing']);

            return self::start($environment, $legacy, $answers + ['mode' => 'in-place']);
        }
        if ($choice !== 'migrate') {
            return $refused(_('A new grid beside it is made by its own setup (kickstart --new).'));
        }
        unset($answers['existing']);
        $problem = self::problem($legacy, $answers);
        if ($problem !== '') {
            return $refused($problem);
        }

        return self::start($environment, $legacy, $answers);
    }

    /** The run started: its steps fixed (what they act on changes as they go), the facts of its summary kept from the plan of before. */
    private static function start(Environment $environment, LegacyInstall $legacy, array $answers): array
    {
        if (isset($answers['run'])) {
            return self::step($environment, $legacy, $answers, 0);
        }
        $plan = self::planned($legacy, $answers);
        $answers['run'] = self::steps($legacy, $answers, $plan);
        $answers['facts'] = array_intersect_key($plan, array_flip(['grid', 'name', 'login', 'site']));

        return self::step($environment, $legacy, $answers, 0);
    }

    /**
     * The grid of the old install here already (a run before): what is left of it reviewed (its start, its website), or said with
     * its summary when nothing is; null when it is not here.
     *
     * @return ?array<string,mixed>
     */
    private static function here(Environment $environment, LegacyInstall $legacy, array $answers): ?array
    {
        $robust = $legacy->robusts()[0] ?? null;
        $nick = $robust === null ? '' : $legacy->nick($robust);
        $grid = $nick === '' ? null : Grid::find($environment, $nick);
        if ($grid === null) {
            return null;
        }
        $inPlace = Grid::inPlace($environment, $nick) !== null;
        $site = $grid->siteAddress();
        $facts = ['grid' => $nick, 'name' => $grid->name(), 'login' => $grid->baseHostname() . ':' . $grid->publicPort(), 'site' => $site];
        $answers = array_replace($answers, ['facts' => $facts, 'mode' => $inPlace ? 'in-place' : 'migrate', 'here' => true]);
        // The address of its website as it was chosen, or as it is given again (a rerun asks it, prefilled)
        $answers['website'] = trim((string) ($answers['website'] ?? $site));
        $wanted = $answers['website'];
        $askSite = !$inPlace && ($site !== '' || $wanted !== '') && !self::siteInstalled($nick);
        $valid = $wanted === '' || filter_var($wanted, FILTER_VALIDATE_URL) !== false;
        $steps = [];
        if ($inPlace && !($grid->robust()?->isRunning() ?? false)) {
            // In place, what is left: enabled, and restarted under the kit when asked (a person: yes)
            $answers['restart'] ??= ($answers['unattended'] ?? false) ? 'no' : 'yes';
            $steps = array_values(array_filter(self::steps($legacy, $answers, $facts), static fn(array $step): bool => $step['name'] !== 'set-aside-starters' || $step['args']['files'] !== []));
        } elseif (!($grid->robust()?->isRunning() ?? false)) {
            $steps[] = ['name' => 'switch', 'root' => false, 'label' => 'Start', 'args' => [], 'skippable' => false];
        }
        // Its website left by a run before (a failure, no site of Apache serving it, its line not given): its address asked again,
        // kept alone when it changes (no other address of the grid follows it), then its step
        $files = $askSite && $wanted !== '' && filter_var($wanted, FILTER_VALIDATE_URL) !== false ? self::vhostFiles($wanted) : null;
        if ($askSite && $valid && $wanted !== '' && self::websiteLeft($wanted, $answers) === '') {
            array_unshift($steps, ['name' => 'install-website', 'root' => true, 'label' => 'Website', 'args' => ['grid' => $nick] + ($files !== null ? ['edit_vhosts' => true] : []), 'skippable' => true]);
        }
        if ($askSite && $valid && rtrim($wanted, '/') !== rtrim($site, '/')) {
            array_unshift($steps, ['name' => 'keep-website', 'root' => false, 'label' => 'Website', 'args' => ['grid' => $nick, 'website' => $wanted], 'skippable' => false]);
        }
        $said = sprintf(_('%s is here already.'), $nick);
        if ($steps === [] && !($askSite && !($answers['unattended'] ?? false))) {
            return ['action' => 'quit', 'message' => $said . "\n\n" . self::summary($environment, $legacy, $facts, $answers)];
        }
        $answers['run'] = $steps;
        $left = static fn(array $step): string => match ($step['name']) {
            'install-website' => $site,
            'in-place-apply' => _('registered here, enabled'),
            'stop-old-instances' => _('its instances stopped'),
            'in-place-check' => _('started under the kit'),
            'give-ownership' => _('the service runs as its account'),
            'restart-service' => _('the service restarted'),
            'set-aside-starters' => _('its start at boot set aside'),
            default => _('the grid is written here, not started'),
        };
        $rows = array_map(static fn(array $step): array => ['section' => _('Left to do'), 'label' => $step['label'], 'value' => $left($step)], array_values(array_filter($steps, static fn(array $step): bool => !in_array($step['name'], ['install-website', 'keep-website'], true))));
        if ($askSite) {
            $why = $wanted === '' ? _('none') : ($valid ? self::websiteLeft($wanted, $answers) : sprintf(_('Not an address: %s'), $wanted));
            $rows[] = ['section' => _('Web'), 'label' => _('Website'), 'value' => $why === '' ? $wanted : ($wanted === '' ? $why : "$wanted, $why"), 'name' => 'website', 'current' => $wanted];
            $files !== null && $files !== [] && ($rows[] = ['section' => _('Web'), 'label' => sprintf(_('Add one line to %s'), implode(', ', $files)), 'value' => Result::isTrue($answers['vhosts'] ?? 'yes') ? _('Yes') : _('No'), 'name' => 'vhosts', 'current' => Result::isTrue($answers['vhosts'] ?? 'yes') ? 'yes' : 'no', 'options' => ['yes' => _('Yes'), 'no' => _('No')]]);
        }

        return [
            'action' => 'screen',
            'kind' => 'review',
            'title' => _('Review'),
            'text' => $said,
            'notice' => '',
            'rows' => $rows,
            'buttons' => ['next' => _('Start'), 'back' => _('Quit')] + ($askSite ? ['edit' => _('Change')] : []),
            'state' => QuickSetup::encode(self::PREFIX . '-review', $answers),
        ];
    }

    /** Whether the website of a grid is installed (Grid::websiteInstalled()). */
    public static function siteInstalled(string $nick): bool
    {
        return Grid::websiteInstalled($nick);
    }

    /** The first screen: the install found, and what to do with it. */
    public static function first(LegacyInstall $legacy, ?Environment $environment = null): array
    {
        $here = $environment === null ? null : self::here($environment, $legacy, []);
        if ($here !== null) {
            return $here;
        }

        return [
            'action' => 'screen',
            'kind' => 'menu',
            'name' => 'existing',
            'title' => _('Existing install'),
            'text' => $legacy->summary(),
            'notice' => '',
            'items' => [
                ['key' => 'migrate', 'label' => _('Migrate'), 'note' => ''],
                ['key' => 'in-place', 'label' => _('In place'), 'note' => ''],
                ['key' => 'new', 'label' => _('New grid beside it'), 'note' => ''],
                ['key' => 'abort', 'label' => _('Abort'), 'note' => ''],
            ],
            'value' => 'migrate',
            'buttons' => ['next' => _('OK'), 'back' => _('Quit')],
            'state' => QuickSetup::encode(self::PREFIX, []),
        ];
    }

    /**
     * The next screen of the scenario, null for a new grid beside it (the questions of a grid follow).
     *
     * @param array<string,mixed> $answers
     * @param array<string,mixed> $given
     * @param array<string,mixed> $request
     * @return ?array<string,mixed>
     */
    public static function advance(Environment $environment, string $step, array $answers, string $button, array $given, array $request, LegacyInstall $legacy): ?array
    {
        if ($button === 'quit') {
            return ['action' => 'abort', 'error' => _('Stopped: nothing changed.')];
        }
        switch ($step) {
            case self::PREFIX:
                $choice = (string) ($given['existing'] ?? 'abort');

                return match ($choice) {
                    'migrate' => self::questions($legacy, self::planned($legacy, $answers), $answers, ''),
                    // Run where it is: its review, its restart under the kit the one thing asked
                    'in-place' => self::inPlaceReview($legacy, self::planned($legacy, ['mode' => 'in-place']), ['mode' => 'in-place'], ''),
                    'new' => null,
                    default => ['action' => 'abort', 'error' => _('Stopped: nothing changed.')],
                };
            case self::PREFIX . '-questions':
                $answers = array_replace($answers, array_intersect_key($given, array_flip(['website', 'name', 'stop', 'maptiles', 'vhosts'])));
                $plan = self::planned($legacy, $answers);
                $problem = self::problem($legacy, $answers);
                // A website whose host Apache serves: its line asked before the review, never taken for granted
                $files = self::vhostFiles((string) ($answers['website'] ?? ($plan['site'] ?? '')));
                if ($problem === '' && $files !== null && $files !== [] && !isset($answers['vhosts'])) {
                    return self::questions($legacy, $plan, $answers, '');
                }

                return $problem !== '' ? self::questions($legacy, $plan, $answers, $problem) : self::review($legacy, $plan, $answers, '');
            case self::PREFIX . '-review':
                if ($button === 'back' && (($answers['here'] ?? false) || ($answers['mode'] ?? '') === 'in-place')) {
                    return ['action' => 'abort', 'error' => _('Stopped: nothing changed.')];
                }
                if ($button === 'back') {
                    return self::questions($legacy, self::planned($legacy, $answers), $answers, '');
                }
                if ($button === 'edit' && ($answers['here'] ?? false)) {
                    $answers = array_replace($answers, array_intersect_key($given, array_flip(['website', 'vhosts'])));
                    unset($answers['run']);

                    return self::here($environment, $legacy, $answers) ?? ['action' => 'abort', 'error' => _('Stopped: nothing changed.')];
                }
                if ($button === 'edit' && ($answers['mode'] ?? '') === 'in-place') {
                    $answers = array_replace($answers, array_intersect_key($given, array_flip(['restart'])));

                    return self::inPlaceReview($legacy, self::planned($legacy, $answers), $answers, '');
                }
                if ($button === 'edit') {
                    // The line of the data only shows its folders: nothing of it is an answer
                    $answers = array_replace($answers, array_intersect_key($given, array_flip(['website', 'name', 'maptiles', 'vhosts'])));

                    return self::review($legacy, self::planned($legacy, $answers), $answers, self::problem($legacy, $answers));
                }

                return self::start($environment, $legacy, $answers);
            case self::PREFIX . '-run':
                return self::run($environment, $legacy, $answers, $button, $request);
        }

        return ['action' => 'abort', 'error' => _('Stopped: nothing changed.')];
    }

    /** What is wrong with the answers, '' for nothing: the instances stay up, a name that is not ASCII, an address that is not one. */
    private static function problem(LegacyInstall $legacy, array $answers): string
    {
        if ($legacy->running() !== [] && !Result::isTrue($answers['stop'] ?? 'yes')) {
            return _('The migration needs the old instances stopped (same ports, same database).');
        }
        if (preg_match('/[^ -~]/', (string) ($answers['name'] ?? '')) === 1) {
            return _('The grid name is ASCII only (viewers need it).');
        }
        $site = trim((string) ($answers['website'] ?? ''));
        if ($site !== '' && filter_var($site, FILTER_VALIDATE_URL) === false) {
            return sprintf(_('Not an address: %s'), $site);
        }

        return '';
    }

    /** The questions of a migration: the old instances stopped, the ASCII name when it needs one, the website, the 3D map. */
    private static function questions(LegacyInstall $legacy, array $plan, array $answers, string $notice): array
    {
        $yesNo = ['yes' => _('Yes'), 'no' => _('No')];
        $fields = [];
        $legacy->running() !== [] && ($fields[] = ['name' => 'stop', 'label' => _('Stop them now (their start at boot set aside)'), 'value' => (string) ($answers['stop'] ?? 'yes'), 'secret' => false, 'readonly' => false, 'options' => $yesNo]);
        ($plan['asciiName'] ?? '') !== '' && ($fields[] = ['name' => 'name', 'label' => _('Grid name'), 'value' => (string) ($answers['name'] ?? $plan['name']), 'secret' => false, 'readonly' => false]);
        $fields[] = ['name' => 'website', 'label' => _('Website address'), 'value' => (string) ($answers['website'] ?? ($plan['site'] ?? '')), 'secret' => false, 'readonly' => false];
        // Beside Apache, the one line its sites serving the host need, asked here: never in the step that installs the website
        $files = self::vhostFiles((string) ($answers['website'] ?? ($plan['site'] ?? '')));
        $files !== null && $files !== [] && ($fields[] = ['name' => 'vhosts', 'label' => sprintf(_('Add one line to %s'), implode(', ', $files)), 'value' => (string) ($answers['vhosts'] ?? 'yes'), 'secret' => false, 'readonly' => false, 'options' => $yesNo]);
        ($plan['maptiles'] ?? '') !== '' && ($fields[] = ['name' => 'maptiles', 'label' => sprintf(_('3D map (installs %s)'), $plan['maptiles']), 'value' => (string) ($answers['maptiles'] ?? 'yes'), 'secret' => false, 'readonly' => false, 'options' => $yesNo]);
        $running = array_map(static fn(string $config): string => basename($config, '.ini'), $legacy->running());

        return [
            'action' => 'screen',
            'kind' => 'form',
            'title' => sprintf(_('Migration of %s'), $plan['grid']),
            'text' => $running === [] ? '' : sprintf(_('The old instances run: %s.'), implode(', ', $running)),
            'notice' => $notice,
            'fields' => $fields,
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => QuickSetup::encode(self::PREFIX . '-questions', $answers),
        ];
    }

    /** The review of a migration: what it takes from the old install, what it sets without a question, each changed line checked again. */
    private static function review(LegacyInstall $legacy, array $plan, array $answers, string $notice): array
    {
        $name = (string) ($answers['name'] ?? $plan['name']);
        $site = trim((string) ($answers['website'] ?? ($plan['site'] ?? '')));
        $left = self::websiteLeft($site, $answers);
        $onOff = ['yes' => _('on'), 'no' => _('off')];
        $rows = [
            ['section' => _('Grid'), 'label' => _('Name'), 'value' => "$name ({$plan['grid']})", 'name' => 'name', 'current' => $name],
            ['section' => _('Grid'), 'label' => _('Login'), 'value' => (string) ($plan['login'] ?? '')],
            // The centre its regions were placed around, kept as the old install has it: shown, not asked
            ...(($plan['center'] ?? '') !== '' ? [['section' => _('Grid'), 'label' => _('Centre'), 'value' => (string) $plan['center']]] : []),
            ['section' => _('Web'), 'label' => _('Website'), 'value' => $site === '' ? _('none') : ($left !== '' ? "$site, " . $left : $site), 'name' => 'website', 'current' => $site],
        ];
        // Its folders of data in one line, their total and how they come; each one with Change
        $folders = $plan['data'] ?? [];
        if ($folders !== []) {
            $across = array_filter($folders, static fn(array $data): bool => (bool) ($data['acrossFilesystems'] ?? false)) !== [];
            $detail = [];
            foreach ($folders as $data) {
                $detail[(string) $data['place']] = sprintf('%s: %s', $data['place'], $data['size']);
            }
            $rows[] = [
                'section' => _('Data'),
                'label' => _('Data'),
                'value' => sprintf(_('%s in %d folders, %s'), self::kilobytes(array_sum(array_map(static fn(array $data): int => (int) ($data['kilobytes'] ?? 0), $folders))), count($folders), $across ? _('copied (another disk)') : _('moved (same disk)')),
                'name' => 'data',
                'current' => (string) array_key_first($detail),
                'options' => $detail,
            ];
        }
        $rows[] = ['section' => _('Data'), 'label' => _('Databases'), 'value' => sprintf(_('kept: %s'), $plan['database']['name'] ?? '')];
        foreach ($plan['dropped'] ?? [] as $dropped) {
            ($dropped['reason'] ?? '') === 'corrected' && ($rows[] = ['section' => _('Corrected from the old install'), 'label' => (string) $dropped['key'], 'value' => self::shortened((string) $dropped['value']) . ' → ' . self::shortened((string) ($dropped['replacement'] ?? _('the value of the kit')))]);
        }
        $ossl = $plan['ossl'] ?? ['kept' => [], 'dropped' => []];
        ($ossl['kept'] !== [] || $ossl['dropped'] !== []) && ($rows[] = ['section' => _('Corrected from the old install'), 'label' => _('OSSL permissions'), 'value' => sprintf(_('%d kept, [XEngine] read as [OSSL]'), count($ossl['kept'])) . ($ossl['dropped'] === [] ? '' : '; ' . sprintf(_('left out: %s'), implode(', ', $ossl['dropped'])))]);
        ($plan['maptiles'] ?? '') !== '' && ($rows[] = ['section' => _('Options'), 'label' => _('3D map'), 'value' => $onOff[Result::isTrue($answers['maptiles'] ?? 'yes') ? 'yes' : 'no'], 'name' => 'maptiles', 'current' => Result::isTrue($answers['maptiles'] ?? 'yes') ? 'yes' : 'no', 'options' => $onOff]);
        $legacy->running() !== [] && ($rows[] = ['section' => _('Old install'), 'label' => _('Instances'), 'value' => _('stopped, their start at boot set aside')]);
        $rows[] = ['section' => _('Old install'), 'label' => _('Folder'), 'value' => sprintf(_('%s, kept'), $legacy->root)];

        return [
            'action' => 'screen',
            'kind' => 'review',
            'title' => _('Review'),
            'text' => '',
            'notice' => $notice,
            'rows' => $rows,
            'buttons' => ['next' => _('Start'), 'back' => _('Back'), 'edit' => _('Change')],
            'state' => QuickSetup::encode(self::PREFIX . '-review', $answers),
        ];
    }

    /** The review of a run in place: what the kit takes of it (its accounts, its grid info), its restart under the kit. */
    private static function inPlaceReview(LegacyInstall $legacy, array $plan, array $answers, string $notice): array
    {
        $onOff = ['yes' => _('on'), 'no' => _('off')];
        $restart = Result::isTrue($answers['restart'] ?? 'yes') ? 'yes' : 'no';
        $rows = [
            ['section' => _('Grid'), 'label' => _('Nick'), 'value' => (string) $plan['grid']],
            ['section' => _('Grid'), 'label' => _('Robust config'), 'value' => (string) ($legacy->robusts()[0] ?? '')],
            ['section' => _('Grid'), 'label' => _('Simulators'), 'value' => implode(', ', array_map(static fn(string $config): string => basename($config, '.ini'), $legacy->simulators()))],
        ];
        foreach ($plan['accounts'] ?? [] as $key => $change) {
            $rows[] = ['section' => _('Accounts'), 'label' => (string) $key, 'value' => sprintf('%s → %s', $change['from'], $change['to'])];
        }
        foreach ($plan['gridInfo'] ?? [] as $line) {
            $rows[] = ['section' => _('Corrected from the old install'), 'label' => _('Grid info'), 'value' => self::shortened((string) $line, 60)];
        }
        $rows[] = ['section' => _('Options'), 'label' => _('Restart under the kit'), 'value' => $onOff[$restart], 'name' => 'restart', 'current' => $restart, 'options' => $onOff];
        $rows[] = ['section' => _('Old install'), 'label' => _('Folder'), 'value' => sprintf(_('%s, run where it is; its start at boot set aside'), $legacy->root)];

        return [
            'action' => 'screen',
            'kind' => 'review',
            'title' => _('Review'),
            'text' => '',
            'notice' => $notice,
            'rows' => $rows,
            'buttons' => ['next' => _('Start'), 'back' => _('Quit'), 'edit' => _('Change')],
            'state' => QuickSetup::encode(self::PREFIX . '-review', array_replace($answers, ['restart' => $restart])),
        ];
    }

    /**
     * The steps of the run, by their place in it: each a name, done by the engine or as root by the client, with what it needs.
     *
     * @return list<array{name:string,root:bool,label:string,args:array<string,mixed>,skippable:bool}>
     */
    public static function steps(LegacyInstall $legacy, array $answers, array $plan): array
    {
        $steps = [];
        $running = $legacy->running();
        if (($answers['mode'] ?? '') === 'in-place') {
            $restart = Result::isTrue($answers['restart'] ?? 'no');
            $steps[] = ['name' => 'set-aside-starters', 'root' => true, 'label' => 'Stop', 'args' => ['files' => $legacy->starters()], 'skippable' => false];
            $steps[] = ['name' => 'in-place-apply', 'root' => false, 'label' => 'Config', 'args' => [], 'skippable' => false];
            // The service and its folders made to follow the accounts the kit took from it
            $steps[] = ['name' => 'give-ownership', 'root' => true, 'label' => 'Ownership', 'args' => [], 'skippable' => false];
            $restart && $running !== [] && ($steps[] = ['name' => 'stop-old-instances', 'root' => true, 'label' => 'Start', 'args' => ['configs' => $running], 'skippable' => false]);
            $restart && ($steps[] = ['name' => 'restart-service', 'root' => true, 'label' => 'Start', 'args' => [], 'skippable' => false]);
            $restart && ($steps[] = ['name' => 'in-place-check', 'root' => false, 'label' => 'Start', 'args' => ['grid' => $plan['grid']], 'skippable' => false]);

            return $steps;
        }
        if ($running !== []) {
            $steps[] = ['name' => 'set-aside-starters', 'root' => true, 'label' => 'Stop', 'args' => ['files' => $legacy->starters()], 'skippable' => false];
            $steps[] = ['name' => 'stop-old-instances', 'root' => true, 'label' => 'Stop', 'args' => ['configs' => $running], 'skippable' => false];
        }
        $steps[] = ['name' => 'import-apply', 'root' => false, 'label' => 'Config', 'args' => [], 'skippable' => false];
        $steps[] = ['name' => 'give-ownership', 'root' => true, 'label' => 'Ownership', 'args' => [], 'skippable' => false];
        if (($plan['maptiles'] ?? '') !== '' && Result::isTrue($answers['maptiles'] ?? 'yes')) {
            $steps[] = ['name' => 'install-packages', 'root' => true, 'label' => '3D map', 'args' => ['packages' => [$plan['maptiles']], 'option' => 'map-warp3d', 'grid' => $plan['grid']], 'skippable' => true];
        }
        $site = trim((string) ($answers['website'] ?? ($plan['site'] ?? '')));
        if ($site !== '' && self::websiteLeft($site, $answers) === '') {
            $files = self::vhostFiles($site);
            $steps[] = ['name' => 'install-website', 'root' => true, 'label' => 'Website', 'args' => ['grid' => $plan['grid']] + ($files !== null ? ['edit_vhosts' => true] : []), 'skippable' => true];
        }
        $steps[] = ['name' => 'switch', 'root' => false, 'label' => 'Start', 'args' => [], 'skippable' => false];

        return $steps;
    }

    /** The k-th step for the client, numbered among the names of the run ("[3/8] Config"), or the summary once there is none left. */
    private static function step(Environment $environment, LegacyInstall $legacy, array $answers, int $k, string $notice = ''): array
    {
        $steps = $answers['run'] ?? self::steps($legacy, $answers, self::planned($legacy, $answers));
        if ($k >= count($steps)) {
            return ['action' => 'quit', 'message' => self::summary($environment, $legacy, $answers['facts'] ?? self::planned($legacy, $answers), $answers)];
        }
        $labels = array_values(array_unique([...array_map(static fn(array $s): string => $s['label'], $steps)]));
        // Backup and Data are the engine's own steps inside the Config of a migration: named on its live line, numbered here
        if (in_array('import-apply', array_column($steps, 'name'), true)) {
            array_splice($labels, (int) array_search('Config', $labels, true), 0, ['Backup']);
            array_splice($labels, (int) array_search('Config', $labels, true) + 1, 0, ['Data']);
        }
        $step = $steps[$k];

        return [
            'action' => 'step',
            'step' => $step + ['number' => (int) array_search($step['label'], $labels, true) + 1, 'count' => count($labels)],
            'labels' => $labels,
            'notice' => $notice,
            'state' => QuickSetup::encode(self::PREFIX . '-run', array_replace($answers, ['k' => $k])),
        ];
    }

    /** A step done: the client's (as root), or the engine's, done now; the next one, or the choice when it failed. */
    private static function run(Environment $environment, LegacyInstall $legacy, array $answers, string $button, array $request): array
    {
        $k = (int) ($answers['k'] ?? 0);
        $steps = $answers['run'] ?? [];
        $step = $steps[$k] ?? null;
        if ($step === null) {
            return ['action' => 'quit', 'message' => self::summary($environment, $legacy, $answers['facts'] ?? self::planned($legacy, $answers), $answers)];
        }
        $plan = [];
        $choice = (string) ($request['answers']['error'] ?? '');
        if ($choice === 'abort') {
            return ['action' => 'abort', 'error' => self::stopped($k, $steps)];
        }
        if ($choice === 'skip' && $step['skippable']) {
            $answers['skipped'] = [...(array) ($answers['skipped'] ?? []), $step['name']];

            return self::step($environment, $legacy, $answers, $k + 1);
        }
        $failed = $button === 'failed' ? (string) ($request['answers']['error_text'] ?? _('the step failed')) : '';
        if ($failed === '' && $choice !== 'retry' && !$step['root']) {
            try {
                self::engineStep($environment, $legacy, $answers, $plan, $step);
            } catch (\Throwable $error) {
                $failed = $error->getMessage();
            }
        } elseif ($choice === 'retry') {
            return self::step($environment, $legacy, $answers, $k);
        }
        if ($failed !== '') {
            // Nobody to choose: the run stops there, said in one line
            if ($answers['unattended'] ?? false) {
                $numbered = self::step($environment, $legacy, $answers, $k)['step'];

                return ['action' => 'abort', 'code' => 1, 'error' => sprintf('[%d/%d] %s: %s. %s', $numbered['number'], $numbered['count'], $step['label'], rtrim(trim($failed), '.'), self::stopped($k, $steps))];
            }

            return self::failure($answers, $step, $failed);
        }

        return self::step($environment, $legacy, $answers, $k + 1);
    }

    /** The choice after a step that failed: again, skip it when the run can go on without it, stop. */
    private static function failure(array $answers, array $step, string $error): array
    {
        $items = [['key' => 'retry', 'label' => _('Retry'), 'note' => '']];
        $step['skippable'] && ($items[] = ['key' => 'skip', 'label' => _('Skip'), 'note' => '']);
        $items[] = ['key' => 'abort', 'label' => _('Abort'), 'note' => ''];

        return [
            'action' => 'screen',
            'kind' => 'menu',
            'name' => 'error',
            'title' => sprintf(_('%s failed'), $step['label']),
            'text' => $error,
            'notice' => '',
            'items' => $items,
            'value' => 'retry',
            'buttons' => ['next' => _('OK'), 'back' => _('Abort')],
            'state' => QuickSetup::encode(self::PREFIX . '-run', $answers),
        ];
    }

    /** What stands when the run stops at step k: nothing changed before the import, the old install kept after it. */
    private static function stopped(int $k, array $steps): string
    {
        $done = array_column(array_slice($steps, 0, $k), 'name');

        if (in_array('in-place-apply', $done, true)) {
            return _('Stopped: the grid is registered here, run where it is; the kit does not start it yet. A rerun goes on from here.');
        }
        if ($done === ['set-aside-starters']) {
            return _('Stopped: the start at boot of the old install is set aside (*.w4os-disabled), its instances run as before; a rerun goes on from here.');
        }

        return in_array('import-apply', $done, true)
            ? _('Stopped: the grid is written here, not started; the old install is kept. A rerun goes on from here.')
            : (in_array('stop-old-instances', $done, true) ? _('Stopped: the old install is kept, its instances stopped; a rerun goes on from here.') : _('Stopped: nothing changed.'));
    }

    /** A step of the engine: the import written (its backup, config, data), the 3D map's option, the start. */
    private static function engineStep(Environment $environment, LegacyInstall $legacy, array $answers, array $plan, array $step): void
    {
        $args = self::importArgs($legacy, $answers);
        $name = (string) $step['name'];
        match ($name) {
            'import-apply' => self::import('apply', $args),
            'switch' => self::import('switch', $args),
            'in-place-apply' => self::import('in-place-apply', $args),
            'in-place-check' => self::import('in-place-check', $args),
            'keep-website' => self::keepWebsite($environment, $step),
            default => throw new \RuntimeException("unknown step $name"),
        };
    }

    /** The address of the website of a grid kept alone (its SiteAddress): no other address of the grid follows it. */
    private static function keepWebsite(Environment $environment, array $step): void
    {
        $grid = Grid::find($environment, (string) $step['args']['grid']) ?? throw new \RuntimeException("no grid {$step['args']['grid']} here");
        Grid::keepSiteAddress($grid->directory() . '/' . $grid->nick() . '.conf', Grid::validWebsite((string) $step['args']['website']));
    }

    /** Why the website is left out ('' when it is not): beside Apache, no site serving its host, or its line refused. */
    private static function websiteLeft(string $site, array $answers): string
    {
        $files = $site === '' ? null : self::vhostFiles($site);
        if ($files === null) {
            return '';
        }
        if ($files === []) {
            return sprintf(_('not installed: no site of Apache serves %s'), (string) parse_url($site, PHP_URL_HOST));
        }

        return Result::isTrue($answers['vhosts'] ?? 'yes') ? '' : _('not installed: it needs its line in the sites of Apache');
    }

    /** @return ?list<string> the files of the sites of Apache that would include the website, null beside another web server */
    public static function vhostFiles(string $site): ?array
    {
        if ($site === '' || filter_var($site, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        if (self::$vhosts !== null) {
            return (self::$vhosts)($site);
        }
        // Caddy first, as the step of the website takes it; Apache otherwise, its sites named by the site's own command
        if ((is_file('/etc/caddy/Caddyfile') && self::has('caddy')) || !self::has('apache2ctl') || !self::has('w4os-site')) {
            return null;
        }
        $host = (string) parse_url($site, PHP_URL_SCHEME) . '://' . (string) parse_url($site, PHP_URL_HOST);
        $prefix = rtrim((string) parse_url($site, PHP_URL_PATH), '/');
        $out = [];
        exec('w4os-site web apache --host ' . escapeshellarg($host) . ($prefix !== '' ? ' --prefix ' . escapeshellarg($prefix) : '') . ' --vhosts 2>/dev/null', $out);

        return array_values(array_filter(array_map('trim', $out)));
    }

    private static function has(string $command): bool
    {
        $out = [];
        exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $out);

        return $out !== [];
    }

    /** The summary of a migration. */
    private static function summary(Environment $environment, LegacyInstall $legacy, array $plan, array $answers): string
    {
        $site = trim((string) ($answers['website'] ?? ($plan['site'] ?? '')));
        $left = in_array('install-website', (array) ($answers['skipped'] ?? []), true);
        // The owner of the grid: the one of its first estate (its databases), else the one its simulators name
        $grid = Grid::find($environment, (string) $plan['grid']);
        $config = $grid?->config();
        $owner = '';
        try {
            $owner = $config === null ? '' : (string) \W4OS\Engine\Models\Avatar::gridOwner($config)?->name();
        } catch (\Throwable) {
        }
        foreach ($owner === '' ? $grid?->simulators() ?? [] : [] as $simulator) {
            $owner !== '' || ($owner = (string) $simulator->estateOwner());
        }
        // In place, its address as the kit reads it once registered (its plan only had the files of the old install)
        $login = ($answers['mode'] ?? '') === 'in-place' && $grid !== null ? $grid->baseHostname() . ':' . $grid->publicPort() : (string) ($plan['login'] ?? '');
        $lines = [
            '✓ Done',
            '',
            sprintf('Grid       %s (%s)', (string) ($answers['name'] ?? $plan['name']), $plan['grid']),
            sprintf('Login      http://%s', $login),
        ];
        // Why the website is not there, said last (a script finds it there): skipped, its line of Apache not answered or refused
        $why = $left ? 'rerun to install it' : ($site === '' ? '' : (in_array('vhosts', (array) ($answers['unanswered'] ?? []), true) && self::websiteLeft($site, $answers) !== '' ? 'no answer for vhosts' : self::websiteLeft($site, $answers)));
        $why = (string) preg_replace('/^not installed: /', '', $why);
        // A rerun says the website as it stands: not there when its step did not put it there
        if (($answers['here'] ?? false) && $site !== '' && $why === '' && !self::siteInstalled((string) $plan['grid'])) {
            $why = 'rerun to install it';
        }
        if ($site !== '' && $why === '') {
            $lines[] = sprintf('Website    %s', rtrim($site, '/') . '/');
            $lines[] = sprintf('Admin      %slogin', rtrim($site, '/') . '/') . ($owner === '' ? '' : " ($owner)");
        }
        if (($answers['mode'] ?? '') === 'in-place') {
            $started = ($answers['here'] ?? false) || Result::isTrue($answers['restart'] ?? 'no');
            $lines[] = sprintf('In place   %s, run by the kit', $legacy->root);
            $started || ($lines[] = sprintf('Instances  still run as before: opensim restart %s runs them under the kit', $plan['grid']));
        } else {
            $lines[] = sprintf('Old install  %s, stopped, kept', $legacy->root);
        }
        $why === '' || ($lines[] = "Website    not installed ($why)");

        return implode("\n", $lines);
    }

    /** The arguments of the import of the grid of the old install. */
    private static function importArgs(LegacyInstall $legacy, array $answers): array
    {
        return [
            'robust' => $legacy->robusts()[0] ?? '',
            'simulators' => $legacy->simulators(),
            'name' => isset($answers['name']) && $answers['name'] !== '' ? (string) $answers['name'] : null,
            // The website shown in the review: the one answered, else the old install's (kept with the grid: chosen)
            'site' => trim((string) ($answers['website'] ?? ($answers['facts']['site'] ?? ''))),
            'grid' => (string) ($answers['facts']['grid'] ?? ''),
        ];
    }

    /** The plan of the migration, as the review and the steps read it (a problem of the import aborts the scenario). */
    private static function planned(LegacyInstall $legacy, array $answers): array
    {
        return self::import(($answers['mode'] ?? '') === 'in-place' ? 'in-place-plan' : 'plan', self::importArgs($legacy, $answers));
    }

    /** @return array<string,mixed> */
    private static function import(string $action, array $args): array
    {
        return (self::$importer ?? self::gridImport(...))($action, $args);
    }

    /**
     * The import of GridImport: its plan as the scenario reads it, or its apply and switch.
     *
     * @return array<string,mixed>
     */
    private static function gridImport(string $action, array $args): array
    {
        $environment = Environment::system(\W4OS\Engine\Config\Layout::resolve());
        $simulators = array_map(static fn(string $file): array => ['file' => $file], $args['simulators']);
        if (str_starts_with($action, 'in-place-')) {
            return self::inPlace($environment, substr($action, 9), $args, $simulators);
        }
        $import = Import\GridImport::prepare($environment, $args['robust'], null, null, $simulators, written: $action === 'switch', name: $args['name']);
        if ($action === 'apply') {
            $import->apply($environment, new NullUi(live: PHP_SAPI === 'cli'));
            $args['site'] === '' || Grid::keepSiteAddress("{$import->plan->gridDir}/{$import->plan->gridNick}.conf", rtrim($args['site'], '/') . '/');

            return [];
        }
        if ($action === 'switch') {
            $import->switch($environment, new NullUi(live: PHP_SAPI === 'cli'));

            return [];
        }
        $document = $import->document();
        $document['problems'] === [] || throw new \RuntimeException(implode('; ', $document['problems']));
        $sizes = [];
        foreach ($document['data'] ?? [] as $data) {
            // Its place under the folder of the grid in the old install (fsassets/data), its name when it is not under one
            $from = (string) $data['from'];
            $parts = explode('/', trim($from, '/'));
            $grid = array_search(strtolower($document['grid']), array_map('strtolower', $parts), true);
            $kilobytes = self::kilobytesOf($from);
            $sizes[] = $data + ['place' => $grid !== false && $grid < count($parts) - 1 ? implode('/', array_slice($parts, $grid + 1)) : basename($from), 'size' => self::kilobytes($kilobytes), 'kilobytes' => $kilobytes];
        }

        return ['data' => $sizes] + $document + [
            'login' => $import->plan->baseHostname . ':' . $import->plan->publicPort,
            'center' => $import->plan->center !== '' ? $import->plan->center : self::centreOf($import),
            'maptiles' => MapRenderer::package($import->plan->binDir !== '' ? dirname($import->plan->binDir) : '') ?? '',
            // A name made ASCII for the viewers: asked, the one proposed being the ASCII one
            'asciiName' => preg_grep('/^\[GridInfoService\] gridname /', $document['gridInfo'] ?? []) !== [] ? $document['name'] : '',
        ];
    }

    /**
     * The install registered where it is, as the API does it (POST /setups/imports, inPlace): its plan, its section written and its
     * grid enabled, its instances started under the kit.
     *
     * @return array<string,mixed>
     */
    private static function inPlace(Environment $environment, string $action, array $args, array $simulators): array
    {
        $register = static function (string $mode) use ($environment, $args, $simulators): array {
            $document = json_decode((new \W4OS\Engine\Api\Api($environment))->import(['kind' => 'robust', 'file' => $args['robust'], 'inPlace' => true, 'simulators' => $simulators, 'mode' => $mode])->body(), true);
            $error = $document['errors'][0] ?? null;
            $error === null || throw new \RuntimeException((string) ($error['detail'] ?? $error['title'] ?? 'the registration in place failed'));

            return (array) ($document['data']['attributes'] ?? []);
        };
        if ($action === 'plan') {
            $plan = $register('plan');
            $grid = $plan['grid'] ?? '';
            $info = \W4OS\Engine\Config\ConfigReader::load($args['robust'])['GridInfoService'] ?? [];
            $login = (string) preg_replace('#^https?://|/$#', '', (string) ($info['login'] ?? ''));

            return $plan + ['name' => (string) ($info['gridname'] ?? $grid), 'login' => $login, 'site' => ''];
        }
        if ($action === 'apply') {
            $plan = $register('apply');
            // The config of the suite read again: its section is written now
            $environment = Environment::system(\W4OS\Engine\Config\Layout::resolve());
            $grid = Grid::find($environment, (string) $plan['grid']) ?? throw new \RuntimeException("the grid {$plan['grid']} is not registered");
            $grid->isEnabled() || $grid->enable() || throw new \RuntimeException("the grid {$plan['grid']} could not be enabled");
            foreach ($grid->simulators() as $simulator) {
                $simulator->isEnabled() || $simulator->enable();
            }

            return [];
        }
        // Up under the service once it restarted: its Robust, then its enabled simulators, a while given to each
        $grid = Grid::find($environment, (string) $args['grid']) ?? throw new \RuntimeException("the grid {$args['grid']} is not registered");
        $down = [];
        for ($i = 0; $i < 90; $i++) {
            $down = array_map(static fn($instance): string => $instance->name(), array_filter($grid->instances(true), static fn($instance): bool => !$instance->isRunning()));
            if ($down === []) {
                break;
            }
            self::$importer === null && sleep(2);
        }
        $down === [] || throw new \RuntimeException('not started under the kit: ' . implode(', ', $down));

        return [];
    }

    /** The centre the regions of the old install give: its default region's place (DefaultRegion of its Robust), else its first region's. */
    private static function centreOf(Import\GridImport $import): string
    {
        $places = [];
        foreach ($import->simulators?->imports ?? [] as $simulator) {
            foreach ($simulator->regions as $region) {
                ($region['location'] ?? '') !== '' && ($places[(string) $region['name']] ??= (string) $region['location']);
            }
        }
        foreach ($import->customizations as $setting) {
            if (($setting['section'] ?? '') === 'GridService' && str_starts_with((string) ($setting['key'] ?? ''), 'Region_') && str_contains((string) ($setting['value'] ?? ''), 'DefaultRegion')) {
                $name = str_replace('_', ' ', substr((string) $setting['key'], 7));
                if (isset($places[$name])) {
                    return $places[$name];
                }
            }
        }

        return (string) (array_values($places)[0] ?? '');
    }

    /** The size of a folder, in kilobytes (du). */
    private static function kilobytesOf(string $dir): int
    {
        $out = [];
        exec('du -sk ' . escapeshellarg($dir) . ' 2>/dev/null', $out);

        return (int) ($out[0] ?? 0);
    }

    /** A size as a person reads it: 412 MB, 11 GB. */
    private static function kilobytes(int $k): string
    {
        return $k >= 1048576 ? sprintf('%.0f GB', $k / 1048576) : ($k >= 1024 ? sprintf('%.0f MB', $k / 1024) : "$k KB");
    }

    /** A value of a config shortened in the middle when long, its start and its end kept (…/assets/AssetSets.xml). */
    private static function shortened(string $value, int $max = 44): string
    {
        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, intdiv($max, 2) - 1) . '…' . mb_substr($value, -intdiv($max, 2));
    }
}
