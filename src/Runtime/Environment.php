<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Config\Layout;
use OpenSim\Console\ConsoleFactory;
use OpenSim\Console\ConsoleFactoryInterface;
use OpenSim\Network\PortMapping\PortMappingService;

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
        public readonly ?\OpenSim\Data\DatabaseAccess $databases = null,
    ) {}

    /** The environment of this machine. */
    /**
     * @param ?\OpenSim\Data\DatabaseAccess $databases the access to the databases an application gives, applied to every grid
     *        (Grid::config()): its server, its user, its password; null for the ones each grid says
     */
    public static function system(Layout $layout, ?ReporterInterface $reporter = null, ?\OpenSim\Data\DatabaseAccess $databases = null): self
    {
        $reporter ??= new NullReporter();

        return new self(
            $layout,
            new SystemProcessTable(),
            new SystemLauncher(),
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
        );
    }
}
