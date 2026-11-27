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
    public const ROOT_STEPS = ['stop-old-instances', 'set-aside-starters', 'give-ownership', 'install-packages', 'install-website'];

    /** The packages a step may install: the ones the choices of a migration name. */
    public const PACKAGES = '/^(opensim-[0-9.]+-maptiles|w4os-site)$/';

    /** How the import is planned, applied and switched (null: GridImport). @var ?\Closure(string, array<string,mixed>): array<string,mixed> */
    public static ?\Closure $importer = null;

    /** The files of the sites of Apache that serve a website, null beside another web server (null: w4os-site --vhosts). @var ?\Closure(string): ?list<string> */
    public static ?\Closure $vhosts = null;

    /** The first screen: the install found, and what to do with it. */
    public static function first(LegacyInstall $legacy): array
    {
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
                    // Run where it is: the command line's own path, for now
                    'in-place' => ['action' => 'handoff', 'path' => 'in-place'],
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
                if ($button === 'back') {
                    return self::questions($legacy, self::planned($legacy, $answers), $answers, '');
                }
                if ($button === 'edit') {
                    $answers = array_replace($answers, array_intersect_key($given, array_flip(['website', 'name', 'maptiles'])));

                    return self::review($legacy, self::planned($legacy, $answers), $answers, self::problem($legacy, $answers));
                }

                // The steps of the run fixed at its start: what they act on changes as they go (the instances stopped)
                $plan = self::planned($legacy, $answers);
                $answers['run'] = self::steps($legacy, $answers, $plan);
                // What the summary says, from the plan of before the run (the grid is here after it, its plan refused then)
                $answers['facts'] = array_intersect_key($plan, array_flip(['grid', 'name', 'login', 'site']));

                return self::step($legacy, $answers, 0);
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
        foreach ($plan['data'] ?? [] as $data) {
            $rows[] = ['section' => _('Data'), 'label' => (string) $data['place'], 'value' => sprintf('%s, %s', $data['size'], ($data['acrossFilesystems'] ?? false) ? _('copied (another disk)') : _('moved (same disk)'))];
        }
        $rows[] = ['section' => _('Data'), 'label' => _('Databases'), 'value' => sprintf(_('kept: %s'), $plan['database']['name'] ?? '')];
        foreach ($plan['dropped'] ?? [] as $dropped) {
            ($dropped['reason'] ?? '') === 'corrected' && ($rows[] = ['section' => _('Corrected from the old install'), 'label' => (string) $dropped['key'], 'value' => sprintf(_('%s → the value the kit needs'), $dropped['value'])]);
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

    /**
     * The steps of the run, by their place in it: each a name, done by the engine or as root by the client, with what it needs.
     *
     * @return list<array{name:string,root:bool,label:string,args:array<string,mixed>,skippable:bool}>
     */
    public static function steps(LegacyInstall $legacy, array $answers, array $plan): array
    {
        $steps = [];
        $running = $legacy->running();
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
    private static function step(LegacyInstall $legacy, array $answers, int $k, string $notice = ''): array
    {
        $steps = $answers['run'] ?? self::steps($legacy, $answers, self::planned($legacy, $answers));
        if ($k >= count($steps)) {
            return ['action' => 'quit', 'message' => self::summary($legacy, $answers['facts'] ?? self::planned($legacy, $answers), $answers)];
        }
        $labels = array_values(array_unique([...array_map(static fn(array $s): string => $s['label'], $steps)]));
        // Backup and Data are the engine's own steps inside Config: named on its live line, numbered here
        array_splice($labels, (int) array_search('Config', $labels, true), 0, ['Backup']);
        array_splice($labels, (int) array_search('Config', $labels, true) + 1, 0, ['Data']);
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
            return ['action' => 'quit', 'message' => self::summary($legacy, $answers['facts'] ?? self::planned($legacy, $answers), $answers)];
        }
        $plan = [];
        $choice = (string) ($request['answers']['error'] ?? '');
        if ($choice === 'abort') {
            return ['action' => 'abort', 'error' => self::stopped($k, $steps)];
        }
        if ($choice === 'skip' && $step['skippable']) {
            $answers['skipped'] = [...(array) ($answers['skipped'] ?? []), $step['name']];

            return self::step($legacy, $answers, $k + 1);
        }
        $failed = $button === 'failed' ? (string) ($request['answers']['error_text'] ?? _('the step failed')) : '';
        if ($failed === '' && $choice !== 'retry' && !$step['root']) {
            try {
                self::engineStep($environment, $legacy, $answers, $plan, $step['name']);
            } catch (\Throwable $error) {
                $failed = $error->getMessage();
            }
        } elseif ($choice === 'retry') {
            return self::step($legacy, $answers, $k);
        }
        if ($failed !== '') {
            return self::failure($answers, $step, $failed);
        }

        return self::step($legacy, $answers, $k + 1);
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

        return in_array('import-apply', $done, true)
            ? _('Stopped: the grid is written here, not started; the old install is kept. A rerun goes on from here.')
            : (in_array('stop-old-instances', $done, true) ? _('Stopped: the old install is kept, its instances stopped; a rerun goes on from here.') : _('Stopped: nothing changed.'));
    }

    /** A step of the engine: the import written (its backup, config, data), the 3D map's option, the start. */
    private static function engineStep(Environment $environment, LegacyInstall $legacy, array $answers, array $plan, string $name): void
    {
        $args = self::importArgs($legacy, $answers);
        match ($name) {
            'import-apply' => self::import('apply', $args),
            'switch' => self::import('switch', $args),
            default => throw new \RuntimeException("unknown step $name"),
        };
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
    private static function vhostFiles(string $site): ?array
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
    private static function summary(LegacyInstall $legacy, array $plan, array $answers): string
    {
        $site = trim((string) ($answers['website'] ?? ($plan['site'] ?? '')));
        $left = in_array('install-website', (array) ($answers['skipped'] ?? []), true);
        $lines = [
            '✓ Done',
            '',
            sprintf('Grid       %s (%s)', (string) ($answers['name'] ?? $plan['name']), $plan['grid']),
            sprintf('Login      http://%s', (string) ($plan['login'] ?? '')),
        ];
        if ($site !== '') {
            $lines[] = $left ? 'Website    not installed (rerun to install it)' : sprintf('Website    %s', rtrim($site, '/') . '/');
            $left || ($lines[] = sprintf('Admin      %slogin', rtrim($site, '/') . '/'));
        }
        $lines[] = sprintf('Old install  %s, stopped, kept', $legacy->root);

        return implode("\n", $lines);
    }

    /** The arguments of the import of the grid of the old install. */
    private static function importArgs(LegacyInstall $legacy, array $answers): array
    {
        return [
            'robust' => $legacy->robusts()[0] ?? '',
            'simulators' => $legacy->simulators(),
            'name' => isset($answers['name']) && $answers['name'] !== '' ? (string) $answers['name'] : null,
            'site' => trim((string) ($answers['website'] ?? '')),
        ];
    }

    /** The plan of the migration, as the review and the steps read it (a problem of the import aborts the scenario). */
    private static function planned(LegacyInstall $legacy, array $answers): array
    {
        return self::import('plan', self::importArgs($legacy, $answers));
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
            $sizes[] = $data + ['place' => $grid !== false && $grid < count($parts) - 1 ? implode('/', array_slice($parts, $grid + 1)) : basename($from), 'size' => self::size($from)];
        }

        return ['data' => $sizes] + $document + [
            'login' => $import->plan->baseHostname . ':' . $import->plan->publicPort,
            'center' => $import->plan->center !== '' ? $import->plan->center : self::centreOf($import),
            'maptiles' => MapRenderer::package($import->plan->binDir !== '' ? dirname($import->plan->binDir) : '') ?? '',
            // A name made ASCII for the viewers: asked, the one proposed being the ASCII one
            'asciiName' => preg_grep('/^\[GridInfoService\] gridname /', $document['gridInfo'] ?? []) !== [] ? $document['name'] : '',
        ];
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

    /** The size of a folder as a person reads it: 412 MB, 11 GB. */
    private static function size(string $dir): string
    {
        $out = [];
        exec('du -sk ' . escapeshellarg($dir) . ' 2>/dev/null', $out);
        $k = (int) ($out[0] ?? 0);

        return $k >= 1048576 ? sprintf('%.0f GB', $k / 1048576) : ($k >= 1024 ? sprintf('%.0f MB', $k / 1024) : "$k KB");
    }
}
