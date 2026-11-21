<?php

declare(strict_types=1);

namespace W4OS\Engine\Flux;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Exceptions\InvalidValue;
use W4OS\Engine\Text\Uuid;

/**
 * The posts of the stream of w4os-wp (its `flux_post` posts, their author in `_avatar_uuid` and `_avatar_name`) brought into the
 * posts of the grid (Posts): the published ones, each once (its source `w4os-wp:<ID>`). Planned first, nothing written.
 *
 *     $import = FluxImport::prepare($config, FluxImport::connect(FluxImport::wordpress('/var/www/site/wp-config.php')), 'wp_');
 *     $import->document();   // how many, by avatar, the ones skipped, the ones brought already
 *     $import->apply();
 */
final class FluxImport
{
    /** The source of a post of w4os-wp, by its ID. */
    public const SOURCE = 'w4os-wp:';

    /**
     * @param list<array{id:int,avatar:string,name:string,content:string,created:int}> $posts to bring
     * @param list<array{id:int,reason:string}> $skipped published ones that cannot be brought
     */
    private function __construct(
        private readonly Posts $target,
        public readonly array $posts,
        public readonly array $skipped,
        public readonly int $imported,
    ) {}

    /**
     * What the posts of a site of w4os-wp would bring, read in its database.
     *
     * @param string $prefix the prefix of its tables (`$table_prefix` of its wp-config.php)
     */
    public static function prepare(GridConfig $config, OSPDO $wordpress, string $prefix = 'wp_'): self
    {
        preg_match('/^\w*$/', $prefix) === 1 || throw InvalidValue::because('invalid-request', "'$prefix' is not a prefix of tables");
        $rows = $wordpress->get_results(
            "SELECT p.ID AS id, p.post_content AS content, p.post_date_gmt AS date, u.meta_value AS avatar, n.meta_value AS name
                FROM {$prefix}posts p
                LEFT JOIN {$prefix}postmeta u ON u.post_id = p.ID AND u.meta_key = '_avatar_uuid'
                LEFT JOIN {$prefix}postmeta n ON n.post_id = p.ID AND n.meta_key = '_avatar_name'
                WHERE p.post_type = 'flux_post' AND p.post_status = 'publish' ORDER BY p.ID",
            [],
            \PDO::FETCH_ASSOC,
        );
        $target = Posts::for($config);
        $brought = $target->brought(array_map(static fn(array $row): string => self::SOURCE . $row['id'], $rows));
        $posts = [];
        $skipped = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (in_array(self::SOURCE . $id, $brought, true)) {
                continue;
            }
            $reason = match (true) {
                !Uuid::isValid((string) $row['avatar']) => 'it has no author (_avatar_uuid)',
                Posts::problem((string) $row['content']) === 'empty' => 'it is empty',
                default => null,
            };
            if ($reason !== null) {
                $skipped[] = ['id' => $id, 'reason' => $reason];

                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['date'], new \DateTimeZone('UTC'));
            $posts[] = [
                'id' => $id,
                'avatar' => strtolower((string) $row['avatar']),
                'name' => trim((string) $row['name']),
                'content' => (string) $row['content'],
                'created' => $date !== false ? $date->getTimestamp() : time(),
            ];
        }

        return new self($target, $posts, $skipped, count($brought));
    }

    /**
     * The database of a site of WordPress, from its wp-config.php: its host, its name, its user and password, the prefix of its tables.
     *
     * @return array{host:string,name:string,user:string,pass:string,prefix:string}
     * @throws InvalidValue when the file cannot be read or does not say its database
     */
    public static function wordpress(string $file): array
    {
        $text = is_readable($file) ? (string) file_get_contents($file) : throw InvalidValue::because('source-not-readable', "cannot read $file");
        $define = static fn(string $name): ?string => preg_match("/define\\(\\s*['\"]" . $name . "['\"]\\s*,\\s*['\"]([^'\"]*)['\"]\\s*\\)/", $text, $m) === 1 ? $m[1] : null;
        $name = $define('DB_NAME') ?? throw InvalidValue::because('invalid-request', "$file does not say DB_NAME");

        return [
            'host' => $define('DB_HOST') ?? 'localhost',
            'name' => $name,
            'user' => $define('DB_USER') ?? '',
            'pass' => $define('DB_PASSWORD') ?? '',
            'prefix' => preg_match('/\$table_prefix\s*=\s*[\'"](\w*)[\'"]/', $text, $m) === 1 ? $m[1] : 'wp_',
        ];
    }

    /**
     * A connection to the database of a site, as wordpress() gives it.
     *
     * @param array{host:string,name:string,user:string,pass:string} $database
     * @throws \W4OS\Engine\Exceptions\ChannelUnavailable when it cannot be reached
     */
    public static function connect(array $database): OSPDO
    {
        $pdo = new OSPDO(['host' => $database['host'], 'name' => $database['name'], 'user' => $database['user'], 'pass' => $database['pass'], 'log' => false]);
        $pdo->connected || throw \W4OS\Engine\Exceptions\ChannelUnavailable::for('read the posts of w4os-wp', 'its database cannot be reached');

        return $pdo;
    }

    /**
     * What it brings: how many posts, by avatar, the published ones it cannot bring and why, how many were brought before.
     *
     * @return array{posts:int,avatars:list<array{avatar:string,name:string,posts:int}>,skipped:list<array{id:int,reason:string}>,imported:int}
     */
    public function document(): array
    {
        $avatars = [];
        foreach ($this->posts as $post) {
            $avatars[$post['avatar']] ??= ['avatar' => $post['avatar'], 'name' => $post['name'], 'posts' => 0];
            $avatars[$post['avatar']]['posts']++;
        }

        return ['posts' => count($this->posts), 'avatars' => array_values($avatars), 'skipped' => $this->skipped, 'imported' => $this->imported];
    }

    /** Bring them, each with its date and its source; a post longer than a new one may be is kept as it was. */
    public function apply(): int
    {
        foreach ($this->posts as $post) {
            $this->target->add($post['avatar'], $post['name'], $post['content'], $post['created'], self::SOURCE . $post['id'], false);
        }

        return count($this->posts);
    }
}
