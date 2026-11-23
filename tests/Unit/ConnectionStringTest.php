<?php

declare(strict_types=1);

/**
 * A connection string of OpenSimulator written for a database server: its port apart, as the connector of MySQL reads it.
 */

use W4OS\Engine\Config\ConnectionString;

describe('A connection string written', function () {
    test('gives the port of its server apart, and reads back the same server', function () {
        $written = (string) ConnectionString::for('127.0.0.1:3307', 'sim', 'opensim', 'secret');

        expect($written)->toStartWith('Data Source=127.0.0.1;Port=3307;Database=sim;')
            ->and(ConnectionString::parse($written)?->source)->toBe('127.0.0.1:3307')
            ->and((string) ConnectionString::for('db.example.org', 'sim', 'opensim', 'secret'))->toStartWith('Data Source=db.example.org;Database=sim;');
    });
});
