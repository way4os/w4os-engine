<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Data\OSPDO;
use OpenSim\Hypergrid\Destination;
use OpenSim\Models\Region;
use OpenSim\Text\Uuid;

/**
 * The pictures of the destinations of a guide, the engine's to find, the sites' to show: the picture of the parcel of each
 * destination, as the search knows it (the parcels of this grid, and of the grids whose simulators register with its search), by
 * the name of the destination, else by its landing point, else the only parcel of its region; the picture of a section is the first
 * of its destinations that has one. None is found: the site shows its placeholder.
 *
 *     (new DestinationPictures($search, $login))->of($grid->destinations());
 *     // ['Visit' => ['picture' => '<uuid>', 'destinations' => [['name' => ..., 'url' => ..., 'picture' => '<uuid>'], ...]]]
 */
final class DestinationPictures
{
    /** @var array<string,list<array<string,mixed>>> the parcels of a region, by its gatekeeper and its name, read once */
    private array $parcels = [];

    /**
     * @param ?OSPDO $search the database of the search of the grid, null when it has none
     * @param string $login the login address of the grid: its own places may have no gatekeeper in the tables
     */
    public function __construct(private readonly ?OSPDO $search, private readonly string $login = '') {}

    /**
     * The sections of a guide with their pictures.
     *
     * @param array<string,list<array{name:string,url:string}>> $sections DestinationList::parse()
     * @return array<string,array{picture:?string,destinations:list<array{name:string,url:string,picture:?string}>}>
     */
    public function of(array $sections): array
    {
        $found = [];
        foreach ($sections as $section => $destinations) {
            $pictured = array_map(fn(array $destination): array => $destination + ['picture' => $this->picture($destination['url'], $destination['name'])], $destinations);
            $first = array_values(array_filter(array_column($pictured, 'picture')))[0] ?? null;
            $found[$section] = ['picture' => $first, 'destinations' => $pictured];
        }

        return $found;
    }

    /** The picture of the parcel of a destination (an asset UUID), null when the search knows none. */
    public function picture(string $destination, string $name = ''): ?string
    {
        if ($this->search === null || !$this->search->connected) {
            return null;
        }
        $at = Destination::parse($destination, $this->login !== '' ? $this->login : null);
        if ($at['region'] === '' || $at['host'] === '') {
            return null;
        }
        $parcels = $this->parcels($at['host'], (string) $at['port'], $at['region']);
        // The point of a landing, its x and y: 128/128/25 or 128,128,25
        $point = static fn(string $at): string => implode('/', array_map(static fn($v): string => (string) round((float) $v), array_slice(preg_split('#[/,]#', $at) ?: [], 0, 2)));
        $found = null;
        foreach ($parcels as $parcel) {
            if ($name !== '' && strcasecmp((string) $parcel['name'], $name) === 0) {
                $found = $parcel;

                break;
            }
            if ($at['pos'] !== '' && $point((string) $parcel['landing']) === $point($at['pos'])) {
                $found ??= $parcel;
            }
        }
        $found ??= count($parcels) === 1 ? $parcels[0] : null;
        $picture = strtolower((string) ($found['picture'] ?? ''));

        return $picture === '' || in_array($picture, Uuid::NULL_KEYS, true) ? null : $picture;
    }

    /** @return list<array<string,mixed>> the parcels the search has of a region of a grid */
    private function parcels(string $host, string $port, string $region): array
    {
        $gatekeeper = "http://$host:$port";
        $key = strtolower("$gatekeeper/$region");
        if (isset($this->parcels[$key])) {
            return $this->parcels[$key];
        }
        // The tables keep the address with or without its last slash; the places of the grid itself may have none
        $own = Destination::loginParts($this->login);
        $local = $own !== null && strtolower((string) $own['host']) === $host && (string) ($own['port'] ?? 80) === $port;
        $regions = Region::searchTable($this->search);

        return $this->parcels[$key] = $this->search->get_results(
            "SELECT p.parcelname AS name, p.landingpoint AS landing, p.imageUUID AS picture FROM parcels AS p INNER JOIN $regions AS r ON r.regionUUID = p.regionUUID WHERE LOWER(r.regionname) = LOWER(:region) AND p.gatekeeperURL IN (:one, :two, :three)",
            ['region' => $region, 'one' => $gatekeeper, 'two' => "$gatekeeper/", 'three' => $local ? '' : $gatekeeper],
            \PDO::FETCH_ASSOC,
        ) ?: [];
    }
}
