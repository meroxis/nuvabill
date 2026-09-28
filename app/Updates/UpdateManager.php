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
 *  1. install(): download, verify the signature, back up, maintenance mode, copy files.
 *  2. finish(): in a fresh request or process, migrate, clear caches and go live.
 * If anything fails, the backup is restored.
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
        $directory = storage_path('app/updates');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // One update at a time: a second click or the nightly run must not download and unpack over a running one.
        // The operating system releases this lock by itself if PHP is stopped half-way.
        $lock = fopen($directory.DIRECTORY_SEPARATOR.'install.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another update is being installed right now. Wait a few minutes, then reload this page.');
        }

        try {
            $this->installUnlocked($release, $directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function installUnlocked(Release $release, string $directory): void
    {
        if ($this->hasPendingFinish()) {
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

        $backup = $this->backup()->create('before-'.$release->version);

        Artisan::call('down', ['--retry' => 60]);

        try {
            $this->extract($zipPath);
        } catch (Throwable $exception) {
            $this->backup()->restore($backup, includeDatabase: false);
            Artisan::call('up');

            throw new RuntimeException('Copying the new files failed, so the previous version was restored: '.$exception->getMessage(), previous: $exception);
        }

        file_put_contents($this->pendingPath(), json_encode([
            'from' => $this->currentVersion(),
            'to' => $release->version,
            'backup' => $backup,
            'started_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        @unlink($zipPath);
    }

    public function hasPendingFinish(): bool
    {
        return is_file($this->pendingPath());
    }

    /**
     * Second step, running on the new code: migrate the database and go live again.
     *
     * @return array{from: string, to: string}
     */
    public function finish(): array
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

            if (is_file((string) $pending['backup'])) {
                $this->backup()->restore((string) $pending['backup']);
            }

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
        return new Backup(base_path(), storage_path('app/backups'));
    }

    private function pendingPath(): string
    {
        return storage_path('app/updates/pending.json');
    }
}
