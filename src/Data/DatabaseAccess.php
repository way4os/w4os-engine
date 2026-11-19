<?php

declare(strict_types=1);

namespace OpenSim\Data;

/**
 * The access to the databases an application gives the engine (a site that has it in its own folder: its database server, its
 * user, its password), applied to every database of a grid (GridConfig::withDatabaseAccess(), by the environment: Grid::config()):
 * the host, the port and the credentials replaced in each connection string, its database and the rest of it kept. What it does not
 * give stays as the grid says it. The password is never said (safe(), a dump of it).
 *
 *     $environment = Environment::system($layout, databases: new DatabaseAccess('db.example.org', 3306, 'site', $password));
 *     $grid->config();   // every ConnectionString of the grid on db.example.org, as site
 */
final class DatabaseAccess
{
    /**
     * @param string $host the server, empty to keep the one of the grid
     * @param ?int $port its port, null to keep the one of the grid (or none)
     * @param string $user the user, empty to keep the one of the grid
     * @param string $password the password, kept with the user of the grid when no user is given
     */
    public function __construct(
        public readonly string $host = '',
        public readonly ?int $port = null,
        public readonly string $user = '',
        #[\SensitiveParameter]
        private readonly string $password = '',
    ) {}

    /** A connection string with this access: its `Data Source`, `User ID` and `Password` replaced, the other pairs as they are. */
    public function applyTo(string $text): string
    {
        $text = trim($text, " \t\r\n\"");
        // A database of a file (SQLite) is no server
        if (stripos($text, 'URI=file:') !== false || stripos($text, 'Data Source=') === false) {
            return $text;
        }
        $pairs = [];
        foreach (explode(';', $text) as $part) {
            $pair = explode('=', $part, 2);
            count($pair) === 2 && trim($pair[0]) !== '' && ($pairs[trim($pair[0])] = trim($pair[1]));
        }
        [$host, $port] = self::split($pairs['Data Source'] ?? '');
        $host = $this->host !== '' ? $this->host : $host;
        $port = $this->port ?? ($this->host !== '' ? null : $port);
        $pairs['Data Source'] = $host . ($port !== null ? ":$port" : '');
        if ($this->user !== '') {
            $pairs['User ID'] = $this->user;
            $pairs['Password'] = $this->password;
        }
        $written = '';
        foreach ($pairs as $name => $value) {
            $written .= "$name=$value;";
        }

        return $written;
    }

    /**
     * What it is, said without its password.
     *
     * @return array{host:string,port:?int,user:string,password:string}
     */
    public function safe(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'user' => $this->user, 'password' => $this->password === '' ? '' : '***'];
    }

    /** @return array<string,mixed> a dump says it without its password */
    public function __debugInfo(): array
    {
        return $this->safe();
    }

    /** @return array{0:string,1:?int} the host and the port of a Data Source */
    private static function split(string $source): array
    {
        if (preg_match('/^(.*):(\d+)$/', $source, $m) === 1) {
            return [$m[1], (int) $m[2]];
        }

        return [$source, null];
    }
}
