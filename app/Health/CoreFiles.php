<?php

namespace App\Health;

use App\Support\Activity;
use App\Support\Settings;
use App\Updates\ReleaseSource;
use App\Updates\Signature;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Compares Nuvabill's own code with the signed list of files in its release (release-files.json),
 * to spot files someone changed and code planted where visitors can reach it.
 *
 * Staff can put back an original file (taken from the signed release zip), move a planted file to
 * quarantine, or mark a change as their own so it stops warning until the file changes again.
 */
class CoreFiles
{
    public const LIST_FILE = 'release-files.json';

    public const SIGNATURE_FILE = 'release-files.json.sig';

    public const STATE_OK = 'ok';

    public const STATE_MISSING = 'missing';

    public const STATE_UNSIGNED = 'unsigned';

    public const STATE_BAD_SIGNATURE = 'bad-signature';

    public const STATE_OTHER_VERSION = 'other-version';

    /**
     * Files that run as code. Only these are listed and compared: images, styles and texts may be
     * changed on purpose and cannot run.
     */
    public static function isCode(string $path): bool
    {
        return (bool) preg_match('/\.(php\d?|phtml|phar|js|mjs)$/i', $path);
    }

    /**
     * Web server and PHP settings files. They are not code, but they can make the server run code on
     * every request, so the planted-code scan looks inside them (see runsCode()).
     */
    public static function isServerSettings(string $path): bool
    {
        return (bool) preg_match('#(^|/)(\.htaccess|\.user\.ini|php\.ini)$#i', $path);
    }

    /**
     * Whether a server settings file makes the server run code: it adds a file to every request
     * (auto_prepend_file, auto_append_file), or it lets PHP or CGI run files that are not PHP files.
     * The handler lines hosting panels write for .php files (for example cPanel's PHP version) are fine.
     */
    public static function runsCode(string $contents): bool
    {
        $runner = '/php|cgi|x-httpd|lsapi|proxy:/i';
        $phpFile = '/^\.?(php\d*|phtml|phar)$/i';
        // Whether a <Files> or <FilesMatch> pattern only names PHP files, for example "\.(php|phtml)$".
        $onlyPhp = fn (string $pattern): bool => collect(preg_split('/[^a-z0-9]+/i', $pattern, -1, PREG_SPLIT_NO_EMPTY) ?: ['*'])
            ->every(fn (string $word): bool => (bool) preg_match($phpFile, $word));
        $block = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            if (preg_match('/^(php_(admin_)?value\s+)?auto_(prepend|append)_file\s*=?\s*["\']?([^"\'\s;]*)/i', $line, $match)) {
                if ($match[4] !== '' && strtolower($match[4]) !== 'none') {
                    return true;
                }

                continue;
            }

            if (preg_match('/^<(Files|FilesMatch)\b(.*)>$/i', $line, $match)) {
                $block = $match[2];
            } elseif (preg_match('#^</Files(Match)?>#i', $line)) {
                $block = null;
            } elseif (preg_match('/^(AddType|AddHandler)\s+(\S+)\s+(.+)$/i', $line, $match) && preg_match($runner, $match[2])) {
                foreach (preg_split('/\s+/', $match[3]) ?: [] as $extension) {
                    if (! preg_match($phpFile, $extension)) {
                        return true;
                    }
                }
            } elseif (preg_match('/^(SetHandler|ForceType|Action)\s+(.+)$/i', $line, $match) && preg_match($runner, $match[2])
                && ($block === null || ! $onlyPhp($block))) {
                return true;
            } elseif (preg_match('/^RewriteRule\s.*\bH=[^,\]\s]*(php|cgi|x-httpd|lsapi)/i', $line)) {
                // A rewrite rule can hand any file to PHP with the H= flag.
                return true;
            }
        }

        return false;
    }

    public function __construct(
        private readonly Settings $settings,
        private readonly ?string $root = null,
    ) {}

    public function root(): string
    {
        return rtrim($this->root ?? base_path(), '/\\');
    }

    /**
     * The release's file list, if it is there, signed and for this version.
     *
     * @return array{state: string, version: string|null, files: array<string, string>}
     */
    public function manifest(): array
    {
        $listPath = $this->root().DIRECTORY_SEPARATOR.self::LIST_FILE;

        if (! is_file($listPath)) {
            return ['state' => self::STATE_MISSING, 'version' => null, 'files' => []];
        }

        $json = (string) file_get_contents($listPath);
        $data = json_decode($json, true);
        $version = is_array($data) ? (string) ($data['version'] ?? '') : '';
        $files = is_array($data) && is_array($data['files'] ?? null) ? array_map('strval', $data['files']) : [];
        $signaturePath = $this->root().DIRECTORY_SEPARATOR.self::SIGNATURE_FILE;

        if (! is_file($signaturePath)) {
            return ['state' => self::STATE_UNSIGNED, 'version' => $version, 'files' => []];
        }

        if (! Signature::verifyFileList($version, $json, (string) file_get_contents($signaturePath), (string) config('nuvabill.updates.public_key'))) {
            return ['state' => self::STATE_BAD_SIGNATURE, 'version' => $version, 'files' => []];
        }

        if ($version !== (string) config('nuvabill.version')) {
            return ['state' => self::STATE_OTHER_VERSION, 'version' => $version, 'files' => []];
        }

        return ['state' => self::STATE_OK, 'version' => $version, 'files' => $files];
    }

    /**
     * What differs from the release.
     *
     * Each changed or planted file carries the SHA-256 of what was found, so "Mark as mine" accepts
     * only that content (see accept()).
     *
     * @param  array<string, string>  $files  Path => SHA-256 from the release list.
     * @return array{checked: int, accepted: int, changed: list<array{path: string, hash: string|null, modified: int|null}>, missing: list<string>, planted: list<array{path: string, hash: string|null, modified: int|null, public: bool}>}
     */
    public function compare(array $files): array
    {
        $accepted = (array) $this->settings->get('health.accepted_files', []);
        $result = ['checked' => 0, 'accepted' => 0, 'changed' => [], 'missing' => [], 'planted' => []];

        foreach ($files as $path => $expected) {
            if (! self::isCode($path) || ! $this->isSafePath($path)) {
                continue;
            }

            $full = $this->path($path);

            if (! is_file($full)) {
                $result['missing'][] = $path;

                continue;
            }

            $result['checked']++;
            $actual = $this->hash($path);

            if ($actual !== null && hash_equals($expected, $actual)) {
                continue;
            }

            if ($actual !== null && isset($accepted[$path]) && hash_equals((string) $accepted[$path], $actual)) {
                $result['accepted']++;

                continue;
            }

            $result['changed'][] = ['path' => $path, 'hash' => $actual, 'modified' => @filemtime($full) ?: null];
        }

        foreach ($this->reachableCode() as $path) {
            if (isset($files[$path])) {
                continue;
            }

            $full = $this->path($path);
            $hash = $this->hash($path);

            // A server settings file only counts when it makes the server run code.
            if (self::isServerSettings($path) && ! self::runsCode((string) @file_get_contents($full))) {
                continue;
            }

            if ($hash !== null && isset($accepted[$path]) && hash_equals((string) $accepted[$path], $hash)) {
                $result['accepted']++;

                continue;
            }

            $result['planted'][] = ['path' => $path, 'hash' => $hash, 'modified' => @filemtime($full) ?: null, 'public' => str_starts_with($path, 'public/')];
        }

        return $result;
    }

    /**
     * Code files in the folders visitors can reach: the public folder, and the Nuvabill folder
     * itself for hosts whose website folder points there; the web server and PHP settings files
     * there; and the settings files in config/, which run on every request. Folders of other
     * programs, uploads and the built front-end are left out.
     *
     * @return list<string>
     */
    public function reachableCode(): array
    {
        $found = [];

        foreach (@scandir($this->root()) ?: [] as $name) {
            if (is_file($this->path($name)) && (self::isCode($name) || self::isServerSettings($name))) {
                $found[] = $name;
            }
        }

        foreach ($this->filesIn('public') as $relative) {
            if (! self::isCode($relative) && ! self::isServerSettings($relative)) {
                continue;
            }

            // Scripts of earlier builds stay in public/build until an update removes them; pages
            // only load the files the current build lists, so only program files count there.
            if (str_starts_with($relative, 'public/build/') && ! preg_match('/\.(php\d?|phtml|phar)$/i', $relative) && ! self::isServerSettings($relative)) {
                continue;
            }

            $found[] = $relative;
        }

        // Laravel loads every PHP file in config/ on each request, so a new one there runs too.
        foreach ($this->filesIn('config') as $relative) {
            if (preg_match('/\.php$/i', $relative)) {
                $found[] = $relative;
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * Every file under a folder of the Nuvabill folder, without following links. Uploads in
     * public/storage are left out (only that folder, not any folder named "storage"; Vite's "hot" file
     * is no code), and a folder that cannot be opened is skipped instead of stopping the scan.
     *
     * @return list<string>
     */
    private function filesIn(string $folder): array
    {
        $full = $this->path($folder);

        if (! is_dir($full) || ! is_readable($full)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $file): bool => ! $file->isLink() && $this->relative($file->getPathname()) !== 'public/storage'
                    && (! $file->isDir() || $file->isReadable()),
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        $files = [];

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = $this->relative($file->getPathname());
            }
        }

        return $files;
    }

    /**
     * Stop warning about these files until they change again. Only the content that was checked is
     * accepted: $seen holds the SHA-256 each file had in the check staff looked at. When a file changed
     * since, nothing is accepted and staff are asked to check again.
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $seen  Path => SHA-256 from the check's saved result.
     */
    public function accept(array $paths, array $seen): int
    {
        $current = [];
        $changed = [];

        foreach ($paths as $path) {
            if (! $this->isSafePath($path) || ! is_file($this->path($path))) {
                continue;
            }

            $hash = $this->hash($path);

            if ($hash === null || ! isset($seen[$path]) || ! hash_equals((string) $seen[$path], $hash)) {
                $changed[] = $path;

                continue;
            }

            $current[$path] = $hash;
        }

        if ($changed !== []) {
            throw new RuntimeException(__('Some files changed after the check, so nothing was marked as yours. Press "Check now" and look at them again: :files', [
                'files' => implode(', ', array_slice($changed, 0, 5)).(count($changed) > 5 ? ' …' : ''),
            ]));
        }

        // Entries for files that are gone, or changed since they were accepted, no longer do anything.
        $accepted = array_filter((array) $this->settings->get('health.accepted_files', []),
            fn (mixed $hash, mixed $path): bool => is_string($path) && is_string($hash) && $this->isSafePath($path) && $this->hash($path) === $hash,
            ARRAY_FILTER_USE_BOTH);

        $this->settings->set('health.accepted_files', array_merge($accepted, $current));
        Activity::log('health.files_accepted', 'Marked '.count($current).' changed files as the site\'s own: '.implode(', ', array_slice(array_keys($current), 0, 10)));

        return count($current);
    }

    /**
     * Move files that are not part of Nuvabill out of reach, into storage/app/quarantine. Nothing is
     * deleted, so a file moved by mistake can be put back by hand.
     *
     * @param  list<string>  $paths
     */
    public function quarantine(array $paths): int
    {
        $folder = storage_path('app/quarantine/'.now()->format('Y-m-d-His'));
        $count = 0;

        // Every path is checked before anything moves, so a refused one is never skipped quietly.
        foreach ($paths as $path) {
            if (! $this->isSafePath($path)) {
                throw new RuntimeException(__('Could not move :file.', ['file' => $path]));
            }
        }

        foreach ($paths as $path) {
            if (! is_file($this->path($path))) {
                continue;
            }

            $target = $folder.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path).'.quarantined';
            File::ensureDirectoryExists(dirname($target), 0700);

            if (! @rename($this->path($path), $target)) {
                throw new RuntimeException(__('Could not move :file. Check the folder permissions.', ['file' => $path]));
            }

            @chmod($target, 0600);
            $count++;
        }

        if ($count > 0) {
            Activity::log('health.files_quarantined', "Moved {$count} files that are not part of Nuvabill to quarantine: ".implode(', ', array_slice($paths, 0, 10)));
        }

        return $count;
    }

    /**
     * Put back the release's own copy of changed files. The signed release zip of this version is
     * downloaded and checked first; the changed copies are kept in quarantine.
     *
     * @param  list<string>  $paths
     */
    public function restore(array $paths, ReleaseSource $releases): int
    {
        $manifest = $this->manifest();
        $paths = array_values(array_filter($paths, fn (string $path): bool => $this->isSafePath($path) && isset($manifest['files'][$path])));

        if ($manifest['state'] !== self::STATE_OK || $paths === []) {
            return 0;
        }

        $version = (string) config('nuvabill.version');
        $release = $releases->forVersion($version) ?? throw new RuntimeException(__('The release of Nuvabill :version could not be found.', ['version' => $version]));
        $work = storage_path('app/updates/restore-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($work);
        $zipPath = $work.DIRECTORY_SEPARATOR.'release.zip';

        try {
            $download = Http::timeout(300)->withOptions(['sink' => $zipPath])->get($release->zipUrl);
            $signature = Http::timeout(30)->get($release->signatureUrl);

            if ($download->failed() || $signature->failed()
                || ! Signature::verify($version, $zipPath, trim($signature->body()), (string) config('nuvabill.updates.public_key'))) {
                throw new RuntimeException(__('The release could not be downloaded and checked. Try again later.'));
            }

            $zip = new ZipArchive;

            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException(__('The release file is damaged.'));
            }

            $originals = [];

            foreach ($paths as $path) {
                $contents = $zip->getFromName($path);

                if ($contents === false || ! hash_equals($manifest['files'][$path], hash('sha256', $contents))) {
                    $zip->close();

                    throw new RuntimeException(__('The release does not contain the expected :file.', ['file' => $path]));
                }

                $originals[$path] = $contents;
            }

            $zip->close();
            $this->keepCopies(array_keys($originals));

            foreach ($originals as $path => $contents) {
                File::ensureDirectoryExists(dirname($this->path($path)));
                file_put_contents($this->path($path), $contents);
            }
        } finally {
            File::deleteDirectory($work);
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        Activity::log('health.files_restored', 'Put back the original of '.count($originals).' Nuvabill files: '.implode(', ', array_slice(array_keys($originals), 0, 10)));

        return count($originals);
    }

    /**
     * After an update: remove code files the new release no longer has, but only while they are
     * still exactly the old release's copy. Anything changed stays, so site health can show it.
     *
     * @param  array<string, string>  $old
     * @param  array<string, string>  $new
     */
    public function removeRetired(array $old, array $new): int
    {
        $removed = 0;

        foreach (array_diff_key($old, $new) as $path => $hash) {
            $full = $this->path($path);

            if ($this->isSafePath($path) && is_file($full) && hash_equals((string) $hash, (string) hash_file('sha256', $full))) {
                @unlink($full) && $removed++;
            }
        }

        return $removed;
    }

    /**
     * Keep the current copies of files that are about to be replaced.
     *
     * @param  list<string>  $paths
     */
    private function keepCopies(array $paths): void
    {
        $folder = storage_path('app/quarantine/'.now()->format('Y-m-d-His').'-replaced');

        foreach ($paths as $path) {
            if (is_file($this->path($path))) {
                $target = $folder.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path).'.changed';
                File::ensureDirectoryExists(dirname($target), 0700);
                @copy($this->path($path), $target);
                @chmod($target, 0600);
            }
        }
    }

    /**
     * A relative path inside the Nuvabill folder, never outside it or into storage and settings.
     */
    public function isSafePath(string $path): bool
    {
        // Checked with both slashes as "/", since Windows takes either. ".." is refused as a folder
        // step; a name like "x..php" is fine.
        $normal = str_replace('\\', '/', $path);

        return $path !== ''
            && ! in_array('..', explode('/', $normal), true)
            && ! str_contains($path, "\0")
            && ! str_starts_with($normal, '/')
            && ! preg_match('#^[a-zA-Z]:#', $path)
            && ! preg_match('#^(storage|bootstrap/cache|\.env|\.git)(/|$)#i', $normal);
    }

    private function path(string $relative): string
    {
        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * The file's SHA-256, or null when it cannot be read.
     */
    private function hash(string $relative): ?string
    {
        $hash = @hash_file('sha256', $this->path($relative));

        return is_string($hash) ? $hash : null;
    }

    private function relative(string $full): string
    {
        $relative = substr($full, strlen($this->root()));

        // Only Windows uses "\" between folders; elsewhere it can be part of a file name.
        return ltrim(DIRECTORY_SEPARATOR === '\\' ? str_replace('\\', '/', $relative) : $relative, '/');
    }
}
