<?php

declare(strict_types=1);

namespace W4OS\Engine\Api;

use W4OS\Engine\Models\Avatar;

/**
 * Who may do what through a site, by the user levels of OpenSimulator (Avatar::LEVELS): the lowest level a person needs for an
 * operation of the API on what is not their own (`level`), whether a visitor may do it for their own account (`self`), or nothing
 * for what anybody may (the pages of the helpers, what the viewers and the simulators call). The contract says it of each operation
 * (`x-access`), and of the fields of a page that need more than the page (`x-form`, `access.level`); the bridges ask here, they never
 * write a level.
 *
 * In OpenSimulator a level gives the rights of the ones below it, and only GOD_FULL (200) gives rights of its own (a god of the grid:
 * PermissionsModule::IsGridAdministrator, GodController, LLLoginService); the lower levels give none unless a setting asks for them
 * (LevelUpload, LevelGroupCreate, MinLoginLevel...). So: reading, and the care of the accounts, from the customer service
 * (GOD_CUSTOMER_SERVICE); what changes the grid itself (its instances, its regions, its setups, its backups, a role) from GOD_FULL,
 * as a god may in the world; GOD_MAINTENANCE, the technical accounts, is never needed.
 *
 *     Access::allows($avatar->level(), 'startInstance');        // a god or above
 *     Access::allows($level, 'updateAvatar', own: true);          // a visitor, for their own account
 *     Access::fieldLevel('avatars', 'role');                      // GOD_FULL
 */
final class Access
{
    /** What anybody may: the pages of the helpers and the services of the grid (the ones of Router::PUBLIC are open too). */
    private const OPEN = [
        'pageAsset', 'pageBlock', 'pageDirectoryInfo', 'pageGridStatus', 'pageGuide', 'pageHealth', 'pageProfile', 'pageSearch',
        'pageWelcome', 'feedGridStatus', 'getGridInfo',
    ];

    /** What a visitor does for themselves, with no level: signing in, their password, their email. */
    private const VISITOR = ['signIn', 'requestPasswordReset', 'resetPassword', 'requestEmailConfirmation', 'confirmEmail'];

    /** What a visitor may do for their own account; others need the level of the operation. */
    private const SELF = [
        'createAvatar', 'updateAvatar', 'getAvatar', 'listAvatarPicks', 'listAvatarClassifieds', 'listAvatarGroups',
        // The stream of the avatar: its posts, written and deleted by the avatar itself
        'listAvatarPosts', 'createAvatarPost', 'deleteAvatarPost',
    ];

    /** The care of the accounts, a change that is not a read: the customer service, as reading is. */
    private const CUSTOMER_SERVICE = ['createAvatar', 'updateAvatar', 'createAvatarPost', 'deleteAvatarPost', 'deletePost'];

    /** The fields of a page that need more than the change of the page: a role, a level, as a god sets them (LLLoginService). */
    private const FIELDS = ['avatars' => [
        'role' => Avatar::GOD_FULL,
        'level' => Avatar::GOD_FULL,
        // A ban, hard or of the estates, is a god's
        'banned' => Avatar::GOD_FULL,
        'estateBanned' => Avatar::GOD_FULL,
        'estateBans' => Avatar::GOD_FULL,
    ]];

    /** @var ?array<string,array{level:?int,self:bool}> by operation, from the contract */
    private static ?array $index = null;

    /**
     * Who may change what belongs to a site, not to the engine (its own settings: its theme...), as any change that is not the care
     * of an account: a god. The contract says it (`x-site`); the bridges ask here, they never write a level.
     *
     * @return array{settings: array{level:int,self:bool}}
     */
    public static function site(): array
    {
        return ['settings' => ['level' => Avatar::GOD_FULL, 'self' => false]];
    }

    /**
     * The access of an operation, from what it is: open, a visitor's own, a read (GOD_CUSTOMER_SERVICE), the care of an account
     * (GOD_CUSTOMER_SERVICE), any other change (GOD_FULL).
     *
     * @return array{level:?int,self:bool}
     */
    public static function of(string $method, string $path, string $operationId): array
    {
        $method = strtoupper($method);
        if (in_array($operationId, self::OPEN, true) || in_array("$method $path", Router::PUBLIC, true)) {
            return ['level' => null, 'self' => false];
        }
        if (in_array($operationId, self::VISITOR, true)) {
            return ['level' => null, 'self' => true];
        }
        $level = $method === 'GET' || in_array($operationId, self::CUSTOMER_SERVICE, true) ? Avatar::GOD_CUSTOMER_SERVICE : Avatar::GOD_FULL;
        $needs = self::needs($method, $path);

        return ['level' => $level, 'self' => in_array($operationId, self::SELF, true)] + ($needs === [] ? [] : ['needs' => $needs]);
    }

    /** What of a grid the engine must reach for an operation (`access` of the grid), by the first part of its path under the grid. */
    private const NEEDS = [
        'avatars' => 'databases', 'avatar-models' => 'databases', 'regions' => 'databases', 'parcels' => 'databases',
        'estates' => 'databases', 'posts' => 'databases', 'sign-ins' => 'databases', 'password-resets' => 'databases',
        'email-confirmations' => 'databases', 'options' => 'files', 'site-description' => 'files', 'site-pages' => 'files', 'search' => 'databases',
        'address' => 'files',
    ];

    /**
     * What of a grid an operation needs the engine to reach (`needs` of `x-access`): `files`, its config on this machine (the repair of
     * its options), `settings`, where its settings are changed (its files, or what the application keeps), `databases`, its databases. Empty for what it does with what it has.
     *
     * @return list<string>
     */
    private static function needs(string $method, string $path): array
    {
        if (!str_starts_with($path, '/grids/{grid}/')) {
            return [];
        }
        $first = explode('/', substr($path, strlen('/grids/{grid}/')))[0];
        if ($first === 'settings') {
            return $method === 'PATCH' ? ['settings'] : [];
        }
        if ($first === 'assets') {
            return str_contains($path, '{format}') ? [] : ['databases'];
        }

        return isset(self::NEEDS[$first]) ? [self::NEEDS[$first]] : [];
    }

    /**
     * The lowest level an operation needs on what is not one's own, null when anybody may.
     *
     * @throws \InvalidArgumentException for an operation the contract does not have
     */
    public static function level(string $operationId): ?int
    {
        return self::access($operationId)['level'];
    }

    /** Whether a visitor may do it for their own account. */
    public static function isSelf(string $operationId): bool
    {
        return self::access($operationId)['self'];
    }

    /** Whether a person of this level may do it: on their own account (own), or on any. */
    public static function allows(int $level, string $operationId, bool $own = false): bool
    {
        $access = self::access($operationId);

        return $access['level'] === null || ($own && $access['self']) || $level >= $access['level'];
    }

    /** The level a field of a page needs to be changed: its own (FIELDS), else the one of the change of the page. */
    public static function fieldLevel(string $type, string $field): int
    {
        return self::FIELDS[$type][$field] ?? ($type === 'avatars' ? Avatar::GOD_CUSTOMER_SERVICE : Avatar::GOD_FULL);
    }

    /** @return array{level:?int,self:bool} */
    private static function access(string $operationId): array
    {
        if (self::$index === null) {
            self::$index = [];
            foreach (Contract::document()['paths'] as $operations) {
                foreach ($operations as $operation) {
                    is_array($operation) && isset($operation['operationId'], $operation['x-access']) &&
                        (self::$index[$operation['operationId']] = $operation['x-access']);
                }
            }
        }

        return self::$index[$operationId] ?? throw new \InvalidArgumentException("'$operationId' is not an operation of the API");
    }
}
