<?php

declare(strict_types=1);

namespace W4OS\Engine\Services;

use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Hypergrid\Destination;
use W4OS\Engine\Hypergrid\Gatekeeper;
use W4OS\Engine\Network\Http;
use W4OS\Engine\Runtime\ClockInterface;

/**
 * Fills the events of the search from a HYPEvents calendar (`events.json` of a 2do directory, or any implementation of it): the
 * events that did not end an hour ago and whose region answers, as OpenSimSearch shows them in the events of the viewers.
 */
final class EventsParser
{
    /** The owner and the creator of an event, the calendar does not tell. */
    public const NOBODY = '00000000-0000-0000-0000-000000000001';

    /** The categories of the events of the viewers, by the name a calendar gives them. */
    public const CATEGORIES = [
        'discussion' => 18,
        'sports' => 19,
        'live music' => 20,
        'commercial' => 22,
        'nightlife/entertainment' => 23,
        'games/contests' => 24,
        'pageants' => 25,
        'education' => 26,
        'arts and culture' => 27,
        'charity/support groups' => 28,
        'miscellaneous' => 29,
        // From the HYPEvents code
        'art' => 27,
        'fair' => 23,
        'lecture' => 27,
        'litterature' => 27,
        'music' => 20,
        'roleplay' => 24,
        'social' => 28,
    ];

    /** @var array{prefetch:\Closure(list<string>):void,online:\Closure(string):bool,parts:\Closure(string):array<string,mixed>,located:\Closure(string):array<string,mixed>,link:\Closure(string,int):string} */
    private array $places;

    /**
     * @param string $source the address of the calendar (HYPEvents), without `/events.json`
     * @param ?\Closure(string):?string $http the body of an address, null when it does not answer (default: curl)
     * @param ?array<string,\Closure> $places how a place is known: `prefetch` (the places of a run, asked at once), `online` (its region answers), `parts` (host, port, region, position),
     *        `located` (the region on the map), `link` (the text of the links to it); the ones of the hypergrid by default
     */
    public function __construct(
        private readonly OSPDO $db,
        private readonly string $source,
        private readonly ?\Closure $http = null,
        ?array $places = null,
        private readonly string $table = SearchTables::EVENTS,
        private readonly ?ClockInterface $clock = null,
    ) {
        // The gatekeepers asked all at once first, what they say remembered for the run (when the places are the hypergrid's)
        $prefetch = isset($places['online']) ? static function (array $urls): void {} : static function (array $urls): void {
            Gatekeeper::forget();
            Gatekeeper::prefetch($urls);
        };
        $this->places = ($places ?? []) + [
            'prefetch' => $prefetch,
            'online' => static fn(string $url): bool => Gatekeeper::regionIsOnline($url),
            'parts' => static fn(string $url): array => Destination::sanitize($url, '', true),
            'located' => static fn(string $url): array => (array) Gatekeeper::region($url),
            'link' => static fn(string $url, int $format): string => (string) (new \W4OS\Engine\Hypergrid\Place($url))->tpLink($format),
        ];
    }

    /**
     * Replace the events of the search by the ones of the calendar.
     *
     * @return int the events kept, -1 when the calendar did not answer
     */
    public function parse(): int
    {
        $url = rtrim($this->source, '/') . '/events.json';
        $body = $this->http !== null ? ($this->http)($url) : Http::get($url);
        $calendar = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($calendar) || $calendar === []) {
            error_log("Invalid json received from $url");

            return -1;
        }
        // The places of the events to come, asked all at once
        $now = (int) ($this->clock?->time() ?? time());
        $this->places['prefetch'](array_values(array_unique(array_map(
            static fn(array $event): string => (string) ($event['hgurl'] ?? ''),
            array_filter($calendar, static fn($event): bool => is_array($event) && (int) strtotime((string) ($event['start'] ?? '')) >= $now - 3600),
        ))));
        $events = [];
        foreach ($calendar as $event) {
            $row = is_array($event) ? $this->event($event) : null;
            $row !== null && ($events[] = $row);
        }
        $events = self::merged($events);
        $this->db->prepareAndExecute("DELETE FROM {$this->table}");
        foreach ($events as $row) {
            $this->db->insert($this->table, $row) || error_log('error while inserting new events');
        }

        return count($events);
    }

    /** Seconds between the end of an event and the start of the next one of the same show, at most, for them to be one. */
    public const MERGE_GAP = 3600;

    /**
     * The slots of one show as one event, as the 2do board has them: the events of the same name at the same place (their names as
     * the calendar gives them, the same exactly; their places the same simname), the next one starting at most an hour after the
     * end of the one before, are one event from the first start to the last end. The others as they are, the soonest first.
     *
     * @param list<array<string,mixed>> $events rows of the table
     * @return list<array<string,mixed>>
     */
    public static function merged(array $events): array
    {
        usort($events, static fn(array $a, array $b): int => (int) $a['dateUTC'] <=> (int) $b['dateUTC']);
        $merged = [];
        $last = [];
        foreach ($events as $event) {
            $show = $event['name'] . "\0" . $event['simname'];
            $at = $last[$show] ?? null;
            if ($at !== null) {
                $end = (int) $merged[$at]['dateUTC'] + (int) $merged[$at]['duration'] * 60;
                if ((int) $event['dateUTC'] <= $end + self::MERGE_GAP) {
                    $merged[$at]['duration'] = intdiv(max($end, (int) $event['dateUTC'] + (int) $event['duration'] * 60) - (int) $merged[$at]['dateUTC'], 60);
                    continue;
                }
            }
            $last[$show] = count($merged);
            $merged[] = $event;
        }

        return $merged;
    }

    /** The category number of the names a calendar gives: miscellaneous for a name nobody knows, none for no name. */
    public static function category(mixed $values): int
    {
        if (empty($values)) {
            return 0;
        }
        foreach ((array) $values as $value) {
            if (is_int($value)) {
                return $value;
            }
            if (isset(self::CATEGORIES[strtolower((string) $value)])) {
                return self::CATEGORIES[strtolower((string) $value)];
            }
        }

        return 29;
    }

    /**
     * @param array<string,mixed> $event
     * @return ?array<string,mixed> the row of the table, null for an event that is over, or whose region does not answer
     */
    private function event(array $event): ?array
    {
        $now = (int) ($this->clock?->time() ?? time());
        $start = (int) strtotime((string) ($event['start'] ?? ''));
        if ($start < $now - 3600) {
            return null;
        }
        $end = (int) strtotime((string) ($event['end'] ?? ''));
        $duration = $end > $start ? (int) round(($end - $start) / 60) : 60;
        $duration = $duration > 0 ? $duration : 60;

        $place = (string) ($event['hgurl'] ?? '');
        if (!$this->places['online']($place)) {
            return null;
        }
        $region = $this->places['parts']($place);
        $located = $this->places['located']($place);
        $position = empty($region['pos']) ? [128, 128, 25] : explode('/', (string) $region['pos']);
        // The point in its region, as the parcels keep theirs; the global position adds the region's place on its grid
        $landing = implode('/', $position);
        if (!empty($located['x']) && !empty($located['y'])) {
            $position[0] += $located['x'];
            $position[1] += $located['y'];
        }
        // Its description as the calendar gives it: the links a viewer needs are added to its answer only (SearchQuery::viewerDescription())
        // UTF-8 as the calendar gives it: accents, emojis, full-width signs (the tables are utf8mb4)
        $description = strip_tags(html_entity_decode((string) ($event['description'] ?? '')));

        return [
            'owneruuid' => self::NOBODY,
            'name' => strip_tags(html_entity_decode((string) ($event['title'] ?? ''))),
            'creatoruuid' => self::NOBODY,
            'category' => self::category($event['categories'] ?? null),
            'description' => $description,
            'dateUTC' => $start,
            'duration' => $duration,
            'covercharge' => 0,
            'coveramount' => 0,
            'simname' => $this->places['link']($place, Destination::TXT),
            'parcelUUID' => self::NOBODY,
            'globalPos' => implode(',', $position),
            'landingpoint' => $landing,
            'eventflags' => 0,
            'gatekeeperURL' => (string) ($region['gatekeeper'] ?? ''),
        ];
    }
}
