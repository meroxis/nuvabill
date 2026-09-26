<?php

namespace App\Support;

use RuntimeException;

/**
 * Reads and updates keys in the .env file (used by the installer).
 */
class EnvFile
{
    public function __construct(private string $path) {}

    public function ensureExists(string $examplePath): void
    {
        if (! is_file($this->path)) {
            if (! is_file($examplePath) || ! copy($examplePath, $this->path)) {
                throw new RuntimeException('Could not create the .env file. Make the Nuvabill folder writable.');
            }
        }
    }

    public function get(string $key): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', (string) file_get_contents($this->path), $match)) {
            return trim($match[1], " \t\"'");
        }

        return null;
    }

    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    public function set(array $values): void
    {
        $contents = is_file($this->path) ? (string) file_get_contents($this->path) : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->format($value);
            $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';

            $contents = preg_match($pattern, $contents)
                ? (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $contents, 1)
                : rtrim($contents)."\n".$line."\n";
        }

        if (file_put_contents($this->path, $contents) === false) {
            throw new RuntimeException('Could not write the .env file. Make it writable.');
        }
    }

    private function format(string|int|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            $value === '' => '',
            (bool) preg_match('/^[A-Za-z0-9_.:\/@+-]+$/', $value) => $value,
            default => '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"',
        };
    }
}
