<?php

declare(strict_types=1);

/**
 * The dump of a database: how a value is written in an INSERT, how a failure is told.
 */

use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Archives\DatabaseDump;

describe('a value in an INSERT', function () {
    test('is NULL, a number as it is, text quoted, what is not text in hexadecimal', function () {
        $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);

        expect(DatabaseDump::literal($pdo, null))
            ->toBe('NULL')
            ->and(DatabaseDump::literal($pdo, 42))
            ->toBe('42')
            ->and(DatabaseDump::literal($pdo, 0.1))
            ->toBe('0.1')
            ->and(DatabaseDump::literal($pdo, "it's"))
            ->toBe("'it''s'")
            ->and(DatabaseDump::literal($pdo, ''))
            ->toBe("''")
            ->and(DatabaseDump::literal($pdo, 'é'))
            ->toBe("'é'")
            ->and(DatabaseDump::literal($pdo, "\xff\x00\x01"))
            ->toBe('0x' . 'ff0001')
            ->and(DatabaseDump::literal($pdo, "a\0b"))
            ->toBe('0x' . '610062');
    })->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

    test('comes in an INSERT with the rows one per line', function () {
        expect(DatabaseDump::insert('`t`', ['(1,NULL)', "(2,'x')"]))->toBe(
            "INSERT INTO `t` VALUES (1,NULL),\n(2,'x');\n",
        );
    });

    test('has its table name quoted, a backtick doubled', function () {
        expect(DatabaseDump::identifier('UserAccounts'))
            ->toBe('`UserAccounts`')
            ->and(DatabaseDump::identifier('we`ird'))
            ->toBe('`we``ird`');
    });
});

describe('dump of a database that cannot be read', function () {
    test('fails with the reason and writes nothing worth keeping', function () {
        $file = test_tmp() . '/dump-' . bin2hex(random_bytes(4)) . '.sql.gz';

        expect(
            fn() => DatabaseDump::write(
                ['host' => '127.0.0.1:1', 'name' => 'grid', 'user' => 'x', 'pass' => 'secret'],
                $file,
            ),
        )->toThrow(RuntimeException::class, 'cannot connect to grid: ERROR 2002');
        @unlink($file);
    })->skip(fn() => !extension_loaded('pdo_mysql'), 'pdo_mysql is missing');
});

describe('a dump read back', function () {
    test('is its statements, as the dump writes them: one per ";" at the end of a line, the routines between their DELIMITER', function () {
        $dump = "-- Dump of database grid\n/*!40101 SET NAMES utf8mb4 */;\n\n--\n-- Table t\n--\n\nDROP TABLE IF EXISTS `t`;\n" .
            "CREATE TABLE `t` (\n  `id` int,\n  `name` text\n);\n\n" . DatabaseDump::insert('`t`', ['(1,\'a;b\')', "(2,'x')"]) .
            "\nDELIMITER ;;\nDROP TRIGGER IF EXISTS `tr`;;\nCREATE TRIGGER `tr` BEFORE INSERT ON `t` FOR EACH ROW BEGIN\n  SET NEW.id = 1;\nEND;;\nDELIMITER ;\n\n-- End of dump\n";

        expect(iterator_to_array(DatabaseDump::statements(new ArrayIterator(explode("\n", $dump))), false))->toBe([
            '/*!40101 SET NAMES utf8mb4 */',
            'DROP TABLE IF EXISTS `t`',
            "CREATE TABLE `t` (\n  `id` int,\n  `name` text\n)",
            "INSERT INTO `t` VALUES (1,'a;b'),\n(2,'x')",
            'DROP TRIGGER IF EXISTS `tr`',
            "CREATE TRIGGER `tr` BEFORE INSERT ON `t` FOR EACH ROW BEGIN\n  SET NEW.id = 1;\nEND",
        ]);
    });

    test('makes the database again from its file', function () {
        $file = test_tmp() . '/load-' . bin2hex(random_bytes(4)) . '.sql.gz';
        file_put_contents($file, gzencode("DROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (\n  `id` int\n);\n" . DatabaseDump::insert('`t`', ['(1)', '(2)'])));
        $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);

        $count = DatabaseDump::load($pdo, $file);

        expect($count)
            ->toBe(3)
            ->and((int) $pdo->get_var('SELECT COUNT(*) FROM t'))
            ->toBe(2);
    })->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');
});
