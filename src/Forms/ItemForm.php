<?php

declare(strict_types=1);

namespace W4OS\Engine\Forms;

use W4OS\Engine\Data\Listing;

/**
 * What the page of one item of a model shows and lets change, as the model tells it: its fields in their order (the standard field
 * format, FieldSet), the groups they are in (sections or tabs), and the actions of the item. The labels are keys of the catalog
 * (Catalog::MESSAGES), as in a Listing, so it is the same in every language.
 *
 * The contract gives it as `x-form` on the operation that reads the item (document()). A field is shown in the view and in the edit
 * form; a `readOnly` one is never sent, the others are what the PATCH of the item takes.
 *
 * Who sees and changes a field (`access`): `view` and `edit`, each a list of roles of whoever asks: `manager` (of the grid), `self`
 * (the avatar itself), `owner` (of the item). No `view`: whoever sees the item; no `edit`: the managers. The API itself opens
 * everything to its key: the roles are for the site that shows the form to a person.
 */
final class ItemForm
{
    /**
     * How a field shows and takes its value: the types of a column of a list (Listing::TYPES), and the ones of an input: `string`
     * (one line), `text` (several lines), `integer`, `select` (one of `options`), `checkboxes` (some of `options`), `secret` (a
     * password, never shown), `url`, `avatar` (the UUID of an avatar of the grid).
     */
    public const INPUTS = ['string', 'text', 'integer', 'select', 'checkboxes', 'secret', 'url', 'avatar', 'connection-string'];

    /**
     * @param list<array<string,mixed>> $fields made by field()
     * @param list<array{name:string,label:string}> $groups made by group(), in their order
     * @param list<array<string,mixed>> $actions made by Listing::action()
     * @param list<array<string,string>> $sections made by section(), in their order: what the page of the item shows, each a group of
     *        its fields or a list of its own, that a site shows as tabs or one after the other
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $groups = [],
        public readonly array $actions = [],
        public readonly array $sections = [],
    ) {
        foreach ($sections as $section) {
            !isset($section['group']) || in_array($section['group'], array_column($groups, 'name'), true) ||
                throw new \InvalidArgumentException("'{$section['group']}' is not a group of the form");
        }
        foreach ($fields as $field) {
            in_array($field['type'], [...Listing::TYPES, ...self::INPUTS], true) ||
                throw new \InvalidArgumentException("'{$field['type']}' is not a type of field");
            ($field['group'] ?? null) === null ||
                in_array($field['group'], array_column($groups, 'name'), true) ||
                throw new \InvalidArgumentException("'{$field['group']}' is not a group of the form");
        }
    }

    /**
     * A section of the page of the item: its name, the key of its title, and what it shows: the fields of a group (`group`), or the
     * items of a list of its own (`items`, the path under the item: posts, picks...).
     *
     * @return array{name:string,label:string,group?:string,items?:string}
     */
    public static function section(string $name, string $label, ?string $group = null, ?string $items = null): array
    {
        return ['name' => $name, 'label' => $label] + ($group === null ? [] : ['group' => $group]) + ($items === null ? [] : ['items' => $items]);
    }

    /**
     * The form as a grid has it: without the fields whose setting (`setting`) it has false, the groups left without a field, the
     * sections of those groups.
     *
     * @param array<string,bool> $settings by name, a setting not given being true
     */
    public function for(array $settings): self
    {
        $fields = array_values(array_filter($this->fields, static fn(array $field): bool => !isset($field['setting']) || ($settings[$field['setting']] ?? true)));
        $used = array_unique(array_column($fields, 'group'));
        $groups = array_values(array_filter($this->groups, static fn(array $group): bool => in_array($group['name'], $used, true)));
        $sections = array_values(array_filter($this->sections, static fn(array $section): bool => !isset($section['group']) || in_array($section['group'], $used, true)));

        return new self($fields, $groups, $this->actions, $sections);
    }

    /** A group of fields: its name, and the key of its title. */
    public static function group(string $name, string $label): array
    {
        return ['name' => $name, 'label' => $label];
    }

    /**
     * A field that is shown, never sent: the attribute, the key of its title, its type, its group.
     *
     * @param array<string,mixed> $more `condition`, `access` (FieldSet), `picture`, `link` (as a column), `banner` (shown over every
     *        tab of the page)
     */
    public static function shown(string $name, string $label, string $type, string $group, array $more = []): array
    {
        return ['name' => $name, 'label' => $label, 'type' => $type, 'group' => $group, 'readOnly' => true] + $more;
    }

    /**
     * A field that is shown and changed by the PATCH of the item.
     *
     * @param array<string,string> $options value => key of its text, for `select` and `checkboxes`
     * @param array<string,mixed> $more `condition`, `access`, `required`, `description` (a key of the catalog), `writeOnly` (a value
     *        the item does not give back, a password)
     */
    public static function field(
        string $name,
        string $label,
        string $type,
        string $group,
        array $options = [],
        array $more = [],
    ): array {
        return ['name' => $name, 'label' => $label, 'type' => $type, 'group' => $group] +
            ($options === [] ? [] : ['options' => $options]) +
            $more;
    }

    /** @return list<string> the fields the banner of the page shows over every tab (`banner`), in their order */
    public function banner(): array
    {
        return array_values(array_column(array_filter($this->fields, static fn(array $field): bool => (bool) ($field['banner'] ?? false)), 'name'));
    }

    /** The fields in the standard format (FieldSet), for whoever checks or asks them in PHP. */
    public function fieldSet(): FieldSet
    {
        return new FieldSet($this->fields);
    }

    /** @return list<string> the fields the PATCH of the item takes */
    public function writable(): array
    {
        return array_values(
            array_column(array_filter($this->fields, static fn(array $field): bool => !($field['readOnly'] ?? false)), 'name'),
        );
    }

    /**
     * What the contract says of the item (`x-form`).
     *
     * @return array{groups:list<array<string,string>>,fields:list<array<string,mixed>>,actions:list<array<string,mixed>>,sections?:list<array<string,string>>}
     */
    public function document(): array
    {
        return ['groups' => $this->groups, 'fields' => $this->fields, 'actions' => $this->actions] + ($this->sections === [] ? [] : ['sections' => $this->sections]);
    }

    /**
     * The keys of the catalog it uses.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [
            ...array_column($this->groups, 'label'),
            ...array_column($this->fields, 'label'),
            ...array_column($this->fields, 'description'),
            ...array_column($this->actions, 'label'),
            ...array_column($this->sections, 'label'),
        ];
        foreach ($this->fields as $field) {
            array_push(
                $keys,
                ...array_values($field['options'] ?? []),
                ...array_column($field['values'] ?? [], 'label'),
                ...array_column($field['values'] ?? [], 'description'),
            );
        }

        return array_values(array_unique($keys));
    }
}
