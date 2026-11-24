<?php

declare(strict_types=1);

namespace W4OS\Engine\Services;

use W4OS\Engine\Result;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Runtime\ClockInterface;
use W4OS\Engine\Text\Uuid;

/**
 * The questions of the search module of the simulators and of the viewers (OpenSimSearch): places, popular places, land for sale,
 * events, classifieds. Each is an XML-RPC method that gets the struct the viewer sent and answers the rows the viewer shows.
 *
 *     $query = new SearchQuery($search, 'regionsregister', profiles: $profiles, gatekeeper: 'grid.example.org:8002');
 *     $rpc = new XmlRpc($query->methods());
 *
 * The search can be shared by several grids: `gatekeeper` (the `gk` argument of the address, which the viewer does not send) tells for
 * which grid the search is made, and keeps the rows of the others out of the answer.
 */
final class SearchQuery
{
    /** The categories of the events, by number. */
    private const EVENT_CATEGORIES = [
        18 => 'Discussion',
        19 => 'Sports',
        20 => 'Live Music',
        22 => 'Commercial',
        23 => 'Nightlife/Entertainment',
        24 => 'Games/Contests',
        25 => 'Pageants',
        26 => 'Education',
        27 => 'Arts and Culture',
        28 => 'Charity/Support Groups',
        29 => 'Miscellaneous',
    ];

    private readonly ?string $gatekeeper;

    /**
     * @param string $regions the table of the regions of the search
     * @param ?OSPDO $profiles the database of the profiles (the classifieds), the grid's
     * @param ?OSPDO $accounts the database of the user accounts, to publish a profile
     * @param ?OSPDO $presence the database of the presence service, who holds the secure sessions
     * @param ?string $gatekeeper the grid the search is made for, as the `gk` argument says it
     */
    public function __construct(
        private readonly OSPDO $search,
        private readonly string $regions,
        private readonly ?OSPDO $profiles = null,
        private readonly ?OSPDO $accounts = null,
        private readonly ?OSPDO $presence = null,
        private readonly ?string $gk = null,
        private readonly ?ClockInterface $clock = null,
    ) {
        $this->gatekeeper = self::gatekeeperUrl($gk);
    }

    /** The address of a gatekeeper as the tables keep it: with its scheme. */
    public static function gatekeeperUrl(?string $gatekeeper): ?string
    {
        $gatekeeper = trim((string) $gatekeeper);

        return $gatekeeper === ''
            ? null
            : (preg_match('#https?://#', $gatekeeper) === 1
                ? $gatekeeper
                : "http://$gatekeeper");
    }

    /** @return array<string,\Closure(array<string,mixed>):(array<string,mixed>|string)> the XML-RPC methods, by name */
    public function methods(): array
    {
        return [
            'dir_places_query' => $this->places(...),
            'dir_popular_query' => $this->popular(...),
            'dir_land_query' => $this->land(...),
            'dir_events_query' => $this->events(...),
            'dir_classified_query' => $this->classifieds(...),
            'event_info_query' => $this->eventInfo(...),
            'classifieds_info_query' => $this->classifiedInfo(...),
            'avatar_publish_update' => $this->publish(...),
        ];
    }

    /**
     * The places (parcels shown in search) whose name or description has the words.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function places(array $request): array
    {
        $flags = (int) ($request['flags'] ?? 0);
        $text = (string) ($request['text'] ?? '');
        if ('%' . implode('%', explode(' ', $text)) . '%' === '%%%') {
            return XmlRpc::envelope(false, 'Invalid search terms');
        }
        [$terms, $values] = SearchFilters::places($text, (int) ($request['category'] ?? 0), SearchFilters::ratings($flags), 'parcels');
        if ($this->gatekeeper !== null) {
            $terms[] = 'parcels.gatekeeperURL = :gatekeeper';
            $values['gatekeeper'] = $this->gatekeeper;
        }
        // By traffic, else by name
        $order = $flags & 1024 ? 'dwell DESC, parcelname' : 'parcelname';
        $rows = $this->rows(
            $this->search,
            "SELECT * FROM parcels INNER JOIN {$this->regions} AS r ON parcels.regionUUID = r.regionUUID WHERE " .
                implode(' AND ', $terms) .
                " ORDER BY $order LIMIT 101 OFFSET " .
                $this->offset($request),
            $values,
        );
        $data = array_map(
            static fn(array $row): array => array_merge($row, [
                'parcel_id' => $row['infouuid'],
                'name' => $row['parcelname'],
                'for_sale' => 'False',
                'auction' => 'False',
            ]),
            $rows,
        );

        // Nothing found is an empty answer, not an error: the viewer says it in its list, once, where an error is an alert for each
        // request, and the viewers ask a search several times (they show the answers of their last request only)
        return XmlRpc::envelope(true, '', $data);
    }

    /**
     * The popular places, by traffic.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function popular(array $request): array
    {
        $text = (string) ($request['text'] ?? '');
        $flags = (int) ($request['flags'] ?? 0);
        $hypergrid = ($request['include_hypergrid'] ?? null) !== 'false';
        $landSales = ($request['include_landsales'] ?? null) === 'true';
        $terms = [];
        $values = [];
        $flags & (1 << 12) && ($terms[] = 'has_picture = 1');
        array_push($terms, ...SearchFilters::rated(SearchFilters::ratings($flags), 'pop'));
        if ($text !== '') {
            $terms[] = '(name LIKE :text)';
            $values['text'] = "%$text%";
        }
        $gatekeeper = self::gatekeeperUrl((string) ($request['gatekeeper_url'] ?? '')) ?? $this->gatekeeper;
        if (!$hypergrid && $gatekeeper !== null) {
            $terms[] = 'pop.gatekeeperURL = :gatekeeper';
            $values['gatekeeper'] = $gatekeeper;
        }
        $join = '';
        if (!$landSales) {
            $join = 'LEFT JOIN parcelsales AS sales ON sales.parcelUUID = pop.parcelUUID';
            $terms[] = 'sales.regionUUID IS NULL';
        }
        $rows = $this->rows(
            $this->search,
            'SELECT pop.infoUUID, pop.name, pop.dwell, pop.gatekeeperURL, r.regionname, r.regionUUID, par.landingpoint, par.imageUUID FROM popularplaces AS pop ' .
                "INNER JOIN parcels AS par ON pop.parcelUUID = par.parcelUUID INNER JOIN {$this->regions} AS r ON par.regionUUID = r.regionUUID $join" .
                ($terms === [] ? '' : ' WHERE ' . implode(' AND ', $terms)) .
                ' ORDER BY pop.dwell DESC, par.parcelname LIMIT 100 OFFSET ' .
                $this->offset($request),
            $values,
        );

        return XmlRpc::envelope(
            true,
            '',
            array_map(
                static fn(array $row): array => [
                    'parcel_id' => $row['infoUUID'],
                    'name' => $row['name'],
                    'dwell' => $row['dwell'],
                    'gatekeeperURL' => $row['gatekeeperURL'],
                    'regionname' => $row['regionname'],
                    'regionUUID' => $row['regionUUID'],
                    'landingpoint' => $row['landingpoint'],
                    'imageUUID' => $row['imageUUID'],
                ],
                $rows,
            ),
        );
    }

    /**
     * The land for sale.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function land(array $request): array
    {
        $flags = (int) ($request['flags'] ?? 0);
        $type = (int) ($request['type'] ?? 0);
        $terms = [];
        $values = [];
        if ($type !== 4294967295) {
            // Auctions first, to leave quickly: (24 is bits 3 and 4 of SearchTypeFlags)
            if (($type & 26) === 2) {
                return XmlRpc::envelope(true, '', []);
            }
        }
        $kind = $type === 4294967295 ? 'any' : match ($type & 24) { 8 => 'mainland', 16 => 'estate', default => 'any' };
        [$terms, $values] = SearchFilters::land(
            $kind,
            $flags & (1 << 20) ? (int) ($request['price'] ?? 0) : null,
            $flags & (1 << 21) ? (int) ($request['area'] ?? 0) : null,
            SearchFilters::ratings($flags),
        );
        if ($this->gatekeeper !== null) {
            $terms[] = 'gatekeeperURL = :gatekeeper';
            $values['gatekeeper'] = $this->gatekeeper;
        }
        // The price per meter is always asked by the map, and makes a good default order
        $order = 'lsq';
        $flags & (1 << 19) && ($order = 'parcelname');
        $flags & (1 << 16) && ($order = 'saleprice');
        $flags & (1 << 18) && ($order = 'area');
        $flags & (1 << 15) || ($order .= ' DESC');
        $rows = $this->rows(
            $this->search,
            'SELECT *, saleprice / area AS lsq FROM parcelsales' .
                ($terms === [] ? '' : ' WHERE ' . implode(' AND ', $terms)) .
                " ORDER BY $order LIMIT 101 OFFSET " .
                $this->offset($request),
            $values,
        );

        return XmlRpc::envelope(
            true,
            '',
            array_map(
                static fn(array $row): array => [
                    'parcel_id' => $row['infoUUID'],
                    'name' => $row['parcelname'],
                    'auction' => 'false',
                    'for_sale' => 'true',
                    'sale_price' => $row['saleprice'],
                    'landing_point' => $row['landingpoint'],
                    'region_UUID' => $row['regionUUID'],
                    'area' => $row['area'],
                ],
                $rows,
            ),
        );
    }

    /**
     * The events of a day (a number of days from today), or the ones to come (`u`), by category and words: `day|category|words`.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function events(array $request): array
    {
        $text = (string) ($request['text'] ?? '');
        $flags = (int) ($request['flags'] ?? 0);
        if ($text === '%%%') {
            return XmlRpc::envelope(false, 'Invalid search terms');
        }
        $pieces = explode('|', $text);
        [$terms, $values] = SearchFilters::events(
            $pieces[0] === 'u' ? 'u' : (int) $pieces[0],
            (int) ($pieces[1] ?? 0),
            SearchFilters::ratings($flags),
            count($pieces) < 3 ? '' : $pieces[2],
            $this->now(),
        );
        $rows = $this->rows(
            $this->search,
            'SELECT owneruuid, name, eventid, dateUTC, eventflags, globalPos FROM ' .
                SearchTables::EVENTS .
                ($terms === [] ? '' : ' WHERE ' . implode(' AND ', $terms)) .
                ' LIMIT 101 OFFSET ' .
                $this->offset($request),
            $values,
        );

        return XmlRpc::envelope(
            true,
            '',
            array_map(static function (array $row): array {
                $date = new \DateTime('@' . $row['dateUTC']);
                $date->setTimezone(new \DateTimeZone('America/Los_Angeles'));

                // The landing point is only needed to put the events on the map
                return [
                    'owner_id' => $row['owneruuid'],
                    'name' => $row['name'],
                    'event_id' => $row['eventid'],
                    'date' => $date->format('m/d h:i A'),
                    'unix_time' => $row['dateUTC'],
                    'event_flags' => $row['eventflags'],
                    'landing_point' => $row['globalPos'],
                ];
            }, $rows),
        );
    }

    /**
     * The classifieds, by price of the listing.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>|string
     */
    public function classifieds(array $request): array|string
    {
        if (!$this->hasClassifieds()) {
            return XmlRpc::plain(false);
        }
        $text = (string) ($request['text'] ?? '');
        $flags = (int) ($request['flags'] ?? 0);
        $category = (int) ($request['category'] ?? 0);
        if ($text === '%%%') {
            return XmlRpc::envelope(false, 'Invalid search terms', []);
        }
        // The ones not expired yet, as the web search lists them
        [$terms, $values] = SearchFilters::classifieds($text, $category, SearchFilters::ratings($flags, SearchFilters::CLASSIFIED_BITS), now: $this->now());
        $rows = $this->rows(
            $this->profiles,
            'SELECT * FROM classifieds' .
                ($terms === [] ? '' : ' WHERE ' . implode(' AND ', $terms)) .
                ' ORDER BY priceforlisting DESC LIMIT 101 OFFSET ' .
                $this->offset($request),
            $values,
        );

        return XmlRpc::envelope(
            true,
            '',
            array_map(
                static fn(array $row): array => [
                    'classifiedid' => $row['classifieduuid'],
                    'name' => $row['name'],
                    'classifiedflags' => $row['classifiedflags'],
                    'creation_date' => $row['creationdate'],
                    'expiration_date' => $row['expirationdate'],
                    'priceforlisting' => $row['priceforlisting'],
                ],
                $rows,
            ),
        );
    }

    /**
     * One event, with all it says.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function eventInfo(array $request): array
    {
        $rows = $this->rows($this->search, 'SELECT * FROM ' . SearchTables::EVENTS . ' WHERE eventID = ?', [
            $request['eventID'] ?? 0,
        ]);

        return XmlRpc::envelope(
            true,
            '',
            array_map(function (array $row): array {
                // The grid asking is the one of the event: its region by its name alone. Another grid: host:port:Region, the way a
                // viewer names a region of another grid, which its panel of the event links as hop://<its grid>/host:port:Region/x/y/z
                $own = $this->gatekeeper !== null
                    && rtrim($this->gatekeeper, '/') === rtrim((string) self::gatekeeperUrl((string) ($row['gatekeeperURL'] ?? '')), '/');
                $stored = (string) $row['simname'];
                $simname = $own
                    ? trim((string) preg_replace('/^\S+:\d+[ :]/', '', $stored))
                    : ((string) (new \W4OS\Engine\Hypergrid\Place($stored))->tpLink(\W4OS\Engine\Hypergrid\Destination::LSL) ?: $stored);

                return [
                    'event_id' => $row['eventid'],
                    'creator' => $row['creatoruuid'],
                    'name' => $row['name'],
                    'category' => self::EVENT_CATEGORIES[(int) $row['category']] ?? '*Unspecified*',
                    'description' => self::viewerDescription((string) $row['description'], $stored),
                    'date' => date('o-m-d H:i:s', (int) $row['dateUTC']),
                    'dateUTC' => $row['dateUTC'],
                    'duration' => $row['duration'],
                    'covercharge' => $row['covercharge'],
                    'coveramount' => $row['coveramount'],
                    'simname' => $simname,
                    'globalposition' => $row['globalPos'],
                    'eventflags' => $row['eventflags'],
                ];
            }, $rows),
        );
    }

    /**
     * The description of an event as a viewer is given it: the teleport links of its place first (hop:// and secondlife:///app/teleport/),
     * which the panel of the event of a viewer cannot open by itself, then its text. The one place they are added: where their form
     * for the viewers is made, never stored (a row of before that has them has them once).
     *
     * @param string $place the place of the event as the table keeps it (simname: host:port Region/x/y/z)
     */
    public static function viewerDescription(string $description, string $place): string
    {
        $links = trim((string) (new \W4OS\Engine\Hypergrid\Place($place))->tpLink(\W4OS\Engine\Hypergrid\Destination::APPTP + \W4OS\Engine\Hypergrid\Destination::HOP));
        $text = self::withoutViewerLinks($description);

        return $links === '' ? $text : "$links\n\n$text";
    }

    /**
     * A description without the block of teleport links the parser of the events stored before it (lines of hop:// and
     * secondlife:///app/teleport/ links, then an empty line), at its start only; any other address stays.
     */
    public static function withoutViewerLinks(string $description): string
    {
        return (string) preg_replace('#\A(?:(?:hop://|secondlife:///app/teleport/)\S+\R)+\R#', '', $description);
    }

    /**
     * One classified, with all it says.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>|string
     */
    public function classifiedInfo(array $request): array|string
    {
        if (!$this->hasClassifieds()) {
            return XmlRpc::plain(false);
        }
        $rows = $this->rows($this->profiles, 'SELECT * FROM classifieds WHERE classifieduuid = ?', [
            $request['classifiedID'] ?? '',
        ]);
        $fields = [
            'classifieduuid',
            'creatoruuid',
            'creationdate',
            'expirationdate',
            'category',
            'name',
            'description',
            'parceluuid',
            'parentestate',
            'snapshotuuid',
            'simname',
            'posglobal',
            'parcelname',
            'classifiedflags',
            'priceforlisting',
        ];

        return XmlRpc::envelope(
            true,
            '',
            array_map(static fn(array $row): array => array_intersect_key($row, array_flip($fields)), $rows),
        );
    }

    /**
     * The "show in search" box of a profile. The profile service of Robust gets the box with the rest of the profile and never writes
     * it where OpenSimulator reads it (the first flag of the account), so the search module tells it here. Only the simulator an
     * avatar is connected to holds its secure session, and may.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function publish(array $request): array
    {
        $avatar = (string) ($request['avatar_id'] ?? '');
        if (!Uuid::isValid($avatar)) {
            throw new Failure('Bad request: avatar_id is not an avatar', 400);
        }
        if (!$this->hasSession($avatar, $request['secure_session_id'] ?? '')) {
            throw new Failure('Forbidden: the secure session is not the one of this avatar', 403);
        }
        $found =
            $this->accounts === null
                ? []
                : $this->rows($this->accounts, 'SELECT UserFlags FROM UserAccounts WHERE PrincipalID = :id', [
                    'id' => $avatar,
                ]);
        if ($found === []) {
            return XmlRpc::envelope(false, 'This avatar has no account', []);
        }
        // The other flags of the account (identified, transacted, age verified...) are not the user's choice here
        $flags =
            ((int) $found[0]['UserFlags'] & ~1) |
            (Result::isTrue($request['allow_publish'] ?? false) ? 1 : 0);
        $this->accounts?->prepareAndExecute('UPDATE UserAccounts SET UserFlags = :flags WHERE PrincipalID = :id', [
            'flags' => $flags,
            'id' => $avatar,
        ]);

        return XmlRpc::envelope(true, '', []);
    }

    /** Whether the caller holds the secure session of an avatar who is in the grid. */
    private function hasSession(string $avatar, mixed $secure): bool
    {
        if ($this->presence === null) {
            return false;
        }
        $rows = $this->rows($this->presence, 'SELECT SecureSessionID FROM Presence WHERE UserID = :user', [
            'user' => $avatar,
        ]);
        $known = strtolower((string) ($rows[0]['SecureSessionID'] ?? ''));
        $given = is_string($secure) ? strtolower($secure) : '';

        return $known !== '' && !Uuid::isNullKey($known) && $given !== '' && hash_equals($known, $given);
    }

    private function hasClassifieds(): bool
    {
        return $this->profiles !== null && $this->profiles->connected && $this->profiles->table_exists('classifieds');
    }

    /** The row to start from: the module sends the page as a string, it goes into the query as a number. */
    private function offset(array $request): int
    {
        return max(0, (int) ($request['query_start'] ?? 0));
    }

    /**
     * @param ?array<string,mixed>|list<mixed> $values
     * @return list<array<string,mixed>>
     */
    private function rows(?OSPDO $db, string $sql, ?array $values = []): array
    {
        $statement = $db?->prepareAndExecute($sql, $values ?: null);

        return $statement ? $statement->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    private function now(): int
    {
        return (int) ($this->clock?->time() ?? time());
    }
}
