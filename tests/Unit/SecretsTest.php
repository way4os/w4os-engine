<?php

declare(strict_types=1);

/** What the engine shows of a log or a config, without the secrets they hold. */

use OpenSim\Runtime\LogFile;
use OpenSim\Text\Secrets;

describe('Secrets', function () {
    test('are masked in what is shown: the secret of the data service, passwords, keys in addresses', function () {
        expect(Secrets::mask('[DATASNAPSHOT]: data service http://w.example.org/register.php notified. Secret: 4f1c2a6e-1111-4222-8333-944444444444'))
            ->toBe('[DATASNAPSHOT]: data service http://w.example.org/register.php notified. Secret: ***')
            ->and(Secrets::mask('GET http://w.example.org/register.php?service=online&secret=abc123&port=9000'))
            ->toBe('GET http://w.example.org/register.php?service=online&secret=***&port=9000')
            ->and(Secrets::mask('Data Source=localhost;Database=os;User ID=opensim;Password=s3cr3t;Old Guids=true;'))
            ->toBe('Data Source=localhost;Database=os;User ID=opensim;Password=***;Old Guids=true;')
            ->and(Secrets::mask('ConsolePass = "hello world"'))
            ->toBe('ConsolePass = "***"')
            ->and(Secrets::mask('http://admin:pw@host:8003/ and an ordinary line'))
            ->toBe('http://admin:***@host:8003/ and an ordinary line')
            ->and(Secrets::mask('Region Welcome registered'))
            ->toBe('Region Welcome registered');
    });

    test('never reach what the engine shows of a log while an instance stops or starts', function () {
        $file = test_tmp() . '/secrets-' . bin2hex(random_bytes(4)) . '.log';
        file_put_contents($file, "2026-11-16 22:00:00,000 INFO  - [DATASNAPSHOT]: data service http://w/register.php notified. Secret: 4f1c2a6e-1111-4222-8333-944444444444\n");
        $log = new LogFile($file);

        expect($log->lastLine())
            ->toEndWith('[DATASNAPSHOT]: data service http://w/register.php notified. Secret: ***')
            ->and($log->since(0))
            ->not->toContain('4f1c2a6e');
    });
});
