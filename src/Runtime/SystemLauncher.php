<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

use W4OS\Engine\Console\ScreenConsole;

/**
 * Starts the instances on this machine: detached (nohup) when they have a remote console, in a screen session otherwise; either way
 * in a session of their own, without a terminal (setsid, their input /dev/null): a terminal they kept (the one of the command that
 * started them, or the one sudo gives it with use_pty) would never be given back.
 */
final class SystemLauncher implements LauncherInterface
{
    private static ?string $setsid = null;

    public function launch(LaunchSpec $spec): bool
    {
        if (self::$setsid === null) {
            $found = [];
            exec('command -v setsid 2>/dev/null', $found);
            self::$setsid = $found !== [] ? 'setsid ' : '';
        }
        $command = implode(' ', array_map('escapeshellarg', $spec->command));
        if ($spec->environment !== []) {
            $command =
                'env ' .
                implode(
                    ' ',
                    array_map(
                        static fn(string $name, string $value): string => escapeshellarg("$name=$value"),
                        array_keys($spec->environment),
                        $spec->environment,
                    ),
                ) .
                ' ' .
                $command;
        }
        $output = $spec->output !== '' ? escapeshellarg($spec->output) : '/dev/null';
        $script = 'cd ' . escapeshellarg($spec->directory) . ' || exit 1; ';
        if ($spec->bigStack) {
            $script .= 'ulimit -s 1048576 2>/dev/null; ';
        }
        // The output of this start only, in a file of the account it runs as
        $spec->output === '' || $script .= ': >' . $output . ' || exit 1; ';
        if ($spec->screen) {
            // The output of the session is kept, so that an early crash can be reported
            $script .= self::$setsid . 'screen -L -Logfile ' . $output . ' -S ' . escapeshellarg($spec->name) . ' -d -m ' . $command . ' </dev/null';
        } else {
            // No terminal session: the output goes to a file, the console is the remote one
            $script .= '(' . self::$setsid . 'nohup ' . $command . ' >' . $output . ' 2>&1 </dev/null &)';
        }
        exec($script . ' >/dev/null 2>&1', $lines, $code);
        if ($spec->screen && $code === 0) {
            (new ScreenConsole($spec->name))->flushLog();
        }

        return $code === 0;
    }
}
