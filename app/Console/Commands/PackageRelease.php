<?php

namespace App\Console\Commands;

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
    private const HIDDEN_FILES = ['.htaccess', '.env.example', '.editorconfig', '.gitattributes', '.gitignore', '.npmrc'];

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
        $key = $this->option('key') ? trim((string) file_get_contents((string) $this->option('key'))) : (string) getenv('NUVABILL_SIGNING_KEY');

        $files = 0;
        $fingerprints = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path(), RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()))), '/');

            if (! $file->isFile() || self::isExcluded($relative) || in_array($relative, [CoreFiles::LIST_FILE, CoreFiles::SIGNATURE_FILE], true)) {
                continue;
            }

            $zip->addFile($file->getPathname(), $relative);
            $files++;

            if (CoreFiles::isCode($relative)) {
                $fingerprints[$relative] = (string) hash_file('sha256', $file->getPathname());
            }
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

        foreach (self::EXCLUDED as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }
}
