<?php

namespace App\Extensions;

use InvalidArgumentException;

/**
 * The contents of an extension's extension.json file.
 */
final readonly class ExtensionManifest
{
    public const TYPE_GATEWAY = 'gateway';

    public const TYPE_SERVER = 'server';

    public function __construct(
        public string $slug,
        public string $type,
        public string $name,
        public string $version,
        public string $namespace,
        public string $class,
        public string $path,
        public string $description = '',
        public string $author = '',
        public string $requires = '',
    ) {}

    public static function fromFile(string $file): self
    {
        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            throw new InvalidArgumentException("Extension manifest [{$file}] is not valid JSON.");
        }

        foreach (['slug', 'type', 'name', 'version', 'namespace', 'class'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || $data[$field] === '') {
                throw new InvalidArgumentException("Extension manifest [{$file}] is missing \"{$field}\".");
            }
        }

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $data['slug'])) {
            throw new InvalidArgumentException("Extension slug [{$data['slug']}] may only use lowercase letters, numbers, dashes and underscores.");
        }

        if (! in_array($data['type'], [self::TYPE_GATEWAY, self::TYPE_SERVER], true)) {
            throw new InvalidArgumentException("Extension [{$data['slug']}] has an unknown type [{$data['type']}].");
        }

        return new self(
            slug: $data['slug'],
            type: $data['type'],
            name: $data['name'],
            version: $data['version'],
            namespace: rtrim($data['namespace'], '\\').'\\',
            class: ltrim($data['class'], '\\'),
            path: dirname($file),
            description: (string) ($data['description'] ?? ''),
            author: (string) ($data['author'] ?? ''),
            requires: (string) ($data['requires'] ?? ''),
        );
    }

    /**
     * Whether this extension says it works with the given Nuvabill version.
     * Supports a single ">=x.y.z" constraint, which is all v0.1 extensions need.
     */
    public function isCompatibleWith(string $version): bool
    {
        if ($this->requires === '' || ! str_starts_with($this->requires, '>=')) {
            return true;
        }

        return version_compare($version, trim(substr($this->requires, 2)), '>=');
    }
}
