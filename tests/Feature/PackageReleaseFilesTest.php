<?php

namespace Tests\Feature;

use App\Console\Commands\PackageRelease;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What goes into the release zip: the application and its documents, never local settings,
 * secrets, notes, tests or marketplace packages installed on the developer's copy.
 */
class PackageReleaseFilesTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function paths(): array
    {
        return [
            'application code' => ['app/Billing/OrderPlacer.php', false],
            'composer manifest' => ['composer.json', false],
            'web server rules' => ['.htaccess', false],
            'example settings' => ['.env.example', false],
            'readme' => ['README.md', false],
            'changelog' => ['CHANGELOG.md', false],
            'license' => ['LICENSE', false],
            'built front-end' => ['public/build/manifest.json', false],
            'built-in theme' => ['themes/nova/views/layouts/app.blade.php', false],
            'secrets' => ['.env', true],
            'git folder' => ['.git/config', true],
            'editor settings' => ['.vscode/settings.json', true],
            'any hidden folder' => ['.tooling/settings.json', true],
            'a hidden local file' => ['.local-settings.json', true],
            'a developer note' => ['NOTES.md', true],
            'tests' => ['tests/Feature/ExampleTest.php', true],
            'installed paid theme' => ['themes/aurora/theme.json', true],
            'installed add-on' => ['extensions/addons/free-trial/extension.json', true],
            'local database' => ['database/database.sqlite', true],
            'storage file' => ['storage/logs/laravel.log', true],
        ];
    }

    #[DataProvider('paths')]
    public function test_only_the_application_ships(string $path, bool $excluded): void
    {
        $this->assertSame($excluded, PackageRelease::isExcluded($path));
    }
}
