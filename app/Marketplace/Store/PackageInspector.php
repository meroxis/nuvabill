<?php

namespace App\Marketplace\Store;

use App\Marketplace\PackageArchive;
use App\Models\MarketplaceItem;
use Illuminate\Support\Str;
use Throwable;

/**
 * The automatic checks run on every uploaded version before a person reviews it: the manifest,
 * versions, hidden or encoded code, risky PHP functions, outside connections, and file types.
 * They help the reviewer, who still reads the code: a scan can miss things.
 */
class PackageInspector
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * PHP functions that run commands or code. They are not always wrong, so they are warnings.
     *
     * @var list<string>
     */
    private const RISKY_FUNCTIONS = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'eval', 'assert', 'create_function', 'dl', 'putenv', 'ini_set', 'unserialize', 'move_uploaded_file', 'curl_exec'];

    /**
     * Functions that write files, decode text or call a function by its name. Honest code uses
     * them too, so they are warnings, listed after the ones above.
     *
     * @var list<string>
     */
    private const WATCHED_FUNCTIONS = ['file_put_contents', 'fwrite', 'fputs', 'call_user_func', 'call_user_func_array', 'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'hex2bin'];

    /**
     * Classes that unpack archives, which could bring in files after the checks.
     *
     * @var list<string>
     */
    private const WATCHED_CLASSES = ['ZipArchive', 'PharData', 'Phar'];

    /**
     * Signs of encoded or hidden code, which the marketplace does not accept.
     *
     * @var list<string>
     */
    private const ENCODED_MARKERS = ['ionCube Loader', 'sg_load(', 'SourceGuardian', 'zend_loader', '<?php //0'];

    /**
     * What may stand between a name and its bracket in PHP: spaces, line breaks and comments.
     */
    private const GAP = '(?>\s+|/\*.*?\*/|(?://|\#)[^\r\n]*)*';

    /**
     * What may stand before the name of a plain function call. A "\" in front of the name is fine.
     */
    private const CALL_START = '(?<![\w$>:\\\\])\\\\?';

    /**
     * Running decoded text as code, also with spaces, comments, an @ or a "\" in between:
     * eval (\base64_decode(...
     */
    private const ENCODED_EVAL = '~\beval'.self::GAP.'\('.self::GAP.'@?'.self::GAP.'\\\\?(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|hex2bin)'.self::GAP.'\(~is';

    /**
     * A file name that is fixed in the code, such as __DIR__.'/file.php'. The name is captured.
     */
    private const FIXED_FILE = '(?:(?:__DIR__|dirname'.self::GAP.'\('.self::GAP.'__(?:DIR|FILE)__'.self::GAP.'\))'.self::GAP.'\.'.self::GAP.')?([\'"])([^\'"$\\\\]*)\2'.self::GAP.'\)?'.self::GAP.'(?:;|\?>)';

    /**
     * How a PHP value starts, to tell an include from the word "include" in a sentence.
     */
    private const VALUE_START = '[$\'"\\\\]|__DIR__|__FILE__|[a-z_][\w\\\\]*'.self::GAP.'(?:\(|::|;)';

    /**
     * A PHP short open tag. XML declarations such as <?xml version="1.0"?> are left out: they
     * look the same, but PHP cannot parse them, so they never run.
     */
    private const SHORT_TAG = '/<\?(?!(?:xml|xml-stylesheet|xpacket)\s+[a-z][\w-]*\s*=)/i';

    /**
     * File types that can run as PHP (or are programs), and archives, which could hold anything.
     * They are not allowed in a package.
     *
     * @var list<string>
     */
    private const BLOCKED_EXTENSIONS = ['phar', 'phtml', 'pht', 'phps', 'php3', 'php4', 'php5', 'php7', 'php8', 'inc', 'exe', 'dll', 'so', 'sh', 'bat', 'cmd', 'com', 'jar', 'htaccess', 'zip', 'tar', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar'];

    /**
     * Image files must be the kind of image their name says.
     *
     * @var array<string, int>
     */
    private const IMAGE_TYPES = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP];

    /**
     * Documentation may show PHP examples; it is still checked for hidden code.
     *
     * @var list<string>
     */
    private const DOCUMENT_EXTENSIONS = ['md', 'markdown'];

    /**
     * @return array{ok: bool, slug: string, version: string, name: string, type: string, manifest: array<string, mixed>, permissions: list<string>, checks: list<array{key: string, title: string, text: string, level: string}>}
     */
    public function inspect(string $zipPath, MarketplaceItem $item, ?string $latestVersion = null): array
    {
        $checks = [];
        $size = (int) @filesize($zipPath);

        try {
            $archive = new PackageArchive($zipPath);
            $fileChecks = [...$this->codeChecks($archive), ...$this->connectionChecks($archive)];
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

        $checks = [...$checks, ...$fileChecks];

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
        $hidden = [];
        $images = [];
        $phpFiles = 0;

        foreach ($archive->files() as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $basename = strtolower(basename($file));

            // Only the store writes the license stamp, into each buyer's copy (DownloadBuilder).
            if (in_array($extension, self::BLOCKED_EXTENSIONS, true) || in_array($basename, ['.htaccess', '.nuvabill-license'], true)) {
                $blocked[] = $file;

                continue;
            }

            $code = $archive->contents($file);

            // An archive under another name is still an archive.
            if ($this->isArchive($code)) {
                $blocked[] = $file;

                continue;
            }

            if (isset(self::IMAGE_TYPES[$extension])) {
                $image = @getimagesizefromstring($code);

                if (! is_array($image) || $image[2] !== self::IMAGE_TYPES[$extension]) {
                    $images[] = $file;
                }
            }

            // PHP runs from any file a .php file includes, so every file with PHP in it is checked.
            if ($extension !== 'php') {
                if (! $this->hasPhp($code)) {
                    continue;
                }

                if (! in_array($extension, self::DOCUMENT_EXTENSIONS, true)) {
                    $hidden[] = $file;
                }
            }

            $phpFiles++;
            $found = $this->riskyCode($code, str_ends_with($basename, '.blade.php'));

            // Code the scan cannot read is treated like encoded code.
            if ($found === null || preg_match(self::ENCODED_EVAL, $code) !== 0) {
                $encoded[] = $file;
            } else {
                foreach (self::ENCODED_MARKERS as $marker) {
                    if (stripos($code, $marker) !== false) {
                        $encoded[] = $file;

                        break;
                    }
                }
            }

            foreach ($found ?? [] as $name => $hit) {
                $risky[$name] ??= ['label' => $file.':'.$hit['line'].' '.$hit['label'], 'count' => 0];
                $risky[$name]['count'] += $hit['count'];
            }
        }

        $checks[] = $encoded === []
            ? $this->check('encoded', __('Readable code'), __('No encoded or hidden code in :count PHP files', ['count' => $phpFiles]))
            : $this->check('encoded', __('Encoded or hidden code'), __('The marketplace only accepts readable code: :files', ['files' => implode(', ', array_slice($encoded, 0, 5))]), 'fail');

        if ($hidden !== []) {
            $checks[] = $this->check('hidden', __('PHP code in other files'), __('PHP code only goes in .php files: :files', ['files' => implode(', ', array_slice($hidden, 0, 5))]), 'fail');
        }

        $checks[] = $risky === []
            ? $this->check('functions', __('No risky functions found'), __('No exec(), eval() or similar calls were found. The scan is only a help, so a person still reads the code.'))
            : $this->check('functions', __('Risky functions to look at'), $this->riskyList($risky), 'warn');

        if ($blocked !== []) {
            $checks[] = $this->check('files', __('File types that are not allowed'), implode(', ', array_slice($blocked, 0, 5)), 'fail');
        }

        if ($images !== []) {
            $checks[] = $this->check('images', __('Images that cannot be opened'), __('These files have an image name but are not that kind of image: :files', ['files' => implode(', ', array_slice($images, 0, 5))]), 'fail');
        }

        return $checks;
    }

    /**
     * Calls and includes in one file that a reviewer must look at: the first place of each
     * kind, and how often it is there. The whole file is searched at once, so a line break or
     * a comment between a name and its bracket hides nothing.
     *
     * @return array<string, array{label: string, line: int, count: int}>|null Null when the file cannot be searched.
     */
    private function riskyCode(string $code, bool $blade): ?array
    {
        // In a Blade view "@include(...)" names a view. Anywhere else the @ only hides errors.
        $includeStart = $blade ? '(?<![\w$>:@\\\\])' : '(?<![\w$>:\\\\])';

        $patterns = [
            '~'.self::CALL_START.'('.implode('|', [...self::RISKY_FUNCTIONS, ...self::WATCHED_FUNCTIONS]).')'.self::GAP.'\(~is',
            '~'.$includeStart.'(include|require)(?:_once)?\b'.self::GAP.'\(?'.self::GAP.'(?:'.self::FIXED_FILE.'|(?='.self::VALUE_START.'))~is',
            '~(`)[^`\r\n]*\$[^`\r\n]*`~',
            '~\b('.implode('|', self::WATCHED_CLASSES).')\b~i',
            '~(?<![\w$>:\\\\])(\$+[a-z_]\w*)'.self::GAP.'\(~is',
        ];

        $found = [];

        foreach ($patterns as $number => $pattern) {
            if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                return null;
            }

            foreach ($matches as $match) {
                [$name, $label] = match ($number) {
                    0, 1 => [strtolower($match[1][0]), strtolower($match[1][0]).'()'],
                    2 => ['backticks', 'backticks()'],
                    3 => [strtolower($match[1][0]), $match[1][0]],
                    default => ['variable', Str::limit($match[1][0], 40).'()'],
                };

                // A fixed .php file of the package is the normal way to include code.
                if ($number === 1 && isset($match[3]) && str_ends_with(strtolower($match[3][0]), '.php')) {
                    continue;
                }

                $found[$name] ??= ['label' => $label, 'line' => (int) preg_match_all('/\R/', substr($code, 0, $match[0][1])) + 1, 'count' => 0];
                $found[$name]['count']++;
            }
        }

        return $found;
    }

    /**
     * The warnings as one line, the calls that run commands or code first. Each kind is named
     * once, so a long list of harmless ones cannot push a dangerous one out of sight.
     *
     * @param  array<string, array{label: string, count: int}>  $risky
     */
    private function riskyList(array $risky): string
    {
        $order = array_flip([...self::RISKY_FUNCTIONS, 'backticks', 'include', 'require', ...self::WATCHED_FUNCTIONS, ...array_map('strtolower', self::WATCHED_CLASSES), 'variable']);

        uksort($risky, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return implode(', ', array_map(fn (array $hit): string => $hit['label'].($hit['count'] > 1 ? ' ×'.$hit['count'] : ''), $risky));
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
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
                continue;
            }

            $contents = $archive->contents($file);

            // Code that runs: PHP and JavaScript files, and any other file with PHP in it.
            if (! in_array($extension, ['php', 'js', 'mjs', 'cjs'], true) && ! $this->hasPhp($contents)) {
                continue;
            }

            preg_match_all('#\bhttps?://([a-z0-9.-]+\.[a-z]{2,})#i', $contents, $matches);

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
     * Whether a file holds PHP code. "<?php" and "<?=" run on every server, so they count in
     * every file, also in one that looks like an image. A bare "<?" only runs where
     * short_open_tag is on and shows up by chance in binary data, so it only counts in text.
     */
    private function hasPhp(string $contents): bool
    {
        return stripos($contents, '<?php') !== false
            || str_contains($contents, '<?=')
            || (! str_contains(substr($contents, 0, 8000), "\0") && preg_match(self::SHORT_TAG, $contents) !== 0);
    }

    /**
     * Whether a file is an archive, whatever its name says: zip (also one added to the end of
     * another file), rar, 7z, gzip, bzip2, xz or tar.
     */
    private function isArchive(string $contents): bool
    {
        return preg_match('/^(?:PK\x03\x04|PK\x05\x06|PK\x07\x08|Rar!\x1A\x07|7z\xBC\xAF\x27\x1C|\x1F\x8B\x08|BZh[1-9]|\xFD7zXZ\x00)/', $contents) === 1
            || substr($contents, 257, 5) === 'ustar'
            || str_contains(substr($contents, -65557), "PK\x05\x06");
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
