<?php

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Models\Avatar;

/**
 * A list of accounts to make, given as CSV (first, last, email, password, with or without a header line; with a header, its
 * columns in any order, password_hash and password_salt too; a comma, a semicolon or a tab between them, quoted fields as RFC
 * 4180 has them), as JSON or YAML (a list of objects with the same keys), as a third party gives it.
 */
final class AccountList
{
    /**
     * @return array{accounts:list<array{first:string,last:string,email:string,password:string,password_hash:string,password_salt:string,line:int}>,errors:list<string>}
     */
    public static function parse(string $text, ?string $format = null): array
    {
        $text = ltrim($text, "\xEF\xBB\xBF"); // the byte order mark of a spreadsheet's export
        $format ??= str_starts_with(ltrim($text), '[') || str_starts_with(ltrim($text), '{') ? 'json' : 'csv';
        $rows = match ($format) {
            'json' => self::jsonRows($text),
            'yaml' => self::dataRows(self::yaml($text)),
            default => self::csvRows($text),
        };

        $accounts = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $line => $row) {
            $first = trim((string) ($row['first'] ?? ''));
            $last = trim((string) ($row['last'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $problem = null;
            $hash = strtolower(trim((string) ($row['password_hash'] ?? '')));
            $salt = trim((string) ($row['password_salt'] ?? ''));
            if ($hash !== '' && !Avatar::validPasswordHash($hash, $salt)) {
                $problem = 'password_hash is the 32 hexadecimal characters Robust keeps, with its password_salt';
            } elseif (Avatar::validateName("$first $last") !== null) {
                $problem = "name \"$first $last\" must be a first and a last name of letters, digits and -";
            } elseif ($email !== '' && !Avatar::validEmail($email)) {
                $problem = "email \"$email\" is not an address";
            } elseif (isset($seen[strtolower("$first $last")])) {
                $problem = "$first $last is in the list twice";
            }
            if ($problem !== null) {
                $errors[] = "line $line: $problem";
                continue;
            }
            $seen[strtolower("$first $last")] = true;
            $accounts[] = [
                'first' => $first,
                'last' => $last,
                'email' => $email,
                'password' => (string) ($row['password'] ?? ''),
                // The password as Robust keeps it: given instead of the password, it is written as it is
                'password_hash' => $hash,
                'password_salt' => $salt,
                'line' => $line,
                // Its groups, `Group` or `Group:Role` (GroupMemberships): a list, or a cell separated by |
                'groups' => self::groupsOf($row['groups'] ?? null),
            ];
        }

        return ['accounts' => $accounts, 'errors' => $errors];
    }

    /** @return array<int,array<string,mixed>> line number => values */
    private static function jsonRows(string $text): array
    {
        return self::dataRows(json_decode($text, true));
    }

    /** The accounts of a list, or of the `users` (or `accounts`) of a setup file. */
    private static function dataRows(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $list = array_is_list($data) ? $data : $data['accounts'] ?? ($data['users'] ?? []);
        $rows = [];
        foreach (is_array($list) ? $list : [] as $i => $entry) {
            if (is_array($entry)) {
                $rows[$i + 1] = self::keys($entry);
            }
        }

        return $rows;
    }

    private static function yaml(string $text): mixed
    {
        try {
            return \Symfony\Component\Yaml\Yaml::parse($text);
        } catch (\Symfony\Component\Yaml\Exception\ParseException) {
            return null;
        }
    }

    /** @return array<int,array<string,mixed>> line number => values */
    private static function csvRows(string $text): array
    {
        $first = trim((string) (preg_split('/\r\n|\r|\n/', $text, 2) ?: [''])[0]);
        $delimiter =
            substr_count($first, ';') > substr_count($first, ',') ? ';' : (str_contains($first, "\t") ? "\t" : ',');
        $names = ['first', 'last', 'email', 'password'];
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, $text);
        rewind($stream);
        // The records as RFC 4180 has them (a quoted field may hold the separator and line breaks), each with its first line
        $records = [];
        $line = 1;
        while (true) {
            $at = (int) ftell($stream);
            $cells = fgetcsv($stream, null, $delimiter, '"', '');
            if ($cells === false) {
                break;
            }
            $records[$line] = $cells;
            $line += max(1, substr_count(substr($text, $at, (int) ftell($stream) - $at), "\n"));
        }
        fclose($stream);
        // A header names first and last, in any order, with the other columns
        $head = array_map(static fn($cell): string => self::normalize((string) $cell), (array) reset($records));
        $header = in_array('first', $head, true) && in_array('last', $head, true) ? $head : null;
        // Taken off by its key: the keys are the lines
        $header === null || array_key_first($records) === null || ($records = array_diff_key($records, [array_key_first($records) => true]));

        $rows = [];
        foreach ($records as $at => $cells) {
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '' && count($cells) <= 1) {
                continue;
            }
            $cells = array_map(static fn($cell): string => (string) $cell, $cells);
            $rows[$at] =
                $header !== null
                    ? self::keys(array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), '')))
                    : array_combine($names, array_pad(array_slice($cells, 0, 4), 4, ''));
        }

        return $rows;
    }

    /** @return list<string> the groups of an account: a list, or a text separated by | (or ;) */
    private static function groupsOf(mixed $value): array
    {
        $items = is_array($value) ? $value : (preg_split('/[|;]/', (string) $value) ?: []);

        return array_values(array_filter(array_map(static fn($item): string => trim((string) $item), $items), static fn(string $item): bool => $item !== ''));
    }

    /**
     * The keys a list may use for a value, brought to first, last, email, password.
     *
     * @param array<string|int,mixed> $entry
     * @return array<string,mixed>
     */
    private static function keys(array $entry): array
    {
        $values = [];
        foreach ($entry as $key => $value) {
            $values[self::normalize((string) $key)] = $value;
        }

        return $values;
    }

    private static function normalize(string $key): string
    {
        return match (strtolower(trim(preg_replace('/[^A-Za-z]/', '', $key) ?? ''))) {
            'first', 'firstname' => 'first',
            'last', 'lastname', 'surname' => 'last',
            'email', 'mail' => 'email',
            'password', 'pass', 'pwd' => 'password',
            'passwordhash', 'hash' => 'password_hash',
            'passwordsalt', 'salt' => 'password_salt',
            'groups', 'group' => 'groups',
            default => strtolower(trim($key)),
        };
    }
}
