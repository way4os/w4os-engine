<?php

namespace W4OS\Engine\Provisioning;

/**
 * What the grid service of a Robust tells of its regions, over HTTP: no database
 * access is needed, so it works for a grid whose Robust is on another machine.
 * It is asked on the private port of Robust, which every simulator of the grid
 * has to reach to register its regions.
 */
final class RobustGrid
{
    private const NO_SCOPE = '00000000-0000-0000-0000-000000000000';

    /**
     * The places taken around a place, within $radius blocks.
     *
     * @return array<string,true>|null null when the grid does not answer
     */
    public static function locations(string $host, int $port, int $x, int $y, int $radius = 40): ?array
    {
        $answer = self::ask($host, $port, [
            'METHOD' => 'get_region_range',
            'SCOPEID' => self::NO_SCOPE,
            'XMIN' => max(0, $x - $radius) * 256,
            'XMAX' => ($x + $radius) * 256,
            'YMIN' => max(0, $y - $radius) * 256,
            'YMAX' => ($y + $radius) * 256,
        ]);
        if ($answer === null) {
            return null;
        }
        $used = [];
        foreach (self::regions($answer) as [$rx, $ry, $width, $height]) {
            LocationFinder::take($used, $rx, $ry, $width, $height);
        }

        return $used;
    }

    /**
     * Whether the grid has a region of this name (the grid compares names without
     * the case).
     *
     * @return bool|null null when the grid does not answer
     */
    public static function hasRegion(string $host, int $port, string $name): ?bool
    {
        $answer = self::ask($host, $port, [
            'METHOD' => 'get_region_by_name',
            'SCOPEID' => self::NO_SCOPE,
            'NAME' => $name,
        ]);

        return $answer === null ? null : self::named($answer);
    }

    /** The UUID registered for this name: false if absent, null if the grid cannot be checked. */
    public static function regionUuid(string $host, int $port, string $name): string|false|null
    {
        return self::uuidOf(self::ask($host, $port, ['METHOD' => 'get_region_by_name', 'SCOPEID' => self::NO_SCOPE, 'NAME' => $name]));
    }

    /** The flag of a region whose simulator registered it and has not left the grid since (RegionFlags.RegionOnline). */
    public const ONLINE = 4;

    /**
     * What the grid keeps of a region of this name: its UUID and name, its place (in blocks) and size, its owner, the simulator that
     * registered it last and its flags.
     *
     * @return array{uuid:string,name:string,location:string,size:int,owner:string,server:string,flags:int}|false|null false when
     *         the grid has none, null when it does not answer
     */
    public static function region(string $host, int $port, string $name): array|false|null
    {
        return self::recordOf(self::ask($host, $port, ['METHOD' => 'get_region_by_name', 'SCOPEID' => self::NO_SCOPE, 'NAME' => $name]));
    }

    /** The region an answer of get_region_by_name describes: false when it describes none, null when it is not an answer. */
    public static function recordOf(?string $xml): array|false|null
    {
        $uuid = self::uuidOf($xml);
        if (!is_string($uuid)) {
            return $uuid;
        }
        $region = simplexml_load_string((string) $xml)->result;

        return [
            'uuid' => $uuid,
            'name' => trim((string) $region->regionName),
            'location' => intdiv((int) $region->locX, 256) . ',' . intdiv((int) $region->locY, 256),
            'size' => max(256, (int) ($region->sizeX ?? 256)),
            'owner' => \W4OS\Engine\Text\Uuid::isValid((string) ($region->owner_uuid ?? '')) ? (string) $region->owner_uuid : '',
            'server' => trim((string) ($region->serverURI ?? '')),
            'flags' => (int) ($region->flags ?? 0),
        ];
    }

    /** Whether a simulator answers at this address (the serverURI a region was registered from). */
    public static function serving(string $server, int $timeout = 3): bool
    {
        if (!preg_match('#^https?://#', $server)) {
            return false;
        }
        $body = \W4OS\Engine\Network\Http::get(rtrim($server, '/') . '/simstatus/', $timeout);

        return is_string($body) && trim($body) === 'OK';
    }

    /** The name (First Last) of an account of the grid: false when it has none, null when it does not answer. */
    public static function accountName(string $host, int $port, string $uuid): string|false|null
    {
        $xml = self::ask($host, $port, ['METHOD' => 'getaccount', 'ScopeID' => self::NO_SCOPE, 'UserID' => $uuid], 'accounts');
        if ($xml === null) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($document === false || !isset($document->result)) {
            return null;
        }
        $name = trim(trim((string) ($document->result->FirstName ?? '')) . ' ' . trim((string) ($document->result->LastName ?? '')));

        return $name !== '' ? $name : false;
    }

    /** The name registered for an existing UUID: false if absent, null if the grid cannot be checked. */
    public static function regionName(string $host, int $port, string $uuid): string|false|null
    {
        return self::nameOf(self::ask($host, $port, ['METHOD' => 'get_region_by_uuid', 'SCOPEID' => self::NO_SCOPE, 'REGIONID' => $uuid]));
    }

    /** The name of a valid registered region, keeping an absent region distinct from an unavailable reply. */
    public static function nameOf(?string $xml): string|false|null
    {
        $uuid = self::uuidOf($xml);
        if (!is_string($uuid)) {
            return $uuid;
        }
        $document = simplexml_load_string($xml);
        $name = trim((string) ($document->result->regionName ?? ''));
        return $name !== '' ? $name : null;
    }

    /** The UUID in a registry reply, keeping an absent region distinct from an invalid/unavailable reply. */
    public static function uuidOf(?string $xml): string|false|null
    {
        if ($xml === null) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($document === false || !isset($document->result)) {
            return null;
        }
        if (isset($document->result->uuid)) {
            $uuid = (string) $document->result->uuid;
            return \W4OS\Engine\Text\Uuid::isValid($uuid) ? $uuid : null;
        }
        return in_array(strtolower(trim((string) $document->result)), ['null', 'false'], true) ? false : null;
    }

    /** Whether an answer to get_region_by_name describes a region. */
    public static function named(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document !== false && isset($document->result->uuid);
    }

    /**
     * The regions of an answer of the grid service: their place, in blocks, and
     * the blocks they take.
     *
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    public static function regions(?string $xml): array
    {
        if ($xml === null) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($document === false) {
            return [];
        }
        $regions = [];
        foreach ($document->children() as $name => $region) {
            if (str_starts_with((string) $name, 'region') && isset($region->locX, $region->locY)) {
                $regions[] = [
                    intdiv((int) $region->locX, 256),
                    intdiv((int) $region->locY, 256),
                    max(1, intdiv((int) ($region->sizeX ?? 256), 256)),
                    max(1, intdiv((int) ($region->sizeY ?? 256), 256)),
                ];
            }
        }

        return $regions;
    }

    /** The answer of the grid service to a request, null when it does not answer. */
    private static function ask(string $host, int $port, array $fields, string $service = 'grid'): ?string
    {
        // Whatever its status: the grid service answers its errors in its XML
        [$status, $body] = \W4OS\Engine\Network\Http::exchange("http://$host:$port/$service", $fields);

        return $status === 0 || $body === '' ? null : $body;
    }
}
