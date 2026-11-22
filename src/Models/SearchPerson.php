<?php

declare(strict_types=1);

namespace W4OS\Engine\Models;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Collection;
use W4OS\Engine\Data\Listing;
use W4OS\Engine\Forms\ItemForm;
use W4OS\Engine\I18n\Catalog;

/**
 * A person of the search, as the People tab of the viewers finds and shows them to anyone: an avatar of the grid who published
 * their profile ("show in search"), active, not an account of the services; what anyone sees of them only (their key, their birth,
 * their account, their partner, about them, their groups), never their email or what only a manager reads.
 */
final class SearchPerson extends Model
{
    public static function type(): string
    {
        return 'search/people';
    }

    private function avatar(): Avatar
    {
        return $this->row['avatar'];
    }

    public function id(): string
    {
        return (string) $this->avatar()->uuid();
    }

    public function uuid(): ?string
    {
        return $this->id();
    }

    public function name(): string
    {
        return $this->avatar()->name();
    }

    public function slug(): string
    {
        return $this->avatar()->slug();
    }

    public function detail(): array
    {
        $d = $this->avatar()->detail();
        $partner = $d['partner'] === null ? null : Avatar::find($this->config, (string) $d['partner']);

        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'picture' => trim((string) ($d['profileImage'] ?? ''), '0-') === '' ? null : (string) $d['profileImage'],
            'born' => $d['created'],
            // Their role, a word of the API (role_<word> of the catalog)
            'account' => (string) ($d['role'] ?? ''),
            'partner' => $partner?->uuid(),
            'partnerName' => $partner?->name(),
            'partnerPage' => $partner === null ? null : Avatar::profilePage($this->config, $partner->name()),
            'about' => trim((string) ($d['about'] ?? '')),
            'groups' => array_column($this->avatar()->groups(), 'name'),
            // Its web profile: the address the grid gives with First.Last, null when it gives none
            'profilePage' => $d['profilePage'],
        ];
    }

    /**
     * The people the search finds. Options: `search` (words of their name), `sort` ("name", "name desc"), `page` and `perPage`.
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        self::listing()->check($options);
        $direction = str_ends_with(strtolower(trim((string) ($options['sort'] ?? 'name'))), 'desc') ? 'desc' : 'asc';
        // Only who published their profile ("show in search" in the viewer), as the search of the viewers does
        $found = Avatar::all($config, [
            'search' => (string) ($options['search'] ?? ''),
            'filters' => ['active' => 'yes', 'technical' => 'no', 'published' => 'yes'],
            'sort' => "name $direction",
            'page' => $options['page'] ?? 1,
            'perPage' => $options['perPage'] ?? 0,
        ]);

        return new Collection(array_map(static fn(Avatar $avatar): self => new self($config, ['avatar' => $avatar]), $found->items()), [
            'total' => $found->total(),
            'page' => $found->page(),
            'perPage' => $found->perPage(),
            'sort' => "name $direction",
            'search' => $found->search(),
        ]);
    }

    public static function find(GridConfig $config, string $id): ?static
    {
        $avatar = Avatar::find($config, $id);

        return $avatar === null || !$avatar->isPublished() || !$avatar->isEnabled() || $avatar->isTechnical() ? null : new self($config, ['avatar' => $avatar]);
    }

    /** The list of the People tab of the viewers: the name. */
    public static function listing(): Listing
    {
        return new Listing(
            [Listing::column('name', 'web_search_name', sort: 'name', more: ['picture' => 'picture', 'default' => 'person', 'link' => 'profilePage'])],
            search: ['name'],
            texts: ['title' => 'web_search_people', 'empty' => 'web_search_nothing'],
        );
    }

    /** What the viewers show of a person found: their picture, their key, their birth, their account, their partner, about them, their groups. */
    public static function itemForm(): ItemForm
    {
        return new ItemForm(
            [
                ItemForm::shown('picture', 'profile_picture', 'picture', 'person', ['default' => 'person']),
                ItemForm::shown('name', 'web_search_name', 'text', 'person'),
                ItemForm::shown('id', 'web_search_key', 'text', 'person'),
                ItemForm::shown('born', 'web_search_born', 'date', 'person', ['age' => true]),
                ItemForm::shown('account', 'web_search_account', 'role', 'person', ['values' => Listing::values(array_combine(array_keys(Avatar::ROLES), array_map(Catalog::roleKey(...), array_keys(Avatar::ROLES))))]),
                ItemForm::shown('partnerName', 'web_search_partner', 'link', 'person', ['link' => 'partnerPage', 'empty' => 'web_search_none']),
                ItemForm::shown('about', 'web_search_about', 'text', 'person'),
                ItemForm::shown('groups', 'web_search_groups', 'list', 'person', ['empty' => 'web_search_none']),
                ItemForm::shown('profilePage', 'web_search_show_profile', 'link', 'person', ['action' => true]),
            ],
            [ItemForm::group('person', 'web_search_people')],
        );
    }
}
