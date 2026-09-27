<?php

namespace App\Marketplace\Store;

use App\Marketplace\PackageArchive;
use App\Models\MarketplaceItem;
use Throwable;

/**
 * The automatic checks run on every uploaded version before a person reviews it: the manifest,
 * versions, hidden or encoded code, risky PHP functions, outside connections, and file types.
 */
class PackageInspector
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * PHP functions reviewers must look at. They are not always wrong, so they are warnings.
     *
     * @var list<string>
     */
    private const RISKY_FUNCTIONS = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'eval', 'assert', 'create_function', 'dl', 'putenv', 'ini_set', 'unserialize', 'move_uploaded_file', 'curl_exec'];

    /**
     * Signs of encoded or hidden code, which the marketplace does not accept.
     *
     * @var list<string>
     */
    private const ENCODED_MARKERS = ['ionCube Loader', 'sg_load(', 'SourceGuardian', 'zend_loader', '<?php //0', 'eval(gzinflate', 'eval(base64_decode', 'eval(str_rot13', 'eval(gzuncompress'];

    /**
     * @var list<string>
     */
    private const BLOCKED_EXTENSIONS = ['phar', 'exe', 'dll', 'so', 'sh', 'bat', 'cmd', 'com', 'jar', 'htaccess'];

    /**
     * @return array{ok: bool, slug: string, version: string, name: string, type: string, manifest: array<string, mixed>, permissions: list<string>, checks: list<array{key: string, title: string, text: string, level: string}>}
     */
    public function inspect(string $zipPath, MarketplaceItem $item, ?string $latestVersion = null): array
    {
        $checks = [];
        $size = (int) @filesize($zipPath);

        try {
            $archive = new PackageArchive($zipPath);
        } catch (Throwable $exception) {
            return [
                'ok' => false, 'slug' => '', 'version' => '', 'name' => '', 'type' => '', 'manifest' => [], 'permissions' => [],
                'checks' => [['key' => 'manifest', 'title' => __('Package could not be read'), 'text' => $exception->getMessage(), 'level' => 'fail']],
            ];
        }

        $manifest = $archive->manifest();
        $checks[] = $this->check('manifest', __('Manifest found'), $archive->type()->manifestFile().': '.$archive->type()->label().' "'.$archive->slug().'", '.__('version').' '.$archive->version());

        if ($archive->slug() !== $item->slug || $archive->type() !== $item->type) {
            $checks[] = $this->check('match', __('Does not match the item'), __('The package is ":slug" (:type) but the item is ":item" (:itemType).', ['slug' => $archive->slug(), 'type' => $archive->type()->label(), 'item' => $item->slug, 'itemType' => $item->type->label()]), 'fail');
        }

        if ($latestVersion !== null && version_compare($archive->version(), $latestVersion, '<=')) {
            $checks[] = $this->check('version', __('Version is not newer'), __('Version :new must be newer than :old.', ['new' => $archive->version(), 'old' => $latestVersion]), 'fail');
        }

        $requires = (string) ($manifest['requires'] ?? '');
        $checks[] = $requires !== '' && str_starts_with($requires, '>=')
            ? $this->check('requires', __('Says which Nuvabill it needs'), __('Nuvabill :version or newer', ['version' => substr($requires, 2)]))
            : $this->check('requires', __('No "requires" in the manifest'), __('Add for example ">=0.3.0" so older sites do not install it.'), 'warn');

        $checks = [...$checks, ...$this->codeChecks($archive), ...$this->connectionChecks($archive)];

        $checks[] = $size <= self::MAX_BYTES
            ? $this->check('size', __('Size is fine'), __(':size, under the 20 MB limit', ['size' => $this->size($size)]))
            : $this->check('size', __('Too big'), __(':size, over the 20 MB limit', ['size' => $this->size($size)]), 'fail');

        return [
            'ok' => ! collect($checks)->contains('level', 'fail'),
            'slug' => $archive->slug(),
            'version' => $archive->version(),
            'name' => $archive->name(),
            'type' => $archive->type()->value,
            'manifest' => $manifest,
            'permissions' => $archive->permissions(),
            'checks' => $checks,
        ];
    }

    /**
     * @return list<array{key: string, title: string, text: string, level: string}>
     */
    private function codeChecks(PackageArchive $archive): array
    {
        $checks = [];
        $risky = [];
        $encoded = [];
        $blocked = [];
        $phpFiles = 0;

        foreach ($archive->files() as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $basename = strtolower(basename($file));

            if (in_array($extension, self::BLOCKED_EXTENSIONS, true) || $basename === '.htaccess') {
                $blocked[] = $file;

                continue;
            }

            if ($extension !== 'php') {
                continue;
            }

            $phpFiles++;
            $code = $archive->contents($file);

            foreach (self::ENCODED_MARKERS as $marker) {
                if (stripos($code, $marker) !== false) {
                    $encoded[] = $file;

                    break;
                }
            }

            foreach (preg_split('/\R/', $code) ?: [] as $number => $line) {
                if (preg_match('/(?<![\w$>:\\\\])('.implode('|', self::RISKY_FUNCTIONS).')\s*\(/i', $line, $match) || preg_match('/`[^`]*\$[^`]*`/', $line)) {
                    $risky[] = $file.':'.($number + 1).' '.($match[1] ?? 'backticks').'()';
                }
            }
        }

        $checks[] = $encoded === []
            ? $this->check('encoded', __('Readable code'), __('No encoded or hidden code in :count PHP files', ['count' => $phpFiles]))
            : $this->check('encoded', __('Encoded or hidden code'), __('The marketplace only accepts readable code: :files', ['files' => implode(', ', array_slice($encoded, 0, 5))]), 'fail');

        $checks[] = $risky === []
            ? $this->check('functions', __('No risky functions'), __('No exec(), eval() or similar calls'))
            : $this->check('functions', __('Risky functions to look at'), implode(', ', array_slice(array_unique($risky), 0, 8)).(count($risky) > 8 ? ' …' : ''), 'warn');

        if ($blocked !== []) {
            $checks[] = $this->check('files', __('File types that are not allowed'), implode(', ', array_slice($blocked, 0, 5)), 'fail');
        }

        return $checks;
    }

    /**
     * Hosts the code talks to must be listed as "http:host" permissions, so buyers know.
     *
     * @return list<array{key: string, title: string, text: string, level: string}>
     */
    private function connectionChecks(PackageArchive $archive): array
    {
        $declared = collect($archive->permissions())
            ->filter(fn (string $code): bool => str_starts_with($code, 'http:'))
            ->map(fn (string $code): string => strtolower(substr($code, 5)))
            ->all();

        $found = [];

        foreach ($archive->files() as $file) {
            if (! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['php', 'js'], true)) {
                continue;
            }

            preg_match_all('#https://([a-z0-9.-]+\.[a-z]{2,})#i', $archive->contents($file), $matches);

            foreach ($matches[1] as $host) {
                $found[strtolower($host)] = true;
            }
        }

        $undeclared = array_values(array_filter(array_keys($found), function (string $host) use ($declared): bool {
            foreach ($declared as $allowed) {
                if ($host === $allowed || str_ends_with($host, '.'.ltrim($allowed, '*.'))) {
                    return false;
                }
            }

            return ! in_array($host, ['www.w3.org', 'schemas.xmlsoap.org', 'example.com', 'nuvabill.com', 'my.nuvabill.com'], true);
        }));

        if ($undeclared === []) {
            return [$this->check('connections', __('Outside connections'), $declared === [] ? __('None') : __('Only :hosts, as listed', ['hosts' => implode(', ', $declared)]))];
        }

        return [$this->check('connections', __('Addresses not in the permissions'), __('The code mentions :hosts. List them as "http:host" permissions or explain them to the reviewer.', ['hosts' => implode(', ', array_slice($undeclared, 0, 6))]), 'warn')];
    }

    /**
     * @return array{key: string, title: string, text: string, level: string}
     */
    private function check(string $key, string $title, string $text, string $level = 'ok'): array
    {
        return ['key' => $key, 'title' => $title, 'text' => $text, 'level' => $level];
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
