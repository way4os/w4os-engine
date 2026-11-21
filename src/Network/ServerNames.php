<?php

declare(strict_types=1);

namespace W4OS\Engine\Network;

use W4OS\Engine\Data\Cache;
use W4OS\Engine\Runtime\Environment;

/**
 * The machine a host stands for, however it is written: its aliases and its addresses are one server. Its key is the address it
 * resolves to (the lowest of them), its label its real name: the name of this machine for its own (SYSTEMIP, localhost, its name,
 * its addresses), the reverse DNS of the others, else the host as written. Each host is resolved once for a while (TTL), so that a
 * list does not ask the DNS for each row, and does not keep an old answer long.
 *
 *     ServerNames::of($environment, 'play.example.org');   // ['key' => '203.0.113.7', 'label' => 'srv7.example.org']
 */
final class ServerNames
{
    /** How long a host is kept resolved, in seconds. */
    public const TTL = 600;

    /** The hosts that are this machine, as the configs of OpenSimulator write it. */
    private const HERE = ['', 'systemip', 'localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /** @var ?array{addresses:\Closure(string):list<string>,name:\Closure(string):?string,self:\Closure():array{name:string,addresses:list<string>}} */
    private static ?array $resolver = null;

    /**
     * Resolve with other functions (a test's), or the system's again with null: `addresses` of a host, `name` of an address (its
     * reverse DNS, null without one), `self` the name and the addresses of this machine.
     *
     * @param ?array{addresses:\Closure(string):list<string>,name:\Closure(string):?string,self:\Closure():array{name:string,addresses:list<string>}} $resolver
     */
    public static function using(?array $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** @return array{key:string,label:string} */
    public static function of(Environment $environment, string $host): array
    {
        $host = strtolower(trim($host, " []\t"));

        return Cache::for($environment)->remember('server.' . $host, static fn(): array => self::resolve($host), self::TTL);
    }

    /** @return array{key:string,label:string} */
    private static function resolve(string $host): array
    {
        $resolver = self::$resolver ?? self::system();
        $self = ($resolver['self'])();
        $here = ['key' => strtolower($self['name']), 'label' => $self['name']];
        if (in_array($host, [...self::HERE, strtolower($self['name']), strtolower(explode('.', $self['name'])[0])], true)) {
            return $here;
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($resolver['addresses'])($host);
        if ($addresses === []) {
            return ['key' => $host, 'label' => $host];
        }
        if (array_intersect($addresses, $self['addresses']) !== []) {
            return $here;
        }
        sort($addresses);
        $name = ($resolver['name'])($addresses[0]);

        return ['key' => $addresses[0], 'label' => $name !== null && $name !== '' ? $name : $host];
    }

    /** @return array{addresses:\Closure(string):list<string>,name:\Closure(string):?string,self:\Closure():array{name:string,addresses:list<string>}} */
    private static function system(): array
    {
        return [
            'addresses' => static fn(string $host): array => array_values(array_unique([
                ...(gethostbynamel($host) ?: []),
                ...array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            ])),
            'name' => static function (string $ip): ?string {
                $name = @gethostbyaddr($ip);

                return is_string($name) && $name !== $ip ? $name : null;
            },
            'self' => static function (): array {
                $name = (string) gethostname();

                return ['name' => $name, 'addresses' => gethostbynamel($name) ?: []];
            },
        ];
    }
}
