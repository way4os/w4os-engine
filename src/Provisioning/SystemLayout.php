<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Config\DirectoryLayout;
use W4OS\Engine\Config\Layout;
use W4OS\Engine\Runtime\Core;
use W4OS\Engine\Runtime\ShellService;

/**
 * The system layout of a machine that has none: the account the instances run as, the folders of the layout with their owner, and
 * `w4os.conf`. It needs root; a client that is not root runs it through sudo (`needed()` says whether to).
 */
final class SystemLayout
{
    /** The account the instances run as when the install does not name another. */
    public const USER = 'opensim';

    /** Where the packaged cores are, before the default root of the layout. */
    public const PACKAGED_CORES = '/usr/share/opensim';

    /**
     * @param ?\Closure(string):int $run runs a command, returns its status (default: the shell)
     * @param ?\Closure(string):bool $userExists whether an account exists (default: the system's)
     * @param ?bool $root whether this process is root (default: it is when its user id is 0)
     * @param ?\Closure(string):array|false $groupInfo the members of a system group (default: the system's)
     * @param ?string $conf the config file of the suite (default: the system one, Layout::systemFile())
     * @param ?ServiceUnits $units the units made to run as the account (default: the system's, when this process is root)
     */
    public function __construct(
        private readonly UiInterface $ui,
        private readonly ?\Closure $run = null,
        private readonly ?\Closure $userExists = null,
        private readonly ?bool $root = null,
        private readonly ?\Closure $groupInfo = null,
        private readonly ?string $conf = null,
        private readonly ?ServiceUnits $units = null,
    ) {}

    /** Whether the place of the grids cannot be written by whoever calls: the layout has to be made. */
    public static function needed(Layout $layout): bool
    {
        $existing = $layout->etcRoot();
        while ($existing !== dirname($existing) && !file_exists($existing)) {
            $existing = dirname($existing);
        }

        return !is_dir($existing) || !is_writable($existing);
    }

    /**
     * Make the account, the folders and `w4os.conf` (left as it is when it exists; the `opensim.conf` of before w4os, in the
     * folder of the grids, moved to its place as it is), the folders given to the account it names, and the units of the suite run
     * as that account. Run again when the account changes.
     *
     * @return bool whether the layout is there at the end
     */
    public function prepare(Layout $layout): bool
    {
        $root = $this->root ?? (function_exists('posix_geteuid') && posix_geteuid() === 0);
        if (!$root) {
            $this->ui->error(_('Making the system layout needs root: run it with sudo.'));

            return false;
        }
        $conf = $this->conf ?? Layout::systemFile();
        // The config of before w4os, in the folder of the grids, moved to its place first: its settings are the ones of the install
        $legacy = rtrim($layout->etcRoot(), '/') . '/' . Layout::LEGACY_FILE;
        if (!is_file($conf) && is_file($legacy)) {
            if ((!is_dir(dirname($conf)) && !@mkdir(dirname($conf), 0o755, true)) || !@rename($legacy, $conf)) {
                $this->ui->error(sprintf(_('Could not move %s to %s.'), $legacy, $conf));

                return false;
            }
            $this->ui->note(sprintf(_('Moved %s to %s.'), $legacy, $conf));
            $layout = Layout::load($conf, $layout->all());
        }
        $user = $layout->systemUser() !== '' ? $layout->systemUser() : self::USER;
        $home = $layout->varRoot();
        if (!$this->account($user)) {
            $this->ui->note(sprintf(_('Creating the account %s.'), $user));
            $made = $this->execute(
                ShellService::commandExists('adduser')
                    ? sprintf('adduser --system --group --home %s --shell /bin/bash %s', ShellService::arg($home), ShellService::arg($user))
                    : sprintf('useradd --system --user-group --home-dir %s --shell /bin/bash %s', ShellService::arg($home), ShellService::arg($user)),
            );
            if ($made !== 0) {
                $this->ui->error(sprintf(_('Could not create the account %s.'), $user));

                return false;
            }
        }
        $etc = $layout->etcRoot();
        $folders = [dirname($conf), $etc, "$etc/grids", "$etc/presets", "$etc/opensim.d", "$etc/robust.d", $layout->varRoot(), $layout->dataRoot(), $layout->logsRoot(), $layout->cacheRoot(), $layout->tasksRoot()];
        // The backup root is made when it is the default one; a chosen one (a mount) is the administrator's
        if (self::makesBackupRoot($layout)) {
            $folders[] = $layout->backupRoot();
        }
        $group = ServiceUnits::groupOf($user);
        foreach ($folders as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0o755, true)) {
                $this->ui->error(sprintf(_('Could not create %s.'), $dir));

                return false;
            }
            $this->execute(sprintf('chown %s:%s %s', ShellService::arg($user), ShellService::arg($group), ShellService::arg($dir)));
            @chmod($dir, 0o755);
            // On a system with dpkg the owner survives the upgrades of the packages: an administrator's own override stays, the
            // one made for the default account follows the account of the install
            $this->execute(sprintf(
                'command -v dpkg-statoverride >/dev/null 2>&1 && { o=$(dpkg-statoverride --list %1$s); [ -z "$o" ] || [ "${o%%%% *}" != %4$s ] || [ %4$s = %2$s ] || { dpkg-statoverride --remove %1$s; o=; }; [ -n "$o" ] || dpkg-statoverride --update --add %2$s %3$s 755 %1$s; } || true',
                ShellService::arg($dir),
                ShellService::arg($user),
                ShellService::arg($group),
                ShellService::arg(self::USER),
            ));
        }
        // What the folders of the suite hold follows its account too, when it changes: what another account owns is given to it, the
        // group with it when it was that account's own (a group of the web server stays); an install that exists is never in them
        foreach (array_unique($folders) as $dir) {
            $this->execute(self::follow($dir, $user, $group));
        }
        $this->ui->note(sprintf(_('Folders ready in %s, %s, %s.'), $etc, $layout->varRoot(), $layout->logsRoot()));
        // The setup process needs this group to protect files for the web server; an account that is the web server's has it
        $web = $layout->webGroup();
        $webGroup = ($this->groupInfo ?? static fn(string $name): array|false => function_exists('posix_getgrnam') ? posix_getgrnam($name) : false)($web);
        if ($webGroup !== false && $web !== $group && !in_array($user, $webGroup['members'], true)) {
            $this->execute(sprintf('/usr/sbin/usermod -aG %s %s', ShellService::arg($web), ShellService::arg($user)));
        }

        if (!is_file($conf)) {
            file_put_contents($conf, $this->conf($layout, $user));
            $this->ui->note(sprintf(_('Wrote %s.'), $conf));
        }
        // The config of the suite is its account's, which enables and changes its grids
        $this->execute(sprintf('chown %s:%s %s', ShellService::arg($user), ShellService::arg($group), ShellService::arg($conf)));
        // The units run as the account it names, never only as the one they name
        $units = $this->units ?? (PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid') && posix_geteuid() === 0 ? new ServiceUnits(run: $this->run) : null);
        try {
            foreach ($units?->apply($user, $group) ?? [] as $file) {
                $this->ui->note(sprintf(_('The units run as %s: %s.'), $user, $file));
            }
        } catch (\RuntimeException $e) {
            $this->ui->error($e->getMessage());

            return false;
        }

        return true;
    }

    /** The command that gives what a folder holds to an account, the group with it where it was the former owner's own. */
    private static function follow(string $dir, string $user, string $group): string
    {
        // POSIX: run by /bin/sh; the account and its group given to the loop as its $0, user:group
        return sprintf(
            'find %1$s -mindepth 1 ! -user %2$s -exec sh -c \'for p; do if [ "$(stat -c %%G "$p")" = "$(id -gn "$(stat -c %%U "$p")" 2>/dev/null)" ]; then chown -h "$0" "$p"; else chown -h "${0%%%%:*}" "$p"; fi; done\' %3$s {} + 2>/dev/null || true',
            ShellService::arg($dir),
            ShellService::arg($user),
            ShellService::arg("$user:$group"),
        );
    }

    /** Whether the layout makes its backup root: the default one, named by w4os.conf (as a new one is written) or not. */
    public static function makesBackupRoot(Layout $layout): bool
    {
        return $layout->get('BackupRoot') === '' || $layout->get('BackupRoot') === DirectoryLayout::roots(DirectoryLayout::SYSTEM)['BackupRoot'];
    }

    /** The content of a new `w4os.conf`: the roots of the layout, the core of the packages when there is one, the account. */
    private function conf(Layout $layout, string $user): string
    {
        $roots = DirectoryLayout::roots(DirectoryLayout::SYSTEM);
        // The cores of the packages are in /usr/share/opensim (they may come after the engine), the others where the layout puts them
        $packaged = Core::latest(self::PACKAGED_CORES) !== null || (PHP_OS_FAMILY === 'Linux' && ShellService::commandExists('dpkg'));
        $coreRoot = $packaged ? self::PACKAGED_CORES : $layout->coreRoot();
        $lines = ['[Defaults]', 'DirectoryLayout = ' . DirectoryLayout::SYSTEM, 'CoreRoot = ' . $coreRoot];
        foreach (['EtcRoot', 'VarRoot', 'LogsRoot', 'CacheRoot', 'DataRoot', 'BackupRoot'] as $key) {
            $lines[] = "$key = " . ($layout->get($key) !== '' ? $layout->get($key) : ($roots[$key] ?? ''));
        }
        $lines[] = "SystemUser = $user";
        $lines[] = 'WebUser = ' . $layout->webUser();
        $lines[] = 'WebGroup = ' . $layout->webGroup();

        return implode("\n", $lines) . "\n";
    }

    private function account(string $user): bool
    {
        return ($this->userExists ?? static fn(string $name): bool => function_exists('posix_getpwnam') && posix_getpwnam($name) !== false)($user);
    }

    private function execute(string $command): int
    {
        return ($this->run ?? static fn(string $c): int => ShellService::run($c))($command);
    }
}
