<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

/**
 * Client-area themes live in themes/{slug}. Views are loaded with the "theme::" prefix from the
 * active theme first and the default "nova" theme second, so a theme only overrides what it changes.
 */
class Themes
{
    public const DEFAULT = 'nova';

    public function __construct(private string $path) {}

    public function active(): string
    {
        $slug = (string) setting('theme.active', self::DEFAULT);

        return preg_match('/^[a-z0-9_-]+$/', $slug) && is_dir($this->path.'/'.$slug.'/views') ? $slug : self::DEFAULT;
    }

    public function register(): void
    {
        $paths = array_values(array_unique([
            $this->path.'/'.$this->active().'/views',
            $this->path.'/'.self::DEFAULT.'/views',
        ]));

        View::addNamespace('theme', $paths);
    }

    /**
     * Installed themes with the details from their theme.json.
     *
     * @return Collection<string, array{name: string, version: string, author: string, description: string}>
     */
    public function all(): Collection
    {
        return collect(glob($this->path.'/*/theme.json') ?: [])
            ->mapWithKeys(function (string $file): array {
                $data = json_decode((string) file_get_contents($file), true) ?: [];

                return [basename(dirname($file)) => [
                    'name' => (string) ($data['name'] ?? basename(dirname($file))),
                    'version' => (string) ($data['version'] ?? ''),
                    'author' => (string) ($data['author'] ?? ''),
                    'description' => (string) ($data['description'] ?? ''),
                ]];
            });
    }
}
