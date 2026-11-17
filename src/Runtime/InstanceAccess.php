<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Config\Layout;

/**
 * Who may start and stop the instances of this machine: the user that runs them (SystemUser of the install), whose screens, files and
 * processes they are; any user when the install names none (a development machine). Another (the web server of a site) is refused,
 * nothing done.
 */
final class InstanceAccess
{
    /**
     * Why this process may not start or stop the instances, null when it may.
     *
     * @param ?string $current the user this process runs as (default: its effective user)
     */
    public static function refusal(Layout $layout, ?string $current = null): ?string
    {
        $owner = $layout->systemUser();
        $current ??= self::currentUser();
        if ($owner === '' || $current === $owner) {
            return null;
        }

        return "this process runs as $current, the instances of this machine as $owner: they are started and stopped by $owner (opensim start, opensim stop)";
    }

    /** The effective user of this process. */
    private static function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            return (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
        }

        return (string) (getenv('USER') ?: '');
    }
}
