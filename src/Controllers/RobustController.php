<?php

declare(strict_types=1);

namespace W4OS\Engine\Controllers;

use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Config\ConnectionString;
use W4OS\Engine\Config\GridFileService;
use W4OS\Engine\Data\Database;

/**
 * The Robust of a grid: the server of its services (login, users, inventory, assets, presence). A grid has one of its own on
 * this machine, or none when it is run elsewhere.
 */
final class RobustController extends AbstractController
{
    public function type(): string
    {
        return 'robust';
    }

    public function assembly(): string
    {
        return 'Robust';
    }

    protected function linkName(): string
    {
        return $this->grid()?->nick() ?? parent::linkName();
    }

    /** Where the viewers and the other grids reach it: `host:port` of the grid. */
    public function uri(): ?string
    {
        $host = $this->baseHostname();
        $port = $this->publicPort();

        return $host !== null && $host !== '' && $port !== null ? "$host:$port" : null;
    }

    /** The database of its services that have none of their own: [DatabaseService]. */
    public function database(): ?ConnectionString
    {
        return $this->connection(Database::Robust);
    }

    /** The name the grid gives itself (`gridname` of [GridInfoService]). */
    public function gridName(): ?string
    {
        return $this->own('gridName');
    }

    public function baseHostname(): ?string
    {
        return $this->own('baseHostname');
    }

    /** The web site of the grid (`WebURL`), where its helpers are. */
    public function webUrl(): ?string
    {
        return $this->own('webUrl');
    }

    /** The port the viewers and the other grids reach it by. */
    public function publicPort(): ?int
    {
        return $this->own('publicPort');
    }

    /** The port of the simulators of the grid, which they reach it by. */
    public function privatePort(): ?int
    {
        return $this->own('privatePort');
    }

    /**
     * The services it serves: the connectors of its [ServiceList].
     *
     * @return list<string>
     */
    public function connectors(): array
    {
        return (array) ($this->own('connectors') ?? []);
    }

    /**
     * Whether it reaches its database, as it answers: its grid service asked for the default regions (what it reads in its
     * database). True for an answer of the service, false for an error (Robust runs, its database does not answer it: a connection
     * string its MySql.Data cannot use, a server down), null when it does not answer (stopped, still starting).
     *
     * @param ?\Closure(string,array<string,string>):array{0:int,1:string} $post the POST (Http::exchange() without it)
     */
    public function databaseAnswers(?\Closure $post = null): ?bool
    {
        $entry = (string) ($this->settings()['ServiceList']['GridServiceConnector'] ?? '');
        $port = preg_match('/^\s*"?(\d+)\//', $entry, $m) === 1 ? (int) $m[1] : $this->privatePort();
        if ($port === null || $port <= 0) {
            return null;
        }
        $fields = ['METHOD' => 'get_default_regions', 'SCOPEID' => '00000000-0000-0000-0000-000000000000'];
        [$status, $body] = ($post ?? static fn(string $url, array $form): array => \W4OS\Engine\Network\Http::exchange($url, $form))("http://127.0.0.1:$port/grid", $fields);

        return $status === 0 ? null : $status === 200 && str_contains($body, '<ServerResponse');
    }

    public function serves(string $connector): bool
    {
        return in_array($connector, $this->connectors(), true);
    }

    /**
     * The roles its config gives to regions (Region_<Name> = "DefaultRegion, ...").
     *
     * @return list<string>
     */
    public function regionFlags(): array
    {
        return (array) ($this->own('regionFlags') ?? []);
    }

    /**
     * The connectors a Robust config serves: the keys of its [ServiceList].
     *
     * @return list<string>
     */
    public static function connectorsIn(string $robustIni): array
    {
        return array_keys(ConfigReader::parse(GridFileService::read($robustIni))['ServiceList'] ?? []);
    }

    public static function enabledFolder(): string
    {
        return 'robust.d';
    }

    protected function consolePortKeys(): array
    {
        return ['ConsolePort', 'consoleport'];
    }

    protected function listeningPorts(): array
    {
        $uses = [];
        self::addPort($uses, $this->publicPort(), 'tcp', 'public port of the grid: viewers, other grids', 'public');
        self::addPort($uses, $this->privatePort(), 'tcp', 'private port of the grid: its simulators only', 'private');
        // The services it serves on another port
        foreach (array_values($this->settings()['ServiceList'] ?? []) as $connector) {
            $port = (string) preg_replace('/^.*@/', '', explode('/', $connector, 2)[0]);
            if (ctype_digit($port) && (int) $port !== $this->publicPort() && (int) $port !== $this->privatePort()) {
                self::addPort($uses, $port, 'tcp', 'additional service', 'private');
            }
        }
        // The engine API of the grid, served by the web server of this machine for its sites: on 127.0.0.1 only
        self::addPort($uses, $this->grid()?->knownApiPort(), 'tcp', 'engine API of the grid, for the sites of this machine (127.0.0.1)', 'local');

        return $uses;
    }
}
