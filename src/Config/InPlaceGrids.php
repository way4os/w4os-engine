<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

/**
 * The grids of an existing install the suite runs where they are: each its `[Grid <nick>]` section in the config of the suite
 * (Layout::FILE), the only thing written. It says where the files are and what is enabled:
 *
 *     [Grid oldgrid]
 *     RobustIni = /opt/opensim/bin/Robust.HG.ini
 *     CoreDirectory = /opt/opensim
 *     Simulators = welcome:/opt/opensim/bin/OpenSim.ini, north:/opt/north/bin/OpenSim.ini
 *     Enabled = yes                       ; its Robust starts with the others
 *     EnabledSimulators = welcome
 *
 * Removing the section leaves the install as it was.
 */
final class InPlaceGrids
{
    /** @return array<string,string> the section of a grid in a config file, empty when it has none */
    public static function section(string $conf, string $nick): array
    {
        if ($conf === '' || !is_file($conf)) {
            return [];
        }

        return array_map(static fn($value): string => (string) $value, ConfigReader::load($conf)[Layout::GRID_SECTION . $nick] ?? []);
    }

    /**
     * Set keys of the section of a grid (made when missing); an empty value removes the key.
     *
     * @param array<string,string> $values
     */
    public static function set(string $conf, string $nick, array $values): void
    {
        $ini = is_file($conf) ? IniEditor::load($conf) : new IniEditor('');
        foreach ($values as $key => $value) {
            $value === '' ? $ini->remove(Layout::GRID_SECTION . $nick, $key) : $ini->set(Layout::GRID_SECTION . $nick, $key, $value);
        }
        $ini->save($conf);
    }

    /**
     * The simulators of a section, in their order: the short name, the name of the instance (`<nick>.<short>`), its config.
     *
     * @param array<string,string> $section
     * @return list<array{short:string,name:string,ini:string}>
     */
    public static function simulators(string $nick, array $section): array
    {
        $simulators = [];
        foreach (array_filter(array_map('trim', explode(',', $section['Simulators'] ?? ''))) as $entry) {
            [$short, $ini] = str_contains($entry, ':') ? array_map('trim', explode(':', $entry, 2)) : [basename(dirname($entry, 2)), $entry];
            $simulators[] = ['short' => $short, 'name' => "$nick.$short", 'ini' => $ini];
        }

        return $simulators;
    }

    /**
     * The section that registers an existing install in place: its Robust config, its core (the folder above its bin), its
     * simulators (`short:ini`), disabled.
     *
     * @param list<array{file:string,name?:?string}> $simulators
     * @return array<string,string>
     */
    public static function sectionFor(string $robustIni, array $simulators, ?string $core = null): array
    {
        $robustIni = (string) (realpath($robustIni) ?: $robustIni);
        // The core: the one asked, else the one its config runs from ([Launch] BinDir, [Const] BinDirectory), else its folder
        $expanded = ConfigReader::expand(ConfigReader::load($robustIni));
        $named = trim((string) ($expanded['Launch']['BinDir'] ?? ($expanded['Const']['BinDirectory'] ?? '')), '"');
        $bin = rtrim((string) ($core !== null && $core !== '' ? $core : ($named !== '' && is_dir($named) ? $named : dirname($robustIni))), '/');
        $entries = [];
        foreach ($simulators as $simulator) {
            $file = (string) (realpath((string) $simulator['file']) ?: $simulator['file']);
            $short = \W4OS\Engine\Provisioning\Slug::slug((string) (($simulator['name'] ?? '') ?: basename(dirname($file, basename(dirname($file)) === 'bin' ? 2 : 1))));
            $entries[] = "$short:$file";
        }

        return ['RobustIni' => $robustIni, 'CoreDirectory' => basename($bin) === 'bin' ? dirname($bin) : $bin]
            + ($entries === [] ? [] : ['Simulators' => implode(', ', $entries)]);
    }

    /**
     * What the instances of a grid in place could not write, run by the user of this install (the one running the code): the
     * notice that says it and what to change, one per owner of these folders. The install is not changed.
     *
     * @param array<string,string> $section
     * @return list<\W4OS\Engine\Text\Notice>
     */
    public static function unwritable(\W4OS\Engine\Runtime\Environment $environment, array $section): array
    {
        $bin = ($section['CoreDirectory'] ?? '') !== '' ? rtrim($section['CoreDirectory'], '/') . '/bin' : '';
        $files = array_filter([$section['RobustIni'] ?? '', ...array_column(self::simulators('', $section), 'ini')]);
        $folders = [];
        foreach ($files as $ini) {
            array_push($folders, ...\W4OS\Engine\Runtime\InstanceWrites::folders(ConfigReader::expand(ConfigReader::load($ini)), $bin));
        }
        $user = $environment->layout->systemUser() ?: (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '');
        $byOwner = [];
        foreach (\W4OS\Engine\Runtime\InstanceWrites::unwritable($folders, $user) as $there => $owner) {
            $byOwner[$owner][] = $there;
        }
        $notices = [];
        foreach ($byOwner as $owner => $there) {
            $there = array_values(array_unique($there));
            $notices[] = \W4OS\Engine\Text\Notice::of('in-place-unwritable', [
                'account' => $user,
                'owner' => $owner,
                'folders' => implode(' ', $there),
                'command' => "sudo chown -R $user: " . implode(' ', array_map('escapeshellarg', $there)),
            ]);
        }

        return $notices;
    }

    /** Remove the section of a grid: the suite forgets it, the install is as it was. */
    public static function forget(string $conf, string $nick): void
    {
        if (!is_file($conf)) {
            return;
        }
        $ini = IniEditor::load($conf);
        $ini->removeSection(Layout::GRID_SECTION . $nick);
        $ini->save($conf);
    }

    /** @param array<string,string> $section */
    public static function robustEnabled(array $section): bool
    {
        return in_array(strtolower($section['Enabled'] ?? ''), ['yes', 'true', '1', 'on'], true);
    }

    /**
     * @param array<string,string> $section
     * @return list<string> the short names of its simulators that start with the others
     */
    public static function enabledSimulators(array $section): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $section['EnabledSimulators'] ?? ''))));
    }

    /**
     * The grid in place a config file of an instance belongs to: its nick, and the short name of the simulator (null for its Robust).
     *
     * @return ?array{nick:string,simulator:?string}
     */
    public static function owner(Layout $layout, string $ini): ?array
    {
        $ini = (string) (realpath($ini) ?: $ini);
        foreach ($layout->grids() as $nick => $section) {
            if (($section['RobustIni'] ?? '') !== '' && (realpath($section['RobustIni']) ?: $section['RobustIni']) === $ini) {
                return ['nick' => $nick, 'simulator' => null];
            }
            foreach (self::simulators($nick, $section) as $simulator) {
                if ((realpath($simulator['ini']) ?: $simulator['ini']) === $ini) {
                    return ['nick' => $nick, 'simulator' => $simulator['short']];
                }
            }
        }

        return null;
    }

    /** Whether the instance of a config file of a grid in place starts with the others (null: not a grid in place). */
    public static function isEnabled(Layout $layout, string $ini): ?bool
    {
        $owner = self::owner($layout, $ini);
        if ($owner === null) {
            return null;
        }
        $section = self::section((string) $layout->file(), $owner['nick']);

        return $owner['simulator'] === null
            ? self::robustEnabled($section)
            : in_array($owner['simulator'], self::enabledSimulators($section), true);
    }

    /** Enable or disable the instance of a config file of a grid in place, in its section; false when it is not one. */
    public static function setEnabled(Layout $layout, string $ini, bool $enabled): bool
    {
        $owner = self::owner($layout, $ini);
        $conf = (string) $layout->file();
        if ($owner === null || $conf === '') {
            return false;
        }
        if ($owner['simulator'] === null) {
            // A grid enabled is its Robust and the simulators imported with it; disabled, its Robust (its simulators are told apart)
            $all = array_column(self::simulators($owner['nick'], self::section($conf, $owner['nick'])), 'short');
            self::set($conf, $owner['nick'], ['Enabled' => $enabled ? 'yes' : 'no'] + ($enabled && $all !== [] ? ['EnabledSimulators' => implode(', ', $all)] : []));

            return true;
        }
        $names = array_diff(self::enabledSimulators(self::section($conf, $owner['nick'])), [$owner['simulator']]);
        $enabled && ($names[] = $owner['simulator']);
        self::set($conf, $owner['nick'], ['EnabledSimulators' => implode(', ', $names)]);

        return true;
    }
}
