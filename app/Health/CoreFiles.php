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
     * @param  array<string, string>  $files  Path => SHA-256 from the release list.
     * @return array{checked: int, accepted: int, changed: list<array{path: string, modified: int|null}>, missing: list<string>, planted: list<array{path: string, modified: int|null, public: bool}>}
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
            $actual = (string) hash_file('sha256', $full);

            if (hash_equals($expected, $actual)) {
                continue;
            }

            if (isset($accepted[$path]) && hash_equals((string) $accepted[$path], $actual)) {
                $result['accepted']++;

                continue;
            }

            $result['changed'][] = ['path' => $path, 'modified' => @filemtime($full) ?: null];
        }

        foreach ($this->reachableCode() as $path) {
            if (isset($files[$path])) {
                continue;
            }

            $full = $this->path($path);
            $hash = (string) hash_file('sha256', $full);

            if (isset($accepted[$path]) && hash_equals((string) $accepted[$path], $hash)) {
                $result['accepted']++;

                continue;
            }

            $result['planted'][] = ['path' => $path, 'modified' => @filemtime($full) ?: null, 'public' => str_starts_with($path, 'public/')];
        }

        return $result;
    }

    /**
     * Code files in the folders visitors can reach: the public folder, and the Nuvabill folder
     * itself for hosts whose website folder points there. Folders of other programs, uploads and
     * the built front-end are left out.
     *
     * @return list<string>
     */
    public function reachableCode(): array
    {
        $found = [];

        foreach (glob($this->root().DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file) && self::isCode(basename($file))) {
                $found[] = basename($file);
            }
        }

        $public = $this->root().DIRECTORY_SEPARATOR.'public';

        if (is_dir($public)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($public, \FilesystemIterator::SKIP_DOTS),
                    fn (\SplFileInfo $file): bool => ! $file->isLink() && ! in_array($file->getFilename(), ['storage', 'hot'], true),
                ),
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile() || ! self::isCode($file->getFilename())) {
                    continue;
                }

                $relative = $this->relative($file->getPathname());

                // Scripts of earlier builds stay in public/build until an update removes them; pages
                // only load the files the current build lists, so only program files count there.
                if (str_starts_with($relative, 'public/build/') && ! preg_match('/\.(php\d?|phtml|phar)$/i', $relative)) {
                    continue;
                }

                $found[] = $relative;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Stop warning about these files until they change again.
     *
     * @param  list<string>  $paths
     */
    public function accept(array $paths): int
    {
        $accepted = (array) $this->settings->get('health.accepted_files', []);
        $count = 0;

        foreach ($paths as $path) {
            if ($this->isSafePath($path) && is_file($this->path($path))) {
                $accepted[$path] = (string) hash_file('sha256', $this->path($path));
                $count++;
            }
        }

        $this->settings->set('health.accepted_files', $accepted);
        Activity::log('health.files_accepted', 'Marked '.$count.' changed files as the site\'s own: '.implode(', ', array_slice($paths, 0, 10)));

        return $count;
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

        foreach ($paths as $path) {
            if (! $this->isSafePath($path) || ! is_file($this->path($path))) {
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
        return $path !== ''
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0")
            && ! str_starts_with($path, '/')
            && ! preg_match('#^[a-zA-Z]:#', $path)
            && ! preg_match('#^(storage|bootstrap/cache|\.env|\.git)(/|$)#', $path);
    }

    private function path(string $relative): string
    {
        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function relative(string $full): string
    {
        return ltrim(str_replace('\\', '/', substr($full, strlen($this->root()))), '/');
    }
}
