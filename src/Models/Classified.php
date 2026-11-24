<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

use W4OS\Engine\Data\Listing;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Forms\ItemForm;
use W4OS\Engine\Services\SearchFilters;

/**
 * A classified of the search (`classifieds` of the search database, the ads of the profiles), as the Classifieds tab of the viewers
 * finds it: not expired yet, of a category, of the ratings asked, its name or description with the words; listed with its price,
 * the dearest first.
 */
final class Classified extends SearchResult
{
    public static function type(): string
    {
        return 'search/classifieds';
    }

    public function id(): string
    {
        return (string) $this->row['classifieduuid'];
    }

    protected function region(): string
    {
        return (string) ($this->row['simname'] ?? '');
    }

    /** Its point in its region: its global position, within its region (the viewers keep it so). */
    protected function landing(): string
    {
        $point = $this->point();

        return $point === null ? '' : implode('/', $point);
    }

    protected static function pictureColumn(): ?string
    {
        return 'snapshotuuid';
    }

    public function detail(): array
    {
        $flags = (int) ($this->row['classifiedflags'] ?? 0);
        $category = (int) ($this->row['category'] ?? 0);
        $point = $this->point();

        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'description' => trim((string) ($this->row['description'] ?? '')),
            'picture' => $this->picture(),
            'category' => isset(SearchFilters::CLASSIFIED_CATEGORIES[$category]) ? (string) $category : '',
            'rating' => match (true) { ($flags & 64) !== 0 => 'adult', ($flags & 8) !== 0 => 'moderate', default => 'general' },
            // Where it is, as the viewers say it: its parcel, its region, its point
            'location' => trim(($this->row['parcelname'] ?? '') . (($this->row['simname'] ?? '') === '' ? '' : ', ' . $this->row['simname']), ', ')
                . ($point === null ? '' : ' (' . implode(', ', $point) . ')'),
            'created' => (int) ($this->row['creationdate'] ?? 0),
            'price' => (int) ($this->row['priceforlisting'] ?? 0),
        ] + $this->place();
    }

    protected static function from(OSPDO $db): string
    {
        return 'c.* FROM classifieds AS c';
    }

    protected static function terms(string $search, array $filters): array
    {
        return SearchFilters::classifieds($search, (int) ($filters['category'] ?? 0), self::ratings($filters), 'c', time());
    }

    protected static function sorts(): array
    {
        return ['price' => 'c.priceforlisting', 'name' => 'c.name'];
    }

    protected static function key(): string
    {
        return 'c.classifieduuid';
    }

    protected static function tie(): string
    {
        return 'c.creationdate DESC';
    }

    /** The list of the Classifieds tab of the viewers: the name, the price. */
    public static function listing(): Listing
    {
        return new Listing(
            [
                // Its picture beside its name, the head of its page
                Listing::column('name', 'web_search_name', sort: 'name', more: ['picture' => 'picture', 'default' => 'place']),
                Listing::column('price', 'web_search_price', 'number', 'price'),
            ],
            [self::categoryFilter(SearchFilters::CLASSIFIED_CATEGORIES, 'classified_category'), self::ratingFilter()],
            ['name', 'description'],
            sort: '-price',
            texts: ['title' => 'web_search_classifieds', 'empty' => 'web_search_nothing'],
        );
    }

    /** What the viewers show of a classified: its picture, its location, its content type, its category, its date, its cost. */
    public static function itemForm(): ItemForm
    {
        $categories = array_map(static fn(string $key): string => "classified_category_$key", SearchFilters::CLASSIFIED_CATEGORIES);

        return new ItemForm(
            [
                ItemForm::shown('picture', 'profile_picture', 'picture', 'classified', ['default' => 'place']),
                ItemForm::shown('name', 'web_search_name', 'text', 'classified'),
                ItemForm::shown('location', 'web_search_location', 'text', 'classified', ['teleport' => true]),
                ItemForm::shown('rating', 'web_search_content_type', 'select', 'classified', ['options' => self::ratingOptions()]),
                ItemForm::shown('category', 'web_search_category', 'select', 'classified', ['options' => array_combine(array_map('strval', array_keys($categories)), $categories)]),
                ItemForm::shown('created', 'web_search_creation_date', 'date', 'classified'),
                ItemForm::shown('price', 'web_search_listing_cost', 'number', 'classified'),
                ItemForm::shown('description', 'web_search_description', 'text', 'classified', ['bare' => true]),
            ],
            [ItemForm::group('classified', 'web_search_classifieds')],
        );
    }

    /** @return ?list<int> its point within its region (x, y, z), null when it has none */
    private function point(): ?array
    {
        $global = trim((string) ($this->row['posglobal'] ?? ''), '<> ');
        if ($global === '') {
            return null;
        }
        $point = array_map('floatval', explode(',', $global) + [0, 0, 0]);

        return [(int) fmod($point[0], 256), (int) fmod($point[1], 256), (int) ($point[2] ?? 0)];
    }
}
