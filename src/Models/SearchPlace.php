<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

use W4OS\Engine\Data\Listing;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Forms\ItemForm;
use W4OS\Engine\Services\SearchFilters;

/**
 * A place of the search (the parcels its simulators show in the search: `parcels` of the search database), as the Places tab of the
 * viewers finds it: its name or description with the words (3 letters at least), of a category, of the ratings asked; listed with
 * its traffic, the most visited first.
 */
final class SearchPlace extends SearchResult
{
    public static function type(): string
    {
        return 'search/places';
    }

    public function id(): string
    {
        return (string) $this->row['parcelUUID'];
    }

    public function name(): string
    {
        return (string) $this->row['parcelname'];
    }

    protected function region(): string
    {
        return (string) ($this->row['region'] ?? '');
    }

    protected function landing(): string
    {
        return (string) ($this->row['landingpoint'] ?? '');
    }

    protected static function pictureColumn(): ?string
    {
        return 'imageUUID';
    }

    public function detail(): array
    {
        $category = (int) ($this->row['searchcategory'] ?? 0);

        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'description' => trim((string) ($this->row['description'] ?? '')),
            'picture' => $this->picture(),
            'region' => $this->region(),
            'area' => isset($this->row['area']) ? (int) $this->row['area'] : null,
            'traffic' => (int) ($this->row['dwell'] ?? 0),
            'rating' => self::rating($this->row['mature'] ?? null),
            'category' => isset(SearchFilters::PLACE_CATEGORIES[$category]) ? (string) $category : '',
        ] + $this->place();
    }

    protected static function from(OSPDO $db): string
    {
        return 'p.*, r.regionname AS region, a.parcelarea AS area FROM parcels AS p LEFT JOIN ' . self::regions($db) . ' AS r ON r.regionUUID = p.regionUUID'
            . ' LEFT JOIN allparcels AS a ON a.parcelUUID = p.parcelUUID';
    }

    protected static function terms(string $search, array $filters): array
    {
        return SearchFilters::places($search, (int) ($filters['category'] ?? 0), self::ratings($filters), 'p');
    }

    protected static function sorts(): array
    {
        return ['traffic' => 'p.dwell', 'name' => 'p.parcelname'];
    }

    protected static function key(): string
    {
        return 'p.parcelUUID';
    }

    protected static function tie(): string
    {
        return 'p.parcelname';
    }

    /** The list of the Places tab of the viewers: the name, the traffic; by category and rating. */
    public static function listing(): Listing
    {
        return new Listing(
            [
                // Its picture beside its name, the head of its page
                Listing::column('name', 'web_search_name', sort: 'name', more: ['picture' => 'picture', 'default' => 'place']),
                Listing::column('traffic', 'web_search_traffic', 'number', 'traffic'),
            ],
            [self::categoryFilter(SearchFilters::PLACE_CATEGORIES, 'place_category'), self::ratingFilter()],
            ['name', 'description'],
            sort: '-traffic',
            texts: ['title' => 'web_search_places', 'empty' => 'web_search_nothing'],
        );
    }

    /** What the viewers show of a place found: its picture, its region, its area, its traffic, its rating, its description. */
    public static function itemForm(): ItemForm
    {
        return new ItemForm(
            [
                ItemForm::shown('picture', 'profile_picture', 'picture', 'place', ['default' => 'place']),
                ItemForm::shown('name', 'web_search_name', 'text', 'place'),
                ItemForm::shown('region', 'web_search_region', 'text', 'place'),
                ItemForm::shown('area', 'web_search_area', 'number', 'place', ['unit' => 'm²']),
                ItemForm::shown('traffic', 'web_search_traffic', 'number', 'place'),
                ItemForm::shown('rating', 'web_search_rating', 'select', 'place', ['options' => self::ratingOptions()]),
                ItemForm::shown('description', 'web_search_description', 'text', 'place'),
            ],
            [ItemForm::group('place', 'web_search_places')],
        );
    }
}
