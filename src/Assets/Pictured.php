<?php

declare(strict_types=1);

namespace W4OS\Engine\Assets;

/**
 * What a page shows with a picture: a place, a parcel, a region, a result of the search, a group. Its picture is always asked of it,
 * never chosen apart (Pictures): its own, else the image of its title (TitleImage), a page never shows initials or a placeholder for
 * it. A person is not one: the picture of nothing of a person is the kit's.
 *
 *     $parcel->picture();   // its snapshot, else the image of its name: '<uuid>' or 'title-...', shown at <assets>/<id>.jpg
 */
interface Pictured
{
    /** The id of its picture: the UUID of its asset, else the one of the image of its title. */
    public function picture(): string;
}
