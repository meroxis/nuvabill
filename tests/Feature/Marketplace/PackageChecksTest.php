<?php

namespace Tests\Feature\Marketplace;

use App\Marketplace\PackageArchive;
use App\Marketplace\Store\SigningKey;
use App\Marketplace\Store\VersionUploader;
use App\Models\Developer;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceVersion;
use App\Providers\MarketplaceStoreServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * The store's automatic checks on an uploaded package, and the rule that the files they read
 * are exactly the files a site unpacks.
 */
class PackageChecksTest extends TestCase
{
    use RefreshDatabase;

    private const ADDON = ['type' => 'addon', 'namespace' => 'Notes\\', 'class' => 'Notes\\Addon', 'requires' => '>=0.3.0'];

    /**
     * A real 1 x 1 pixel PNG image.
     */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nuvabill-checks-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/storage/app/private');
        $this->app->useStoragePath($this->dir.'/storage');

        config(['nuvabill.marketplace.store' => true]);
        (new MarketplaceStoreServiceProvider($this->app))->enable();
        app(SigningKey::class)->generate();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_the_files_that_are_checked_are_the_files_that_are_unpacked(): void
    {
        $files = ['theme.json' => '{"slug":"paper","name":"Paper","version":"1.0.0"}', 'views/layouts/app.blade.php' => '<html></html>', 'assets/empty.css' => ''];
        $archive = new PackageArchive($this->zip($files, 'paper/'));

        $this->assertEqualsCanonicalizing(array_keys($files), $archive->files());

        $archive->extractTo($this->dir.'/unpacked');
        $unpacked = collect(File::allFiles($this->dir.'/unpacked', true))
            ->mapWithKeys(fn ($file): array => [str_replace('\\', '/', $file->getRelativePathname()) => $file->getContents()])
            ->all();

        $this->assertEqualsCanonicalizing(array_keys($files), array_keys($unpacked));

        foreach ($files as $name => $contents) {
            $this->assertSame($contents, $archive->contents($name), $name);
            $this->assertSame($contents, $unpacked[$name], $name);
        }
    }

    public function test_a_file_name_with_a_backslash_is_refused(): void
    {
        $zip = $this->zip(['theme.json' => '{"slug":"paper","name":"Paper","version":"1.0.0"}', 'views/app.blade.php' => '<html></html>'], 'paper/', ['paper#extra/notes.txt' => 'Notes']);
        $this->rewrite($zip, 'paper#extra', 'paper\\extra');

        $checks = $this->checksOf($this->item('paper', 'theme'), $zip);

        $this->assertSame('fail', $checks['manifest']['level']);
        $this->assertSame('The package has a file name with a backslash: paper\\extra/notes.txt. Make the zip again with another zip tool.', $checks['manifest']['text']);
        $this->assertCount(1, $checks, 'Nothing else is read from a package that is refused.');
    }

    public function test_the_same_file_name_twice_is_refused(): void
    {
        $manifest = '{"slug":"paper","name":"Paper","version":"1.0.0"}';

        $twice = $this->zip(['theme.json' => $manifest, 'views/app.blade.php' => 'first', 'views/bpp.blade.php' => 'second']);
        $this->rewrite($twice, 'views/bpp.blade.php', 'views/app.blade.php');

        $cases = [
            'the same name' => $twice,
            'upper and lower case' => $this->zip(['theme.json' => $manifest, 'views/app.blade.php' => 'first', 'Views/App.blade.php' => 'second']),
            'the manifest twice' => $this->zip(['theme.json' => $manifest, 'Theme.json' => '{"slug":"other","name":"Other","version":"9.0.0"}']),
            'a file that is also a folder' => $this->zip(['theme.json' => $manifest, 'views' => 'first', 'views/app.blade.php' => 'second']),
        ];

        foreach ($cases as $case => $zip) {
            $checks = $this->checksOf($this->item('paper', 'theme'), $zip);

            $this->assertSame('fail', $checks['manifest']['level'], $case);
            $this->assertStringStartsWith('The package has the same file name more than once:', $checks['manifest']['text'], $case);
        }
    }

    public function test_file_names_a_disk_could_store_under_another_name_are_refused(): void
    {
        foreach (['views/app.blade.php.', 'views/app.blade.php ', 'views/app.php:notes', 'views//app.blade.php', 'views/./app.blade.php', 'THEME~1.JSO'] as $name) {
            $this->assertStringStartsWith('The package has an unsafe file path:', $this->refusal(fn () => new PackageArchive($this->zip(['theme.json' => '{"slug":"paper","name":"Paper","version":"1.0.0"}', $name => 'x']))), $name);
        }

        // Plain names with dots, dashes and spaces inside stay fine.
        $archive = new PackageArchive($this->zip(['theme.json' => '{"slug":"paper","name":"Paper","version":"1.0.0"}', '.editorconfig' => 'x', 'views/my page.v2-final.blade.php' => 'x']));
        $this->assertCount(3, $archive->files());
    }

    public function test_a_file_that_is_not_as_long_as_the_zip_says_is_refused(): void
    {
        // The zip lists the first file as 8 bytes long, but it holds more.
        $zip = $this->zip(['notes.txt' => 'The first eight bytes and then some more.', 'theme.json' => '{"slug":"paper","name":"Paper","version":"1.0.0"}'], store: true);
        $this->patchFirstEntry($zip, 24, pack('V', 8));

        $checks = $this->checksOf($this->item('paper', 'theme'), $zip);
        $this->assertSame('fail', $checks['manifest']['level']);
        $this->assertSame('The package file is damaged.', $checks['manifest']['text']);

        $this->assertSame('Could not write notes.txt.', $this->refusal(fn () => (new PackageArchive($zip))->extractTo($this->dir.'/unpacked')));
    }

    public function test_only_plain_zip_files_are_read(): void
    {
        // The first file says it is packed in a way not every server can unpack.
        $zip = $this->zip(['notes.txt' => 'Notes', 'theme.json' => '{"slug":"paper","name":"Paper","version":"1.0.0"}'], store: true);
        $this->patchFirstEntry($zip, 10, pack('v', 12));

        $checks = $this->checksOf($this->item('paper', 'theme'), $zip);

        $this->assertSame('fail', $checks['manifest']['level']);
        $this->assertSame('The package must be a normal zip file, without a password or special compression.', $checks['manifest']['text']);
    }

    public function test_php_in_a_file_that_is_not_text_is_still_found(): void
    {
        $checks = $this->checksOf($this->item('notes', 'addon'), $this->addon([
            'src/Addon.php' => "<?php\nnamespace Notes;\nclass Addon extends \\App\\Extensions\\Addons\\Addon {}\n",
            'assets/logo.png' => "\0\0\0<?= 'hello' ?>",
            'assets/real.png' => (string) base64_decode(self::PNG),
        ]));

        $this->assertSame('fail', $checks['hidden']['level']);
        $this->assertStringContainsString('assets/logo.png', $checks['hidden']['text']);
        $this->assertStringNotContainsString('assets/real.png', $checks['hidden']['text']);

        $this->assertSame('fail', $checks['images']['level']);
        $this->assertStringContainsString('assets/logo.png', $checks['images']['text']);
        $this->assertStringNotContainsString('assets/real.png', $checks['images']['text'], 'A real image is fine.');
    }

    public function test_an_image_must_be_the_kind_its_name_says(): void
    {
        $checks = $this->checksOf($this->item('notes', 'addon'), $this->addon([
            'assets/photo.jpg' => (string) base64_decode(self::PNG),
            'assets/empty.gif' => '',
            'assets/icon.png' => (string) base64_decode(self::PNG),
        ]));

        $this->assertSame('fail', $checks['images']['level']);
        $this->assertStringContainsString('assets/photo.jpg', $checks['images']['text']);
        $this->assertStringContainsString('assets/empty.gif', $checks['images']['text']);
        $this->assertStringNotContainsString('assets/icon.png', $checks['images']['text']);
    }

    public function test_archives_inside_a_package_are_not_allowed(): void
    {
        $inner = (string) file_get_contents($this->zip(['readme.txt' => 'Hello']));

        $checks = $this->checksOf($this->item('notes', 'addon'), $this->addon([
            'assets/extra.zip' => $inner,
            'assets/backup.tar.gz' => 'x',
            // An archive under another name, and one added to the end of another file.
            'assets/data.bin' => $inner,
            'assets/tail.dat' => 'Some text first. '.$inner,
        ]));

        $this->assertSame('fail', $checks['files']['level']);

        foreach (['assets/extra.zip', 'assets/backup.tar.gz', 'assets/data.bin', 'assets/tail.dat'] as $file) {
            $this->assertStringContainsString($file, $checks['files']['text']);
        }
    }

    public function test_calls_a_reviewer_must_look_at_are_listed_with_the_dangerous_ones_first(): void
    {
        $code = <<<'PHP'
            <?php
            fwrite($handle, 'a');
            \shell_exec('ls');
            $name = 'strrev';
            $name('abc');
            file_put_contents(__DIR__.'/cache.txt', 'x');
            include $path;
            require __DIR__.'/helpers.php';
            passthru
                ('ls');
            $zip = new \ZipArchive;
            fwrite($handle, 'b');
            $text = base64_decode($input);
            @include 'notes.txt';
            call_user_func /* later */ ($name);
            PHP;

        $checks = $this->checksOf($this->item('notes', 'addon'), $this->addon([
            'src/Addon.php' => $code,
            'views/page.blade.php' => "@include('notes::partials.menu')\n<p>Prices include tax.</p>\n",
        ]));

        $this->assertSame('warn', $checks['functions']['level']);
        $this->assertSame(implode(', ', [
            'src/Addon.php:3 shell_exec()',
            'src/Addon.php:9 passthru()',
            'src/Addon.php:7 include() ×2',
            'src/Addon.php:6 file_put_contents()',
            'src/Addon.php:2 fwrite() ×2',
            'src/Addon.php:15 call_user_func()',
            'src/Addon.php:13 base64_decode()',
            'src/Addon.php:11 ZipArchive',
            'src/Addon.php:5 $name()',
        ]), $checks['functions']['text']);
        $this->assertSame('ok', $checks['encoded']['level'], 'Warnings do not stop a version.');
    }

    public function test_decoded_text_run_as_code_fails_however_it_is_written(): void
    {
        foreach (["eval(\\base64_decode('AAAA'));", "eval /* x */ (\n    @\\gzinflate(\$data));", 'EVAL(hex2bin($data));'] as $line) {
            $checks = $this->checksOf($this->item('notes', 'addon'), $this->addon(['src/Addon.php' => "<?php\n{$line}\n"]));

            $this->assertSame('fail', $checks['encoded']['level'], $line);
            $this->assertStringContainsString('src/Addon.php', $checks['encoded']['text'], $line);
        }
    }

    public function test_a_clean_package_passes_and_says_the_scan_is_only_a_help(): void
    {
        $version = app(VersionUploader::class)->upload($this->item('notes', 'addon'), $this->addon([
            'src/Addon.php' => "<?php\nnamespace Notes;\nrequire_once __DIR__.'/helpers.php';\nclass Addon extends \\App\\Extensions\\Addons\\Addon {}\n",
            'src/helpers.php' => "<?php\nfunction notes_title(): string { return 'Notes'; }\n",
            'assets/icon.png' => (string) base64_decode(self::PNG),
            'assets/logo.svg' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>\n",
        ]));

        $this->assertSame(MarketplaceVersion::STATUS_PENDING, $version->status);
        $checks = collect($version->checks)->keyBy('key');
        $this->assertSame(['ok'], collect($version->checks)->pluck('level')->unique()->values()->all());
        $this->assertSame('No risky functions found', $checks['functions']['title']);
        $this->assertStringContainsString('a person still reads the code', $checks['functions']['text']);
    }

    public function test_a_version_checked_under_older_rules_is_checked_again_before_it_is_signed(): void
    {
        $version = app(VersionUploader::class)->upload($this->item('notes', 'addon'), $this->addon([
            'src/Addon.php' => "<?php\nfile_put_contents(__DIR__.'/cache.txt', 'x');\n",
        ]));

        // The results as an older copy of the store saved them: nothing to look at.
        $version->update(['checks' => collect($version->checks)->map(fn (array $check): array => ['level' => 'ok'] + $check)->all()]);
        $this->assertSame(0, $version->warnings());

        $this->signInAdmin();
        $this->get(route('admin.store.reviews.show', $version))->assertOk()->assertSee('Read the code');
        $this->put(route('admin.store.reviews.checklist', $version), ['checklist' => ['code', 'installs']])->assertSessionHasNoErrors();

        $this->post(route('admin.store.reviews.approve', $version))
            ->assertSessionHas('error', 'The automatic checks were run again and found something new. Look at the checks, then decide again.');

        $version->refresh();
        $this->assertSame(MarketplaceVersion::STATUS_PENDING, $version->status);
        $this->assertNull($version->signature);
        $this->assertSame(['code', 'installs'], $version->checklist);
        $this->assertSame('src/Addon.php:2 file_put_contents()', collect($version->checks)->firstWhere('key', 'functions')['text']);

        // The reviewer has seen the new result and approves.
        $this->post(route('admin.store.reviews.approve', $version), ['seen' => $version->item->listingFingerprint()])->assertRedirect(route('admin.store.reviews.index'));
        $this->assertSame(MarketplaceVersion::STATUS_APPROVED, $version->fresh()->status);
    }

    public function test_a_version_that_no_longer_passes_the_checks_cannot_be_signed(): void
    {
        $version = app(VersionUploader::class)->upload($this->item('notes', 'addon'), $this->addon([
            'assets/logo.png' => "\0\0\0<?= 'hello' ?>",
        ]));
        $version->update(['status' => MarketplaceVersion::STATUS_PENDING, 'checks' => collect($version->checks)->where('level', 'ok')->values()->all()]);

        $this->signInAdmin();
        $this->post(route('admin.store.reviews.approve', $version))->assertSessionHas('error');
        $this->post(route('admin.store.reviews.approve', $version))->assertSessionHas('error', 'This version failed an automatic check. Ask for changes instead.');

        $this->assertNull($version->fresh()->signature);
        $this->assertTrue($version->fresh()->hasFailures());
    }

    private function item(string $slug, string $type): MarketplaceItem
    {
        $developer = Developer::query()->firstOrCreate(['slug' => 'raz-studio'], ['name' => 'Raz Studio', 'status' => 'active']);

        return $developer->items()->firstOrCreate(['slug' => $slug], ['type' => $type, 'name' => ucfirst($slug), 'summary' => 'Test item.', 'price' => 0, 'update_price' => 0, 'currency' => 'USD', 'status' => MarketplaceItem::STATUS_DRAFT]);
    }

    /**
     * Why the package code refused to go on, or an empty text when it did not refuse.
     */
    private function refusal(callable $action): string
    {
        try {
            $action();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    /**
     * The automatic check results of an upload, by key.
     *
     * @return Collection<string, array{key: string, title: string, text: string, level: string}>
     */
    private function checksOf(MarketplaceItem $item, string $zip): Collection
    {
        $item->versions()->delete();

        return collect(app(VersionUploader::class)->upload($item, $zip)->checks)->keyBy('key');
    }

    /**
     * An add-on package "notes" with these extra files.
     *
     * @param  array<string, string>  $files
     */
    private function addon(array $files): string
    {
        return $this->zip(['extension.json' => (string) json_encode(self::ADDON + ['slug' => 'notes', 'name' => 'Notes', 'version' => '1.0.0'])] + $files);
    }

    /**
     * Make a zip and return its path.
     *
     * @param  array<string, string>  $files  Put below the top folder.
     * @param  array<string, string>  $outside  Added as named, next to the top folder.
     */
    private function zip(array $files, string $folder = '', array $outside = [], bool $store = false): string
    {
        $path = $this->dir.'/'.uniqid('package-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($folder.$name, $contents);
        }

        foreach ($outside as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        if ($store) {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $zip->setCompressionIndex($index, ZipArchive::CM_STORE);
            }
        }

        $zip->close();

        return $path;
    }

    /**
     * Change a file name inside a finished zip to another one of the same length.
     */
    private function rewrite(string $zip, string $from, string $to): void
    {
        $this->assertSame(strlen($from), strlen($to));

        file_put_contents($zip, str_replace($from, $to, (string) file_get_contents($zip)));
    }

    /**
     * Change bytes in the zip's list of files, in the line of the first file.
     */
    private function patchFirstEntry(string $zip, int $offset, string $bytes): void
    {
        $raw = (string) file_get_contents($zip);

        file_put_contents($zip, substr_replace($raw, $bytes, (int) strpos($raw, "PK\x01\x02") + $offset, strlen($bytes)));
    }
}
