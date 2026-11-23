<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Config\Layout;
use W4OS\Engine\Runtime\ShellService;

/**
 * The units of the suite run as the account of the install (SystemUser), whichever it is: a drop-in of each sets its User= and
 * Group=, the units themselves name the default account only. The launch master, its socket and what it starts are then that
 * account's. One account a machine: several would need one master each (a template unit and a socket per account).
 */
final class ServiceUnits
{
    /** The units that run as the account of the install. */
    public const UNITS = ['opensim.service', 'opensim-backup.service', 'opensim-backup-oar.service'];

    /** The name of the drop-in of each. */
    public const DROP_IN = 'w4os-system-user.conf';

    /**
     * @param string $directory where systemd reads the units of the administrator
     * @param ?\Closure(string):int $run runs a command, returns its status (default: the shell)
     * @param ?bool $systemd whether systemd runs this machine (default: /run/systemd/system is there)
     */
    public function __construct(
        private readonly string $directory = '/etc/systemd/system',
        private readonly ?\Closure $run = null,
        private readonly ?bool $systemd = null,
    ) {}

    /**
     * The drop-in of each unit, written when it is not the one of this account, then systemd told (daemon-reload). A running service
     * takes the account at its next start.
     *
     * @return list<string> the drop-ins written
     */
    public function apply(string $user, string $group): array
    {
        $text = "# Written by w4os from SystemUser in " . Layout::systemFile() . ": the account the instances run as\n[Service]\nUser=$user\nGroup=$group\n";
        $written = [];
        foreach (self::UNITS as $unit) {
            $file = "$this->directory/$unit.d/" . self::DROP_IN;
            if (is_file($file) && file_get_contents($file) === $text) {
                continue;
            }
            if ((!is_dir(dirname($file)) && !@mkdir(dirname($file), 0o755, true)) || @file_put_contents($file, $text) === false) {
                throw new \RuntimeException("could not write $file");
            }
            $written[] = $file;
        }
        if ($written !== [] && ($this->systemd ?? is_dir('/run/systemd/system'))) {
            ($this->run ?? static fn(string $command): int => ShellService::run($command))('systemctl daemon-reload');
        }

        return $written;
    }

    /** The group of an account: its own (primary) group, its name as the group when it has none known. */
    public static function groupOf(string $user): string
    {
        $entry = function_exists('posix_getpwnam') ? posix_getpwnam($user) : false;
        $group = $entry !== false && function_exists('posix_getgrgid') ? posix_getgrgid((int) $entry['gid']) : false;

        return $group !== false ? (string) $group['name'] : $user;
    }
}
