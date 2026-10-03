<?php

namespace App\Console\Commands;

use App\Http\Controllers\Marketplace\MediaController;
use App\Marketplace\PackageArchive;
use App\Marketplace\Store\ItemPublisher;
use App\Marketplace\Store\VersionUploader;
use App\Models\Developer;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceVersion;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

#[Signature('nuvabill:marketplace-publish {listing* : listing.json files; each names its package zip} {--developer=Nuvabill : The developer, made official if new}')]
#[Description('Publish official marketplace items on the store: create the listing, check, approve and sign the package')]
class MarketplacePublish extends Command
{
    public function handle(VersionUploader $uploader, ItemPublisher $publisher): int
    {
        if (! config('nuvabill.marketplace.store')) {
            $this->components->error('This is not the marketplace store (NUVABILL_MARKETPLACE_STORE is not true).');

            return self::FAILURE;
        }

        try {
            $developer = Developer::official((string) $this->option('developer'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $failed = 0;

        foreach ((array) $this->argument('listing') as $file) {
            try {
                $this->publish($file, $developer, $uploader, $publisher);
            } catch (Throwable $exception) {
                $failed++;
                $this->components->error(basename($file).': '.$exception->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function publish(string $file, Developer $developer, VersionUploader $uploader, ItemPublisher $publisher): void
    {
        $listing = json_decode((string) @file_get_contents($file), true);

        if (! is_array($listing) || ! is_string($listing['package'] ?? null)) {
            throw new \RuntimeException('Not a listing file (needs "package").');
        }

        $base = dirname($file);
        $zip = $base.'/'.$listing['package'];
        $archive = new PackageArchive($zip);
        $slug = $archive->slug();
        $type = $archive->type();
        $name = $archive->name();
        unset($archive);

        $renamedTo = MarketplaceItem::renamedTo($slug);

        if ($renamedTo !== null) {
            throw new \RuntimeException("The slug {$slug} is an old slug of {$renamedTo}. Use the new slug in the package.");
        }

        $item = MarketplaceItem::query()->firstOrNew(['slug' => $slug]);

        if ($item->exists && $item->developer_id !== $developer->id) {
            throw new \RuntimeException("The slug {$slug} belongs to another developer.");
        }

        $item->fill([
            'developer_id' => $developer->id,
            'type' => $type,
            'name' => (string) ($listing['name'] ?? $name),
            'summary' => (string) ($listing['summary'] ?? ''),
            'description' => (string) ($listing['description'] ?? ''),
            'category' => $listing['category'] ?? null,
            'price' => Money::toMinor($listing['price'] ?? 0),
            'update_price' => Money::toMinor($listing['update_price'] ?? 0),
            'currency' => (string) setting('billing.currency'),
            'is_featured' => (bool) ($listing['featured'] ?? false),
            'demo_url' => $listing['demo_url'] ?? null,
            'docs_url' => $listing['docs_url'] ?? null,
            'icon' => $listing['icon'] ?? null,
        ]);
        $item->status ??= MarketplaceItem::STATUS_DRAFT;
        $item->save();

        if (is_array($listing['screenshots'] ?? null)) {
            $names = [];
            File::ensureDirectoryExists(MediaController::path($slug));

            foreach ($listing['screenshots'] as $index => $shot) {
                $source = $base.'/'.$shot;
                $name = ($index + 1).'-'.Str::random(6).'.'.strtolower(pathinfo($source, PATHINFO_EXTENSION));
                File::copy($source, MediaController::path($slug, $name));
                $names[] = $name;
            }

            foreach ((array) $item->screenshots as $old) {
                @unlink(MediaController::path($slug, basename($old)));
            }

            $item->update(['screenshots' => $names]);
        }

        $version = $uploader->upload($item->refresh(), $zip, $listing['changelog'] ?? null);

        if ($version->status !== MarketplaceVersion::STATUS_PENDING) {
            throw new \RuntimeException('The automatic checks failed: '.collect($version->checks)->where('level', 'fail')->pluck('text')->implode('; '));
        }

        $publisher->approve($version);
        $this->components->info("{$item->name} {$version->version} is live.");
    }
}
