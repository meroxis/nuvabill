<?php

namespace Tests\Feature;

use App\Console\Commands\PackageRelease;
use App\Updates\Signature;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

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
            'installed marketplace server module' => ['extensions/servers/mikrotik-vpn/extension.json', true],
            'license stamp of a server module' => ['extensions/servers/mikrotik-vpn/.nuvabill-license', true],
            'installed marketplace gateway' => ['extensions/gateways/nowpayments/src/NowPaymentsGateway.php', true],
            'installed marketplace registrar' => ['extensions/registrars/some-registrar/extension.json', true],
            'built-in server module' => ['extensions/servers/cpanel/src/CpanelModule.php', false],
            'built-in gateway' => ['extensions/gateways/stripe/extension.json', false],
            'built-in registrar' => ['extensions/registrars/enom/extension.json', false],
            'local database' => ['database/database.sqlite', true],
            'storage file' => ['storage/logs/laravel.log', true],
            'composer login' => ['auth.json', true],
            'composer login in a folder' => ['packages/tools/auth.json', true],
            'npm login' => ['.npmrc', true],
            'a key file' => ['keys/release.key', true],
            'an ssh key' => ['deploy/id_ed25519', true],
            'a certificate bundle' => ['vendor/composer/ca-bundle/res/cacert.pem', false],
        ];
    }

    #[DataProvider('paths')]
    public function test_only_the_application_ships(string $path, bool $excluded): void
    {
        $this->assertSame($excluded, PackageRelease::isExcluded($path));
    }

    public function test_a_real_release_zip_has_no_logins_or_keys_and_is_signed(): void
    {
        $parent = storage_path('framework/testing/release-'.Str::random(8));
        $project = $parent.'/project';
        $keys = Signature::generateKeyPair();
        $files = [
            'public/build/manifest.json' => '{}',
            'app/Billing/OrderPlacer.php' => '<?php // the application',
            'vendor/composer/ca-bundle/res/cacert.pem' => "-----BEGIN CERTIFICATE-----\nMIIBfake\n-----END CERTIFICATE-----\n",
            'auth.json' => '{"http-basic":{"repo.example.test":{"username":"fixture","password":"FAKE_LOGIN"}}}',
            '.npmrc' => '//registry.npmjs.org/:_authToken=FAKE_TOKEN',
            'keys/release.key' => $keys['secret'],
            'notes/signing.txt' => 'Key: '.$keys['secret'],
            'certs/server.pem' => "-----BEGIN PRIVATE KEY-----\n".str_repeat('QUJDRA==', 20)."\n-----END PRIVATE KEY-----\n",
            'extensions/servers/cpanel/extension.json' => '{"type":"server"}',
            'extensions/servers/mikrotik-vpn/extension.json' => '{"type":"server","paid":true}',
            'extensions/servers/mikrotik-vpn/src/MikrotikModule.php' => '<?php // a paid marketplace module',
            'extensions/servers/mikrotik-vpn/.nuvabill-license' => '{"license":"fixture"}',
        ];

        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($project.'/'.$path));
            file_put_contents($project.'/'.$path, $content);
        }

        $base = $this->app->basePath();
        $public = $this->app->publicPath();

        try {
            $this->app->setBasePath($project);
            $this->app->usePublicPath($project.'/public');
            $this->artisan('nuvabill:package', ['--key' => $project.'/keys/release.key'])->assertExitCode(0);

            $version = (string) config('nuvabill.version');
            $zipPath = $project."/dist/nuvabill-{$version}.zip";
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($zipPath));
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
                $this->assertStringNotContainsString($keys['secret'], (string) $zip->getFromIndex($i));
            }

            $zip->close();
            sort($names);

            $this->assertSame([
                'app/Billing/OrderPlacer.php', 'extensions/servers/cpanel/extension.json', 'public/build/manifest.json', 'release-files.json', 'release-files.json.sig', 'vendor/composer/ca-bundle/res/cacert.pem',
            ], $names);

            // A marketplace module is neither packed nor fingerprinted as core code.
            $zip->open($zipPath);
            $this->assertStringNotContainsString('mikrotik-vpn', (string) $zip->getFromName('release-files.json'));
            $zip->close();
            $this->assertTrue(Signature::verify($version, $zipPath, (string) file_get_contents($zipPath.'.sig'), $keys['public']));
            $this->assertStringStartsWith(hash_file('sha256', $zipPath), (string) file_get_contents($zipPath.'.sha256'));
        } finally {
            $this->app->setBasePath($base);
            $this->app->usePublicPath($public);
            File::deleteDirectory($parent);
        }
    }
}
