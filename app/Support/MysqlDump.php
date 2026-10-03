<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Writes a MySQL or MariaDB database to a file as SQL, one statement per line, so a restore can run
 * the lines one by one. Rows go straight to the file, so even a large database never has to fit in
 * PHP's memory, and every table is read at the same moment.
 */
class MysqlDump
{
    public static function toFile(string $file, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();
        $pdo = $connection->getPdo();
        $handle = fopen($file, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Cannot write the database dump {$file}.");
        }

        // A snapshot shows every table at the same moment without locking the site. Starting one
        // inside an open transaction would commit that transaction, so then the dump goes without.
        $snapshot = $connection->transactionLevel() === 0 && ! $pdo->inTransaction();

        try {
            if ($snapshot) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            }

            self::write($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");

            foreach (self::query($pdo, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $table = '`'.str_replace('`', '``', (string) $name).'`';
                $create = (string) (self::query($pdo, "SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_NUM)[1] ?? '');

                self::write($handle, "DROP TABLE IF EXISTS {$table};\n".preg_replace('/\s*\R\s*/', ' ', $create).";\n");
                self::writeRows($pdo, $table, $handle);
            }

            self::write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            if ($snapshot) {
                try {
                    $pdo->exec('COMMIT');
                } catch (Throwable) {
                    // Nothing was written, so a lost connection loses nothing here.
                }
            }

            fclose($handle);
        }
    }

    /**
     * Rows are read unbuffered: the server sends them one at a time instead of the whole table at once.
     *
     * @param  resource  $handle
     */
    private static function writeRows(PDO $pdo, string $table, $handle): void
    {
        $buffered = $pdo->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);
        $statement = null;

        try {
            $statement = self::query($pdo, "SELECT * FROM {$table}");

            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                // Quoting happens in PHP, so it needs no second query while the rows are coming in.
                $values = array_map(fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value), $row);

                self::write($handle, "INSERT INTO {$table} VALUES (".implode(',', $values).");\n");
            }
        } finally {
            // Reads what is left, so the connection is free for the next query even after an error.
            $statement?->closeCursor();
            $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    private static function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);

        if ($statement === false) {
            throw new RuntimeException("The database could not be read: {$sql}");
        }

        return $statement;
    }

    /**
     * @param  resource  $handle
     */
    private static function write($handle, string $text): void
    {
        if (fwrite($handle, $text) !== strlen($text)) {
            throw new RuntimeException('The database dump could not be written. Is the disk full?');
        }
    }
}
