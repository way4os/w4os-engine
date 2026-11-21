<?php

declare(strict_types=1);

namespace W4OS\Engine\Services;

/**
 * What a search asks, as the viewers ask it, written once for the search of the viewers (SearchQuery, query.php) and the web
 * search of the helpers: the words, the category, the ratings, the price and the area of land for sale, the day of events. Each
 * method gives the terms of a WHERE (to join with AND) and their values, the columns under a table alias when one is given.
 *
 *     [$terms, $values] = SearchFilters::places('beach', 7, ['general', 'moderate'], 'p');
 *     SearchFilters::ratings(1 << 24 | 1 << 25);   // ['general', 'moderate']: the bits a viewer sends
 */
final class SearchFilters
{
    /** The ratings a viewer asks for, by the bits of its flags (DirFindFlags: IncludePG, IncludeMature, IncludeAdult). */
    public const RATING_BITS = ['general' => 1 << 24, 'moderate' => 1 << 25, 'adult' => 1 << 26];

    /** The ratings of the classifieds, by their bits (ClassifiedFlags: PG, Mature, Adult). */
    public const CLASSIFIED_BITS = ['general' => 4, 'moderate' => 8, 'adult' => 64];

    /** The fewest letters the search of places takes (the viewers ask for 3). */
    public const MIN_TEXT = 3;

    /** The categories of places (ParcelCategory of the viewers): any is 0. */
    public const PLACE_CATEGORIES = [3 => 'arts', 4 => 'business', 5 => 'educational', 6 => 'gaming', 7 => 'hangout', 8 => 'newcomer', 9 => 'parks', 10 => 'residential', 11 => 'shopping', 13 => 'other', 14 => 'rental'];

    /** The categories of events (EventCategories of the viewers): any is 0. */
    public const EVENT_CATEGORIES = [18 => 'discussion', 19 => 'sports', 20 => 'live_music', 22 => 'commercial', 23 => 'nightlife', 24 => 'games', 25 => 'pageants', 26 => 'education', 27 => 'arts', 28 => 'charity', 29 => 'miscellaneous'];

    /** The categories of classifieds (ClassifiedCategories of the viewers): any is 0. */
    public const CLASSIFIED_CATEGORIES = [1 => 'shopping', 2 => 'land_rental', 3 => 'property_rental', 4 => 'attraction', 5 => 'new_products', 6 => 'employment', 7 => 'wanted', 8 => 'service', 9 => 'personal'];

    /** The kinds of land for sale (the Type of the land search of the viewers): any, the mainland, the estates. */
    public const LAND_TYPES = ['any', 'mainland', 'estate'];

    /**
     * The ratings asked by the bits of a viewer.
     *
     * @param array<string,int> $bits RATING_BITS, or CLASSIFIED_BITS for the classifieds
     * @return list<string>
     */
    public static function ratings(int $flags, array $bits = self::RATING_BITS): array
    {
        return array_keys(array_filter($bits, static fn(int $bit): bool => ($flags & $bit) !== 0));
    }

    /**
     * The ratings asked of rows that say theirs in a `mature` column (parcels, popular places, land for sale): its terms.
     *
     * @param list<string> $ratings
     * @return list<string>
     */
    public static function rated(array $ratings, string $alias = ''): array
    {
        $terms = [];
        self::rating($terms, $ratings, self::prefix($alias) . 'mature', ['general' => "'PG'", 'moderate' => "'Mature'", 'adult' => "'Adult'"]);

        return $terms;
    }

    /**
     * Places: their name or description has the words (each word in turn), of a category, of the ratings asked.
     *
     * @param list<string> $ratings general, moderate, adult; none for every one
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    public static function places(string $text, int $category = 0, array $ratings = [], string $alias = ''): array
    {
        $p = self::prefix($alias);
        $terms = ["({$p}parcelname LIKE :text OR {$p}description LIKE :text)"];
        $values = ['text' => '%' . implode('%', preg_split('/\s+/', trim($text)) ?: []) . '%'];
        if ($category > 0) {
            $terms[] = "{$p}searchcategory = :category";
            $values['category'] = $category;
        }
        self::rating($terms, $ratings, "{$p}mature", ['general' => "'PG'", 'moderate' => "'Mature'", 'adult' => "'Adult'"]);

        return [$terms, $values];
    }

    /**
     * Land for sale: its kind (mainland, an estate), its price at most, its area at least (null for no limit), of the ratings asked.
     *
     * @param list<string> $ratings
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    public static function land(string $type = 'any', ?int $priceMax = null, ?int $areaMin = null, array $ratings = [], string $alias = ''): array
    {
        $p = self::prefix($alias);
        $terms = [];
        $values = [];
        $type === 'mainland' && ($terms[] = "{$p}parentestate = 1");
        $type === 'estate' && ($terms[] = "{$p}parentestate <> 1");
        if ($priceMax !== null) {
            $terms[] = "{$p}saleprice <= :price";
            $values['price'] = $priceMax;
        }
        if ($areaMin !== null) {
            $terms[] = "{$p}area >= :area";
            $values['area'] = $areaMin;
        }
        self::rating($terms, $ratings, "{$p}mature", ['general' => "'PG'", 'moderate' => "'Mature'", 'adult' => "'Adult'"]);

        return [$terms, $values];
    }

    /**
     * Events: the ones to come and in progress (`u`), or of a day (a number of days from today, in the time of the server), of a
     * category, of the ratings asked, their name or description with the words.
     *
     * @param string|int $day `u`, or a number of days from today (0 today, -1 yesterday)
     * @param list<string> $ratings
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    public static function events(string|int $day, int $category, array $ratings, string $words, int $now, string $alias = ''): array
    {
        $p = self::prefix($alias);
        $terms = [];
        $values = [];
        if ((string) $day === 'u') {
            // The events to come and the ones in progress
            $terms[] = "{$p}dateUTC + {$p}duration * 60 >= $now";
        } else {
            // The start of the day of the server, moved by the number of days asked
            $start = $now - (int) date('Z', $now);
            $start -= $start % 86400;
            $start += (int) $day * 86400;
            $terms[] = "({$p}dateUTC + {$p}duration * 60 >= $start AND {$p}dateUTC < " . ($start + 86400) . ')';
        }
        if ($category > 0) {
            $terms[] = "{$p}category = :category";
            $values['category'] = $category;
        }
        self::rating($terms, $ratings, "{$p}eventflags", ['general' => '0', 'moderate' => '1', 'adult' => '2']);
        if (trim($words) !== '') {
            $terms[] = "({$p}name LIKE :text1 OR {$p}description LIKE :text2)";
            $values['text1'] = $values['text2'] = '%' . trim($words) . '%';
        }

        return [$terms, $values];
    }

    /**
     * Classifieds not expired yet (at $now, none: every one), of a category, of the ratings asked, their name or description with
     * the words (none: every one).
     *
     * @param list<string> $ratings
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    public static function classifieds(string $text, int $category = 0, array $ratings = [], string $alias = '', ?int $now = null): array
    {
        $p = self::prefix($alias);
        $terms = $now === null ? [] : ["{$p}expirationdate > $now"];
        $values = [];
        $bits = array_intersect_key(self::CLASSIFIED_BITS, array_flip($ratings));
        $bits === [] || ($terms[] = '(' . implode(' OR ', array_map(static fn(int $bit): string => "{$p}classifiedflags & $bit", $bits)) . ')');
        if ($category > 0) {
            $terms[] = "{$p}category = :category";
            $values['category'] = $category;
        }
        if (trim($text) !== '') {
            $terms[] = "({$p}name LIKE :text OR {$p}description LIKE :text)";
            $values['text'] = '%' . trim($text) . '%';
        }

        return [$terms, $values];
    }

    /**
     * The ratings asked, as the values of a column, added to the terms; none asked adds nothing.
     *
     * @param list<string> $terms
     * @param list<string> $ratings
     * @param array<string,string> $values the value of each rating in the column, as SQL
     */
    private static function rating(array &$terms, array $ratings, string $column, array $values): void
    {
        $asked = array_values(array_intersect_key($values, array_flip($ratings)));
        $asked === [] || ($terms[] = '(' . implode(' OR ', array_map(static fn(string $v): string => "$column = $v", $asked)) . ')');
    }

    private static function prefix(string $alias): string
    {
        return $alias === '' ? '' : "$alias.";
    }
}
