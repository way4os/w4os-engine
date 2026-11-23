<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

/**
 * Starts the instances through the launch master of opensim.service (LaunchMaster) when it listens: they are the service's then, and
 * stop with it, at shutdown too. Without it (no service on this machine, or stopped), they start as before, by the caller.
 */
final class MasterLauncher implements LauncherInterface
{
    /** Seconds the master has to answer: it starts the process and returns. */
    private const TIMEOUT = 30;

    /**
     * @param ?\Closure(string):void $warn what is said when the service should run and does not
     * @param ?bool $service whether this machine has opensim.service under systemd (null: looked for)
     */
    public function __construct(
        private readonly string $socket,
        private readonly LauncherInterface $direct = new SystemLauncher(),
        private readonly ?\Closure $warn = null,
        private readonly ?bool $service = null,
    ) {}

    public function launch(LaunchSpec $spec): bool
    {
        $connection = file_exists($this->socket) ? @stream_socket_client('unix://' . $this->socket, $code, $message, 5) : false;
        if ($connection === false) {
            if ($this->warn !== null && ($this->service ?? self::hasService())) {
                ($this->warn)("opensim.service is not running: {$spec->name} runs outside it and is not stopped with it, at shutdown either. Start the service: sudo systemctl start opensim");
            }

            return $this->direct->launch($spec);
        }
        stream_set_timeout($connection, self::TIMEOUT);
        fwrite($connection, LaunchMaster::request($spec));
        $line = fgets($connection);
        fclose($connection);
        $reply = is_string($line) ? json_decode($line, true) : null;

        return is_array($reply) && ($reply['launched'] ?? false) === true;
    }

    /** Whether systemd runs this machine and has opensim.service. */
    private static function hasService(): bool
    {
        return is_dir('/run/systemd/system') &&
            (is_file('/usr/lib/systemd/system/opensim.service') || is_file('/etc/systemd/system/opensim.service') || is_file('/lib/systemd/system/opensim.service'));
    }
}
