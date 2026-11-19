<?php

declare(strict_types=1);

namespace OpenSim\Config;

/**
 * A connection string of OpenSimulator as a setting: its server, its port, its database, its user and its password, the other pairs
 * (Old Guids, SslMode...) kept as they are. Written back in the form of dotNet
 * (`Data Source=host;Port=3306;Database=robust;User ID=opensim;Password=...;`). The password is never shown.
 */
final class ConnectionStringType implements SettingType
{
    /** The pairs that are parts of their own. */
    private const PARTS = ['Data Source', 'Port', 'Database', 'User ID', 'Password'];

    /** @return ?array{host:string,port:?int,database:string,user:string,password:string,options:array<string,string>} */
    public function decode(string $raw): ?array
    {
        $pairs = ConnectionString::pairs($raw);
        if ($pairs === []) {
            return null;
        }
        $source = $pairs['Data Source'] ?? '';
        $port = isset($pairs['Port']) && ctype_digit($pairs['Port']) ? (int) $pairs['Port'] : null;
        if (preg_match('/^(.+):(\d+)$/', $source, $m) === 1) {
            [$source, $port] = [$m[1], $port ?? (int) $m[2]];
        }

        return [
            'host' => $source,
            'port' => $port,
            'database' => $pairs['Database'] ?? '',
            'user' => $pairs['User ID'] ?? '',
            'password' => $pairs['Password'] ?? '',
            'options' => array_diff_key($pairs, array_flip(self::PARTS)),
        ];
    }

    public function encode(mixed $value, ?string $current = null): string
    {
        $was = $current !== null ? $this->decode($current) ?? [] : [];
        $value = (is_array($value) ? $value : []) + $was;
        $password = (string) ($value['password'] ?? '');
        $password === Settings::MASK && ($password = (string) ($was['password'] ?? ''));
        $pairs = [
            'Data Source' => (string) ($value['host'] ?? ''),
            'Port' => isset($value['port']) && $value['port'] !== '' ? (string) (int) $value['port'] : '',
            'Database' => (string) ($value['database'] ?? ''),
            'User ID' => (string) ($value['user'] ?? ''),
            'Password' => $password,
        ];
        $text = '';
        foreach ($pairs + (array) ($value['options'] ?? []) as $name => $part) {
            ($part !== '' || $name === 'Password') && ($text .= "$name=$part;");
        }

        return $text;
    }

    public function fields(): array
    {
        return [
            ['name' => 'host', 'label' => 'db_host', 'type' => 'string'],
            ['name' => 'port', 'label' => 'db_port', 'type' => 'integer', 'placeholder' => '3306'],
            ['name' => 'database', 'label' => 'db_name', 'type' => 'string'],
            ['name' => 'user', 'label' => 'db_user', 'type' => 'string'],
            ['name' => 'password', 'label' => 'db_password', 'type' => 'secret'],
        ];
    }

    public function mask(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        return array_replace($value, ['password' => ($value['password'] ?? '') !== '' ? Settings::MASK : '']);
    }
}
