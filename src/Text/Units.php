<?php

declare(strict_types=1);

namespace OpenSim\Text;

/**
 * The unit an amount is shown in: an area in km², as the grid status of w4os-wp shows it. The unit is a key of the catalog
 * (`unit_km2`: the number as %s).
 */
final class Units
{
    /**
     * An area in square meters, in km²: two decimals under 10 km², none above.
     *
     * @return array{0:float,1:string,2:int} the value in km², the key of the unit, its decimals
     */
    public static function area(float $squareMeters): array
    {
        $km2 = $squareMeters / 1_000_000;

        return [$km2, 'unit_km2', $km2 < 10 ? 2 : 0];
    }
}
