<?php

namespace App\Marketplace;

use App\Marketplace\Store\ListingLimits;
use RuntimeException;
use ZipArchive;

/**
 * A marketplace package zip. Finds the manifest (at the top, or inside one top folder), refuses
 * unsafe entries such as "../" paths, symbolic links and doubled names, and extracts the files.
 *
 * One list of entries is made when the zip is opened. The checks, the store's scan and the
 * unpacking all read that list, so what is checked is exactly what is written to disk.
 */
class PackageArchive
{
    /**
     * Largest total size of the unpacked files, to stop "zip bombs".
     */
    private const MAX_UNPACKED_BYTES = 100 * 1024 * 1024;

    private const MAX_FILES = 5000;

    /**
     * Characters no file or folder name may hold. Windows gives them a special meaning, so a
     * name with one of them could land on disk under another name than the one that was checked.
     */
    private const UNSAFE_NAME = '/[\x00-\x1F\x7F:*?"<>|]/';

    private ZipArchive $zip;

    private PackageType $type;

    /**
     * @var array<string, mixed>
     */
    private array $manifest;

    /**
     * Every entry of the zip, by its path. The path is the full one while the zip is checked,
     * and the one below the package's top folder once the manifest is found.
     *
     * @var array<string, array{index: int, directory: bool, size: int}>
     */
    private array $entries = [];

    public function __construct(private string $path)
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is needed to install packages.');
        }

        $this->zip = new ZipArchive;

        if ($this->zip->open($path) !== true) {
            throw new RuntimeException(__('The package file is damaged.'));
        }

        $this->checkEntries();
        $this->findManifest();
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    public function type(): PackageType
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->manifest;
    }

    public function slug(): string
    {
        return (string) ($this->manifest['slug'] ?? '');
    }

    public function version(): string
    {
        return (string) ($this->manifest['version'] ?? '');
    }

    public function name(): string
    {
        return (string) ($this->manifest['name'] ?? $this->slug());
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_filter((array) ($this->manifest['permissions'] ?? []), 'is_string'));
    }

    /**
     * File paths inside the package, relative to its top folder.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->entries as $file => $entry) {
            if (! $entry['directory']) {
                $files[] = (string) $file;
            }
        }

        return $files;
    }

    /**
     * The contents of one file from files(), exactly as extractTo() writes it.
     */
    public function contents(string $file): string
    {
        $entry = $this->entries[$file] ?? null;

        return $entry === null || $entry['directory'] ? '' : $this->read($entry);
    }

    /**
     * Unpack the package into an empty folder.
     */
    public function extractTo(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        foreach ($this->entries as $relative => $entry) {
            $target = $directory.'/'.$relative;

            if ($entry['directory']) {
                is_dir($target) || mkdir($target, 0755, true);

                continue;
            }

            is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);

            $file = @fopen($target, 'wb');
            $written = $file !== false && $this->readExactly($entry, fn ($stream): bool => $entry['size'] === 0 || @stream_copy_to_stream($stream, $file, $entry['size']) === $entry['size']);

            if ($file !== false) {
                fclose($file);
            }

            if (! $written) {
                throw new RuntimeException("Could not write {$relative}.");
            }
        }
    }

    /**
     * Read every entry once, refuse the ones that are not safe, and keep the list that the
     * manifest search, files(), contents() and extractTo() all use.
     */
    private function checkEntries(): void
    {
        if ($this->zip->numFiles === 0 || $this->zip->numFiles > self::MAX_FILES) {
            throw new RuntimeException(__('The package is empty or has too many files.'));
        }

        $total = 0;
        $seen = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';

            // Zip paths use "/". A "\" is read as a folder by some tools and as part of the name by others.
            if (str_contains($name, '\\')) {
                throw new RuntimeException(__('The package has a file name with a backslash: :path. Make the zip again with another zip tool.', ['path' => $name]));
            }

            $directory = str_ends_with($name, '/');
            $path = $directory ? substr($name, 0, -1) : $name;

            if (! is_array($stat) || ! $this->isSafePath($path)) {
                throw new RuntimeException(__('The package has an unsafe file path: :path', ['path' => $name]));
            }

            if ($this->zip->getExternalAttributesIndex($i, $system, $attributes) && $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                throw new RuntimeException(__('The package contains a link, which is not allowed: :path', ['path' => $name]));
            }

            // Every copy of PHP can read these two. Anything else could be read on one server and not on another.
            if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE || ! in_array($stat['comp_method'] ?? null, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new RuntimeException(__('The package must be a normal zip file, without a password or special compression.'));
            }

            // Windows and macOS disks ignore upper and lower case, so there two such names are one file.
            $key = mb_strtolower($path, 'UTF-8');

            if (isset($seen[$key])) {
                throw new RuntimeException(__('The package has the same file name more than once: :path', ['path' => $name]));
            }

            $seen[$key] = $directory;
            $this->entries[$path] = ['index' => $i, 'directory' => $directory, 'size' => (int) ($stat['size'] ?? 0)];
            $total += (int) ($stat['size'] ?? 0);
        }

        // A name cannot be a file and a folder at once.
        foreach (array_keys($seen) as $key) {
            $key = (string) $key;

            while (($slash = strrpos($key, '/')) !== false) {
                $key = substr($key, 0, $slash);

                if (($seen[$key] ?? true) === false) {
                    throw new RuntimeException(__('The package has the same file name more than once: :path', ['path' => $key]));
                }
            }
        }

        if ($total > self::MAX_UNPACKED_BYTES) {
            throw new RuntimeException(__('The package is too big when unpacked.'));
        }
    }

    /**
     * A path made of plain folder and file names: no "..", no empty part, nothing a disk could
     * store under another name (Windows drops a dot or space at the end, and knows files by a
     * short second name such as "EXTENS~1.JSO").
     */
    private function isSafePath(string $path): bool
    {
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || rtrim($part, '. ') !== $part || preg_match(self::UNSAFE_NAME, $part) === 1 || preg_match('/~\d/', $part) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{index: int, directory: bool, size: int}  $entry
     */
    private function read(array $entry): string
    {
        $contents = '';

        $complete = $this->readExactly($entry, function ($stream) use ($entry, &$contents): bool {
            $contents = $entry['size'] === 0 ? '' : (string) @stream_get_contents($stream, $entry['size']);

            return strlen($contents) === $entry['size'];
        });

        // A file that cannot be read in full must never pass as an empty one.
        if (! $complete) {
            throw new RuntimeException(__('The package file is damaged.'));
        }

        return $contents;
    }

    /**
     * Open one file of the zip for $take, which takes as many bytes as the zip lists for the
     * file and says whether it got them all. A file that turns out longer or shorter, or fails
     * its checksum, is refused, so the limit on the unpacked size holds and the scan and the
     * unpacking always get the same bytes.
     *
     * @param  array{index: int, directory: bool, size: int}  $entry
     * @param  callable(resource): bool  $take
     */
    private function readExactly(array $entry, callable $take): bool
    {
        $stream = $this->zip->getStreamIndex($entry['index']);

        if ($stream === false) {
            return false;
        }

        try {
            // One more read gives nothing at a clean end, a byte when the file is longer, and fails on a wrong checksum.
            return $take($stream) && @fread($stream, 1) === '';
        } finally {
            fclose($stream);
        }
    }

    private function findManifest(): void
    {
        foreach (['', $this->topFolder()] as $prefix) {
            if ($prefix === null) {
                continue;
            }

            foreach (PackageType::cases() as $type) {
                $entry = $this->entries[$prefix.$type->manifestFile()] ?? null;

                if ($entry === null || $entry['directory']) {
                    continue;
                }

                $manifest = json_decode($this->read($entry), true);

                if (! is_array($manifest)) {
                    throw new RuntimeException(__(':file is not valid JSON.', ['file' => $type->manifestFile()]));
                }

                $this->usePrefix($prefix);
                $this->manifest = $manifest;
                $this->type = $type === PackageType::Theme || $type === PackageType::OrderForm
                    ? $type
                    : (PackageType::tryFrom((string) ($manifest['type'] ?? '')) ?? throw new RuntimeException(__('extension.json has an unknown type.')));

                if ($this->type === PackageType::License) {
                    throw new RuntimeException(__('extension.json has an unknown type.'));
                }

                if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $this->slug())) {
                    throw new RuntimeException(__('The package slug may only use lowercase letters, numbers, dashes and underscores.'));
                }

                if (! preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $this->version())) {
                    throw new RuntimeException(__('The package version must look like 1.0.0.'));
                }

                // The store keeps versions in a column of this length.
                if (strlen($this->version()) > ListingLimits::VERSION) {
                    throw new RuntimeException(__('The package version can be at most :max characters long.', ['max' => ListingLimits::VERSION]));
                }

                return;
            }
        }

        throw new RuntimeException(__('The package has no theme.json, orderform.json or extension.json.'));
    }

    /**
     * Keep the entries by their path below the package's top folder. Nothing may sit outside it.
     */
    private function usePrefix(string $prefix): void
    {
        $entries = [];

        foreach ($this->entries as $path => $entry) {
            $path = (string) $path;

            if ($path.'/' === $prefix) {
                continue;
            }

            if (! str_starts_with($path, $prefix)) {
                throw new RuntimeException(__('The package has an unsafe file path: :path', ['path' => $path]));
            }

            $entries[substr($path, strlen($prefix))] = $entry;
        }

        $this->entries = $entries;
    }

    /**
     * The single folder every entry is inside, for zips made by right-clicking a folder.
     */
    private function topFolder(): ?string
    {
        $top = null;

        foreach ($this->entries as $path => $entry) {
            $first = strstr($path.($entry['directory'] ? '/' : ''), '/', true);

            if ($first === false || $first === '' || ($top !== null && $first !== $top)) {
                return null;
            }

            $top = $first;
        }

        return $top === null ? null : $top.'/';
    }
}
