<?php

use W4OS\Engine\Api\Access;
use W4OS\Engine\Api\Contract;
use W4OS\Engine\Models\Avatar;

describe('The settings of a site', function () {
    test('are changed by a god, as any change that is not the care of an account, the contract saying it (x-site)', function () {
        expect(Access::site())->toBe(['settings' => ['level' => Avatar::GOD_FULL, 'self' => false]])
            ->and(Contract::document()['x-site'] ?? null)->toBe(Access::site());
    });
});
