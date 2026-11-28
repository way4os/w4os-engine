<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

/**
 * Where the components of the suite the engine does not carry are, on this machine: the helpers, the website (w4os-site) and the
 * modules of OpenSimulator. Each is looked for in one way: the place w4os.conf names (its setting), else the place of its package,
 * else the clone beside this engine's own, when that clone is ready to serve (its libraries there, its site or its modules built).
 * The place found beside a clone is written in w4os.conf once (bring()), so that every tool reads the same one.
 * A third source, what a release unpacks, has its place here later.
 */
final class Components
{
    /**
     * Each component: its setting of w4os.conf, the folder of its package (in /usr/share), its place in the clone beside this one, what that
     * place must hold to be used, what to run in the clone when it does not, what it is called.
     */
    private const KNOWN = [
        'helpers' => ['HelpersRoot', 'w4os-helpers', 'opensim-helpers', ['vendor/autoload.php', 'public/index.php'], 'composer install', 'Helpers'],
        'site' => ['SiteShare', 'w4os-site', 'w4os-site/build/w4os-site', ['grav', 'bin/w4os-site', 'bin/w4os-site-tree'], 'packaging/build', 'Website'],
        'modules' => ['ModulesRoot', 'opensim-modules', 'opensim-kit/build/modules', ['../packaging.env'], 'packaging/build', 'Modules'],
    ];

    /** The modules a clone gives as the packages do: a folder of files an instance is linked to. The 3D map replaces a file of the core, which its package alone does. */
    private const LINKED = ['gloebit', 'opensimsearch'];

    /** The modules enabled the first time, as the package of the kit does. */
    private const DEFAULT_MODULES = 'opensimsearch';

    /** The folder the clones are in, for the tests ('' for none, null: the one this engine's own clone is in). */
    public static ?string $clones = null;

    /** The folder the packages install in, for the tests (null: /usr/share). */
    public static ?string $packages = null;

    /** Whether any account reads a folder, for the tests (null: its rights and the ones of each folder above it). @var ?\Closure(string): bool */
    public static ?\Closure $open = null;

    /** The names of the components. @return list<string> */
    public static function names(): array
    {
        return array_keys(self::KNOWN);
    }

    /** What a component is called. */
    public static function label(string $name): string
    {
        return self::KNOWN[$name][5];
    }

    /** The setting of w4os.conf that names the place of a component. */
    public static function setting(string $name): string
    {
        return self::KNOWN[$name][0];
    }

    /** Where a component is, '' when it is nowhere on this machine. */
    public static function root(Layout $layout, string $name): string
    {
        return self::found($layout, $name)[0];
    }

    /** Where a component is, the place of its package when it is nowhere. */
    public static function place(Layout $layout, string $name): string
    {
        return self::root($layout, $name) ?: self::package($name);
    }

    /** Where a component comes from: setting, package or clone; '' when it is nowhere. */
    public static function source(Layout $layout, string $name): string
    {
        return self::found($layout, $name)[1];
    }

    /**
     * Why the clone a component would come from cannot serve it, '' when it can or when there is no such clone: what to run in
     * it, or the account of the web server that cannot read it.
     */
    public static function unready(Layout $layout, string $name): string
    {
        if (self::root($layout, $name) !== '') {
            return '';
        }
        [, , $place, , $how] = self::KNOWN[$name];
        $clone = self::neighbours() === '' ? '' : self::neighbours() . '/' . explode('/', $place)[0];
        if ($clone === '' || !is_dir($clone)) {
            return '';
        }
        if (!self::ready($name, self::neighbours() . '/' . $place)) {
            return sprintf(_('%s in %s first'), $how, $clone);
        }

        return sprintf(_('%s cannot read %s'), $layout->webUser(), $clone);
    }

    /**
     * A component found beside a clone, its place written in w4os.conf: the place, null when nothing was to write (it has its
     * setting, it is the package's, it is nowhere, or the config of the install is not this account's to write).
     */
    public static function bring(Layout $layout, string $name): ?string
    {
        [$root, $source] = self::found($layout, $name);
        $conf = $layout->file();
        if ($source !== 'clone' || $conf === null || !is_writable($conf)) {
            return null;
        }
        if ($name === 'modules' && ($root = self::linked($layout, $root)) === null) {
            return null;
        }
        $ini = IniEditor::load($conf);
        $ini->set('Defaults', self::setting($name), $root);
        $ini->save($conf);
        $name !== 'modules' || (new PackagedProfiles($conf))->enableModulesOnce(self::DEFAULT_MODULES);

        return $root;
    }

    /**
     * The command of the website (w4os-site) as a shell runs it, '' when it is not on this machine: the one of its package, else
     * the one of its clone, told where its files are and given the bash-tools of the clone beside it.
     */
    public static function siteCommand(Layout $layout): string
    {
        [$root, $source] = self::found($layout, 'site');
        if ($root === '') {
            return '';
        }
        $tools = self::neighbours() . '/bash-tools/bin';

        return ($source === 'package' ? '' : (is_dir($tools) ? 'PATH=' . escapeshellarg($tools) . ':"$PATH" ' : '') . 'W4OS_SITE_SHARE=' . escapeshellarg($root) . ' ')
            . escapeshellarg("$root/bin/w4os-site");
    }

    /** @return list<string> the modules a folder of modules built in a clone gives */
    public static function modules(string $built): array
    {
        return array_values(array_filter(self::LINKED, static fn(string $module): bool => (glob("$built/$module/*.dll") ?: []) !== []));
    }

    /**
     * The modules built in a clone, as the install reads them (by core, then by module): a folder of links under its state, null
     * when it cannot be made.
     */
    private static function linked(Layout $layout, string $built): ?string
    {
        $core = self::builtFor($built);
        $root = rtrim($layout->varRoot(), '/') . '/modules';
        if ($core === '' || $layout->varRoot() === '' || (!is_dir("$root/$core") && !@mkdir("$root/$core", 0o755, true))) {
            return null;
        }
        foreach (self::modules($built) as $module) {
            $link = "$root/$core/$module";
            is_link($link) && unlink($link);
            file_exists($link) || symlink("$built/$module", $link);
        }

        return $root;
    }

    /** The core the modules of a clone were built for (MODULES_CORE of its build), '' when it does not say. */
    private static function builtFor(string $built): string
    {
        $said = is_file("$built/../packaging.env") ? (string) file_get_contents("$built/../packaging.env") : '';

        return preg_match('/^MODULES_CORE=["\']?([A-Za-z0-9._~+-]+)/m', $said, $m) === 1 ? $m[1] : '';
    }

    /** @return array{0:string,1:string} the place and where it comes from */
    private static function found(Layout $layout, string $name): array
    {
        [$setting, , $place] = self::KNOWN[$name] ?? throw new \InvalidArgumentException("unknown component $name");
        $named = rtrim($layout->get($setting), '/');
        if ($named !== '' && is_dir($named)) {
            return [$named, 'setting'];
        }
        if (is_dir(self::package($name))) {
            return [self::package($name), 'package'];
        }
        $clone = self::neighbours() === '' ? '' : self::neighbours() . '/' . $place;
        // The helpers are served where they are: by a web server that reads them
        if ($clone !== '' && self::ready($name, $clone) && ($name !== 'helpers' || self::open($clone))) {
            return [$clone, 'clone'];
        }

        return ['', ''];
    }

    /** Where the package of a component installs it. */
    private static function package(string $name): string
    {
        return rtrim(self::$packages ?? '/usr/share', '/') . '/' . self::KNOWN[$name][1];
    }

    /** Whether the place of a component in a clone holds what it needs to be used. */
    private static function ready(string $name, string $clone): bool
    {
        if (!is_dir($clone) || array_filter(self::KNOWN[$name][3], static fn(string $need): bool => !file_exists("$clone/$need")) !== []) {
            return false;
        }

        return $name !== 'modules' || (self::builtFor($clone) !== '' && self::modules($clone) !== []);
    }

    /** Whether any account reads a folder: itself and each folder above it open to all. */
    private static function open(string $folder): bool
    {
        if (self::$open !== null) {
            return (self::$open)($folder);
        }
        for ($path = (string) realpath($folder); $path !== '' && $path !== '/'; $path = dirname($path)) {
            if (((int) @fileperms($path) & 0o005) !== 0o005) {
                return false;
            }
        }

        return true;
    }

    /** The folder the clones are in: the one this engine's own folder is in, '' for an engine a package installed. */
    private static function neighbours(): string
    {
        if (self::$clones !== null) {
            return rtrim(self::$clones, '/');
        }
        $engine = dirname(__DIR__, 2);

        return str_starts_with($engine, '/usr/share/') ? '' : dirname($engine);
    }
}
