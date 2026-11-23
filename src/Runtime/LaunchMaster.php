<?php

declare(strict_types=1);

namespace W4OS\Engine\Runtime;

/**
 * The launch master of opensim.service: it listens on a socket of its own and starts the instances it is asked to, as its children,
 * so that they are the service's, whoever asked (a shell, the API in PHP-FPM, the start at boot). It knows nothing of the instances:
 * the caller prepares the launch (MasterLauncher), the master only runs it.
 *
 * The protocol, kept as it is between versions: one line of JSON asked, one line of JSON answered, then the connection closes.
 *
 *     > {"version":1,"name":"...","directory":"...","command":["..."],"environment":{},"output":"...","screen":false,"bigStack":false}
 *     < {"launched":true}            or {"launched":false,"error":"..."}
 */
final class LaunchMaster
{
    /** The version of the protocol: a master answers the requests of its version and of the ones before. */
    public const VERSION = 1;

    /** Where the master of the service listens (RuntimeDirectory=opensim of the unit). */
    public const SOCKET = '/run/opensim/launch.sock';

    /** Seconds a client has to send its request. */
    private const READ_TIMEOUT = 10;

    private bool $stopping = false;

    public function __construct(
        private readonly string $socket,
        private readonly LauncherInterface $launcher = new SystemLauncher(),
    ) {}

    /** The socket of the master: OPENSIM_LAUNCH_SOCKET, else the one of the service. */
    public static function socket(): string
    {
        $socket = getenv('OPENSIM_LAUNCH_SOCKET');

        return is_string($socket) && $socket !== '' ? $socket : self::SOCKET;
    }

    /**
     * Listen and launch until it is told to stop (SIGTERM, SIGINT).
     *
     * @param ?\Closure():void $ready called once it listens (the service is told it is up)
     * @throws \RuntimeException when it cannot listen
     */
    public function serve(?\Closure $ready = null): void
    {
        $server = $this->listen();
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->stopping = true);
            pcntl_signal(SIGINT, fn() => $this->stopping = true);
        }
        $ready !== null && $ready();
        while (!$this->stopping) {
            $client = @stream_socket_accept($server, 1);
            if ($client === false) {
                continue;
            }
            $this->answer($client);
        }
        fclose($server);
        @unlink($this->socket);
    }

    /** The socket listened on, the one of a master gone replaced, for its owner only. */
    private function listen(): mixed
    {
        if (file_exists($this->socket) && filetype($this->socket) === 'socket') {
            @unlink($this->socket);
        }
        $previous = umask(0o077);
        $server = @stream_socket_server('unix://' . $this->socket, $code, $message);
        umask($previous);
        if ($server === false) {
            throw new \RuntimeException("cannot listen on {$this->socket}: $message");
        }

        return $server;
    }

    /** One request, one answer. */
    private function answer(mixed $client): void
    {
        stream_set_timeout($client, self::READ_TIMEOUT);
        $line = fgets($client);
        $reply = ['launched' => false, 'error' => 'no request'];
        if (is_string($line) && trim($line) !== '') {
            try {
                $reply = ['launched' => $this->launcher->launch(self::spec(json_decode($line, true, 8, JSON_THROW_ON_ERROR)))];
                $reply['launched'] || ($reply['error'] = 'the launcher could not run it');
            } catch (\JsonException | \InvalidArgumentException $error) {
                $reply = ['launched' => false, 'error' => $error->getMessage()];
            }
        }
        @fwrite($client, json_encode($reply) . "\n");
        fclose($client);
    }

    /**
     * The launch a request asks for.
     *
     * @throws \InvalidArgumentException when it is not one
     */
    public static function spec(mixed $request): LaunchSpec
    {
        if (!is_array($request) || (int) ($request['version'] ?? 0) < 1 || (int) $request['version'] > self::VERSION) {
            throw new \InvalidArgumentException('not a launch request of version 1 to ' . self::VERSION);
        }
        $command = $request['command'] ?? null;
        $environment = $request['environment'] ?? [];
        if (
            !is_string($request['name'] ?? null) || $request['name'] === '' ||
            !is_string($request['directory'] ?? null) || !is_dir($request['directory']) ||
            !is_array($command) || $command === [] || array_filter($command, 'is_string') !== $command ||
            !is_array($environment) || array_filter($environment, 'is_string') !== $environment
        ) {
            throw new \InvalidArgumentException('a launch needs a name, a folder that exists, a command and strings for its environment');
        }

        return new LaunchSpec(
            $request['name'],
            $request['directory'],
            array_values($command),
            $environment,
            is_string($request['output'] ?? null) ? $request['output'] : '',
            ($request['screen'] ?? false) === true,
            ($request['bigStack'] ?? false) === true,
        );
    }

    /** The request of a launch, as a client sends it. */
    public static function request(LaunchSpec $spec): string
    {
        return json_encode([
            'version' => self::VERSION,
            'name' => $spec->name,
            'directory' => $spec->directory,
            'command' => $spec->command,
            'environment' => (object) $spec->environment,
            'output' => $spec->output,
            'screen' => $spec->screen,
            'bigStack' => $spec->bigStack,
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }
}
