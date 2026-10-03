<?php

namespace App\Updates;

use App\Health\CoreFiles;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Checks for, downloads, verifies and installs Nuvabill updates.
 *
 * Installing happens in two steps so the new code runs its own database migrations:
 *  1. install(): download, verify the signature, maintenance mode, back up, copy files.
 *  2. finish(): in a fresh request or process, migrate, clear caches and go live.
 * If anything fails, the backup is restored. If PHP is stopped half-way through install(),
 * finish() (also run by the scheduler every minute) puts the old files back. If the backup
 * itself cannot be put back, the site stays in maintenance mode until staff restore it by hand.
 */
class UpdateManager
{
    public function __construct(
        private ReleaseSource $source,
        private Settings $settings,
    ) {}

    public function currentVersion(): string
    {
        return (string) config('nuvabill.version');
    }

    public function check(): ?Release
    {
        $release = $this->source->latest((string) $this->settings->get('updates.channel', 'stable'));
        $newer = $release !== null && version_compare($release->version, $this->currentVersion(), '>') ? $release : null;

        $this->settings->setMany([
            'updates.last_checked_at' => now()->toIso8601String(),
            'updates.latest' => $newer?->toArray(),
        ]);

        return $newer;
    }

    /**
     * The newest release found by the last check, if it is newer than this copy.
     */
    public function available(): ?Release
    {
        $latest = $this->settings->get('updates.latest');

        if (! is_array($latest) || ! isset($latest['version'])) {
            return null;
        }

        $release = Release::fromArray($latest);

        return version_compare($release->version, $this->currentVersion(), '>') ? $release : null;
    }

    public function shouldAutoInstall(Release $release): bool
    {
        return (bool) $this->settings->get('updates.auto_all')
            || ($release->isSecurity && (bool) $this->settings->get('updates.auto_security'));
    }

    public function install(Release $release): void
    {
        // Downloading and backing up can take minutes: keep going when the browser stops waiting.
        @set_time_limit(0);
        ignore_user_abort(true);

        // One update at a time: a second click or the nightly run must not download and unpack over a running one.
        $lock = $this->lock();

        if ($lock === null) {
            throw new RuntimeException('Another update is being installed right now. Wait a few minutes, then reload this page.');
        }

        try {
            $this->installUnlocked($release, storage_path('app/updates'));
        } finally {
            $this->unlock($lock);
        }
    }

    private function installUnlocked(Release $release, string $directory): void
    {
        if (is_file($this->restoreFailedPath())) {
            throw new RuntimeException($this->restoreFailedMessage());
        }

        if (is_file($this->pendingPath()) || is_file($this->installingPath())) {
            throw new RuntimeException('An update is already half-way done. Finish it first.');
        }

        $publicKey = (string) config('nuvabill.updates.public_key');

        if ($publicKey === '') {
            throw new RuntimeException('This copy has no update public key, so updates cannot be verified.');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to install updates.');
        }

        $zipPath = $directory.DIRECTORY_SEPARATOR."nuvabill-{$release->version}.zip";

        $download = Http::timeout(300)->withOptions(['sink' => $zipPath])->get($release->zipUrl);
        $signature = Http::timeout(30)->get($release->signatureUrl);

        if ($download->failed() || $signature->failed()) {
            @unlink($zipPath);

            throw new RuntimeException('The update could not be downloaded. Try again later.');
        }

        if (! Signature::verify($release->version, $zipPath, trim($signature->body()), $publicKey)) {
            @unlink($zipPath);
            Activity::log('update.rejected', "Update {$release->version} failed the signature check and was not installed");

            throw new RuntimeException('The update failed the security check and was not installed.');
        }

        // Written before the site goes down: if PHP is stopped from here on, finish() puts the old version back.
        $this->markInstalling($release, null);
        Artisan::call('down', ['--retry' => 60]);

        try {
            // Taken in maintenance mode, so no payment or order made meanwhile is lost if the backup is put back.
            $backup = $this->backup()->create('before-'.$release->version);
        } catch (Throwable $exception) {
            @unlink($this->installingPath());
            @unlink($zipPath);
            Artisan::call('up');

            throw new RuntimeException('The backup before the update failed, so nothing was changed: '.$exception->getMessage(), previous: $exception);
        }

        $this->markInstalling($release, $backup);

        try {
            $this->extract($zipPath);
        } catch (Throwable $exception) {
            $this->backup()->restore($backup, includeDatabase: false);
            @unlink($this->installingPath());
            @unlink($zipPath);
            Artisan::call('up');

            throw new RuntimeException('Copying the new files failed, so the previous version was restored: '.$exception->getMessage(), previous: $exception);
        }

        $written = file_put_contents($this->pendingPath(), json_encode([
            'from' => $this->currentVersion(),
            'to' => $release->version,
            'backup' => $backup,
            'started_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        // Without pending.json the install counts as stopped half-way, so finish() puts the old files back.
        if ($written === false) {
            throw new RuntimeException('Cannot write to the storage/app/updates folder.');
        }

        @unlink($this->installingPath());
        @unlink($zipPath);
    }

    /**
     * Whether an update waits for finish(): its files are copied, it stopped half-way and its
     * old files have to be put back, or its backup could not be put back and staff have to act.
     * An install that is still running does not count.
     */
    public function hasPendingFinish(): bool
    {
        if (is_file($this->pendingPath()) || is_file($this->restoreFailedPath())) {
            return true;
        }

        if (! is_file($this->installingPath())) {
            return false;
        }

        // A running install holds the lock; one that PHP stopped half-way does not.
        $lock = $this->lock();

        if ($lock === null) {
            return false;
        }

        $this->unlock($lock);

        return true;
    }

    /**
     * Second step, running on the new code: migrate the database and go live again.
     * One finish at a time, so migrations never run twice and a restore never runs
     * under a migration that is still going.
     *
     * @return array{from: string, to: string}
     */
    public function finish(): array
    {
        // Migrations can take minutes: keep going when the browser stops waiting.
        @set_time_limit(0);
        ignore_user_abort(true);

        $lock = $this->lock();

        if ($lock === null) {
            throw new RuntimeException('The update is still running. Wait a few minutes, then reload this page.');
        }

        try {
            // Read only now: a finish that ran meanwhile has already removed these files.
            if (is_file($this->restoreFailedPath())) {
                // Never migrate over a half-restored database and call it a success.
                throw new RuntimeException($this->restoreFailedMessage());
            }

            if (is_file($this->pendingPath())) {
                $this->forgetInstalling();
            } elseif (is_file($this->installingPath())) {
                $this->rollBackInterruptedInstall();
            }

            return $this->finishUnlocked();
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * The files are all copied, so the note that they were still being copied is out of date.
     * Left behind, it would make the next finish put the old files back over the migrated database.
     */
    private function forgetInstalling(): void
    {
        @unlink($this->installingPath());

        if (is_file($this->installingPath())) {
            throw new RuntimeException('Cannot write to the storage/app/updates folder.');
        }
    }

    /**
     * @return array{from: string, to: string}
     */
    private function finishUnlocked(): array
    {
        $pending = json_decode((string) @file_get_contents($this->pendingPath()), true);

        if (! is_array($pending)) {
            throw new RuntimeException('There is no update to finish.');
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('optimize:clear');
        } catch (Throwable $exception) {
            Log::error('Update failed during migration; restoring backup.', ['exception' => $exception]);

            $this->restoreAfterFailedMigration($pending);

            @unlink($this->pendingPath());
            Artisan::call('up');
            Activity::log('update.failed', "Update to {$pending['to']} failed and version {$pending['from']} was restored: {$exception->getMessage()}");

            throw new RuntimeException("The update to {$pending['to']} failed, so version {$pending['from']} was restored. Details are in the log.", previous: $exception);
        }

        @unlink($this->pendingPath());
        Artisan::call('up');

        // Site health checks the new version within a minute, from the scheduler.
        $this->settings->setMany(['updates.latest' => null, 'health.check_requested' => true]);
        Activity::log('update.installed', "Updated Nuvabill from {$pending['from']} to {$pending['to']}");

        return ['from' => (string) $pending['from'], 'to' => (string) $pending['to']];
    }

    /**
     * Put the files and database from before the update back. If that fails, the site stays in
     * maintenance mode and pending.json is set aside: run again on the old files, finish() would
     * find nothing to migrate and bring a half-restored database live as a finished update.
     *
     * @param  array<string, mixed>  $pending
     */
    private function restoreAfterFailedMigration(array $pending): void
    {
        $backup = (string) ($pending['backup'] ?? '');

        try {
            if ($backup === '' || ! is_file($backup)) {
                throw new RuntimeException("The backup {$backup} is missing.");
            }

            $this->backup()->restore($backup);
        } catch (Throwable $exception) {
            Log::error('The backup could not be put back after a failed update.', ['exception' => $exception, 'backup' => $backup]);

            if (! @rename($this->pendingPath(), $this->restoreFailedPath())) {
                @unlink($this->pendingPath());
            }

            // The database may be half restored, so even the activity log may be gone.
            rescue(fn () => Activity::log('update.failed', "Update to {$pending['to']} failed and its backup could not be put back: {$exception->getMessage()}"));

            throw new RuntimeException($this->restoreFailedMessage($pending), previous: $exception);
        }
    }

    /**
     * What staff have to do once a failed update could not put its backup back.
     *
     * @param  array<string, mixed>|null  $failed  The set-aside pending.json; read from disk when null.
     */
    private function restoreFailedMessage(?array $failed = null): string
    {
        if ($failed === null) {
            $decoded = json_decode((string) @file_get_contents($this->restoreFailedPath()), true);
            $failed = is_array($decoded) ? $decoded : [];
        }

        $to = (string) ($failed['to'] ?? '?');
        $backup = (string) ($failed['backup'] ?? '');

        return "The update to {$to} failed and its backup could not be put back, so the site stays in maintenance mode. "
            ."Restore the backup {$backup} by hand, then delete storage/app/updates/restore-failed.json and run: php artisan up";
    }

    /**
     * An install that PHP stopped half-way (killed, or out of memory or time while backing up or
     * copying files) left the site in maintenance mode. The database is not changed yet, so the
     * old files go back and the site goes live again.
     */
    private function rollBackInterruptedInstall(): never
    {
        $installing = json_decode((string) @file_get_contents($this->installingPath()), true);
        $installing = is_array($installing) ? $installing : [];
        $from = (string) ($installing['from'] ?? $this->currentVersion());
        $to = (string) ($installing['to'] ?? '?');
        $backup = (string) ($installing['backup'] ?? '');

        if ($backup !== '' && is_file($backup)) {
            $this->backup()->restore($backup, includeDatabase: false);
        }

        // The downloaded release; no install is running while the lock is held.
        foreach (glob(storage_path('app/updates').DIRECTORY_SEPARATOR.'nuvabill-*.zip') ?: [] as $download) {
            @unlink($download);
        }

        @unlink($this->installingPath());
        Artisan::call('up');
        Activity::log('update.failed', "Update to {$to} stopped half-way and version {$from} was restored");

        throw new RuntimeException("The update to {$to} stopped half-way, so version {$from} was restored.");
    }

    private function extract(string $zipPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The update file is damaged.');
        }

        $oldFiles = $this->releaseFiles((string) @file_get_contents(base_path(CoreFiles::LIST_FILE)));
        $newFiles = $this->releaseFiles((string) $zip->getFromName(CoreFiles::LIST_FILE));

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (! Backup::extractEntry($zip, $i, base_path()) && ! str_ends_with($name, '/')) {
                Log::info("Update skipped protected or invalid path {$name}.");
            }
        }

        $zip->close();

        // Code the new version no longer has would otherwise stay behind for ever.
        if ($oldFiles !== [] && $newFiles !== []) {
            $removed = app(CoreFiles::class)->removeRetired($oldFiles, $newFiles);

            if ($removed > 0) {
                Log::info("Update removed {$removed} files the new version no longer uses.");
            }
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * @return array<string, string> Path => SHA-256 from a release-files.json, or nothing.
     */
    private function releaseFiles(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) && is_array($data['files'] ?? null) ? array_map('strval', $data['files']) : [];
    }

    private function backup(): Backup
    {
        return app(Backup::class, ['basePath' => base_path(), 'backupPath' => storage_path('app/backups')]);
    }

    /**
     * Take the update lock, so only one install or finish runs at a time. The operating system
     * releases it by itself if PHP is stopped half-way.
     *
     * @return resource|null Null when another process holds it.
     */
    private function lock()
    {
        $directory = storage_path('app/updates');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $lock = fopen($directory.DIRECTORY_SEPARATOR.'install.lock', 'c');

        if ($lock === false) {
            return null;
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    /**
     * @param  resource  $lock
     */
    private function unlock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Note what is being installed, and its backup once there is one, while the site is down.
     */
    private function markInstalling(Release $release, ?string $backup): void
    {
        $written = file_put_contents($this->installingPath(), json_encode([
            'from' => $this->currentVersion(),
            'to' => $release->version,
            'backup' => $backup,
            'started_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        if ($written === false) {
            throw new RuntimeException('Cannot write to the storage/app/updates folder.');
        }
    }

    private function pendingPath(): string
    {
        return storage_path('app/updates/pending.json');
    }

    private function installingPath(): string
    {
        return storage_path('app/updates/installing.json');
    }

    private function restoreFailedPath(): string
    {
        return storage_path('app/updates/restore-failed.json');
    }
}
