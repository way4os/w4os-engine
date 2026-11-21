<?php

declare(strict_types=1);

namespace W4OS\Engine\Testing;

use W4OS\Engine\Config\Layout;
use W4OS\Engine\Runtime\Environment;
use W4OS\Engine\Runtime\RuntimeProbe;

/**
 * An environment that touches nothing of the machine it runs on: the settings of its layout are given, the processes, the
 * launcher, the consoles and the clock are fakes, nothing listens and nothing is installed.
 */
final class EnvironmentFake
{
    /**
     * @param array<string,string> $layout the settings of the install (EtcRoot, DataRoot, CoreDirectory...)
     * @param ?ProcessTableFake $processes the processes of the machine, none by default
     * @param ?string $file the config file of the suite the layout comes from, none by default
     */
    public static function with(array $layout = [], ?ProcessTableFake $processes = null, ?string $file = null): Environment
    {
        $processes ??= new ProcessTableFake();

        return new Environment(
            // The cache of a test is its own: what a test keeps is not what another finds
            new Layout($layout + ['CacheRoot' => sys_get_temp_dir() . '/environment-fake-' . bin2hex(random_bytes(4))], $file),
            $processes,
            new LauncherFake($processes),
            new ConsoleFactoryFake(),
            new ClockFake(),
            new ReporterFake(),
            null,
            new RuntimeProbe(static fn(string $command): string => ''),
            static fn(string $url): ?string => null,
        );
    }
}
