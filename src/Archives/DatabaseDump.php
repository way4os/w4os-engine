<?php

declare(strict_types=1);

namespace W4OS\Engine\Archives;

use W4OS\Engine\Data\OSPDO;
use W4OS\Engine\Provisioning\SqlRunner;

/**
 * The dump of a MySQL or MariaDB database as SQL statements that make it again (tables, rows, views, triggers, routines, events),
 * read through PDO, a consistent snapshot of the tables of InnoDB. Compressed, in a file only its owner and group read.
 */
final class DatabaseDump
{
    /** Bytes after which the rows read are written as one INSERT. */
    private const INSERT_SIZE = 1_000_000;

    /**
     * Write the dump of a database to a file, compressed.
     *
     * @param array{host:string,name:string,user:string,pass:string} $db
     * @throws \RuntimeException when the database cannot be read or the file written
     */
    public static function write(array $db, string $file): void
    {
        $out = @gzopen($file, 'wb6');
        if ($out === false) {
            throw new \RuntimeException("cannot write $file");
        }
        try {
            self::dump(self::connect($db), $db['name'], static function (string $chunk) use ($out, $file): void {
                if (gzwrite($out, $chunk) === false) {
                    throw new \RuntimeException("cannot write $file");
                }
            });
        } catch (\PDOException $e) {
            throw new \RuntimeException("dump of {$db['name']} failed: " . SqlRunner::describe($e), 0, $e);
        } finally {
            gzclose($out);
        }
        chmod($file, 0o640);
    }

    /**
     * The statements of a database, given to a function as they are made.
     *
     * @param \Closure(string):void $write
     * @throws \PDOException
     */
    public static function dump(\PDO $pdo, string $database, \Closure $write): void
    {
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $write(
            "-- Dump of database $database, " .
                date('c') .
                "\n/*!40101 SET NAMES utf8mb4 */;\n/*!40014 SET UNIQUE_CHECKS=0, FOREIGN_KEY_CHECKS=0 */;\n" .
                "/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n",
        );

        $tables = [];
        $views = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as [$name, $type]) {
            if ($type === 'VIEW') {
                $views[] = $name;
            } else {
                $tables[] = $name;
            }
        }

        foreach ($tables as $table) {
            $quoted = self::identifier($table);
            $create = $pdo->query("SHOW CREATE TABLE $quoted")->fetch(\PDO::FETCH_NUM)[1];
            $write("\n--\n-- Table $table\n--\n\nDROP TABLE IF EXISTS $quoted;\n$create;\n\n");
            self::rows($pdo, $table, $write);
        }
        foreach ($views as $view) {
            $quoted = self::identifier($view);
            $create = $pdo->query("SHOW CREATE VIEW $quoted")->fetch(\PDO::FETCH_NUM)[1];
            $write("\n-- View $view\n\nDROP VIEW IF EXISTS $quoted;\n$create;\n");
        }

        foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(\PDO::FETCH_NUM) as $trigger) {
            $write(self::program($pdo, 'TRIGGER', $trigger[0], 2));
        }
        foreach (['PROCEDURE', 'FUNCTION'] as $kind) {
            $routines = $pdo
                ->query("SHOW $kind STATUS WHERE Db = " . $pdo->quote($database))
                ->fetchAll(\PDO::FETCH_NUM);
            foreach ($routines as $routine) {
                $write(self::program($pdo, $kind, $routine[1], 2));
            }
        }
        foreach ($pdo->query('SHOW EVENTS')->fetchAll(\PDO::FETCH_NUM) as $event) {
            $write(self::program($pdo, 'EVENT', $event[1], 3));
        }

        $pdo->exec('COMMIT');
        $write("\n-- End of dump\n");
    }

    /** The rows of a table, as INSERT statements of a limited size. */
    private static function rows(\PDO $pdo, string $table, \Closure $write): void
    {
        $quoted = self::identifier($table);
        $tuples = [];
        $size = 0;
        $statement = $pdo->query("SELECT * FROM $quoted");
        while (($row = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
            $tuple =
                '(' .
                implode(',', array_map(static fn(mixed $value): string => self::literal($pdo, $value), $row)) .
                ')';
            $tuples[] = $tuple;
            $size += strlen($tuple);
            if ($size >= self::INSERT_SIZE) {
                $write(self::insert($quoted, $tuples));
                [$tuples, $size] = [[], 0];
            }
        }
        if ($tuples !== []) {
            $write(self::insert($quoted, $tuples));
        }
    }

    /**
     * An INSERT of rows already written as tuples.
     *
     * @param list<string> $tuples
     */
    public static function insert(string $quotedTable, array $tuples): string
    {
        return "INSERT INTO $quotedTable VALUES " . implode(",\n", $tuples) . ";\n";
    }

    /** A value as a SQL literal: numbers and NULL as such, text quoted, what is not text in hexadecimal. */
    public static function literal(\PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return var_export($value, true);
        }
        $value = (string) $value;
        if ($value !== '' && (preg_match('//u', $value) !== 1 || str_contains($value, "\0"))) {
            return '0x' . bin2hex($value);
        }

        return $pdo->quote($value);
    }

    /** A name of the database, quoted. */
    public static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * A trigger, a routine or an event: the client needs another delimiter than ; to read their body.
     *
     * @param int $column the place of the definition in the answer of SHOW CREATE
     * @throws \RuntimeException when the account cannot read the definition: the server answers NULL
     */
    private static function program(\PDO $pdo, string $kind, string $name, int $column): string
    {
        $quoted = self::identifier($name);
        $create = $pdo->query("SHOW CREATE $kind $quoted")->fetch(\PDO::FETCH_NUM)[$column] ?? null;
        if ($create === null) {
            throw new \RuntimeException(
                'the definition of the ' . strtolower($kind) . " $name cannot be read, the account lacks a privilege",
            );
        }

        return "\nDELIMITER ;;\nDROP $kind IF EXISTS $quoted;;\n$create;;\nDELIMITER ;\n";
    }

    /**
     * Make a database again from its dump (write()): its statements run one after the other, in the database the PDO is connected to.
     *
     * @return int the statements run
     * @throws \RuntimeException when the file cannot be read, or a statement fails (which one is said)
     */
    public static function load(\PDO $pdo, string $file): int
    {
        $in = @gzopen($file, 'rb');
        if ($in === false) {
            throw new \RuntimeException("cannot read $file");
        }
        $lines = (static function () use ($in): \Generator {
            while (($line = gzgets($in)) !== false) {
                yield rtrim($line, "\r\n");
            }
        })();
        $count = 0;
        try {
            foreach (self::statements($lines) as $statement) {
                $pdo->exec($statement);
                $count++;
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException("statement $count of $file failed: " . SqlRunner::describe($e), 0, $e);
        } finally {
            gzclose($in);
        }

        return $count;
    }

    /**
     * The statements of a dump, as write() makes them: a statement ends with the delimiter at the end of a line (a value never holds
     * a line break: it is escaped), `;` unless a DELIMITER line says another (the triggers and the routines); the comments are left.
     *
     * @param iterable<string> $lines without their line break
     * @return \Generator<string>
     */
    public static function statements(iterable $lines): \Generator
    {
        $delimiter = ';';
        $buffer = [];
        foreach ($lines as $line) {
            if ($buffer === [] && (trim($line) === '' || str_starts_with($line, '--'))) {
                continue;
            }
            if ($buffer === [] && preg_match('/^DELIMITER\s+(\S+)\s*$/i', $line, $m) === 1) {
                $delimiter = $m[1];

                continue;
            }
            $buffer[] = $line;
            if (str_ends_with(rtrim($line), $delimiter)) {
                $statement = implode("\n", $buffer);
                $buffer = [];
                yield substr(rtrim($statement), 0, -strlen($delimiter));
            }
        }
        if (trim(implode('', $buffer)) !== '') {
            yield implode("\n", $buffer);
        }
    }

    /**
     * @param array{host:string,name:string,user:string,pass:string} $db
     * @throws \RuntimeException
     */
    private static function connect(array $db): \PDO
    {
        $pdo = new OSPDO([
            'host' => $db['host'],
            'name' => $db['name'],
            'user' => $db['user'],
            'pass' => $db['pass'],
            'log' => false,
        ]);
        if (!$pdo->connected) {
            throw new \RuntimeException("cannot connect to {$db['name']}: " . SqlRunner::describe($pdo->error));
        }

        return $pdo;
    }
}
