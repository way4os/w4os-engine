<?php

declare(strict_types=1);

/**
 * Who may do what through a site: the lowest user level of OpenSimulator (Avatar::LEVELS) a person needs for each operation of the
 * API, on what is not their own; what a visitor does for their own account (`self`); what anybody may (the pages, the services of
 * the viewers). The levels are the six of OpenSimulator, GOD_MAINTENANCE never needed.
 */

use OpenSim\Api\Access;
use OpenSim\Api\Contract;
use OpenSim\Models\Avatar;

describe('The access to the API', function () {
    test('gives every operation of the contract an access: a level of OpenSimulator, never 250, or none for what anybody may', function () {
        $wrong = [];
        foreach (Contract::document()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (!is_array($operation) || !isset($operation['operationId'])) {
                    continue;
                }
                $access = $operation['x-access'] ?? null;
                $access !== null || ($wrong[] = "$method $path: no x-access");
                $level = $access['level'] ?? null;
                $level === null || (isset(Avatar::LEVELS[$level]) && $level < Avatar::GOD_MAINTENANCE) || ($wrong[] = "$method $path: $level");
            }
        }

        expect($wrong)->toBe([]);
    });

    test('lets the customer service and above read the lists and the items, and manage the accounts', function () {
        expect([Access::level('listAvatars'), Access::level('getRegion'), Access::level('listArchives')])
            ->toBe([Avatar::GOD_CUSTOMER_SERVICE, Avatar::GOD_CUSTOMER_SERVICE, Avatar::GOD_CUSTOMER_SERVICE])
            ->and([Access::level('updateAvatar'), Access::level('createAvatar')])
            ->toBe([Avatar::GOD_CUSTOMER_SERVICE, Avatar::GOD_CUSTOMER_SERVICE])
            ->and(Access::allows(Avatar::GOD_CUSTOMER_SERVICE, 'listAvatars'))
            ->toBeTrue()
            ->and(Access::allows(Avatar::GOD_LIKE, 'listAvatars'))
            ->toBeFalse();
    });

    test('keeps to the gods (GOD_FULL) what changes the grid itself: instances, regions, setups, backups, restores, the role of an avatar', function () {
        $god = array_map(Access::level(...), ['deleteAvatar', 'updateRegion', 'startInstance', 'stopInstance', 'restartInstance', 'applySetupFile', 'importSetup', 'restoreBackup', 'createArchive', 'runTask', 'updateSimulator', 'updateGrid']);

        expect(array_unique($god))
            ->toBe([Avatar::GOD_FULL])
            ->and(Access::fieldLevel('avatars', 'role'))
            ->toBe(Avatar::GOD_FULL)
            ->and(Access::fieldLevel('avatars', 'email'))
            ->toBe(Avatar::GOD_CUSTOMER_SERVICE)
            ->and(Access::allows(Avatar::GOD_LIAISON, 'startInstance'))
            ->toBeFalse()
            ->and(Access::allows(Avatar::GOD_MAINTENANCE, 'startInstance'))
            ->toBeTrue();
    });

    test('lets a visitor do for their own account what a site offers them, and anybody what the viewers call', function () {
        expect([Access::isSelf('updateAvatar'), Access::isSelf('signIn'), Access::isSelf('deleteAvatar')])
            ->toBe([true, true, false])
            ->and(Access::allows(Avatar::GOD_NOT, 'updateAvatar', own: true))
            ->toBeTrue()
            ->and(Access::allows(Avatar::GOD_NOT, 'updateAvatar'))
            ->toBeFalse()
            ->and([Access::level('signIn'), Access::level('searchQuery'), Access::level('pageProfile')])
            ->toBe([null, null, null])
            ->and(Access::allows(Avatar::GOD_NOT, 'searchQuery'))
            ->toBeTrue()
            ->and(fn() => Access::level('noSuchOperation'))
            ->toThrow(InvalidArgumentException::class);
    });

    test('says the level of the fields of a page as the contract does (x-form)', function () {
        $fields = array_column(Contract::document()['paths']['/grids/{grid}/avatars/{id}']['get']['x-form']['fields'], null, 'name');

        expect($fields['role']['access']['level'] ?? null)
            ->toBe(Avatar::GOD_FULL);
    });
});
