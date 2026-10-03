<?php

namespace App\Console\Commands;

use App\Extensions\ExtensionOverview;
use App\Health\CoreFiles;
use App\Updates\Signature as ReleaseSignature;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

#[Signature('nuvabill:package
    {--output=dist : Folder for the zip, signature and checksum}
    {--key= : Path to the signing key file (defaults to the NUVABILL_SIGNING_KEY environment variable)}')]
#[Description('Build the release zip that installs and updates use (run after composer install --no-dev and npm run build)')]
class PackageRelease extends Command
{
    /**
     * Paths left out of release zips: development files, local data and secrets.
     *
     * @var list<string>
     */
    private const EXCLUDED = [
        'node_modules', 'tests', 'dist',
        '.env', '.env.backup', '.phpunit.result.cache', 'phpunit.xml', 'install.sh',
        'database/database.sqlite', 'public/hot', 'public/storage',
    ];

    /**
     * The only Markdown files at the top level that ship; other ones there are a developer's own notes.
     *
     * @var list<string>
     */
    private const DOCUMENTS = ['README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'ROADMAP.md', 'SECURITY.md'];

    /**
     * The only hidden files at the top level that ship; other ones there are local settings.
     *
     * @var list<string>
     */
    private const HIDDEN_FILES = ['.htaccess', '.env.example', '.editorconfig', '.gitattributes', '.gitignore'];

    /**
     * Files that hold logins or keys, in any folder: Composer and npm logins and private keys.
     * Git leaving them out is not enough, because the zip is made from the folder, not from Git.
     */
    private const SECRET_FILE = '#(^|/)(auth\.json|\.npmrc|\.netrc|id_(rsa|dsa|ecdsa|ed25519)|[^/]+\.(key|p12|pfx|ppk|jks))$#i';

    public function handle(): int
    {
        $version = (string) config('nuvabill.version');
        $output = base_path((string) $this->option('output'));

        if (! is_file(public_path('build/manifest.json'))) {
            $this->components->error('Build the front-end first: npm ci && npm run build');

            return self::FAILURE;
        }

        if (! is_dir($output)) {
            mkdir($output, 0755, true);
        }

        $zipPath = $output.DIRECTORY_SEPARATOR."nuvabill-{$version}.zip";
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $keyFile = $this->option('key') ? realpath((string) $this->option('key')) : false;
        $key = $this->option('key') ? trim((string) file_get_contents((string) $this->option('key'))) : (string) getenv('NUVABILL_SIGNING_KEY');

        if ($keyFile !== false && str_starts_with(str_replace('\\', '/', $keyFile), str_replace('\\', '/', base_path()).'/')) {
            $this->components->warn('The signing key is inside the project folder. It is left out of the zip, but keep it outside the folder.');
        }

        $files = 0;
        $fingerprints = [];
        $leftOut = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path(), RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()))), '/');

            if (($extension = self::marketplaceExtension($relative)) !== null) {
                $leftOut[$extension] = true;
            }

            if (! $file->isFile() || self::isExcluded($relative) || in_array($relative, [CoreFiles::LIST_FILE, CoreFiles::SIGNATURE_FILE], true)) {
                continue;
            }

            // A private key never ships, whatever its file is called: the signing key above all.
            if ($file->getRealPath() === $keyFile || self::holdsPrivateKey($file->getPathname(), $key)) {
                $this->components->warn("Left out {$relative}: it holds a private key.");

                continue;
            }

            $zip->addFile($file->getPathname(), $relative);
            $files++;

            if (CoreFiles::isCode($relative)) {
                $fingerprints[$relative] = (string) hash_file('sha256', $file->getPathname());
            }
        }

        // A new built-in extension must be added to ExtensionOverview::BUILT_IN, or it does not ship.
        foreach (array_keys($leftOut) as $extension) {
            $this->components->warn("Left out {$extension}: it is not a built-in extension.");
        }

        // Site health compares the site's own code with this list to spot changed or planted files.
        ksort($fingerprints);
        $list = (string) json_encode(['version' => $version, 'files' => $fingerprints], JSON_UNESCAPED_SLASHES);
        $zip->addFromString(CoreFiles::LIST_FILE, $list);

        if ($key !== '') {
            $zip->addFromString(CoreFiles::SIGNATURE_FILE, ReleaseSignature::signFileList($version, $list, $key));
        }

        $zip->close();

        $sha256 = (string) hash_file('sha256', $zipPath);
        file_put_contents($zipPath.'.sha256', $sha256.'  '.basename($zipPath)."\n");

        $this->components->info("Packed {$files} files into {$zipPath}, with fingerprints of ".count($fingerprints).' code files');
        $this->line("SHA-256: {$sha256}");

        if ($key === '') {
            $this->components->warn('No signing key given. The zip is NOT signed and installs will refuse it as an update.');

            return self::SUCCESS;
        }

        file_put_contents($zipPath.'.sig', ReleaseSignature::sign($version, $zipPath, $key));
        $this->components->info('Signed: '.basename($zipPath).'.sig');

        return self::SUCCESS;
    }

    /**
     * Whether a path (relative to the project, with forward slashes) stays out of the release zip.
     */
    public static function isExcluded(string $relative): bool
    {
        if (str_starts_with($relative, 'storage/') && basename($relative) !== '.gitignore') {
            return true;
        }

        if (str_starts_with($relative, 'bootstrap/cache/') && basename($relative) !== '.gitignore') {
            return true;
        }

        // Marketplace packages installed on this copy (paid ones must never ship) and local databases.
        if (preg_match('#^(themes/(?!nova/)|orderforms/(?!\.gitkeep$)|extensions/addons/)|^database/.+\.sqlite(-journal|-wal|-shm)?$#', $relative)) {
            return true;
        }

        // Gateways, server modules and registrars from the marketplace, and any marketplace license stamp.
        if (self::marketplaceExtension($relative) !== null || basename($relative) === '.nuvabill-license') {
            return true;
        }

        // Hidden folders at the top (.git, .github, editor and tool settings) never ship.
        if (preg_match('#^\.[^/]+/#', $relative)) {
            return true;
        }

        if (! str_contains($relative, '/') && str_ends_with(strtolower($relative), '.md') && ! in_array($relative, self::DOCUMENTS, true)) {
            return true;
        }

        if (! str_contains($relative, '/') && str_starts_with($relative, '.') && ! in_array($relative, self::HIDDEN_FILES, true)) {
            return true;
        }

        if (preg_match(self::SECRET_FILE, $relative)) {
            return true;
        }

        foreach (self::EXCLUDED as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The folder of a gateway, server module or registrar that does not come with Nuvabill, such as
     * "extensions/servers/some-module", or null for any other path. Paid ones must never ship.
     */
    public static function marketplaceExtension(string $relative): ?string
    {
        if (! preg_match('#^extensions/(gateway|server|registrar)s/([^/]+)/#', $relative, $match)) {
            return null;
        }

        return in_array($match[2], ExtensionOverview::BUILT_IN[$match[1]] ?? [], true) ? null : "extensions/{$match[1]}s/{$match[2]}";
    }

    /**
     * Whether a file holds a private key: a PEM key block, or the release signing key itself.
     */
    public static function holdsPrivateKey(string $path, string $signingKey = ''): bool
    {
        $size = filesize($path);

        if ($size === false || $size > 1_048_576) {
            return false;
        }

        $content = (string) file_get_contents($path);

        return ($signingKey !== '' && str_contains($content, $signingKey))
            || preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----\r?\n[A-Za-z0-9+\/=\r\n]{64,}/', $content) === 1;
    }
}
