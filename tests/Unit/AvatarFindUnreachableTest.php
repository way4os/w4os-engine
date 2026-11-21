<?php

use W4OS\Engine\Config\GridConfig;
use W4OS\Engine\Data\Connections;
use W4OS\Engine\Exceptions\ChannelUnavailable;
use W4OS\Engine\Models\Avatar;

afterEach(fn() => Connections::using(null));

describe('An avatar asked by its name or its UUID', function () {
    test('whose database the grid says cannot be reached is no missing avatar: the engine says it cannot be reached', function () {
        $config = new GridConfig(['Robust' => ['DatabaseService' => ['ConnectionString' => 'Data Source=db.example.org;Database=robust;User ID=os;Password=pw;']]]);
        Connections::using(static fn() => null);

        expect(fn() => Avatar::find($config, 'Ada.Love'))->toThrow(ChannelUnavailable::class);
    });

    test('of a grid that does not say where its accounts are is none, as before', function () {
        expect(Avatar::find(new GridConfig(), 'Ada.Love'))->toBeNull();
    });
});
