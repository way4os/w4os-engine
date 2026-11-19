<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;
use OpenSim\Data\Listing;
use OpenSim\Flux\Posts;

/**
 * A post of the stream of an avatar (Flux), as the list of all the posts of the grid shows it: the admin list of w4os-wp's Flux (its
 * post type), the post, its Author, its date. The posts are kept by Flux\Posts; an avatar writes and deletes its own under its
 * profile (`/grids/{grid}/avatars/{id}/posts`), the customer service and above delete any here.
 */
final class Post extends Model
{
    /** The sorts the list takes. */
    private const SORTS = ['created', 'name'];

    public function id(): string
    {
        return (string) $this->row['id'];
    }

    public function uuid(): ?string
    {
        return $this->id();
    }

    /** Its first words, as the title of a post of WordPress is shown in a list. */
    public function name(): string
    {
        $content = (string) $this->row['content'];

        return mb_strlen($content) > 60 ? rtrim(mb_substr($content, 0, 59)) . '…' : $content;
    }

    public function slug(): string
    {
        return $this->id();
    }

    public function owner(): ?string
    {
        return (string) $this->row['avatar'];
    }

    public function detail(): array
    {
        $name = (string) $this->row['name'];

        return [
            'id' => $this->id(),
            'author' => (string) $this->row['avatar'],
            'name' => $name,
            'authorProfile' => $name === '' ? null : Avatar::profilePage($this->config, $name),
            'content' => (string) $this->row['content'],
            'created' => (int) $this->row['created'],
            'source' => (string) $this->row['source'],
        ];
    }

    public function delete(): void
    {
        Posts::for($this->config)->remove($this->id());
    }

    /**
     * The posts of every avatar of the grid. Options: `search` (their words, the name of their author), `sort` ("created desc", the
     * default, or "name"), `filters` (`author`: the UUID of an avatar), `page` and `perPage`.
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        self::listing()->check($options);
        [$sort, $direction] = array_pad(preg_split('/\s+/', trim((string) ($options['sort'] ?? 'created desc'))) ?: [], 2, 'asc');
        in_array($sort, self::SORTS, true) || ([$sort, $direction] = ['created', 'desc']);
        $descending = strtolower((string) $direction) === 'desc';
        $author = $options['filters']['author'] ?? null;
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        [$rows, $total] = Posts::for($config)->all(trim((string) ($options['search'] ?? '')), $author, $sort, $descending, $page, $perPage);

        return new Collection(array_map(static fn(array $row): self => new self($config, $row), $rows), [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'sort' => $sort . ($descending ? ' desc' : ' asc'),
            'search' => trim((string) ($options['search'] ?? '')),
            'filters' => self::listing()->filterValues(),
            'applied' => $author === null ? [] : ['author' => $author],
        ]);
    }

    public static function find(GridConfig $config, string $id): ?static
    {
        $row = Posts::for($config)->find($id);

        return $row === null ? null : new self($config, $row);
    }

    /** What the list of the posts shows (Listing): the post, its Author linked to its profile, its date, the newest first. */
    public static function listing(): Listing
    {
        return new Listing(
            [
                Listing::column('content', 'flux_post'),
                Listing::column('name', 'column_author', 'link', 'name', ['link' => 'authorProfile', 'mirrors' => 'author']),
                Listing::column('created', 'column_date', 'datetime', 'created'),
            ],
            [Listing::filter('author', 'column_author', [], 'uuid')],
            ['content', 'name'],
            [Listing::action('delete', 'action_delete_post', 'DELETE', '/grids/{grid}/posts/{id}', more: ['ask' => 'ask_delete_post'])],
            sort: '-created',
            texts: ['title' => 'flux_title', 'menu' => 'flux_menu', 'placeholder' => 'flux_search', 'empty' => 'flux_empty', 'count' => 'posts_count'],
        );
    }
}
