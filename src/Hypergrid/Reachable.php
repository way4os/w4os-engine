<?php

declare(strict_types=1);

namespace W4OS\Engine\Hypergrid;

/**
 * What a viewer can be sent to: a grid, a region, a parcel, a place (Place: a popular place, an entry of the guide, a result of
 * the search, an event, a pick). Its link is always asked of it, in the format wanted, never built apart (TeleportLinks).
 *
 *     $region->tpUri();                                  // grid.example.org:8002/Welcome
 *     $region->tpLink();                                 // AUTO: the link for where it is shown
 *     $parcel->tpLink(Destination::HOP);                 // hop://grid.example.org:8002/Welcome/128/128/25
 *     $grid->place('Plaza/10/20/30')->tpLink(Destination::AUTO, ['client' => 'viewer']);
 */
interface Reachable
{
    /** Where it is, as Destination writes it: host:port/Region Name/x/y/z (a grid alone: host:port); null when it is not known. */
    public function tpUri(): ?string;

    /**
     * Its link in any format of Destination, the ones ruled out for the viewers too (HG, V3HG, LOCAL, APPTP, MAP...), AUTO without
     * one (the link for where it is shown); several formats at once are one per line, or an array with Destination::AS_ARRAY;
     * null when it has no address.
     *
     * @param array{agent?:?string,client?:string,login?:?string} $context where it is shown (Destination::format()); the login of
     *                                                                    its grid is the object's, unless the context gives one
     */
    public function tpLink(?int $format = null, array $context = []): string|array|null;
}
