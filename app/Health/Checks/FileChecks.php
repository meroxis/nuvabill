<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\PendingCheck;
use FilesystemIterator;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Who can read and change Nuvabill's files on the server, and whether forgotten backups or
 * database tools were left in the public folder.
 */
class FileChecks extends CheckGroup
{
    /**
     * Left-overs that should never be downloadable from the public folder.
     */
    private const RISKY_PUBLIC = '/(\.(sql|sql\.gz|sqlite|db|bak|old|orig|swp|zip|tar|tgz|tar\.gz|rar|7z|log)$|^\.env|^(phpinfo|info|test|adminer.*|phpmyadmin.*)\.php$)/i';

    public function key(): string
    {
        return 'files';
    }

    public function section(): string
    {
        return self::SECURITY;
    }

    public function title(): string
    {
        return 'Files and folders';
    }

    public function description(): string
    {
        return 'Who can read and change Nuvabill\'s files on the server, and what was left in the public folder.';
    }

    public function icon(): string
    {
        return 'lock';
    }

    public function run(): array
    {
        // Each check runs on its own, so one that cannot finish never hides the others.
        return [
            $this->guarded($this->check('files.env', 'Only you can read the .env file', weight: 5), $this->envFile(...)),
            $this->guarded($this->check('files.writable', 'Nuvabill can write to storage and cache'), $this->writable(...)),
            $this->guarded($this->check('files.open_folders', 'No folder can be changed by everyone (777)', weight: 5), $this->openFolders(...)),
            $this->guarded($this->check('files.public_leftovers', 'No backups, .sql files or database tools in the public folder', weight: 5), $this->publicLeftovers(...)),
        ];
    }

    /**
     * @param  callable(PendingCheck): CheckResult  $run
     */
    private function guarded(PendingCheck $check, callable $run): CheckResult
    {
        try {
            return $run($check);
        } catch (Throwable $exception) {
            report($exception);

            return $check->skipped('This check could not finish: :error', ['error' => Str::limit($exception->getMessage(), 120)]);
        }
    }

    private function envFile(PendingCheck $check): CheckResult
    {
        $path = base_path('.env');

        if (! $this->hasUnixPermissions()) {
            return $check->skipped('This server does not use Unix file permissions.');
        }

        if (! is_file($path)) {
            return $check->skipped('There is no .env file; settings come from the server.');
        }

        $mode = fileperms($path) & 0777;

        if (($mode & 0o006) === 0) {
            return $check->passed(':mode', ['mode' => sprintf('%o', $mode)]);
        }

        return $check->urgent('Other accounts on the server can read it (now :mode, should be 600)', ['mode' => sprintf('%o', $mode)],
            advice: 'The .env file holds the database password and the app key. On shared hosting, other accounts on the same server may be able to read it.',
            items: [['label' => '.env', 'value' => sprintf('%o → 600', $mode), 'mono' => true, 'status' => 'urgent']],
            fix: $this->ownsFile($path) ? $this->fix('files.fix_env', 'Fix it', confirm: 'Only the account that runs Nuvabill will be able to read the .env file.') : null,
        );
    }

    private function writable(PendingCheck $check): CheckResult
    {
        $folders = ['storage', 'storage/framework/cache', 'storage/framework/sessions', 'storage/logs', 'bootstrap/cache'];
        $blocked = array_values(array_filter($folders, fn (string $folder): bool => is_dir(base_path($folder)) && ! is_writable(base_path($folder))));

        if ($blocked === []) {
            return $check->passed();
        }

        return $check->urgent(':folders cannot be written to', ['folders' => implode(', ', $blocked)],
            advice: 'Nuvabill keeps uploads, caches and logs there. Without write access, updates, backups and sign-ins can fail. Ask your host to make these folders writable for your account.',
            items: array_map(fn (string $folder): array => ['label' => $folder.'/', 'mono' => true, 'status' => 'urgent'], $blocked),
        );
    }

    private function openFolders(PendingCheck $check): CheckResult
    {
        if (! $this->hasUnixPermissions()) {
            return $check->skipped('This server does not use Unix file permissions.');
        }

        $open = [];

        // A folder this account cannot open is still checked itself, but not looked into.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator(base_path(), FilesystemIterator::SKIP_DOTS),
                fn (SplFileInfo $file): bool => $file->isDir() && ! $file->isLink() && ! in_array($file->getFilename(), ['vendor', 'node_modules', '.git'], true),
            ),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $folder) {
            /** @var SplFileInfo $folder */
            if ((fileperms($folder->getPathname()) & 0o002) !== 0) {
                $open[] = $this->relative($folder->getPathname());

                if (count($open) >= 50) {
                    break;
                }
            }
        }

        if ($open === []) {
            return $check->passed();
        }

        $fixable = array_values(array_filter($open, fn (string $folder): bool => $this->ownsFile(base_path($folder))));

        return $check->urgent(':count folders can be changed by any account on the server', ['count' => count($open)],
            advice: 'Anyone who can run code on the server could put files there, for example a hidden program in your site. 755 is enough: your own account can still write to them.',
            items: array_map(fn (string $folder): array => ['label' => $folder.'/', 'value' => '777 → 755', 'mono' => true, 'status' => 'urgent'], $open),
            fix: $fixable !== [] ? $this->fix('files.fix_folders', 'Set them to 755', ['paths' => $fixable]) : null,
        );
    }

    private function publicLeftovers(PendingCheck $check): CheckResult
    {
        $found = [];

        if (is_dir(public_path())) {
            // Only public/storage and public/build are left out, not every folder with that name. A folder
            // this account cannot open is skipped instead of stopping the check.
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator(public_path(), FilesystemIterator::SKIP_DOTS),
                    fn (SplFileInfo $file): bool => ! $file->isLink() && (! $file->isDir() || $file->isReadable())
                        && ! ($file->getPath() === public_path() && in_array($file->getFilename(), ['storage', 'build'], true)),
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if ($file->isFile() && preg_match(self::RISKY_PUBLIC, $file->getFilename())) {
                    $found[] = $this->relative($file->getPathname());
                }
            }
        }

        if ($found === []) {
            return $check->passed();
        }

        return $check->urgent(':count files anyone could download', ['count' => count($found)],
            advice: 'Backups, database dumps and tools in the public folder can be downloaded by anyone who guesses the name. Move them out of the public folder.',
            items: array_map(fn (string $path): array => ['label' => $path, 'value' => $this->size(base_path($path)), 'mono' => true, 'status' => 'urgent'], $found),
            fix: $this->fix('files.quarantine', 'Move them to quarantine', ['paths' => $found], confirm: 'The files are moved to storage/app/quarantine, where nobody can download them. Nothing is deleted.'),
        );
    }

    private function hasUnixPermissions(): bool
    {
        return PHP_OS_FAMILY !== 'Windows';
    }

    /**
     * Only files owned by the account PHP runs as can be changed safely: then that account still
     * reads them after the change.
     */
    private function ownsFile(string $path): bool
    {
        return function_exists('posix_geteuid') && @fileowner($path) === posix_geteuid();
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen(base_path()))), '/');
    }

    private function size(string $path): string
    {
        return Number::fileSize((int) @filesize($path));
    }
}
