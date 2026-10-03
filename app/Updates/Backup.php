<?php

namespace App\Updates;

use App\Support\MysqlDump;
use Illuminate\Support\Facades\DB;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Zips the application files and database before an update, and puts them back if the update fails.
 */
class Backup
{
    private const KEEP = 3;

    private const SQL_ENTRY = '__nuvabill_database.sql';

    private const SQLITE_ENTRY = '__nuvabill_database.sqlite';

    /**
     * Paths never written into a backup or overwritten by a restore.
     *
     * @var list<string>
     */
    private const EXCLUDED = ['.env', '.git', 'node_modules', 'storage', 'public/storage', 'public/hot'];

    public function __construct(
        private string $basePath,
        private string $backupPath,
    ) {}

    public function create(string $label): string
    {
        if (! is_dir($this->backupPath) && ! mkdir($this->backupPath, 0755, true) && ! is_dir($this->backupPath)) {
            throw new RuntimeException("Cannot create the backup folder {$this->backupPath}.");
        }

        // A backup that PHP stopped half-way can leave a zip and a database dump behind.
        $this->prune();

        $file = $this->backupPath.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9._-]/', '-', $label).'-'.date('Ymd-His').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write the backup file {$file}.");
        }

        $dump = null;

        try {
            $sqlitePath = $this->sqlitePath();

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                $relative = $this->relative($item->getPathname());

                if ($this->isExcluded($relative) || ($sqlitePath !== null && realpath($item->getPathname()) === $sqlitePath)) {
                    continue;
                }

                if ($item->isFile()) {
                    $zip->addFile($item->getPathname(), $relative);
                }
            }

            if ($sqlitePath !== null) {
                $zip->addFile($sqlitePath, self::SQLITE_ENTRY);
            } elseif (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
                // Written to a file, not memory, so a large database fits too. The zip reads it when it closes.
                $dump = $this->backupPath.DIRECTORY_SEPARATOR.'.dump-'.bin2hex(random_bytes(6)).'.sql';
                register_shutdown_function(static fn () => @unlink($dump));
                MysqlDump::toFile($dump);
                $zip->addFile($dump, self::SQL_ENTRY);
            }

            if (! $zip->close()) {
                throw new RuntimeException('The backup zip could not be finished: '.$zip->getStatusString());
            }
        } catch (Throwable $exception) {
            // Never leave a backup without its database behind: a restore would trust it.
            self::discard($zip);
            @unlink($file);

            throw $exception;
        } finally {
            if ($dump !== null) {
                @unlink($dump);
            }
        }

        $this->prune();

        return $file;
    }

    public function restore(string $file, bool $includeDatabase = true): void
    {
        $zip = new ZipArchive;

        if ($zip->open($file) !== true) {
            throw new RuntimeException("Cannot open the backup {$file}.");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === self::SQL_ENTRY || $name === self::SQLITE_ENTRY) {
                continue;
            }

            self::extractEntry($zip, $i, $this->basePath);
        }

        if ($includeDatabase) {
            $sqlitePath = $this->sqlitePath();

            // Read as streams, so a large database never has to fit in memory.
            if ($sqlitePath !== null && $zip->locateName(self::SQLITE_ENTRY) !== false) {
                DB::disconnect();
                $this->restoreSqlite($this->entryStream($zip, self::SQLITE_ENTRY), $sqlitePath);
            } elseif ($zip->locateName(self::SQL_ENTRY) !== false) {
                $this->restoreMysql($this->entryStream($zip, self::SQL_ENTRY));
            }
        }

        $zip->close();
    }

    /**
     * Write one zip entry under the target folder, refusing paths that escape it or touch protected files.
     */
    public static function extractEntry(ZipArchive $zip, int $index, string $target): bool
    {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));

        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
            return false;
        }

        foreach (self::EXCLUDED as $excluded) {
            if ($name === $excluded || str_starts_with($name, $excluded.'/')) {
                return false;
            }
        }

        $destination = rtrim($target, '/\\').'/'.$name;

        if (str_ends_with($name, '/')) {
            return is_dir($destination) || mkdir($destination, 0755, true);
        }

        $directory = dirname($destination);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $stream = $zip->getStreamIndex($index);

        if ($stream === false) {
            return false;
        }

        $written = file_put_contents($destination, $stream);
        fclose($stream);

        return $written !== false;
    }

    /**
     * Close a zip without writing it. It may already be closed after a failed close().
     */
    private static function discard(ZipArchive $zip): void
    {
        try {
            $zip->unchangeAll();
            $zip->close();
        } catch (Throwable) {
            // Already closed.
        }
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($this->basePath))), '/');
    }

    private function isExcluded(string $relative): bool
    {
        foreach (self::EXCLUDED as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }

    private function sqlitePath(): ?string
    {
        if (DB::getDriverName() !== 'sqlite') {
            return null;
        }

        $path = realpath((string) DB::connection()->getDatabaseName());

        return $path === false ? null : $path;
    }

    /**
     * @return resource
     */
    private function entryStream(ZipArchive $zip, string $name)
    {
        $stream = $zip->getStream($name);

        if ($stream === false) {
            throw new RuntimeException("The backup's database could not be read: {$zip->getStatusString()}");
        }

        return $stream;
    }

    /**
     * @param  resource  $stream
     */
    private function restoreSqlite($stream, string $sqlitePath): void
    {
        $target = fopen($sqlitePath, 'wb');

        try {
            if ($target === false || stream_copy_to_stream($stream, $target) === false) {
                throw new RuntimeException("The database {$sqlitePath} could not be written.");
            }
        } finally {
            if ($target !== false) {
                fclose($target);
            }

            fclose($stream);
        }
    }

    /**
     * The dump has one SQL statement per line, so the lines run one by one.
     *
     * @param  resource  $stream
     */
    private function restoreMysql($stream): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = [];
        $statements = 0;
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        // The dump has one row per INSERT. Saved one by one, each row waits for the disk, and a large
        // database takes long enough for PHP to stop half-way; so rows are committed in batches.
        if ($mysql) {
            $pdo->exec('SET autocommit=0');
        }

        try {
            while (($line = fgets($stream)) !== false) {
                $statement = rtrim($line, "\r\n");

                if (trim($statement) === '') {
                    continue;
                }

                $pdo->exec($statement);

                if (preg_match('/^CREATE TABLE `((?:[^`]|``)+)`/', $statement, $match) === 1) {
                    $tables[] = str_replace('``', '`', $match[1]);
                }

                if ($mysql && ++$statements % 1000 === 0) {
                    $pdo->exec('COMMIT');
                }
            }

            if ($mysql) {
                $pdo->exec('COMMIT');
            }
        } finally {
            fclose($stream);

            // Turning autocommit back on also commits what is still open after an error, as before.
            if ($mysql) {
                try {
                    $pdo->exec('SET autocommit=1');
                } catch (Throwable) {
                    // The connection is gone; the error that caused it is the one to report.
                }
            }
        }

        if ($mysql) {
            $this->dropTablesMadeSince($pdo, $tables);
        }
    }

    /**
     * Tables a failed update made after the backup are removed too. Otherwise the next try of that
     * update stops at "table already exists" and is rolled back again, every time.
     *
     * @param  list<string>  $backedUp
     */
    private function dropTablesMadeSince(PDO $pdo, array $backedUp): void
    {
        // A dump without tables is not a reason to empty the database.
        if ($backedUp === []) {
            return;
        }

        $current = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN, 0);
        $extra = array_values(array_diff($current, $backedUp));

        if ($extra === []) {
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($extra as $table) {
                $pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`', '``', $table).'`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Keep the newest backups, and remove database dumps left by a backup that PHP stopped half-way.
     */
    private function prune(): void
    {
        $files = glob($this->backupPath.DIRECTORY_SEPARATOR.'*.zip') ?: [];
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, self::KEEP) as $old) {
            @unlink($old);
        }

        foreach (glob($this->backupPath.DIRECTORY_SEPARATOR.'.dump-*') ?: [] as $dump) {
            if (filemtime($dump) < time() - 3600) {
                @unlink($dump);
            }
        }
    }
}
