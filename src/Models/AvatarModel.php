<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Assets\WebAssets;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Provisioning\Slug;
use OpenSim\Text\Uuid;

/**
 * An avatar model: an avatar of the grid whose outfit and appearance a new avatar gets, shown with its profile picture. The models are
 * the active avatars picked by the rule of `[Models]` (the keys of w4os):
 *
 *     [Models]
 *     Match = any            ; first (the first name), last (the last name), any (either), uuid (the list)
 *     MatchPattern = Default ; the name the first or the last name is
 *     List = "<uuid>, <uuid>" ; the models, for Match = uuid
 *
 * A model is made like any avatar (in the console of Robust), dressed in a viewer and given a profile picture; it is never the
 * avatar of a person.
 */
final class AvatarModel extends Model
{
    /** The picture of a model that has none: the silhouette of a person, as for any profile without its picture. */
    public const PLACEHOLDER = WebAssets::PERSON;

    private const ZERO = '00000000-0000-0000-0000-000000000000';

    private const MATCHES = ['first', 'last', 'any', 'uuid'];

    public function id(): string
    {
        return (string) $this->row['PrincipalID'];
    }

    public function uuid(): string
    {
        return $this->id();
    }

    public function name(): string
    {
        return trim($this->row['FirstName'] . ' ' . $this->row['LastName']);
    }

    public function slug(): string
    {
        return Slug::slug($this->name());
    }

    /** The name shown to the people who choose: without the pattern of the rule (Default Ruth is Ruth). */
    public function label(): string
    {
        [$match, $pattern] = self::rule($this->config);
        if ($match === 'uuid' || $pattern === '') {
            return $this->name();
        }
        $label = trim((string) preg_replace('/ *' . preg_quote($pattern, '/') . ' */', ' ', $this->name()));

        return $label !== '' ? $label : $this->name();
    }

    /** The UUID of its profile picture, null when it has none. */
    public function profileImage(): ?string
    {
        $image = strtolower(trim((string) ($this->row['profileImage'] ?? '')));

        return Uuid::isValid($image) && $image !== self::ZERO ? $image : null;
    }

    public function detail(): array
    {
        return [
            'uuid' => $this->id(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'firstName' => (string) $this->row['FirstName'],
            'lastName' => (string) $this->row['LastName'],
            'label' => $this->label(),
            'profileImage' => $this->profileImage(),
            'picture' => $this->profileImage() ?? self::PLACEHOLDER,
            'about' => ($this->row['profileAboutText'] ?? '') !== '' ? (string) $this->row['profileAboutText'] : null,
        ];
    }

    /**
     * What a new avatar copies of the model: its appearance (the rows of `Avatars`) and the items of its inventory the appearance
     * wears (`Wearable N:M` is "item:asset", `_ap_N` a list of items), by the id the appearance gives them. A wearable whose item
     * is not found by its id is looked for by its asset, as w4os does.
     *
     * @return array{name:string,appearance:list<array{0:string,1:string}>,items:array<string,array<string,mixed>>} no appearance
     *         when the model never entered a region
     * @throws \OpenSim\Exceptions\ChannelUnavailable when the databases of the appearances or the inventories cannot be reached
     */
    public function outfit(): array
    {
        $avatars = self::connect($this->config, 'AvatarService', null, 'read the outfit of ' . $this->name());
        $appearance = array_map(
            static fn(array $row): array => [(string) $row['Name'], (string) $row['Value']],
            $avatars->get_results(
                'SELECT Name, Value FROM Avatars WHERE PrincipalID = :id ORDER BY Name',
                ['id' => $this->id()],
                \PDO::FETCH_ASSOC,
            ) ?:
            [],
        );
        $wanted = [];
        foreach ($appearance as [$name, $value]) {
            if (str_starts_with($name, 'Wearable ')) {
                [$item, $asset] = array_pad(explode(':', $value, 2), 2, '');
                $wanted[strtolower($item)] = strtolower($asset);
            } elseif (str_starts_with($name, '_ap_')) {
                foreach (explode(',', $value) as $item) {
                    $wanted[strtolower(trim($item))] = '';
                }
            }
        }
        $items = [];
        if ($wanted !== []) {
            $inventory = self::connect($this->config, 'InventoryService', null, 'read the outfit of ' . $this->name());
            foreach ($wanted as $item => $asset) {
                $row =
                    $inventory->get_row(
                        'SELECT * FROM inventoryitems WHERE avatarID = :owner AND inventoryID = :item',
                        ['owner' => $this->id(), 'item' => $item],
                        \PDO::FETCH_ASSOC,
                    ) ?:
                    ($asset !== ''
                        ? $inventory->get_row(
                            'SELECT * FROM inventoryitems WHERE avatarID = :owner AND assetID = :asset AND assetType != 24',
                            ['owner' => $this->id(), 'asset' => $asset],
                            \PDO::FETCH_ASSOC,
                        )
                        : null);
                is_array($row) && ($items[$item] = $row);
            }
        }

        return ['name' => $this->name(), 'appearance' => $appearance, 'items' => $items];
    }

    /**
     * The models of the grid, in the order of their names; the list is whole.
     *
     * @throws \OpenSim\Exceptions\ChannelUnavailable when the database of the accounts cannot be reached
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        // A rule asked in the filters (match, pattern, list), not the grid's: the models it would pick, as the rule is changed
        $asked = array_intersect_key((array) ($options['filters'] ?? []), ['match' => 1, 'pattern' => 1, 'list' => 1]);
        $models = self::read($config, null, $asked);

        return new Collection($models, ['total' => count($models), 'sort' => 'name asc']);
    }

    /** A model by its UUID or its name (First Last), null when the rule does not pick it. */
    public static function find(GridConfig $config, string $id): ?static
    {
        if (self::optional($config, 'UserAccountService', null, "read the avatar model $id") === null) {
            return null;
        }
        if (Uuid::isValid($id)) {
            return self::read($config, ['PrincipalID = :id', ['id' => strtolower($id)]])[0] ?? null;
        }
        $parts = preg_split('/[\s.]+/', trim($id), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($parts) === 2
            ? self::read($config, ['FirstName = :first AND LastName = :last', ['first' => $parts[0], 'last' => $parts[1]]])[
                    0
                ] ?? null
            : null;
    }

    /**
     * The rule of the models of a grid, or one asked in its place (`match`, `pattern`, `list`: what is not asked is the grid's).
     *
     * @param array<string,string> $asked
     * @return array{0:string,1:string,2:list<string>} the match, the pattern, the list of UUIDs
     * @throws \OpenSim\Exceptions\InvalidValue for a match asked that is not one
     */
    public static function rule(GridConfig $config, array $asked = []): array
    {
        if (isset($asked['match']) && !in_array(strtolower(trim($asked['match'])), self::MATCHES, true)) {
            throw \OpenSim\Exceptions\InvalidValue::because('filter-value-unknown', "'{$asked['match']}' is not a match of the models (" . implode(', ', self::MATCHES) . ')');
        }
        $match = strtolower(trim((string) ($asked['match'] ?? ($config->find('Models', 'Match') ?? 'any'))));
        in_array($match, self::MATCHES, true) || ($match = 'any');
        // Left empty, the pattern is its default
        $pattern = trim((string) ($asked['pattern'] ?? $config->find('Models', 'MatchPattern')), " \t\"") ?: 'Default';
        $list = array_values(
            array_filter(
                array_map(
                    'strtolower',
                    preg_split('/[\s,;"]+/', (string) ($asked['list'] ?? ($config->find('Models', 'List') ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?:
                    [],
                ),
                [Uuid::class, 'isValid'],
            ),
        );

        return [$match, $pattern, $list];
    }

    /** Whether an account is a model by the rule of the grid, as condition() picks them: an active one only. */
    public static function matches(GridConfig $config, string $uuid, string $first, string $last, bool $active): bool
    {
        [$match, $pattern, $list] = self::rule($config);

        return $active && match ($match) {
            'uuid' => in_array(strtolower($uuid), $list, true),
            'first' => $first === $pattern,
            'last' => $last === $pattern,
            default => $first === $pattern || $last === $pattern,
        };
    }

    /**
     * The models the rule picks, with a condition of the caller (one of them).
     *
     * @param ?array{0:string,1:array<string,string>} $only a condition on UserAccounts and its parameters
     * @return list<self>
     */
    /**
     * The condition on UserAccounts the rule of the models is, active accounts only, for a query of the models or of the avatars that
     * are not (Avatar, `filter[model]`).
     *
     * @param string $prefix the alias of UserAccounts in the query (`u.`)
     * @param array<string,string> $asked a rule asked in place of the grid's (rule())
     * @return array{0:string,1:array<string,string>} the condition and its parameters
     */
    public static function condition(GridConfig $config, string $prefix = '', array $asked = []): array
    {
        [$match, $pattern, $list] = self::rule($config, $asked);
        $params = [];
        $condition = match ($match) {
            'uuid' => $list === []
                ? '1 = 0'
                : "{$prefix}PrincipalID IN (" .
                    implode(', ', array_map(static fn(int $i): string => ":model$i", array_keys($list))) .
                    ')',
            'first' => "{$prefix}FirstName = :model_pattern",
            'last' => "{$prefix}LastName = :model_pattern",
            default => "({$prefix}FirstName = :model_pattern OR {$prefix}LastName = :model_pattern)",
        };
        if ($match === 'uuid') {
            foreach ($list as $i => $uuid) {
                $params["model$i"] = $uuid;
            }
        } else {
            $params['model_pattern'] = $pattern;
        }

        return ["{$prefix}active = 1 AND $condition", $params];
    }

    private static function read(GridConfig $config, ?array $only, array $asked = []): array
    {
        $accounts = self::connect($config, 'UserAccountService', null, 'read the avatar models');
        [$condition, $params] = self::condition($config, '', $asked);
        if ($only !== null) {
            $condition .= ' AND ' . $only[0];
            $params += $only[1];
        }
        $rows = $accounts->get_results(
            "SELECT PrincipalID, FirstName, LastName FROM UserAccounts WHERE $condition ORDER BY FirstName, LastName",
            $params,
            \PDO::FETCH_ASSOC,
        ) ?:
        [];
        $profiles = self::profiles($config, array_column($rows, 'PrincipalID'));

        return array_map(
            static fn(array $row): self => new self(
                $config,
                $row + ($profiles[strtolower((string) $row['PrincipalID'])] ?? []),
            ),
            $rows,
        );
    }

    /**
     * The picture and the text of the profiles of some avatars; none when the profiles cannot be reached.
     *
     * @param list<string> $ids
     * @return array<string,array{profileImage:?string,profileAboutText:?string}> by UUID
     */
    private static function profiles(GridConfig $config, array $ids): array
    {
        $pdo = Connections::pdo($config, Database::Profiles);
        if ($pdo === null || $ids === []) {
            return [];
        }
        $names = array_map(static fn(int $i): string => ":p$i", array_keys($ids));
        try {
            $rows = $pdo->get_results(
                'SELECT useruuid, profileImage, profileAboutText FROM userprofile WHERE useruuid IN (' .
                    implode(', ', $names) .
                    ')',
                array_combine(array_map(static fn(string $n): string => substr($n, 1), $names), $ids),
                \PDO::FETCH_ASSOC,
            ) ?:
            [];
        } catch (\PDOException) {
            return [];
        }
        $profiles = [];
        foreach ($rows as $row) {
            $profiles[strtolower((string) $row['useruuid'])] = [
                'profileImage' => $row['profileImage'],
                'profileAboutText' => $row['profileAboutText'],
            ];
        }

        return $profiles;
    }
}
