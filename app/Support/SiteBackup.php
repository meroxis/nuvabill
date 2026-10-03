<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Backups to keep somewhere else, for example Google Drive:
 *
 * - "site": every file of the Nuvabill folder (program, .env, uploads, extensions and themes)
 *   and the database. Unzip it anywhere and the site is back.
 * - "database": only the database. Small, so it can run often.
 *
 * Backup add-ons and "php artisan nuvabill:backup" use this.
 */
class SiteBackup
{
    public const TYPE_SITE = 'site';

    public const TYPE_DATABASE = 'database';

    private const KEEP_LOCAL = 2;

    /**
     * The start of the name of the plain-text database copy made while a backup is zipped.
     */
    private const DUMP_PREFIX = '.database-';

    /**
     * Never copied into a whole-site backup: caches, logs, sessions and other backups.
     *
     * @var list<string>
     */
    private const SKIP = [
        '.git', 'node_modules', 'public/hot', 'public/storage',
        'storage/framework', 'storage/logs',
        'storage/app/backups', 'storage/app/site-backups', 'storage/app/updates',
    ];

    public function __construct(private readonly ?string $folder = null) {}

    public function folder(): string
    {
        return $this->folder ?? storage_path('app/site-backups');
    }

    /**
     * The start of a backup's file name, for example "nuvabill-site-".
     */
    public static function prefix(string $type): string
    {
        return 'nuvabill-'.$type.'-';
    }

    /**
     * Whether this server's zip library can protect backups with a password (AES-256).
     */
    public static function canEncrypt(): bool
    {
        return defined(ZipArchive::class.'::EM_AES_256') && method_exists(ZipArchive::class, 'setEncryptionIndex');
    }

    /**
     * Make the backup zip and return its path. With a password, every file in it is encrypted with AES-256.
     *
     * $record false: a safety copy (before an optimize or a database change), or a backup an add-on
     * still has to upload. Site health then does not count it; an add-on calls record() once its copy
     * is safely stored elsewhere.
     */
    public function create(string $type = self::TYPE_SITE, ?string $password = null, bool $record = true): string
    {
        if (! in_array($type, [self::TYPE_SITE, self::TYPE_DATABASE], true)) {
            throw new InvalidArgumentException("Unknown backup type [{$type}]: use site or database.");
        }

        if (filled($password) && ! self::canEncrypt()) {
            throw new RuntimeException('This server cannot encrypt zip files. Remove the backup password, or ask your host for a newer PHP zip extension.');
        }

        $folder = $this->folder();

        if (! is_dir($folder) && ! mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new RuntimeException("Cannot create the backup folder {$folder}.");
        }

        $this->removeStaleDumps($folder);

        $path = $folder.DIRECTORY_SEPARATOR.self::prefix($type).now()->format('Y-m-d-His').'.zip';
        $database = $folder.DIRECTORY_SEPARATOR.self::DUMP_PREFIX.Str::random(12);
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write the backup file {$path}.");
        }

        // The plain-text copy of the database goes even when PHP stops with a fatal error.
        register_shutdown_function(static fn () => @unlink($database));

        try {
            $zip->addFile($database, $this->exportDatabase($database));

            if ($type === self::TYPE_SITE) {
                $this->addSiteFiles($zip);
            }

            if (filled($password)) {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $zip->setEncryptionIndex($index, ZipArchive::EM_AES_256, (string) $password);
                }
            }

            // Added last so it stays readable in an encrypted backup.
            $zip->addFromString('RESTORE.txt', $this->instructions($type, filled($password)));

            if (! $zip->close()) {
                throw new RuntimeException('The backup zip could not be finished: '.$zip->getStatusString());
            }
        } catch (Throwable $exception) {
            // Closed without writing; after a failed close() it is already closed.
            rescue(function () use ($zip): void {
                $zip->unchangeAll();
                $zip->close();
            }, report: false);
            @unlink($path);

            throw $exception;
        } finally {
            @unlink($database);
        }

        $this->prune($type);

        if ($record) {
            $this->record($type, filled($password));
        }

        return $path;
    }

    /**
     * Note a finished backup for site health, which warns when the last one is old or not encrypted.
     * Backup add-ons pass $offsite once the copy is stored away from this server, for example after
     * the upload to Google Drive worked.
     */
    public function record(string $type, bool $encrypted, bool $offsite = false): void
    {
        $values = [
            'backups.last_at' => now()->toIso8601String(),
            'backups.last_type' => $type,
            'backups.last_encrypted' => $encrypted,
        ];

        if ($offsite) {
            $values['backups.last_offsite_at'] = now()->toIso8601String();
            $values['backups.last_offsite_encrypted'] = $encrypted;
        }

        app(Settings::class)->setMany($values);
    }

    /**
     * Write the database to a file. Returns the name it gets in the zip.
     */
    private function exportDatabase(string $file): string
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            try {
                // A consistent copy, even while the site is being used.
                DB::statement('VACUUM INTO ?', [$file]);
            } catch (Throwable) {
                if (! @copy((string) DB::connection()->getDatabaseName(), $file)) {
                    throw new RuntimeException('The SQLite database could not be copied.');
                }
            }

            return 'database.sqlite';
        }

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            throw new RuntimeException("Backups support MySQL, MariaDB and SQLite, not {$driver}.");
        }

        // Row by row into the file, so a large table never has to fit in PHP's memory.
        MysqlDump::toFile($file);

        return 'database.sql';
    }

    /**
     * Remove database copies left by a backup that PHP stopped half-way, for example out of memory.
     * Only old ones: a backup running right now may be writing a new one.
     */
    private function removeStaleDumps(string $folder): void
    {
        foreach (glob($folder.DIRECTORY_SEPARATOR.self::DUMP_PREFIX.'*') ?: [] as $stale) {
            if (is_file($stale) && filemtime($stale) < time() - 3600) {
                @unlink($stale);
            }
        }
    }

    /**
     * Every file of the Nuvabill folder, except caches, logs, other backups and the live SQLite
     * file (the zip has a consistent copy of it as database.sqlite).
     */
    private function addSiteFiles(ZipArchive $zip): void
    {
        $base = base_path();
        $liveDatabase = DB::getDriverName() === 'sqlite' ? realpath((string) DB::connection()->getDatabaseName()) : false;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($base))), '/');

            if (! $file->isFile() || $this->skipped($relative) || ($liveDatabase !== false && realpath($file->getPathname()) === $liveDatabase)) {
                continue;
            }

            $zip->addFile($file->getPathname(), 'site/'.$relative);
        }
    }

    private function skipped(string $relative): bool
    {
        foreach (self::SKIP as $path) {
            if ($relative === $path || str_starts_with($relative, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    private function instructions(string $type, bool $encrypted): string
    {
        $lines = [
            'Nuvabill '.($type === self::TYPE_SITE ? 'whole-site' : 'database').' backup of '.config('app.url')
                .', made '.now()->toDateTimeString().' with Nuvabill '.config('nuvabill.version').'.',
            '',
            'To restore it:',
        ];

        if ($type === self::TYPE_SITE) {
            array_push($lines,
                '1. Upload everything in the "site" folder to your server and point your domain at its public folder.',
                '2. Put the database back:',
                '   - MySQL or MariaDB: create an empty database, import database.sql, for example',
                '     mysql -u USER -p DATABASE < database.sql, and check DB_... in the .env file.',
                '   - SQLite: copy database.sqlite to database/database.sqlite (or where DB_DATABASE in .env points).',
                '3. Add the cron job again: * * * * * cd /path/to/nuvabill && php artisan schedule:run',
            );
        } else {
            array_push($lines,
                '1. Start from your Nuvabill site, or install the same version with its original .env file.',
                '   The .env file holds the key that opens saved passwords, so keep a copy of it somewhere safe.',
                '2. MySQL or MariaDB: import database.sql, for example mysql -u USER -p DATABASE < database.sql',
                '   SQLite: copy database.sqlite to database/database.sqlite',
                '3. Run: php artisan migrate --force',
            );
        }

        if ($encrypted) {
            $lines[] = '';
            $lines[] = 'This backup has a password. Open it with a tool that supports AES-256 zip files, such as 7-Zip.';
        }

        return implode("\n", $lines)."\n";
    }

    private function prune(string $type): void
    {
        $files = glob($this->folder().DIRECTORY_SEPARATOR.self::prefix($type).'*.zip') ?: [];
        rsort($files);

        foreach (array_slice($files, self::KEEP_LOCAL) as $old) {
            @unlink($old);
        }
    }
}
