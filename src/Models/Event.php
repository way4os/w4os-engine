<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

use W4OS\Engine\Data\Listing;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Forms\ItemForm;
use W4OS\Engine\Services\SearchFilters;

/**
 * An event of the search (`events` of the search database: the ones of the viewers and of the calendars brought in), as the Events
 * tab of the viewers finds it: the ones in progress and to come (`day` u), or of a day (a number of days from today), of a category,
 * of the ratings asked, its name or description with the words; listed with its time, the soonest first.
 */
final class Event extends SearchResult
{
    /** @var array<string,string> the names of the organizers read during this request */
    private static array $organizers = [];

    public static function type(): string
    {
        return 'search/events';
    }

    public function id(): string
    {
        return (string) $this->row['eventid'];
    }

    /** Its region: the one it is kept with, without its grid when that grid is its gatekeeper (`host:port Region`). */
    protected function region(): string
    {
        $kept = trim((string) ($this->row['simname'] ?? ''));
        $parts = parse_url(preg_match('#://#', $this->gatekeeper()) === 1 ? $this->gatekeeper() : 'http://' . $this->gatekeeper()) ?: [];
        $grid = isset($parts['host']) ? $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : '';

        return $grid !== '' && stripos($kept, $grid) === 0 && in_array(substr($kept, strlen($grid), 1), [' ', ':'], true)
            ? trim(substr($kept, strlen($grid) + 1))
            : $kept;
    }

    protected function landing(): string
    {
        return (string) ($this->row['landingpoint'] ?? '');
    }

    public function detail(): array
    {
        $flags = (int) ($this->row['eventflags'] ?? 0);
        $category = (int) ($this->row['category'] ?? 0);
        $organizer = trim((string) ($this->row['owneruuid'] ?? ''));

        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'description' => trim((string) ($this->row['description'] ?? '')),
            'date' => (int) ($this->row['dateUTC'] ?? 0),
            'duration' => (int) ($this->row['duration'] ?? 0),
            'category' => isset(SearchFilters::EVENT_CATEGORIES[$category]) ? (string) $category : '',
            'rating' => match ($flags) { 1 => 'moderate', 2 => 'adult', default => 'general' },
            'adult' => $flags === 2,
            'organizer' => trim($organizer, '0-') === '' ? null : $organizer,
            'organizerName' => $this->organizerName($organizer),
            // Where it is, as it is kept: its region (with its grid for one of another grid)
            'location' => trim((string) ($this->row['simname'] ?? '')),
            // The fee to enter it, null for none
            'coverCharge' => (int) ($this->row['covercharge'] ?? 0) !== 0 ? (int) ($this->row['coveramount'] ?? 0) : null,
        ] + $this->place();
    }

    protected static function from(OSPDO $db): string
    {
        return 'e.* FROM events AS e';
    }

    protected static function terms(string $search, array $filters): array
    {
        $day = (string) ($filters['day'] ?? 'u');

        return SearchFilters::events($day === 'u' || !is_numeric($day) ? 'u' : (int) $day, (int) ($filters['category'] ?? 0), self::ratings($filters), $search, time(), 'e');
    }

    protected static function sorts(): array
    {
        return ['date' => 'e.dateUTC', 'name' => 'e.name'];
    }

    protected static function key(): string
    {
        return 'e.eventid';
    }

    protected static function tie(): string
    {
        return 'e.name';
    }

    /** The list of the Events tab of the viewers: the name, the time. */
    public static function listing(): Listing
    {
        return new Listing(
            [
                Listing::column('name', 'web_search_name', sort: 'name'),
                Listing::column('date', 'web_search_time', 'datetime', 'date'),
            ],
            [
                Listing::filter('day', 'web_search_when', [], 'string'),
                self::categoryFilter(SearchFilters::EVENT_CATEGORIES, 'event_category'),
                self::ratingFilter(),
            ],
            ['name', 'description'],
            sort: 'date',
            texts: ['title' => 'web_search_events', 'empty' => 'web_search_nothing'],
        );
    }

    /** What the viewers show of an event: its type, adult or not, its date, its duration, its organizer, its place, its fee. */
    public static function itemForm(): ItemForm
    {
        $categories = array_map(static fn(string $key): string => "event_category_$key", SearchFilters::EVENT_CATEGORIES);

        return new ItemForm(
            [
                ItemForm::shown('name', 'web_search_name', 'text', 'event'),
                ItemForm::shown('category', 'web_search_land_type', 'select', 'event', ['options' => array_combine(array_map('strval', array_keys($categories)), $categories)]),
                ItemForm::shown('adult', 'web_search_adult_content', 'boolean', 'event'),
                ItemForm::shown('date', 'web_search_date', 'datetime', 'event'),
                ItemForm::shown('duration', 'web_search_duration', 'number', 'event', ['unit' => 'min']),
                ItemForm::shown('organizerName', 'web_search_organizer', 'text', 'event'),
                ItemForm::shown('location', 'web_search_location', 'text', 'event'),
                ItemForm::shown('coverCharge', 'web_search_entry_fee', 'number', 'event', ['empty' => 'web_search_none']),
                ItemForm::shown('description', 'web_search_description', 'text', 'event'),
            ],
            [ItemForm::group('event', 'web_search_events')],
        );
    }

    /** The name of its organizer, '' for nobody (the events of a calendar) or one the grid does not know. */
    private function organizerName(string $uuid): string
    {
        if (trim($uuid, '0-') === '') {
            return '';
        }
        try {
            return self::$organizers[$uuid] ??= (string) (Avatar::find($this->config, $uuid)?->name() ?? '');
        } catch (\Throwable) {
            return self::$organizers[$uuid] = '';
        }
    }
}
