<?php

declare(strict_types=1);

namespace OpenSim\Flux;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Data\OSPDO;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Provisioning\AccountWriter;

/**
 * The stream of the avatars of a grid (w4os-flux, its first step): the posts each avatar writes on its profile, in the `avatar_posts`
 * table of the database of the profiles, made when it is not there. A post is plain text (MAX characters at most), with the name
 * of its author when it was written, its date, and where it came from when it was brought in (`w4os-wp:<ID>`).
 *
 *     $posts = Posts::for($config);
 *     $posts->add($uuid, 'Ada Love', 'Hello');
 *     $posts->of($uuid);          // newest first
 */
final class Posts
{
    public const TABLE = 'avatar_posts';

    /** The length of a post, at most (the one of w4os). */
    public const MAX = 1000;

    private bool $ready = false;

    public function __construct(private readonly OSPDO $pdo) {}

    /**
     * The posts of a grid.
     *
     * @throws ChannelUnavailable when the database of the profiles cannot be reached
     */
    public static function for(GridConfig $config): self
    {
        $pdo = Connections::pdo($config, Database::Profiles) ?? throw ChannelUnavailable::for(
            'keep the posts of the avatars',
            Connections::reason($config, Database::Profiles) ?? 'the database of the profiles cannot be reached',
        );

        return new self($pdo);
    }

    /** What is wrong with a text to post, once cleaned (clean()): `empty`, `too long`, null when it can be posted. */
    public static function problem(string $content): ?string
    {
        $content = self::clean($content);

        return match (true) {
            $content === '' => 'empty',
            mb_strlen($content) > self::MAX => 'too long',
            default => null,
        };
    }

    /** A text as a post keeps it: without tags, its entities read, without the spaces around it. */
    public static function clean(string $content): string
    {
        return trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The posts of an avatar, newest first.
     *
     * @return list<array{id:string,avatar:string,name:string,content:string,created:int,source:string}>
     */
    public function of(string $avatar, int $page = 1, int $perPage = 20): array
    {
        $this->ready();
        $perPage = max(1, $perPage);
        $rows = $this->pdo->get_results(
            'SELECT id, avatar, name, content, created, source FROM ' . self::TABLE . ' WHERE avatar = :avatar ORDER BY created DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (max(1, $page) - 1) * $perPage,
            ['avatar' => strtolower($avatar)],
            \PDO::FETCH_ASSOC,
        );

        return array_map(self::row(...), $rows);
    }

    /**
     * The posts of every avatar, the newest first by default: their words or the name of their author (`search`), of one author
     * (`author`), sorted by `created` or `name` (`$descending`), a page of them.
     *
     * @return array{0:list<array{id:string,avatar:string,name:string,content:string,created:int,source:string}>,1:int} the page, the total
     */
    public function all(string $search = '', ?string $author = null, string $sort = 'created', bool $descending = true, int $page = 1, int $perPage = 0): array
    {
        $this->ready();
        $where = [];
        $values = [];
        if (trim($search) !== '') {
            $where[] = '(content LIKE :words OR name LIKE :words)';
            $values['words'] = '%' . trim($search) . '%';
        }
        if ($author !== null && $author !== '') {
            $where[] = 'avatar = :author';
            $values['author'] = strtolower($author);
        }
        $where = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        // By the name of their author, each one's newest first
        $order = $sort === 'name'
            ? 'name' . ($descending ? ' DESC' : ' ASC') . ', created DESC, id DESC'
            : 'created' . ($descending ? ' DESC' : ' ASC') . ', id' . ($descending ? ' DESC' : ' ASC');
        $limit = $perPage > 0 ? ' LIMIT ' . $perPage . ' OFFSET ' . (max(1, $page) - 1) * $perPage : '';
        $rows = $this->pdo->get_results(
            'SELECT id, avatar, name, content, created, source FROM ' . self::TABLE . "$where ORDER BY $order$limit",
            $values,
            \PDO::FETCH_ASSOC,
        );
        $total = (int) $this->pdo->get_var('SELECT COUNT(*) FROM ' . self::TABLE . $where, $values);

        return [array_map(self::row(...), $rows), $total];
    }

    /** How many posts an avatar has. */
    public function count(string $avatar): int
    {
        $this->ready();

        return (int) $this->pdo->get_var('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE avatar = :avatar', ['avatar' => strtolower($avatar)]);
    }

    /** @return ?array{id:string,avatar:string,name:string,content:string,created:int,source:string} */
    public function find(string $id): ?array
    {
        $this->ready();
        $rows = $this->pdo->get_results(
            'SELECT id, avatar, name, content, created, source FROM ' . self::TABLE . ' WHERE id = :id',
            ['id' => strtolower($id)],
            \PDO::FETCH_ASSOC,
        );

        return isset($rows[0]) ? self::row($rows[0]) : null;
    }

    /**
     * Post a text for an avatar.
     *
     * @param ?int $created its date (now by default)
     * @param string $source where it comes from, for a post brought in (it is brought once)
     * @param bool $check whether the rules of a new post apply (a post brought in is kept as it was)
     * @return array{id:string,avatar:string,name:string,content:string,created:int,source:string}
     * @throws InvalidValue for an empty or too long text (`post-invalid`)
     */
    public function add(string $avatar, string $name, string $content, ?int $created = null, string $source = '', bool $check = true): array
    {
        $this->ready();
        $problem = self::problem($content);
        ($problem === null || (!$check && $problem !== 'empty')) || throw InvalidValue::because(
            'post-invalid',
            "the post is $problem (1 to " . self::MAX . ' characters)',
        );
        $row = [
            'id' => AccountWriter::uuid(),
            'avatar' => strtolower($avatar),
            'name' => $name,
            'content' => self::clean($content),
            'created' => $created ?? time(),
            'source' => $source,
        ];
        $this->pdo->insert(self::TABLE, $row);

        return $row;
    }

    /** Delete a post; false when there is none of this id. */
    public function remove(string $id): bool
    {
        $this->ready();

        return $this->find($id) !== null
            && $this->pdo->prepareAndExecute('DELETE FROM ' . self::TABLE . ' WHERE id = :id', ['id' => strtolower($id)]) !== false;
    }

    /**
     * The sources of the posts brought in already, among these.
     *
     * @param list<string> $sources
     * @return list<string>
     */
    public function brought(array $sources): array
    {
        $this->ready();
        $found = [];
        foreach (array_chunk(array_values(array_unique($sources)), 500) as $group) {
            $marks = implode(',', array_fill(0, count($group), '?'));
            array_push($found, ...$this->pdo->get_results('SELECT source FROM ' . self::TABLE . " WHERE source IN ($marks)", $group, \PDO::FETCH_COLUMN));
        }

        return array_values(array_map('strval', $found));
    }

    /** @param array<string,mixed> $row */
    private static function row(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'avatar' => (string) $row['avatar'],
            'name' => (string) $row['name'],
            'content' => (string) $row['content'],
            'created' => (int) $row['created'],
            'source' => (string) $row['source'],
        ];
    }

    private function ready(): void
    {
        if ($this->ready) {
            return;
        }
        $this->pdo->table_exists(self::TABLE) || $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE .
                ' (id CHAR(36) NOT NULL PRIMARY KEY, avatar CHAR(36) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT \'\', content TEXT NOT NULL,' .
                " created INT NOT NULL, source VARCHAR(64) NOT NULL DEFAULT '')",
        );
        $this->ready = true;
    }
}
