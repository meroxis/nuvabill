<?php

namespace App\Marketplace;

use RuntimeException;
use ZipArchive;

/**
 * A marketplace package zip. Finds the manifest (at the top, or inside one top folder), refuses
 * unsafe entries such as "../" paths and symbolic links, and extracts the files.
 */
class PackageArchive
{
    /**
     * Largest total size of the unpacked files, to stop "zip bombs".
     */
    private const MAX_UNPACKED_BYTES = 100 * 1024 * 1024;

    private const MAX_FILES = 5000;

    private ZipArchive $zip;

    private string $prefix = '';

    private PackageType $type;

    /**
     * @var array<string, mixed>
     */
    private array $manifest;

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

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);

            if (! str_ends_with($name, '/') && str_starts_with($name, $this->prefix)) {
                $files[] = substr($name, strlen($this->prefix));
            }
        }

        return $files;
    }

    public function contents(string $file): string
    {
        $contents = $this->zip->getFromName($this->prefix.$file);

        return $contents === false ? '' : $contents;
    }

    /**
     * Unpack the package into an empty folder.
     */
    public function extractTo(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $this->zip->getNameIndex($i));

            if (! str_starts_with($name, $this->prefix) || $name === $this->prefix) {
                continue;
            }

            $relative = substr($name, strlen($this->prefix));
            $target = $directory.'/'.$relative;

            if (str_ends_with($name, '/')) {
                is_dir($target) || mkdir($target, 0755, true);

                continue;
            }

            is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);

            $stream = $this->zip->getStreamIndex($i);

            if ($stream === false || file_put_contents($target, $stream) === false) {
                throw new RuntimeException("Could not write {$relative}.");
            }

            fclose($stream);
        }
    }

    private function checkEntries(): void
    {
        if ($this->zip->numFiles === 0 || $this->zip->numFiles > self::MAX_FILES) {
            throw new RuntimeException(__('The package is empty or has too many files.'));
        }

        $total = 0;

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

            if ($name === '' || str_contains($name, '../') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) || str_contains($name, "\0")) {
                throw new RuntimeException(__('The package has an unsafe file path: :path', ['path' => $name]));
            }

            if ($this->zip->getExternalAttributesIndex($i, $system, $attributes) && $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                throw new RuntimeException(__('The package contains a link, which is not allowed: :path', ['path' => $name]));
            }

            $total += (int) ($stat['size'] ?? 0);
        }

        if ($total > self::MAX_UNPACKED_BYTES) {
            throw new RuntimeException(__('The package is too big when unpacked.'));
        }
    }

    private function findManifest(): void
    {
        foreach (['', $this->topFolder()] as $prefix) {
            if ($prefix === null) {
                continue;
            }

            foreach (PackageType::cases() as $type) {
                $raw = $this->zip->getFromName($prefix.$type->manifestFile());

                if ($raw === false) {
                    continue;
                }

                $manifest = json_decode($raw, true);

                if (! is_array($manifest)) {
                    throw new RuntimeException(__(':file is not valid JSON.', ['file' => $type->manifestFile()]));
                }

                $this->prefix = $prefix;
                $this->manifest = $manifest;
                $this->type = $type === PackageType::Theme || $type === PackageType::OrderForm
                    ? $type
                    : (PackageType::tryFrom((string) ($manifest['type'] ?? '')) ?? throw new RuntimeException(__('extension.json has an unknown type.')));

                if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $this->slug())) {
                    throw new RuntimeException(__('The package slug may only use lowercase letters, numbers, dashes and underscores.'));
                }

                if (! preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $this->version())) {
                    throw new RuntimeException(__('The package version must look like 1.0.0.'));
                }

                return;
            }
        }

        throw new RuntimeException(__('The package has no theme.json, orderform.json or extension.json.'));
    }

    /**
     * The single folder every entry is inside, for zips made by right-clicking a folder.
     */
    private function topFolder(): ?string
    {
        $top = null;

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $this->zip->getNameIndex($i));
            $first = strstr($name, '/', true);

            if ($first === false || $first === '' || ($top !== null && $first !== $top)) {
                return null;
            }

            $top = $first;
        }

        return $top === null ? null : $top.'/';
    }
}
