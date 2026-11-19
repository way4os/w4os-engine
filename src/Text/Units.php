<?php

declare(strict_types=1);

namespace OpenSim\Text;

/**
 * The unit an amount is shown in, so that it says something of it: a parcel in m², a few regions in hectares, a grid in km².
 * The unit is a key of the catalog (`unit_m2`, `unit_ha`, `unit_km2`: the number as %s).
 */
final class Units
{
    /**
     * An area in square meters, in the unit that suits it: m² under a hectare, hectares under a km², then km² (two decimals under
     * 100 km², none above).
     *
     * @return array{0:float,1:string,2:int} the value in that unit, the key of the unit, its decimals
     */
    public static function area(float $squareMeters): array
    {
        return match (true) {
            $squareMeters < 10_000 => [$squareMeters, 'unit_m2', 0],
            $squareMeters < 1_000_000 => [$squareMeters / 10_000, 'unit_ha', 2],
            default => [$squareMeters / 1_000_000, 'unit_km2', $squareMeters < 100_000_000 ? 2 : 0],
        };
    }
}
