<?php

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Exceptions\ChannelUnavailable;
use W4OS\Engine\Models\Avatar;
use W4OS\Engine\Models\Grid;

/**
 * Makes the avatars of a list in a grid (see Avatar::create()), or tells what it would make: the names that
 * exist are skipped, a failure is told for the avatar and nothing of it is visible.
 */
final class AccountImporter
{
    public const CREATED = 'created';
    public const WOULD_CREATE = 'would create';
    public const EXISTS = 'exists';
    public const FAILED = 'failed';
    public const NOT_TRIED = 'not tried';

    /**
     * Each closure is given the grid (a grid of this install) or the config of a grid it does not manage (its Robust config), the
     * other one null.
     *
     * @param \Closure(?Grid,?GridConfig):?list<string> $names the names ("First Last") of the avatars of the grid, null when they cannot be read
     * @param \Closure(?Grid,?GridConfig):?string $home the id of the default region of the grid, null when it has none
     * @param \Closure(?Grid,array<string,mixed>,?GridConfig):void $create makes an avatar (the values of Avatar::create()), throws when it cannot
     */
    public function __construct(private \Closure $names, private \Closure $home, private \Closure $create) {}

    /**
     * The importer of a real grid: its avatars are read and made by the model, in the databases of its config.
     *
     * @param ?\Closure(Grid):?GridConfig $configOf the config of a grid of this install (default: its folder; the API gives the one an
     *        application gives, a site that has the engine in its own folder)
     */
    public static function forModels(?\Closure $configOf = null): self
    {
        $configOf ??= static fn(Grid $grid): GridConfig => GridConfig::fromFolder($grid->directory());
        $config = static fn(?Grid $grid, ?GridConfig $given = null): GridConfig => $given ?? $configOf($grid) ?? throw new ChannelUnavailable('the grid has no config here');

        return new self(
            static function (?Grid $grid, ?GridConfig $given = null) use ($config): ?array {
                try {
                    return array_map(
                        static fn(Avatar $avatar): string => $avatar->name(),
                        Avatar::all($config($grid, $given))->items(),
                    );
                } catch (ChannelUnavailable) {
                    return null;
                }
            },
            static function (?Grid $grid, ?GridConfig $given = null) use ($config): ?string {
                $pdo = Connections::pdo($config($grid, $given), 'GridService');
                $id = $pdo?->get_var('SELECT uuid FROM regions WHERE (flags & 1) = 1 ORDER BY regionName LIMIT 1');

                return is_string($id) && preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1 ? $id : null;
            },
            // The accounts of a list exist elsewhere: their names are taken as they are, reserved or not
            static function (?Grid $grid, array $values, ?GridConfig $given = null) use ($config): void {
                Avatar::create($config($grid, $given), $values + ['reservedName' => true]);
            },
        );
    }

    /**
     * @param Grid|GridConfig $target a grid of this install, or the config of a grid it does not manage (GridConfig::fromRobust())
     * @param list<array{first:string,last:string,email:string,password:string,line:int}> $accounts
     * @param bool $apply     write the accounts, else only tell what would be written
     * @param bool $keepGoing go on after an account that failed
     * @return array{error:?string,results:list<array{first:string,last:string,email:string,status:string,detail:string,password:string,generated:bool}>}
     */
    public function run(Grid|GridConfig $target, array $accounts, bool $apply, bool $keepGoing = false): array
    {
        [$grid, $config] = $target instanceof Grid ? [$target, null] : [null, $target];
        $names = ($this->names)($grid, $config);
        if ($names === null) {
            return ['error' => 'the database of the grid cannot be read', 'results' => []];
        }
        $exists = array_fill_keys(array_map('strtolower', array_map('trim', $names)), true);
        // The home of the accounts: the default region, when the grid has one
        $homeId = ($this->home)($grid, $config);

        $results = [];
        $stopped = false;
        foreach ($accounts as $account) {
            $row = [
                'first' => $account['first'],
                'last' => $account['last'],
                'email' => $account['email'],
                'status' => '',
                'detail' => '',
                'password' => $account['password'],
                'generated' => false,
            ];
            if ($stopped) {
                $row['status'] = self::NOT_TRIED;
                $results[] = $row;
                continue;
            }
            if (isset($exists[strtolower("{$account['first']} {$account['last']}")])) {
                $row['status'] = self::EXISTS;
                $results[] = $row;
                continue;
            }
            $hash =
                ($account['password_hash'] ?? '') !== ''
                    ? ['hash' => $account['password_hash'], 'salt' => $account['password_salt'] ?? '']
                    : null;
            if ($row['password'] === '' && $hash === null) {
                $row['password'] = self::password();
                $row['generated'] = true;
            }
            if (!$apply) {
                $row['status'] = self::WOULD_CREATE;
                $row['detail'] = $homeId === null ? 'no default region yet: no home' : '';
                $results[] = $row;
                continue;
            }

            try {
                ($this->create)(
                    $grid,
                    array_filter(
                        [
                            'firstName' => $row['first'],
                            'lastName' => $row['last'],
                            'email' => $row['email'],
                            'password' => $hash === null ? $row['password'] : null,
                            'passwordHash' => $hash,
                            'home' => $homeId,
                        ],
                        static fn($value): bool => $value !== null,
                    ),
                    $config,
                );
                $row['status'] = self::CREATED;
                $exists[strtolower("{$account['first']} {$account['last']}")] = true;
            } catch (\InvalidArgumentException | ChannelUnavailable $e) {
                $row['status'] = self::FAILED;
                $row['detail'] = $e->getMessage();
                $stopped = !$keepGoing;
            }
            $results[] = $row;
        }

        return ['error' => null, 'results' => $results];
    }

    /** A password for an account that was given none: letters and digits. */
    public static function password(int $length = 12): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /**
     * The result as a CSV for whoever sent the list: the passwords that were generated are the only ones in it.
     *
     * @param list<array{first:string,last:string,email:string,status:string,detail:string,password:string,generated:bool}> $results
     */
    public static function resultCsv(array $results): string
    {
        $out = fopen('php://memory', 'w+');
        fputcsv($out, ['first', 'last', 'email', 'status', 'password', 'detail'], ',', '"', '');
        foreach ($results as $row) {
            fputcsv(
                $out,
                [
                    $row['first'],
                    $row['last'],
                    $row['email'],
                    $row['status'],
                    $row['generated'] ? $row['password'] : '',
                    $row['detail'],
                ],
                ',',
                '"',
                '',
            );
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
