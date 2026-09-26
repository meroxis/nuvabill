<?php

namespace App\Updates;

/**
 * A published Nuvabill release with a signed zip.
 */
final readonly class Release
{
    public function __construct(
        public string $version,
        public string $notes,
        public string $zipUrl,
        public string $signatureUrl,
        public string $publishedAt,
        public bool $isSecurity,
        public bool $isPrerelease,
        public int $size = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            version: (string) $data['version'],
            notes: (string) ($data['notes'] ?? ''),
            zipUrl: (string) $data['zip_url'],
            signatureUrl: (string) $data['signature_url'],
            publishedAt: (string) ($data['published_at'] ?? ''),
            isSecurity: (bool) ($data['security'] ?? false),
            isPrerelease: (bool) ($data['prerelease'] ?? false),
            size: (int) ($data['size'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'notes' => $this->notes,
            'zip_url' => $this->zipUrl,
            'signature_url' => $this->signatureUrl,
            'published_at' => $this->publishedAt,
            'security' => $this->isSecurity,
            'prerelease' => $this->isPrerelease,
            'size' => $this->size,
        ];
    }

    /**
     * The text that is signed for this release. Binding the version stops an old signed zip
     * from being passed off as a newer one.
     */
    public static function signedMessage(string $version, string $zipSha256): string
    {
        return 'nuvabill-release:'.$version.':'.$zipSha256;
    }
}
