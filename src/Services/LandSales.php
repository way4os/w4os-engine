<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\OSPDO;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Hypergrid\Destination;
use OpenSim\Models\Estate;
use OpenSim\Models\Parcel;

/**
 * The land for sale of the search (`parcelsales`), as Second Life lists it: every parcel for sale of the regions of the grid, shown
 * in search or not. The snapshot of a region holds only the parcels shown in search (`data_exposure = minimum` of [DataSnapshot]);
 * `all` would send the others to every search service the simulator registers with, those of other sites too. So the land of the
 * simulators, which the engine reads, gives them; the snapshot keeps the regions of the other grids.
 *
 *     LandSales::sync($config, $search);   // after the snapshots are read
 */
final class LandSales
{
    /** The maturity of a region, by its access (13 PG, 21 Mature, 42 Adult). */
    private const MATURITY = [13 => 'PG', 21 => 'Mature', 42 => 'Adult'];

    /**
     * Put in the land for sale every parcel for sale of the regions whose land is read, and take out those of these regions that
     * are not for sale any more.
     *
     * @return int how many parcels are for sale
     */
    public static function sync(GridConfig $config, OSPDO $search): int
    {
        try {
            $parcels = Parcel::all($config)->items();
        } catch (ChannelUnavailable) {
            return 0;
        }
        $regions = array_values(array_unique(array_map(static fn(Parcel $parcel): string => strtolower((string) $parcel->region()), $parcels)));
        if ($regions === []) {
            return 0;
        }
        $places = self::regions($config, $regions);
        $estates = self::estates($config);
        $gatekeeper = (string) ($config->find('Hypergrid', 'GatekeeperURI') ?? '');
        $marks = implode(',', array_fill(0, count($regions), '?'));
        $search->prepareAndExecute("DELETE FROM parcelsales WHERE LOWER(regionUUID) IN ($marks)", $regions);
        $sold = 0;
        foreach ($parcels as $parcel) {
            if (!$parcel->isForSale()) {
                continue;
            }
            $d = $parcel->detail();
            $region = strtolower((string) $parcel->region());
            [$x, $y, $z] = array_map(static fn(float $value): int => (int) round($value), $d['landing']);
            $search->insert('parcelsales', [
                'regionUUID' => $region,
                'parcelname' => (string) $d['name'],
                'parcelUUID' => (string) $d['uuid'],
                'area' => (int) $d['area'],
                'saleprice' => (int) $d['price'],
                'landingpoint' => "$x/$y/" . (int) round(Destination::height($z)),
                'infoUUID' => self::infoUuid($places[$region]['handle'] ?? 0, $x, $y),
                'dwell' => (int) $d['dwell'],
                'parentestate' => $estates[$region] ?? 1,
                'mature' => self::MATURITY[$places[$region]['access'] ?? 13] ?? 'PG',
                'gatekeeperURL' => $gatekeeper,
            ]);
            $sold++;
        }

        return $sold;
    }

    /**
     * The id a viewer asks a parcel by: the handle of its region and a point of it, as OpenSimulator builds it (BuildFakeParcelID).
     */
    public static function infoUuid(int $handle, int $x, int $y): string
    {
        $bytes = pack('P', $handle) . pack('v', $x) . "\0\0" . pack('v', $y) . "\0\0";

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * The handle and the access of the regions, from the regions table of the grid: its handle from its place when it has none.
     *
     * @param list<string> $uuids
     * @return array<string,array{handle:int,access:int}>
     */
    private static function regions(GridConfig $config, array $uuids): array
    {
        $pdo = Connections::pdo($config, 'GridService');
        if ($pdo === null || !$pdo->connected) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($uuids), '?'));
        $found = [];
        foreach ($pdo->get_results("SELECT uuid, regionHandle, access, locX, locY FROM regions WHERE LOWER(uuid) IN ($marks)", $uuids, \PDO::FETCH_ASSOC) as $row) {
            $handle = (int) ($row['regionHandle'] ?? 0) ?: (((int) ($row['locX'] ?? 0)) << 32) | (int) ($row['locY'] ?? 0);
            $found[strtolower((string) $row['uuid'])] = ['handle' => $handle, 'access' => (int) ($row['access'] ?? 13)];
        }

        return $found;
    }

    /** @return array<string,int> the number of the estate of each region */
    private static function estates(GridConfig $config): array
    {
        try {
            $estates = Estate::all($config)->items();
        } catch (ChannelUnavailable) {
            return [];
        }
        $found = [];
        foreach ($estates as $estate) {
            foreach ($estate->regions() as $region) {
                $found[strtolower((string) $region)] = $estate->number();
            }
        }

        return $found;
    }
}
