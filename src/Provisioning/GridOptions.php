<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Config\IniEditor;
use W4OS\Engine\Models\Grid;
use W4OS\Engine\Provisioning\Web\Services;
use W4OS\Engine\Provisioning\Web\Snippets;

/**
 * The options of a grid (`opensim enable|disable <grid> <option>`, and the options of the setup): the presets that have an
 * `option.conf` (see OptionTemplate), in the folders of presets of the machine (<etc>/presets, the administrator's) and of the kit
 * (share/presets). Enabling one lays each ini file of the preset over the file of the grid that has the same name, in the files of
 * the simulators (the config each one reads), in the OpenSim.ini of the grid for the ones it already has (the helpers read it first,
 * a setting there that is wrong hides the right one) and, for what Robust says, in its Robust config. Nothing else is touched: the
 * administrator customizes the rest in the files. A setting that is there is changed in place, and disabling comments it out, with
 * its value, so that it can be seen and replaced.
 *
 * The options a grid has are kept in its description (`Options = search, offline-helpers`); a grid that does not say has the ones
 * that are on by default and that it can have.
 */
final class GridOptions
{
    /** @var array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} */
    private const RESULT = ['error' => null, 'simulators' => [], 'grid' => false, 'robust' => false, 'files' => [], 'notes' => []];

    /** @var array<string,OptionTemplate>|null */
    private ?array $templates = null;

    /** Whether the options the files of the grid tell are being read (told()): the options of the grid are then the ones it has said. */
    private bool $telling = false;

    /**
     * @param ?bool $layered whether the OpenSim.ini of the grid holds the settings of its simulators (it has a master, see
     *                       SimulatorMaster); null: it does when the master is there
     * @param array<string,string> $extra more variables for the files: what a setup collected (see GridPlan::variables())
     */
    public function __construct(
        private Grid $grid,
        private ?Presets $presets = null,
        private ?bool $layered = null,
        private array $extra = [],
    ) {}

    /**
     * The options there are.
     *
     * @return array<string,OptionTemplate> by name
     */
    public function templates(): array
    {
        return $this->templates ??= ($this->presets ??= new Presets(
            [],
            $this->grid->environment()->layout->etcRoot(),
        ))->options();
    }

    /** An option by its name. */
    public function template(string $name): ?OptionTemplate
    {
        return $this->templates()[strtolower(trim($name))] ?? null;
    }

    /**
     * The option a word means: its name, else the one of a group (`economy`) that is on, else the one of the variant
     * (`economy` and `gloebit`), else the one the group has by default.
     */
    public function resolve(string $word, string $variant = ''): ?OptionTemplate
    {
        $word = strtolower(trim($word));
        $variant = strtolower(trim($variant));
        if ($variant !== '' && ($found = $this->template("$word-$variant")) !== null) {
            return $found;
        }
        if ($variant === '' && ($found = $this->template($word)) !== null) {
            return $found;
        }
        $members = array_filter($this->templates(), static fn(OptionTemplate $t): bool => $t->group() === $word);
        if ($variant !== '') {
            foreach ($members as $member) {
                if ($member->variant() === $variant) {
                    return $member;
                }
            }

            return null;
        }
        foreach ($members as $member) {
            if (in_array($member->name(), $this->enabled(), true)) {
                return $member;
            }
        }

        return array_values($this->defaults($members))[0] ?? (array_values($members)[0] ?? null);
    }

    /**
     * What stops the grid from having an option, null when it can: the first thing it requires that the grid is not or has not.
     *
     * @param string $prefix the address of the services of the helpers, when they are given for the option
     */
    public function unmet(OptionTemplate $option, string $prefix = ''): ?string
    {
        foreach ($option->requires() as $atom) {
            $negated = str_starts_with($atom, '!');
            $word = strtolower(ltrim($atom, '!'));
            if ($this->fact($word, $prefix) === $negated) {
                return $atom;
            }
        }

        return null;
    }

    /**
     * The options the grid has: the ones it says, else the ones that are on by default and that it can have.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        return $this->toldInGroups($this->said());
    }

    /**
     * The option of a group the files of the grid tell, in place of the one recorded or by default: the OpenSim.ini of the grid is
     * what its simulators run with (the offline messages of the helpers, V1, that an import brought).
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function toldInGroups(array $names): array
    {
        if ($this->telling) {
            return $names;
        }
        $told = $this->told();
        foreach ($told as $name) {
            $group = $this->template($name)?->group() ?? '';
            if ($group === '' || in_array($name, $names, true)) {
                continue;
            }
            $others = array_filter($names, fn(string $n): bool => $this->template($n)?->group() === $group);
            // The option the grid has, when its files tell it too, stays: a repair never changes a choice
            if ($others !== [] && array_intersect($others, $told) === []) {
                $names = [...array_values(array_diff($names, $others)), $name];
            }
        }

        return $names;
    }

    /**
     * The options the grid says it has: the ones recorded, else the ones that are on by default and that it can have.
     *
     * @return list<string>
     */
    private function said(): array
    {
        $recorded = $this->grid->options();
        if ($recorded !== null) {
            return $this->withStandards(array_values(array_filter($recorded, fn(string $name): bool => $this->template($name) !== null)));
        }
        $names = array_keys($this->defaults($this->templates()));
        // The offline messages by default: by what its Robust says (offline())
        $default = $this->offline();
        if ($default !== null) {
            $names = array_values(array_filter($names, fn(string $n): bool => $this->template($n)?->group() !== 'offline'));
            $names[] = $default;
        }
        // A grid made before the options said how it keeps the offline messages
        $mode = $this->grid->offlineMode();
        $offline = $mode !== '' ? $this->template("offline-$mode") : null;
        if ($offline !== null && $this->unmet($offline) === null) {
            $names = array_values(array_filter($names, fn(string $n): bool => $this->template($n)?->group() !== 'offline'));
            $names[] = $offline->name();
        }

        return $this->withStandards($names);
    }

    /**
     * The options every grid has added (OptionTemplate::standard()): an option, or the one of its group, that the grid did not turn
     * off; the one of a group by default (offline(): the offline messages by what Robust says).
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function withStandards(array $names): array
    {
        $off = $this->off();
        $groups = [];
        foreach ($names as $name) {
            $group = $this->template($name)?->group() ?? '';
            $group === '' || ($groups[$group] = true);
        }
        foreach ($this->templates() as $name => $option) {
            if (!$option->standard() || in_array($name, $names, true) || $this->unmet($option) !== null) {
                continue;
            }
            $group = $option->group();
            if ($group === '') {
                in_array($name, $off, true) || ($names[] = $name);

                continue;
            }
            $members = array_filter($this->templates(), static fn(OptionTemplate $t): bool => $t->group() === $group);
            if (isset($groups[$group]) || array_intersect(array_keys($members), $off) !== [] || in_array($group, $off, true)) {
                continue;
            }
            $chosen = $group === 'offline' ? $this->offline() : (array_keys($this->defaults($members))[0] ?? null);
            if ($chosen !== null) {
                $names[] = $chosen;
                $groups[$group] = true;
            }
        }

        return $names;
    }

    /**
     * The offline messages a grid has by default: the module V1 and the helpers when its Robust gives their address
     * (`OfflineMessageURL` of `[Messaging]`: the relay by mail is V1's), else the module V2 and the service of Robust.
     */
    private function offline(): ?string
    {
        $robust = $this->grid->robustIni();
        $url = $robust !== null && is_file($robust) ? trim((string) (\W4OS\Engine\Config\ConfigReader::file($robust)['Messaging']['OfflineMessageURL'] ?? ''), " \t\"") : '';
        $helpers = $this->template('offline-helpers');
        if ($url !== '' && $helpers !== null && $this->unmet($helpers) === null) {
            return $helpers->name();
        }
        $robustModule = $this->template('offline-robust');

        return $robustModule !== null && $this->unmet($robustModule) === null ? $robustModule->name() : null;
    }

    /**
     * The options every grid has that this one turned off (`OptionsOff` of its description).
     *
     * @return list<string>
     */
    private function off(): array
    {
        $path = $this->grid->directory() . '/' . basename($this->grid->directory()) . '.conf';
        $text = is_file($path) ? (string) (\W4OS\Engine\Config\ConfigReader::file($path)['Grid']['OptionsOff'] ?? '') : '';

        return array_values(array_filter(array_map('trim', explode(',', strtolower(trim($text, " \t\""))))));
    }

    /** Say that a grid turns an option every grid has off, or on again. */
    private function recordOff(string $name, bool $off): void
    {
        $path = $this->grid->directory() . '/' . basename($this->grid->directory()) . '.conf';
        if (!is_file($path)) {
            return;
        }
        $names = array_values(array_diff($this->off(), [$name]));
        $off && ($names[] = $name);
        $ini = IniEditor::load($path);
        $names === [] ? $ini->remove('Grid', 'OptionsOff') : $ini->set('Grid', 'OptionsOff', implode(', ', $names));
        $ini->save($path);
    }

    /**
     * Which options a setup makes, from its answers: `options` says by name, the other answers by the name they have in the options
     * (`ossl: true`, `economy: gloebit`, `search: shared`), the rest is what the options are by default. An option the grid cannot
     * have is not one, and a group has one option.
     *
     * @param array<string,mixed> $answers
     * @return list<string>
     */
    public function selection(array $answers): array
    {
        $options = is_array($answers['options'] ?? null) ? $answers['options'] : [];
        // A list says which options are on, and no other; a map says the ones it names
        $exact = array_is_list($options) && array_key_exists('options', $answers)
            ? array_flip(array_map('strtolower', array_map('strval', $options)))
            : null;
        $named = $exact === null ? $options : [];
        $chosen = [];
        $explicit = [];
        foreach ($this->templates() as $name => $option) {
            $given = array_key_exists($name, $named) ? $named[$name] : null;
            $key = $option->answer();
            if ($given === null && $option->group() !== '' && is_string($answers[$key] ?? null)) {
                $given = strtolower((string) $answers[$key]) === $option->variant();
            } elseif ($given === null && array_key_exists($key, $answers) && $option->group() === '') {
                $given = $answers[$key];
            }
            $on = match (true) {
                $exact !== null => isset($exact[$name]),
                $given !== null => self::truthy($given),
                default => $option->default(),
            };
            if ($on && $this->unmet($option) === null) {
                $chosen[$name] = $option;
                ($given === null && $exact === null) || ($explicit[$name] = true);
            }
        }
        // A group that has none (the one asked for cannot be had) has the one it falls back to
        $groups = array_column(array_map(static fn(OptionTemplate $o): array => [$o->group(), true], $chosen), 1, 0);
        foreach ($this->templates() as $name => $option) {
            if ($exact === null && $option->fallback() && $option->group() !== '' && !isset($groups[$option->group()]) && $this->unmet($option) === null) {
                $chosen[$name] = $option;
            }
        }
        // One option of a group: the one that was asked for, else the first
        $byGroup = [];
        foreach ($chosen as $name => $option) {
            $held = $byGroup[$option->group()] ?? null;
            if ($option->group() !== '' && ($held === null || self::rank($option, $explicit) > self::rank($chosen[$held], $explicit))) {
                $byGroup[$option->group()] = $name;
            }
        }

        $chosen = array_filter(
            $chosen,
            static fn(OptionTemplate $option, string $name): bool => $option->group() === '' || $byGroup[$option->group()] === $name,
            ARRAY_FILTER_USE_BOTH,
        );
        uksort($chosen, static fn(string $a, string $b): int => [$chosen[$a]->order(), $a] <=> [$chosen[$b]->order(), $b]);

        return array_keys($chosen);
    }

    /**
     * What an option weighs against the others of its group: the one that was asked for, then the ones that are on by default, then
     * the one the group falls back to.
     *
     * @param array<string,bool> $explicit the options that were asked for, by name
     */
    private static function rank(OptionTemplate $option, array $explicit): int
    {
        return isset($explicit[$option->name()]) ? 2 : ($option->fallback() ? 0 : 1);
    }

    /**
     * The OpenSim.ini of the options the grid has, as one text: what a simulator of a grid that has no master says for them.
     */
    public function layer(): string
    {
        $ini = new IniEditor('');
        $variables = $this->variables();
        foreach ($this->enabled() as $name) {
            $option = $this->template($name);
            if ($option === null || !in_array('OpenSim.ini', $option->files(), true)) {
                continue;
            }
            foreach ($option->settings('OpenSim.ini', $variables, true, fn(string $word): bool => $this->fact($word, '')) as [$section, $key, $value]) {
                // A default the grid did not choose (Initial) is not laid under the simulators: it is theirs or the grid's to give
                if ($value !== '' && !$option->lists('Existing', $section, $key) && !$option->lists('Initial', $section, $key)) {
                    $ini->set($section, $key, $value);
                }
            }
        }

        return trim($ini->toString()) . "\n";
    }

    /**
     * What the options of the grid write in its files, as they write it (its constants as references): the setting each should have,
     * for the health to say what is wrong and the line to write. By file (`Robust`, `OpenSim`), section and key; the last option
     * that writes a setting gives it; what the grid chooses (`Initial`, `Existing`), an empty value and the connectors of Robust
     * (`[ServiceList]`) left out.
     *
     * @return array<string,array<string,array<string,string>>>
     */
    public function expected(): array
    {
        $variables = $this->variables();
        $fact = fn(string $word): bool => $this->fact($word, '');
        $expected = [];
        foreach ($this->enabled() as $name) {
            $option = $this->template($name);
            foreach (['Robust.ini' => 'Robust', 'OpenSim.ini' => 'OpenSim'] as $file => $role) {
                if ($option === null || !in_array($file, $option->files(), true)) {
                    continue;
                }
                foreach ($option->settings($file, $variables, true, $fact) as [$section, $key, $value]) {
                    if (trim($value, " \"") === '' || strcasecmp($section, 'ServiceList') === 0 || $option->lists('Initial', $section, $key) || $option->lists('Existing', $section, $key)) {
                        continue;
                    }
                    $expected[$role][$section][$key] = $value;
                }
            }
        }

        return $expected;
    }

    /**
     * The options of the grid a config does not have: the ones of OpenSim.ini of which a setting is not in it (a section that is
     * there without it too: a [Gloebit] without its environment), its settings as read (the master of the grid and the file of
     * the simulator). The settings written only where they already are (`Existing`) are not asked.
     *
     * @param array<string,array<string,mixed>> $settings by section
     * @return list<string>
     */
    public function missingIn(array $settings): array
    {
        $sections = array_map(static fn($keys): array => is_array($keys) ? array_change_key_case($keys) : [], array_change_key_case($settings));
        $said = array_map(static fn(array $keys): array => array_map(static fn($v): string => strtolower(trim((string) $v, " \"")), $keys), $sections);
        $variables = $this->variables();
        $missing = [];
        foreach (array_values(array_unique([...$this->enabled(), ...$this->inUse()])) as $name) {
            $option = $this->template($name);
            // A config that chose another option of its group keeps its choice: a repair never changes a setting
            if ($option === null || !in_array('OpenSim.ini', $option->files(), true) || $this->choseOther($option, $said)) {
                continue;
            }
            foreach ($option->settings('OpenSim.ini', $variables, true, fn(string $word): bool => $this->fact($word, '')) as [$section, $key, $value]) {
                if ($value !== '' && !$option->lists('Existing', $section, $key) && !array_key_exists(strtolower($key), $sections[strtolower($section)] ?? [])) {
                    $missing[] = $name;

                    break;
                }
            }
        }

        return $missing;
    }

    /**
     * The options the grid uses without having them recorded (a grid made before its options were, or by hand): the ones of which
     * the OpenSim.ini of the grid has a setting that tells them, as the option writes it (one of their own, not a value of the
     * grid, nor `Initial` or `Existing`, nor a mere true or false: `[Economy] economymodule = Gloebit`, `[Search] Module =
     * OpenSimSearch`), that it can have (not unmet), and of a group it has no other option of.
     *
     * @return list<string>
     */
    public function inUse(): array
    {
        $enabled = $this->enabled();
        $groups = array_filter(array_map(fn(string $name): string => $this->template($name)?->group() ?? '', $enabled));
        $used = [];
        foreach ($this->told() as $name) {
            $group = $this->template($name)?->group() ?? '';
            if (in_array($name, $enabled, true) || ($group !== '' && in_array($group, $groups, true))) {
                continue;
            }
            $used[] = $name;
            $group === '' || ($groups[] = $group);
        }

        return $used;
    }

    /**
     * The options the OpenSim.ini of the grid tells, whatever it records: the ones of which it has a setting as the option writes it
     * (see inUse()), that it can have or whose files give what it takes from the grid.
     *
     * @return list<string>
     */
    private function told(): array
    {
        $this->telling = true;
        try {
            return $this->tell();
        } finally {
            $this->telling = false;
        }
    }

    /**
     * The options other files tell, as the OpenSim.ini of the grid tells them (told()): the config of a simulator of another install,
     * with the files it includes, the choice it made in a group (the offline messages V1 and their helper elsewhere).
     *
     * @param list<string> $files
     * @return list<string>
     */
    public function toldBy(array $files): array
    {
        $this->telling = true;
        try {
            return $this->tell($files);
        } finally {
            $this->telling = false;
        }
    }

    /**
     * The settings an option asks its administrator for (Asks) that files do not give, with the files they include: an option
     * they tell without them is not set up there (Gloebit without its keys).
     *
     * @param list<string> $files
     * @return list<string> `Section/Key`
     */
    public function unanswered(string $name, array $files): array
    {
        $option = $this->template($name);
        if ($option === null || $option->asks() === []) {
            return [];
        }
        $given = [];
        foreach ($files as $file) {
            foreach (array_change_key_case(\W4OS\Engine\Config\ConfigReader::load($file, $this->gridConstants(), expand: false)) as $section => $keys) {
                is_array($keys) && ($given[$section] = array_merge($given[$section] ?? [], array_change_key_case($keys)));
            }
        }

        return array_values(array_filter($option->asks(), static function (string $pair) use ($given): bool {
            [$section, $key] = array_pad(explode('/', $pair, 2), 2, '');

            return trim((string) ($given[strtolower($section)][strtolower($key)] ?? ''), " \t\"") === '';
        }));
    }

    /**
     * @param ?list<string> $files the files read, the ones of the grid without them
     * @return list<string> told()
     */
    private function tell(?array $files = null): array
    {
        $said = [];
        foreach ($files ?? $this->gridFiles() as $file) {
            foreach (array_change_key_case(\W4OS\Engine\Config\MovedSettings::normalize(\W4OS\Engine\Config\ConfigReader::load($file, $this->gridConstants(), expand: false))) as $section => $keys) {
                is_array($keys) && ($said[$section] = array_merge($said[$section] ?? [], array_change_key_case(array_map(static fn($v): string => strtolower(trim((string) $v, " \"")), $keys))));
            }
        }
        if ($said === []) {
            return [];
        }
        $used = [];
        foreach ($this->templates() as $name => $option) {
            if (in_array('OpenSim.ini', $option->files(), true) === false) {
                continue;
            }
            // An option the grid cannot have here (the search without helpers on this machine) is in use when its files give what the
            // option would take from the grid (the address of the search)
            if ($this->unmet($option) !== null && !$this->givenByGrid($option, $said)) {
                continue;
            }
            $this->says($option, $said) && ($used[] = $name);
        }

        return $used;
    }

    /**
     * Whether settings say an option, as it writes them: one of its own settings that tells it (not a value of the grid, nor
     * `Initial` or `Existing`, nor a mere true or false: `[Messaging] OfflineMessageModule = OfflineMessageModule`), in the files of
     * the simulators (OpenSim.ini, and a file of the grid they include: the Gloebit.ini of Gloebit), not of Robust.
     *
     * @param array<string,array<string,string>> $said by section then key, in lower case, the values in lower case without quotes
     */
    private function says(OptionTemplate $option, array $said): bool
    {
        // All of the settings it names, as it writes them (Told): a module the simulators loaded, not a setting of it alone
        if ($option->told() !== []) {
            foreach ($option->told() as $pair) {
                [$section, $key] = array_pad(explode('/', $pair, 2), 2, '');
                $value = null;
                foreach ($option->files() as $file) {
                    foreach (preg_match('/^Robust/i', basename($file)) === 1 ? [] : $option->settings($file, [], true) as [$s, $k, $v]) {
                        strcasecmp($s, $section) === 0 && strcasecmp($k, $key) === 0 && ($value = strtolower(trim($v, " \"")));
                    }
                }
                if ($value === null || ($said[strtolower($section)][strtolower($key)] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }
        // What another option of its group writes the same way does not tell this one (the module of the groups, V2, is all of theirs)
        $shared = [];
        foreach ($option->group() === '' ? [] : $this->templates() as $name => $other) {
            if ($name !== $option->name() && $other->group() === $option->group()) {
                foreach (self::telling($other) as [$section, $key, $value]) {
                    $shared[strtolower("$section/$key") . "=$value"] = true;
                }
            }
        }
        foreach (self::telling($option) as [$section, $key, $value]) {
            if (!isset($shared[strtolower("$section/$key") . "=$value"]) && ($said[strtolower($section)][strtolower($key)] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The settings of an option that can tell it, as it writes them, the values in lower case without quotes: its own, in the files
     * of the simulators, not a value of the grid, nor `Initial` or `Existing`, nor a mere true or false, nor an include.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private static function telling(OptionTemplate $option): array
    {
        $telling = [];
        foreach ($option->files() as $file) {
            if (preg_match('/\.ini$/i', $file) !== 1 || preg_match('/^Robust/i', basename($file)) === 1) {
                continue;
            }
            foreach ($option->settings($file, [], true) as [$section, $key, $value]) {
                $value = strtolower(trim($value, " \""));
                if (in_array($value, ['', 'true', 'false'], true) || str_contains($value, '{{') || stripos($key, 'Include-') === 0 || $option->lists('Existing', $section, $key) || $option->lists('Initial', $section, $key)) {
                    continue;
                }
                $telling[] = [$section, $key, $value];
            }
        }

        return $telling;
    }

    /**
     * Whether a config has chosen another option of the group of an option (the offline messages V1 where V2 is the default): its
     * choice, the option is not missing there.
     *
     * @param array<string,array<string,string>> $said see says()
     */
    private function choseOther(OptionTemplate $option, array $said): bool
    {
        if ($option->group() === '' || $this->says($option, $said)) {
            return false;
        }
        foreach ($this->templates() as $name => $other) {
            if ($name !== $option->name() && $other->group() === $option->group() && $this->says($other, $said)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The options of the grid its simulators of this machine miss (a simulator made before an option became a preset, by an older
     * engine, or by hand), in what they run with at their next start (effective(): for a grid with a master, what the master holds
     * of the options and of the OpenSim.ini of the grid, never copied in a simulator): planned, then laid again in the files of the simulators that miss them with
     * `$apply`, with the values of the grid: what the option says (its module, its addresses), else what the grid has (its
     * OpenSim.ini, else the file of a simulator of the grid that has the option: its keys too, Gloebit's), never a default the
     * grid did not choose (`Initial`: named in the notes, to be given). Nothing said of it has a value: only the names of the
     * settings, a secret never leaves the files. A repair never changes a setting: a value that is there stays, and a simulator that
     * chose another option of a group (the offline messages V1 where V2 is the default) keeps its choice, nothing of the default
     * laid beside it; changing a setting is its operator's.
     *
     * @return array{simulators:array<string,list<string>>,applied:list<string>,notes:list<string>} the options each simulator
     *         misses (none: nothing to repair), the ones laid again, what is left to do (a setting to give, a restart)
     */
    public function repair(bool $apply): array
    {
        return $apply ? \W4OS\Engine\Models\AddressHistory::around($this->grid, fn(): array => $this->repaired(true)) : $this->repaired(false);
    }

    /** @return array<string,mixed> see repair() */
    private function repaired(bool $apply): array
    {
        $missing = [];
        $merged = [];
        foreach ($this->grid->simulators() as $simulator) {
            $settings = $this->effective($simulator->settings());
            $lacks = $this->missingIn($settings);
            $lacks === [] || ($missing[$simulator->slug()] = $lacks) && ($merged[$simulator->slug()] = $settings);
        }
        $applied = [];
        $notes = [];
        if ($apply && $missing !== []) {
            $files = [];
            foreach ($this->simulators() as $path) {
                $files[basename($path, '.ini')] = $path;
            }
            foreach ($missing as $slug => $names) {
                $path = $files[$slug] ?? null;
                if ($path === null) {
                    $notes[] = "$slug: its file is not on this machine, the options are not laid";

                    continue;
                }
                foreach ($names as $name) {
                    $unknown = [];
                    if ($this->fill($path, $name, $merged[$slug], $this->gridValues($name, array_keys(array_filter($missing, static fn(array $lacks): bool => in_array($name, $lacks, true))), $files), $unknown)) {
                        $applied[] = $name;
                    }
                    foreach ($unknown as $setting) {
                        $notes[] = "$slug: $name needs $setting, which the grid does not say (it is not invented): bring the settings of the grid from its other install, opensim import --grid-settings <its OpenSim.ini> {$this->grid->nick()}";
                    }
                }
            }
            $applied = array_values(array_unique($applied));
            if ($applied !== []) {
                // The grid has them now: the master, made again, holds them for the simulators that take it
                $result = self::RESULT;
                $this->record(array_values(array_unique([...$this->enabled(), ...$applied])));
                $this->finish($result);
                foreach ($applied as $name) {
                    array_push($notes, ...$this->notes($this->template($name)));
                }
                $notes[] = 'the simulators take them at their next start (opensim restart)';
            }
        }

        return ['simulators' => $missing, 'applied' => $applied, 'notes' => array_values(array_unique($notes))];
    }

    /**
     * The settings a simulator runs with at its next start: the ones it reads now (the master and its file) and, for a grid with a
     * master, what the master made again holds: the options of the grid (layer()), then its OpenSim.ini (SimulatorMaster). A setting
     * the grid gives there is not missing: it is never copied in the file of a simulator.
     *
     * @param array<string,array<string,mixed>> $settings as the simulator reads them
     * @return array<string,array<string,mixed>>
     */
    private function effective(array $settings): array
    {
        if (!$this->layered()) {
            return $settings;
        }
        foreach ([\W4OS\Engine\Config\ConfigReader::parse($this->layer()), \W4OS\Engine\Config\ConfigReader::load($this->gridFiles(), $this->gridConstants(), expand: false)] as $layer) {
            $names = array_change_key_case(array_combine(array_keys($settings), array_keys($settings)));
            foreach ($layer as $section => $keys) {
                $name = $names[strtolower((string) $section)] ?? $section;
                is_array($keys) && ($settings[$name] = (is_array($settings[$name] ?? null) ? $settings[$name] : []) + $keys);
            }
        }

        return $settings;
    }

    /**
     * What the grid says of the sections of an option: its OpenSim.ini, then the files of its simulators that do not miss it, the
     * first that says a setting.
     *
     * @param list<string> $lacking the simulators that miss the option (none of them gives a value)
     * @param array<string,string> $files the files of the simulators, by slug
     * @return array<string,array<string,string>> by section (lower case), then key (lower case): [key as written, value]
     */
    private function gridValues(string $name, array $lacking, array $files): array
    {
        $option = $this->template($name);
        if ($option === null) {
            return [];
        }
        $sections = array_unique(array_map(static fn(array $s): string => strtolower($s[0]), $option->settings('OpenSim.ini', $this->variables(), true)));
        $sources = [...$this->gridFiles(), ...array_values(array_diff_key($files, array_flip($lacking)))];
        $values = [];
        foreach ($sources as $source) {
            if (!is_file($source)) {
                continue;
            }
            foreach (array_change_key_case(\W4OS\Engine\Config\ConfigReader::load($source, $this->gridConstants(), expand: false)) as $section => $keys) {
                if (!in_array($section, $sections, true) || !is_array($keys)) {
                    continue;
                }
                foreach ($keys as $key => $value) {
                    $values[$section][strtolower((string) $key)] ??= [(string) $key, (string) $value];
                }
            }
        }

        return $values;
    }

    /**
     * The files of the grid that say what its simulators share: its OpenSim.ini (the one of its preset when it is joined by one,
     * and its own), with the files they include.
     *
     * @return list<string>
     */
    private function gridFiles(): array
    {
        $own = rtrim($this->grid->directory(), '/') . '/OpenSim.ini';

        return array_values(array_unique(array_filter([$own, $this->general()], 'is_file')));
    }

    /**
     * The constants a file of the grid is read with, for the files it includes (`${Const|EtcDirectory}/Gloebit.ini`): those the
     * master of its simulators gives, the folder of the grid.
     *
     * @return array<string,string>
     */
    private function gridConstants(): array
    {
        return ['EtcDirectory' => rtrim($this->grid->directory(), '/')];
    }

    /**
     * Whether the files of the grid give the settings an option takes from the grid (the ones whose value is a variable of the grid:
     * the address of a service), those whose name is a variable left aside.
     *
     * @param array<string,array<string,string>> $said the settings of the files of the grid, in lower case
     */
    private function givenByGrid(OptionTemplate $option, array $said): bool
    {
        $needed = array_filter(
            $option->settings('OpenSim.ini', [], false),
            static fn(array $s): bool => str_contains($s[2], '{{') && $s[2] !== '{{off}}',
        );
        foreach ($needed as [$section, $key]) {
            if (!array_key_exists(strtolower($key), $said[strtolower($section)] ?? [])) {
                return false;
            }
        }

        return $needed !== [];
    }

    /**
     * Lay in the file of a simulator the settings of an option it misses: the option's own (its module), else the grid's, else
     * what the option makes of the grid (its addresses), never an `Initial` default; and the other settings the grid has in the
     * sections of the option (keys, owner, storage), when the simulator has none.
     *
     * @param array<string,array<string,mixed>> $settings the settings of the simulator as read (the master included)
     * @param array<string,array<string,array{0:string,1:string}>> $grid gridValues()
     * @param list<string> $unknown the settings the grid does not say, `Section/key`
     * @return bool whether something was written
     */
    private function fill(string $path, string $name, array $settings, array $grid, array &$unknown): bool
    {
        $option = $this->template($name);
        if ($option === null) {
            return false;
        }
        $has = array_map(static fn($keys): array => is_array($keys) ? array_change_key_case($keys) : [], array_change_key_case($settings));
        $literal = [];
        foreach ($option->settings('OpenSim.ini', [], true) as [$section, $key]) {
            $literal[strtolower("$section/$key")] = true;
        }
        $quote = static fn(string $value): string => preg_match('/^[A-Za-z0-9_.+-]*$/', $value) === 1 ? $value : '"' . str_replace('"', '', $value) . '"';
        $writes = [];
        foreach ($option->settings('OpenSim.ini', $this->variables(), true, fn(string $word): bool => $this->fact($word, '')) as [$section, $key, $value]) {
            [$s, $k] = [strtolower($section), strtolower($key)];
            if ($option->lists('Existing', $section, $key) || array_key_exists($k, $has[$s] ?? [])) {
                continue;
            }
            $initial = $option->lists('Initial', $section, $key);
            $said = $grid[$s][$k][1] ?? null;
            $chosen = match (true) {
                isset($literal["$s/$k"]) && !$initial => $value,
                $said !== null => $quote($said),
                !$initial && $value !== '' => $value,
                default => null,
            };
            $chosen === null ? ($unknown[] = "$section/$key") : ($writes[] = [$section, $key, $chosen]);
            $has[$s][$k] = true;
        }
        // An option set in a file of the grid the simulators include (Gloebit.ini) has its settings there only: the simulator gets
        // the include, never a copy; the file is the grid's to have
        $includes = array_filter($option->settings('OpenSim.ini', $this->variables(), true), static fn(array $s): bool => stripos($s[1], 'Include-') === 0);
        foreach ($includes as [, , $value]) {
            $file = str_ireplace('${Const|EtcDirectory}', rtrim($this->grid->directory(), '/'), trim($value, '"'));
            is_file($file) || ($unknown[] = basename($file) . ' of the grid (its settings, in one place)');
        }
        // The rest of what the grid says in the sections of the option: its keys, its owner, its storage
        foreach ($includes !== [] ? [] : $grid as $s => $keys) {
            foreach ($keys as $k => [$key, $value]) {
                if (!array_key_exists($k, $has[$s] ?? [])) {
                    $section = $this->sectionName($option, $s);
                    $writes[] = [$section, $key, $quote($value)];
                    $has[$s][$k] = true;
                }
            }
        }
        if ($writes === []) {
            return false;
        }

        return $this->edit($path, static function (IniEditor $ini) use ($writes): void {
            foreach ($writes as [$section, $key, $value]) {
                $ini->set($section, $key, $value);
            }
        });
    }

    /** The name of a section as an option writes it, from its name in lower case. */
    private function sectionName(OptionTemplate $option, string $lower): string
    {
        foreach ($option->settings('OpenSim.ini', [], false) as [$section]) {
            if (strtolower($section) === $lower) {
                return $section;
            }
        }

        return $lower;
    }

    /**
     * Enable an option of the grid.
     *
     * @param  array{prefix?:string} $params the address of the services of the helpers (`<prefix>/query.php`) for an option that
     *                                      asks them from another place than the grid's
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>}
     */
    public function enable(string $name, array $params = []): array
    {
        $result = self::RESULT;
        // An option by its name, or by its group (groups: the one the grid has, else the one by default)
        $option = $this->template($name) ?? $this->resolve($name);
        $prefix = rtrim(trim($params['prefix'] ?? ''), '/');
        if ($option === null) {
            $result['error'] = "unknown option '$name' (" . implode(', ', array_keys($this->templates())) . ')';

            return $result;
        }
        if ($prefix !== '' && Grid::validateWebUrl($prefix) !== null) {
            $result['error'] = "'$prefix' is not an address, give the start of the address of the services (https://www.example.org/helpers)";

            return $result;
        }
        if (($unmet = $this->unmet($option, $prefix)) !== null) {
            $result['error'] = $option->needs() !== ''
                ? str_replace('{nick}', $this->grid->nick(), $option->needs())
                : "the grid {$this->grid->nick()} cannot have {$option->label()}: " . ($unmet[0] === '!' ? 'it must not be ' . substr($unmet, 1) : "it needs $unmet");

            return $result;
        }
        // One at a time in a group
        $others = [];
        if ($option->group() !== '') {
            foreach ($this->enabled() as $other) {
                if ($other !== $option->name() && $this->template($other)?->group() === $option->group()) {
                    $others[] = $other;
                    $this->lay($this->template($other), false, $this->variables(), $result);
                }
            }
        }
        $this->lay($option, true, $this->variables($prefix, $option), $result);
        // On again, it and the others of its group
        foreach ($option->standard() ? array_keys(array_filter($this->templates(), static fn(OptionTemplate $t): bool => $t->name() === $option->name() || ($option->group() !== '' && $t->group() === $option->group()))) : [] as $member) {
            $this->recordOff($member, false);
        }
        // Recorded first: the master, made again by finish(), holds the options the grid has now
        $this->record(array_values(array_unique([...array_diff($this->enabled(), $others), $option->name()])));
        $this->finish($result);
        array_push($result['notes'], ...$this->notes($option));

        return $result;
    }

    /**
     * Disable an option of the grid.
     *
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>}
     */
    public function disable(string $name): array
    {
        $result = self::RESULT;
        $option = $this->template($name) ?? $this->resolve($name);
        if ($option === null) {
            $result['error'] = "unknown option '$name' (" . implode(', ', array_keys($this->templates())) . ')';

            return $result;
        }
        $this->lay($option, false, $this->variables(), $result);
        // An option every grid has stays off only when the grid says so
        $option->standard() && $this->recordOff($option->name(), true);
        $this->record(array_values(array_diff($this->enabled(), [$option->name()])));
        $this->finish($result);

        return $result;
    }

    /**
     * The OpenSim.ini that holds what the grid decides for its simulators: the one of its folder, or the one of its preset for a grid
     * joined by a preset (what applies to all its simulators is set there).
     */
    public function general(): string
    {
        return $this->root() . '/OpenSim.ini';
    }

    /**
     * The simulators of the grid made on this machine: the name of the instance and its ini file.
     *
     * @return array<string,string> instance => path
     */
    public function simulators(): array
    {
        $simulators = [];
        $files = glob(GridConfig::simulatorsDirectory($this->grid->directory()) . '/*.ini') ?: [];
        sort($files);
        foreach ($files as $file) {
            $simulators[basename($file, '.ini')] = $file;
        }

        return $simulators;
    }

    /** The folder that holds the files of the grid: its own, or the one of its preset when it joined a grid by one. */
    private function root(): string
    {
        return $this->grid->preset() !== ''
            ? SimulatorMaster::presetDir($this->grid->directory()) ?? $this->grid->directory()
            : $this->grid->directory();
    }

    /**
     * Whether the grid is, or has, something an option requires.
     *
     * @param string $word standalone, hypergrid, helpers, connector:<Name>, service:<name of a service of the helpers>
     */
    private function fact(string $word, string $prefix): bool
    {
        return match (true) {
            $word === 'standalone' => $this->grid->isStandalone(),
            $word === 'hypergrid' => $this->grid->isHypergrid(),
            $word === 'helpers' => $prefix !== '' || $this->grid->hasHelpers(),
            $word === 'master' => $this->layered(),
            // Its simulators can write their map tile, in their cache when the core reads MapTilesDirectory (MapRenderer)
            $word === 'warp3d' => MapRenderer::whyNotWarp3D(
                $core = $this->grid->coreDirectory() ?: $this->grid->environment()->layout->coreDirectory(),
                MapRenderer::hasFolderSetting($core) ? $this->grid->cacheDirectory() : '',
                $this->grid->environment()->layout->systemUser(),
            ) === null,
            str_starts_with($word, 'service:') => $prefix !== '' || $this->grid->helperUrl(substr($word, 8)) !== '',
            str_starts_with($word, 'connector:') => in_array(
                strtolower(substr($word, 10)),
                array_map('strtolower', $this->grid->connectors()),
                true,
            ),
            // A word that is the name of a variable holds when it has something to say
            default => !in_array($this->variables($prefix)[$word] ?? '', ['', 'false', '0'], true),
        };
    }

    /**
     * The options that are on by default and that the grid can have, one for each group (the first).
     *
     * @param  array<string,OptionTemplate> $from
     * @return array<string,OptionTemplate>
     */
    private function defaults(array $from): array
    {
        $taken = [];
        $defaults = [];
        // The one a group falls back to comes after the others
        uasort($from, static fn(OptionTemplate $a, OptionTemplate $b): int => $a->fallback() <=> $b->fallback());
        foreach ($from as $name => $option) {
            if (!$option->default() || $this->unmet($option) !== null || isset($taken[$option->group()])) {
                continue;
            }
            $defaults[$name] = $option;
            $option->group() === '' || ($taken[$option->group()] = true);
        }
        uksort($defaults, static fn(string $a, string $b): int => [$from[$a]->order(), $a] <=> [$from[$b]->order(), $b]);

        return $defaults;
    }

    /**
     * What an option tells of the grid, to put in its files: its name, its data service of the search, the addresses of the helpers.
     *
     * @return array<string,string>
     */
    private function variables(string $prefix = '', ?OptionTemplate $also = null): array
    {
        $url = fn(string $service): string => $prefix !== '' && isset(Services::SCRIPTS[$service])
            ? "$prefix/" . Services::SCRIPTS[$service]
            : $this->grid->helperUrl($service);
        $economy = $this->servicesFile();
        $current = $economy !== null && is_file($economy) ? IniEditor::load($economy)->get('GridInfoService', 'economy') : null;
        $currency = $this->grid->helperUrl('currency');
        $variables = $this->extra + [
            'grid_name' => $this->grid->name(),
            'grid_nick' => $this->grid->nick(),
            'grid_dir' => $this->grid->directory(),
            // Where the files of the grid an option sets are (the folder of its preset, for a grid joined by one)
            'config_dir' => $this->root(),
            'data_service' => SimulatorConfig::dataServiceNameOf($this->grid->nick(), $this->grid->name()),
            // The address of the economy the grid tells the viewers: the one given, the one it tells, else where its helpers are
            'economy_url' => $prefix !== '' ? $prefix : (($current ?? '') !== '' ? (string) $current : ($currency === '' ? '' : dirname($currency))),
            // Robust answers the simulators of a grid; a standalone keeps what Robust keeps in its own process
            'robust_url' => $this->grid->isStandalone() ? '' : '${Const|PrivURL}:${Const|PrivatePort}',
        ];
        // The services and the pages of the helpers: their address, their path on the site of the grid (none when its search is elsewhere)
        foreach ([...array_keys(Services::SCRIPTS), ...array_keys(Services::PAGES)] as $service) {
            $variables["url_$service"] = $url($service);
            $variables["page_$service"] = $prefix !== '' && $service === 'search' ? '' : $this->pagePath($service);
        }
        // The registers of the search: the grid's own (its helpers) and 2do.directory's, unless the setup chose one alone (register)
        $register = $this->extra['register'] ?? 'both';
        $own = $this->grid->isPlanned() && $this->grid->hasHelpers()
            ? (new Services($this->grid->helpersPath()))->url($this->grid->webUrl(), 'register')
            : $variables['url_register'];
        $variables['register_own'] = $register === '2do' ? '' : $own;
        $variables['register_2do'] = $register === 'helpers' ? '' : Grid::SHARED_SEARCH . '/register.php';
        // The web search the viewers open: the page of the helpers, 2do.directory's, or none (the viewers keep their own)
        $variables['web_search_url'] = match ($this->extra['web_search'] ?? 'internal') {
            'shared' => Grid::SHARED_WEB_SEARCH,
            'none' => '',
            default => $variables['page_search'] === '' ? '' : '${Const|WebURL}' . $variables['page_search'],
        };
        // Where the helpers are on the site: the path of their economy
        $base = dirname($variables['page_currency']);
        $variables['helpers_base'] = $variables['page_currency'] === '' ? '' : ($base === '.' ? '/' : $base);
        // The paths of the site that are the helpers' (the first segment of the base and of each page, and the addresses of their
        // services the grid names elsewhere on its site), for the web server to hand over (Snippets::routes())
        $routes = Snippets::routes(
            (string) $variables['helpers_base'],
            [...array_map(fn(string $key): string => (string) $variables[$key], preg_grep('/^page_/', array_keys($variables))), ...($this->grid->isPlanned() ? [] : array_keys($this->grid->helperPaths()))],
        );
        $variables['web_globs'] = implode(' ', $routes['globs']);
        $variables['web_regex'] = implode('|', $routes['regex']);
        // The economy the grid gives its viewers: the helpers at their path, a path of the site the setup gave, or the address of
        // another service, which the helpers do not serve (EconomyProvided); a grid of before keeps the helpers' path
        $economy = trim((string) ($this->extra['economy_path'] ?? ''));
        $variables['economy_address'] = match (true) {
            str_contains($economy, '://') => $economy,
            $economy !== '' => '${Const|WebURL}' . Services::normalize($economy),
            default => '${Const|WebURL}' . $variables['helpers_base'],
        };
        $variables['economy_provided'] = !array_key_exists('economy_path', $this->extra) ? ''
            : (str_contains($economy, '://') && strcasecmp((string) parse_url($economy, PHP_URL_HOST), (string) parse_url($this->grid->webUrl(), PHP_URL_HOST)) !== 0 ? 'false' : 'true');
        // The site of the grid, and what the options that are on give to the others
        $variables += Snippets::siteVariables($this->grid->webUrl());
        foreach ([...$this->enabled(), ...($also === null ? [] : [$also->name()])] as $name) {
            $variables += $this->template($name)?->variables() ?? [];
        }

        return $variables;
    }

    /** The path of a page of the helpers on the site of the grid, empty when the grid has no helpers. */
    private function pagePath(string $page): string
    {
        return (string) (parse_url($this->grid->helperUrl($page), PHP_URL_PATH) ?? '');
    }

    /**
     * Lay an option over the files of the grid, or take it off them.
     *
     * @param array<string,string> $variables
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} $result
     */
    private function lay(OptionTemplate $option, bool $on, array $variables, array &$result): void
    {
        $fact = fn(string $word): bool => $this->fact($word, '');
        foreach ($option->files() as $file) {
            $simulators = $file === 'OpenSim.ini';
            $services = preg_match('/^Robust(\.HG)?\.ini$/i', $file) === 1;
            $path = null;
            if (!$simulators && !$services) {
                // A file of the grid: the preset's own when the grid has none, an ini the grid has is merged, any other is the grid's
                $name = $option->path($file, $variables);
                if ($name === null) {
                    continue;
                }
                $path = $this->root() . "/$name";
                if (!is_file($path)) {
                    $on && $this->copy($option, $file, $path, $variables, $fact, $result);

                    continue;
                }
                if (preg_match('/\.ini$/i', $file) !== 1) {
                    continue;
                }
            }
            $settings = $option->settings($file, $variables, $on, $fact);
            // A page the site of the grid declared is its own: the options never lay theirs over it
            if ($services && ($declared = \W4OS\Engine\Provisioning\Web\SitePages::declaredKeys($this->grid)) !== []) {
                $settings = array_values(array_filter($settings, static fn(array $s): bool => !in_array(strtolower("$s[0]|$s[1]"), $declared, true)));
            }
            // What the grid has no value for, and the option says to take off when it has none
            $cleared = $on
                ? array_values(array_filter(
                    array_udiff(
                        $option->settings($file, $variables, false, $fact),
                        $settings,
                        static fn(array $a, array $b): int => strcasecmp("$a[0]/$a[1]", "$b[0]/$b[1]"),
                    ),
                    static fn(array $setting): bool => $option->lists('Clear', $setting[0], $setting[1]),
                ))
                : [];
            if ($settings === [] && $cleared === []) {
                continue;
            }
            $change = function (IniEditor $ini, bool $general) use ($option, $settings, $cleared, $on, $file): void {
                $only = $file === 'OpenSim.ini' && ($this->layered() ? !$general : $general);
                foreach ($settings as [$section, $key, $value]) {
                    $on
                        ? self::put($ini, $option, $section, $key, $value, $only)
                        : self::take($ini, $option, $section, $key);
                }
                foreach ($cleared as [$section, $key]) {
                    self::take($ini, $option, $section, $key);
                }
            };
            if ($simulators) {
                $this->eachSimulatorFile($result, $change);
            } elseif ($services) {
                $result['robust'] = $this->robust(fn(IniEditor $ini) => $change($ini, false), $result) || $result['robust'];
            } else {
                $this->edit($path, fn(IniEditor $ini) => $change($ini, false)) && ($result['files'][] = $path);
            }
        }
    }

    /**
     * A file of an option that the grid does not have: the preset's, with the variables put in. A file that is not an ini is public.
     *
     * @param array<string,string> $variables
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} $result
     */
    private function copy(OptionTemplate $option, string $file, string $path, array $variables, callable $fact, array &$result): void
    {
        is_dir(dirname($path)) || @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, $option->text($file, $variables, $fact));
        preg_match('/\.ini$/i', $path) === 1 || @chmod($path, 0o644);
        $result['files'][] = $path;
    }

    /**
     * Lay the settings of a file of a preset over an ini, with the rules the preset says for them.
     *
     * @param array<string,string> $variables
     * @param ?callable(string):bool $fact whether a word of a block holds; none: every block applies
     */
    public static function layText(IniEditor $ini, OptionTemplate $option, string $file, array $variables, ?callable $fact = null): void
    {
        foreach ($option->settings($file, $variables, true, $fact) as [$section, $key, $value]) {
            self::put($ini, $option, $section, $key, $value, false);
        }
    }

    /** Set a setting of an option in a file, with the rules the option says for it. */
    private static function put(IniEditor $ini, OptionTemplate $option, string $section, string $key, string $value, bool $only): void
    {
        if ($option->lists('Existing', $section, $key) && !$ini->mentions($section, $key)) {
            return;
        }
        if ($option->lists('Initial', $section, $key) && ($ini->get($section, $key) ?? '') !== '') {
            return;
        }
        // The file does not have what the grid decides, or has it as a copy of what the simulators say
        if ($only && !$ini->mentions($section, $key)) {
            return;
        }
        match ($value) {
            '' => $ini->uncomment($key),
            '{{off}}' => $ini->comment($section, $key),
            default => $ini->set($section, $key, $value),
        };
    }

    /** Take a setting of an option off a file: commented out with its value. */
    private static function take(IniEditor $ini, OptionTemplate $option, string $section, string $key): void
    {
        if ($option->lists('Existing', $section, $key) || $option->lists('Keep', $section, $key)) {
            return;
        }
        $ini->comment($section, $key);
    }

    /**
     * The master of the simulators follows the OpenSim.ini of the grid, where a grid that has one keeps what it decides.
     *
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} $result
     */
    private function finish(array $result): void
    {
        if ($result['error'] === null && $this->layered === null && $this->layered() && $this->grid->coreDirectory() !== '') {
            (new SimulatorMaster($this->grid->environment()))->ensure($this->grid->directory(), $this->grid->coreDirectory() . '/bin');
        }
    }

    /** Whether the grid has a master for its simulators: what it decides is in its OpenSim.ini, its simulators say what is their own. */
    private function layered(): bool
    {
        return $this->layered ?? SimulatorMaster::exists($this->grid->directory());
    }

    /**
     * Edit the ini file of each simulator of the grid and the OpenSim.ini of the grid, and keep which ones changed.
     *
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} $result
     * @param callable(IniEditor,bool):void $edit given the file and whether it is the OpenSim.ini of the grid
     */
    private function eachSimulatorFile(array &$result, callable $edit): void
    {
        $general = $this->general();
        if (is_file($general) && $this->edit($general, fn(IniEditor $ini) => $edit($ini, true))) {
            $result['grid'] = true;
        }
        foreach ($this->simulators() as $instance => $file) {
            if ($this->edit($file, fn(IniEditor $ini) => $edit($ini, false))) {
                $result['simulators'][] = $instance;
            }
        }
    }

    /**
     * Edit the Robust config of the grid, when it is on this machine.
     *
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} $result
     */
    private function robust(callable $edit, array &$result): bool
    {
        $ini = $this->servicesFile();
        if ($this->grid->isRemote() || $ini === null || !is_file($ini)) {
            $note = "Robust of {$this->grid->nick()} is not on this machine: the same change is to make in its config there";
            in_array($note, $result['notes'], true) || ($result['notes'][] = $note);

            return false;
        }

        return $this->edit($ini, $edit);
    }

    /** The config of the services of the grid: its Robust, or the StandaloneCommon.ini of the folder of a standalone, which has none. */
    private function servicesFile(): ?string
    {
        return $this->grid->isStandalone()
            ? $this->grid->directory() . '/config-include/StandaloneCommon.ini'
            : $this->grid->robustIni();
    }

    /** Run an edit on an ini file and save it when it changed. */
    private function edit(string $path, callable $edit): bool
    {
        $ini = IniEditor::load($path);
        $before = $ini->toString();
        $edit($ini);
        if ($ini->toString() === $before) {
            return false;
        }
        $ini->save($path);

        return true;
    }

    /**
     * What the administrator must know once an option is on: its modules that are not enabled for the install or not installed, and
     * the settings it asks for that nobody gave.
     *
     * @return list<string>
     */
    private function notes(OptionTemplate $option): array
    {
        $notes = [];
        $layout = $this->grid->environment()->layout;
        $core = basename($layout->coreDirectory());
        foreach ($option->modules() as $module) {
            if (!in_array($module, $layout->enabledModules(), true)) {
                $notes[] = "the module $module is not in EnabledModules of w4os.conf, the simulators do not load it";
            }
            if ($core !== '' && !is_dir($layout->modulesRoot() . "/$core/$module")) {
                $notes[] = "the module $module is not installed for $core (apt install opensim-$core-$module)";
            }
        }
        foreach ($option->paths() as $path) {
            file_exists($path) ||
                ($notes[] = $option->pathNote() !== '' ? $option->pathNote() : "$path is not there, {$option->label()} needs it");
        }
        $missing = $this->missing($option);
        if ($missing !== []) {
            $notes[] = str_replace(
                '{keys}',
                implode(', ', $missing),
                $option->note() !== '' ? $option->note() : "{$option->label()}: give {keys} in the OpenSim.ini of the grid or of the simulators",
            );
        }

        return $notes;
    }

    /**
     * The settings an option asks for that neither a simulator nor its grid has: what a simulator says, else what its grid says when it
     * has a master.
     *
     * @return list<string> the keys
     */
    private function missing(OptionTemplate $option): array
    {
        $missing = [];
        // A file of the grid the option sets (the Gloebit.ini the simulators include) says it for all of them
        $own = [];
        foreach ($option->files() as $file) {
            if (preg_match('/\.ini$/i', $file) !== 1 || $file === 'OpenSim.ini' || preg_match('/^Robust/i', basename($file)) === 1) {
                continue;
            }
            $name = $option->path($file, $this->variables());
            $name === null || !is_file($this->root() . "/$name") || ($own[] = IniEditor::load($this->root() . "/$name"));
        }
        foreach ($option->asks() as $ask) {
            [$section, $key] = explode('/', $ask, 2) + [1 => ''];
            foreach ($own as $ini) {
                $ini->get($section, $key) === null || ($missing[$key] = false);
            }
        }
        $general = $this->layered() ? IniEditor::load($this->general()) : null;
        foreach ([null, ...array_values($this->simulators())] as $file) {
            $ini = $file === null ? $general : IniEditor::load($file);
            if ($ini === null) {
                continue;
            }
            foreach ($option->asks() as $ask) {
                [$section, $key] = explode('/', $ask, 2) + [1 => ''];
                if (($missing[$key] ?? null) !== false && $ini->get($section, $key) === null && ($file === null || $general?->get($section, $key) === null)) {
                    $missing[$key] = true;
                }
            }
        }

        return array_keys(array_filter($missing));
    }

    /**
     * Keep which options the grid has, in its description.
     *
     * @param list<string> $names
     */
    private function record(array $names): void
    {
        $path = $this->grid->directory() . '/' . basename($this->grid->directory()) . '.conf';
        if (!is_file($path)) {
            return;
        }
        $ini = IniEditor::load($path);
        $ini->set('Grid', 'Options', implode(', ', $names));
        $ini->save($path);
        // The grid is read again: what it says is what was just written
        $this->grid->isPlanned() || ($this->grid = Grid::at($this->grid->environment(), $this->grid->directory()));
    }

    private static function truthy(mixed $value): bool
    {
        return is_string($value)
            ? !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off', 'none', 'disabled'], true)
            : (bool) $value;
    }
}
