<?php

namespace App\Updates;

use Illuminate\Support\Facades\DB;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
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

        $file = $this->backupPath.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9._-]/', '-', $label).'-'.date('Ymd-His').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write the backup file {$file}.");
        }

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
            $zip->addFromString(self::SQL_ENTRY, $this->dumpMysql());
        }

        $zip->close();
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

            if ($sqlitePath !== null && ($contents = $zip->getFromName(self::SQLITE_ENTRY)) !== false) {
                DB::disconnect();
                file_put_contents($sqlitePath, $contents);
            } elseif (($sql = $zip->getFromName(self::SQL_ENTRY)) !== false) {
                $this->restoreMysql($sql);
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
     * One SQL statement per line, so restoring can run them one by one.
     */
    private function dumpMysql(): string
    {
        $pdo = DB::connection()->getPdo();
        $lines = ['SET FOREIGN_KEY_CHECKS=0;'];

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = (string) array_values((array) $row)[0];
            $create = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
            $lines[] = "DROP TABLE IF EXISTS `{$table}`;";
            $lines[] = preg_replace('/\s*\R\s*/', ' ', (string) $create['Create Table']).';';

            foreach (DB::table($table)->cursor() as $record) {
                $values = array_map(
                    fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                    (array) $record,
                );
                $lines[] = "INSERT INTO `{$table}` VALUES (".implode(',', $values).');';
            }
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

        return implode("\n", $lines)."\n";
    }

    private function restoreMysql(string $sql): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach (preg_split('/\n/', $sql) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }

    private function prune(): void
    {
        $files = glob($this->backupPath.DIRECTORY_SEPARATOR.'*.zip') ?: [];
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, self::KEEP) as $old) {
            @unlink($old);
        }
    }
}
