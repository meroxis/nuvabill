<?php

namespace App\Console\Commands;

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
        '.git', '.github', '.ai', '.claude', '.idea', '.vscode', 'node_modules', 'tests', 'dist',
        '.env', '.env.backup', '.phpunit.result.cache', 'phpunit.xml', 'boost.json', 'CLAUDE.md', 'AGENTS.md', 'install.sh',
        'database/database.sqlite', 'public/hot', 'public/storage',
    ];

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

        $files = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path(), RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()))), '/');

            if (! $file->isFile() || $this->isExcluded($relative)) {
                continue;
            }

            $zip->addFile($file->getPathname(), $relative);
            $files++;
        }

        $zip->close();

        $sha256 = (string) hash_file('sha256', $zipPath);
        file_put_contents($zipPath.'.sha256', $sha256.'  '.basename($zipPath)."\n");

        $this->components->info("Packed {$files} files into {$zipPath}");
        $this->line("SHA-256: {$sha256}");

        $key = $this->option('key') ? trim((string) file_get_contents((string) $this->option('key'))) : (string) getenv('NUVABILL_SIGNING_KEY');

        if ($key === '') {
            $this->components->warn('No signing key given. The zip is NOT signed and installs will refuse it as an update.');

            return self::SUCCESS;
        }

        file_put_contents($zipPath.'.sig', ReleaseSignature::sign($version, $zipPath, $key));
        $this->components->info('Signed: '.basename($zipPath).'.sig');

        return self::SUCCESS;
    }

    private function isExcluded(string $relative): bool
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

        foreach (self::EXCLUDED as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }
}
