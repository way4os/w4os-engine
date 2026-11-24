<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

use W4OS\Engine\Data\Listing;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Forms\ItemForm;
use W4OS\Engine\Services\SearchFilters;

/**
 * Land for sale (`parcelsales` of the search database), as the Land Sales tab of the viewers finds it: of a type (the mainland, an
 * estate), its price at most, its area at least, of the ratings asked; listed with its type, its price, its area and its price by
 * square meter, the cheapest by square meter first.
 */
final class LandSale extends SearchResult
{
    public static function type(): string
    {
        return 'search/land';
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
        return 'picture';
    }

    public function detail(): array
    {
        $area = (int) ($this->row['area'] ?? 0);
        $price = (int) ($this->row['saleprice'] ?? 0);

        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'description' => trim((string) ($this->row['about'] ?? '')),
            'picture' => $this->picture(),
            'region' => $this->region(),
            'type' => (int) ($this->row['parentestate'] ?? 0) === 1 ? 'mainland' : 'estate',
            'price' => $price,
            'area' => $area,
            'pricePerMeter' => $area > 0 ? round($price / $area, 2) : null,
            'traffic' => (int) ($this->row['dwell'] ?? 0),
            'rating' => self::rating($this->row['mature'] ?? null),
        ] + $this->place();
    }

    protected static function from(OSPDO $db): string
    {
        return 's.*, r.regionname AS region, p.imageUUID AS picture, p.description AS about FROM parcelsales AS s LEFT JOIN ' . self::regions($db)
            . ' AS r ON r.regionUUID = s.regionUUID LEFT JOIN parcels AS p ON p.parcelUUID = s.parcelUUID';
    }

    protected static function terms(string $search, array $filters): array
    {
        $number = static fn(string $name): ?int => is_numeric($filters[$name] ?? null) && (int) $filters[$name] >= 0 ? (int) $filters[$name] : null;

        return SearchFilters::land((string) ($filters['type'] ?? '') ?: 'any', $number('price'), $number('area'), self::ratings($filters), 's');
    }

    protected static function sorts(): array
    {
        return ['pricePerMeter' => 's.saleprice * 1.0 / s.area', 'name' => 's.parcelname', 'type' => 's.parentestate', 'price' => 's.saleprice', 'area' => 's.area'];
    }

    protected static function key(): string
    {
        return 's.parcelUUID';
    }

    protected static function tie(): string
    {
        return 's.parcelname';
    }

    /** The list of the Land Sales tab of the viewers: the name, the type, the price, the area, the price by square meter. */
    public static function listing(): Listing
    {
        return new Listing(
            [
                // Its picture beside its name, the head of its page
                Listing::column('name', 'web_search_name', sort: 'name', more: ['picture' => 'picture', 'default' => 'place']),
                Listing::column('type', 'web_search_land_type', 'list', 'type', ['values' => Listing::values(self::types())]),
                Listing::column('price', 'web_search_price', 'number', 'price'),
                Listing::column('area', 'web_search_area', 'number', 'area', ['unit' => 'm²']),
                Listing::column('pricePerMeter', 'web_search_price_m2', 'number', 'pricePerMeter', ['decimals' => 2]),
            ],
            [
                Listing::filter('type', 'web_search_land_type', ['any' => 'web_search_land_any'] + self::types()),
                Listing::filter('price', 'web_search_price_max', [], 'integer'),
                Listing::filter('area', 'web_search_area_min', [], 'integer'),
                self::ratingFilter(),
            ],
            sort: 'pricePerMeter',
            texts: ['title' => 'web_search_land', 'empty' => 'web_search_nothing'],
        );
    }

    /** What the viewers show of land for sale: its picture, its region, its type, its price, its area, its traffic, its rating. */
    public static function itemForm(): ItemForm
    {
        return new ItemForm(
            [
                ItemForm::shown('picture', 'profile_picture', 'picture', 'land', ['default' => 'place']),
                ItemForm::shown('name', 'web_search_name', 'text', 'land'),
                ItemForm::shown('region', 'web_search_region', 'text', 'land', ['teleport' => true]),
                ItemForm::shown('type', 'web_search_land_type', 'select', 'land', ['options' => self::types()]),
                ItemForm::shown('price', 'web_search_price', 'number', 'land'),
                ItemForm::shown('area', 'web_search_area', 'number', 'land', ['unit' => 'm²']),
                ItemForm::shown('traffic', 'web_search_traffic', 'number', 'land'),
                ItemForm::shown('rating', 'web_search_rating', 'select', 'land', ['options' => self::ratingOptions()]),
                ItemForm::shown('description', 'web_search_description', 'text', 'land'),
            ],
            [ItemForm::group('land', 'web_search_land')],
        );
    }

    /** @return array<string,string> its types, each its text */
    private static function types(): array
    {
        return ['mainland' => 'web_search_land_mainland', 'estate' => 'web_search_land_estate'];
    }
}
