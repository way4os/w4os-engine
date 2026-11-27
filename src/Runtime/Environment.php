<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

use W4OS\Engine\Config\Layout;
use W4OS\Engine\Console\ConsoleFactory;
use W4OS\Engine\Console\ConsoleFactoryInterface;
use W4OS\Engine\Network\PortMapping\PortMappingService;

/**
 * What the models need to act on the machine: where the install is, the processes, the launcher, the consoles, the clock, the
 * port mapping, the reporter. The models hold no global state; a front-end makes one environment, a test makes it with fakes.
 *
 *     $environment = Environment::system(Layout::load());
 *     OpenSim::start('vonda', $environment);
 */
final class Environment
{
    public function __construct(
        public readonly Layout $layout,
        public readonly ProcessTableInterface $processes,
        public readonly LauncherInterface $launcher,
        public readonly ConsoleFactoryInterface $consoles,
        public readonly ClockInterface $clock,
        public readonly ReporterInterface $reporter,
        public readonly ?PortMappingService $portMapping = null,
        public readonly RuntimeProbe $probe = new RuntimeProbe(),
        /** @var ?\Closure(string):?string reads an address, the body of the answer, null when it does not answer */
        public readonly ?\Closure $http = null,
        /** @var ?list<int> occupied ports reported by the deployment host; replaces local config/socket discovery */
        public readonly ?array $occupiedPorts = null,
        /** The access to the databases an application gives (a site that has the engine in its own folder), for every grid */
        public readonly ?\W4OS\Engine\Data\DatabaseAccess $databases = null,
        /** The settings an application keeps for the engine (a site), read before the files of a grid, for every grid */
        public readonly ?\W4OS\Engine\Config\SettingsStore $settings = null,
    ) {}

    /** The same environment with another layout (the config of the suite read again). */
    public function withLayout(Layout $layout): self
    {
        return new self($layout, $this->processes, $this->launcher, $this->consoles, $this->clock, $this->reporter, $this->portMapping, $this->probe, $this->http, $this->occupiedPorts, $this->databases, $this->settings);
    }

    /** The environment of this machine. */
    /**
     * @param ?\W4OS\Engine\Data\DatabaseAccess $databases the access to the databases an application gives, applied to every grid
     *        (Grid::config()): its server, its user, its password; null for the ones each grid says
     * @param array<string,array<string,string>>|\W4OS\Engine\Config\SettingsStore|null $settings the settings the application keeps for every
     *        grid (section => key => value), read before its files: an array is read only, a store also keeps what the settings pages
     *        write for a grid without files
     */
    public static function system(
        Layout $layout,
        ?ReporterInterface $reporter = null,
        ?\W4OS\Engine\Data\DatabaseAccess $databases = null,
        array|\W4OS\Engine\Config\SettingsStore|null $settings = null,
    ): self {
        $reporter ??= new NullReporter();

        return new self(
            $layout,
            new SystemProcessTable(),
            // Through the launch master of opensim.service when it runs: the instances are the service's, whoever starts them
            new MasterLauncher(LaunchMaster::socket(), new SystemLauncher($layout->systemUser()), static fn(string $message) => $reporter->info($message)),
            new ConsoleFactory(),
            new SystemClock(),
            $reporter,
            new PortMappingService(
                $layout->stateRoot() . '/portmap',
                static fn(string $message, bool $problem) => $problem
                    ? $reporter->error($message)
                    : $reporter->info($message),
            ),
            databases: $databases,
            settings: is_array($settings) ? new \W4OS\Engine\Config\ArraySettings($settings, false) : $settings,
        );
    }

    /** The same environment with the settings an application keeps (SettingsStore). */
    public function withSettings(?\W4OS\Engine\Config\SettingsStore $settings): self
    {
        return new self(
            $this->layout,
            $this->processes,
            $this->launcher,
            $this->consoles,
            $this->clock,
            $this->reporter,
            $this->portMapping,
            $this->probe,
            $this->http,
            $this->occupiedPorts,
            $this->databases,
            $settings,
        );
    }

    /** The same environment saying what it does to another reporter. */
    public function reporting(ReporterInterface $reporter): self
    {
        return new self(
            $this->layout,
            $this->processes,
            $this->launcher,
            $this->consoles,
            $this->clock,
            $reporter,
            $this->portMapping,
            $this->probe,
            $this->http,
            $this->occupiedPorts,
            $this->databases,
            $this->settings,
        );
    }

    /** Use the selected deployment host's port snapshot instead of this PHP process's local ports. */
    public function onHostWithOccupiedPorts(array $ports): self
    {
        foreach ($ports as $port) {
            if (!is_int($port) || $port < 1 || $port > 65535) {
                throw new \InvalidArgumentException('Occupied ports must be integers from 1 to 65535');
            }
        }

        return new self(
            $this->layout,
            $this->processes,
            $this->launcher,
            $this->consoles,
            $this->clock,
            $this->reporter,
            $this->portMapping,
            $this->probe,
            $this->http,
            array_values(array_unique($ports)),
            $this->databases,
            $this->settings,
        );
    }
}
