<?php

declare(strict_types=1);

namespace W4OS\Engine\Config;

/**
 * A connection string of OpenSimulator (`Data Source=localhost;Database=opensim;User ID=opensim;Password=secret;Old Guids=true;`):
 * read from a config, written into one.
 */
final class ConnectionString
{
    /**
     * @param string $source the Data Source as written (`localhost`, `db.example.org:3307`)
     * @param string $host the host of the Data Source
     * @param ?int $port its port when it has one
     */
    public function __construct(
        public readonly string $source,
        public readonly string $database,
        public readonly string $user,
        public readonly string $password,
        public readonly string $host = '',
        public readonly ?int $port = null,
        public readonly string $driver = 'mysql',
    ) {}

    /** A connection string for a host that may carry its port; the source is the host as given. */
    public static function for(string $source, string $database, string $user, string $password): self
    {
        [$host, $port] = self::split($source);

        return new self($source, $database, $user, $password, $host, $port);
    }

    /** The one a text says, null when it has no database to connect to. */
    public static function parse(string $text): ?self
    {
        if (preg_match('/(?:^|;\s*)URI=file:(.*?)(?:,version=|,UseUTF16Encoding=|$)/i', trim($text, " \t\r\n\""), $match)) {
            $file = trim($match[1]);

            return $file === '' ? null : new self($file, $file, '', '', driver: 'sqlite');
        }
        $pairs = self::pairs($text);
        $source = $pairs['Data Source'] ?? '';
        $database = $pairs['Database'] ?? '';
        if ($source === '' || $database === '') {
            return null;
        }
        // Its port given apart (Port=3307), as the connector of MySQL reads it
        $port = trim($pairs['Port'] ?? '');
        ctype_digit($port) && !preg_match('/:\d+$/', $source) && ($source .= ":$port");

        return self::for($source, $database, $pairs['User ID'] ?? '', $pairs['Password'] ?? '');
    }

    /**
     * The `Name=value;` pairs of a text.
     *
     * @return array<string,string>
     */
    public static function pairs(string $text): array
    {
        $pairs = [];
        foreach (explode(';', trim($text, " \t\r\n\"")) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2 && trim($pair[0]) !== '') {
                $pairs[trim($pair[0])] = trim($pair[1]);
            }
        }

        return $pairs;
    }

    /** What is written in the config. */
    public function __toString(): string
    {
        if ($this->driver === 'sqlite') {
            return 'URI=file:' . $this->source . ',version=3,UseUTF16Encoding=True';
        }

        // The port apart (Port=3307): the connector of MySQL reads no port in the Data Source
        return sprintf(
            'Data Source=%s;Database=%s;User ID=%s;Password=%s;Old Guids=true;',
            $this->port !== null && $this->host !== '' ? "{$this->host};Port={$this->port}" : $this->source,
            $this->database,
            $this->user,
            $this->password,
        ) . (self::isLocal($this->host) ? 'SslMode=None;' : '');
    }

    /**
     * Whether a host is this machine: localhost, 127.0.0.1, ::1, or a socket. A database there takes no TLS: the MySql.Data of an
     * OpenSimulator on Mono cannot do the TLS a MariaDB of today offers by default, and Robust then starts without its database.
     */
    public static function isLocal(string $host): bool
    {
        $host = strtolower(trim($host, " \t[]"));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_starts_with($host, '/');
    }

    /**
     * A connection string of a config with `SslMode=None;` added when its database is on this machine and it says no SslMode (a
     * MySQL one; isLocal()); null when there is nothing to add.
     */
    public static function withLocalTls(string $text): ?string
    {
        $value = trim($text);
        if ($value === '' || stripos($value, 'URI=file:') !== false) {
            return null;
        }
        $pairs = array_change_key_case(self::pairs($value), CASE_LOWER);
        $said = array_intersect_key($pairs, ['sslmode' => 1, 'ssl mode' => 1, 'ssl-mode' => 1]);
        $source = $pairs['data source'] ?? $pairs['server'] ?? $pairs['host'] ?? '';
        if ($said !== [] || $source === '' || !self::isLocal(self::split($source)[0])) {
            return null;
        }

        return rtrim($value, '; ') . ';SslMode=None;';
    }

    /**
     * The credentials as OSPDO takes them.
     *
     * @return array{host:string,port:?int,name:string,user:string,pass:string}|array{driver:string,name:string,user:string,pass:string}
     */
    public function credentials(): array
    {
        if ($this->driver === 'sqlite') {
            return ['driver' => 'sqlite', 'name' => $this->source, 'user' => '', 'pass' => ''];
        }

        return [
            'host' => $this->host,
            'port' => $this->port,
            'name' => $this->database,
            'user' => $this->user,
            'pass' => $this->password,
        ];
    }

    /** What tells one database from another. */
    public function key(): string
    {
        if ($this->driver === 'sqlite') {
            return "sqlite:{$this->source}";
        }

        return "{$this->user}@{$this->source}/{$this->database}";
    }

    /** @return array{0:string,1:?int} */
    private static function split(string $source): array
    {
        if (preg_match('/^(.+):(\d+)$/', $source, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return [$source, null];
    }
}
