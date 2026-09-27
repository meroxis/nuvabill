<?php

namespace App\Marketplace\Store;

use App\Models\MarketplaceItem;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceVersion;
use App\Support\Activity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Stores an uploaded package, runs the automatic checks and puts the version in the review queue.
 * A package that fails a check goes back to the developer with the reasons instead.
 */
class VersionUploader
{
    public function __construct(private PackageInspector $inspector) {}

    public function upload(MarketplaceItem $item, UploadedFile|string $file, ?string $changelog = null): MarketplaceVersion
    {
        $relative = 'marketplace/packages/'.$item->slug.'/'.Str::random(16).'.zip';
        $path = storage_path('app/private/'.$relative);
        File::ensureDirectoryExists(dirname($path));

        if ($file instanceof UploadedFile) {
            $file->move(dirname($path), basename($path));
        } else {
            File::copy($file, $path);
        }

        $report = $this->inspector->inspect($path, $item, $item->latestVersion?->version);
        $version = $report['version'] !== '' ? $report['version'] : '0.0.0-'.Str::lower(Str::random(6));

        $existing = $item->versions()->where('version', $version)->first();

        if ($existing !== null && $existing->status !== MarketplaceVersion::STATUS_APPROVED) {
            @unlink($existing->path());
            $existing->delete();
        } elseif ($existing !== null) {
            $version .= '-duplicate-'.Str::lower(Str::random(4));
            $report['checks'][] = ['key' => 'version', 'title' => __('Version already released'), 'text' => __('Raise the version number in the manifest.'), 'level' => 'fail'];
            $report['ok'] = false;
        }

        $record = $item->versions()->create([
            'version' => $version,
            'changelog' => $changelog,
            'file_path' => $relative,
            'file_size' => (int) filesize($path),
            'sha256' => (string) hash_file('sha256', $path),
            'manifest' => $report['manifest'],
            'checks' => $report['checks'],
            'status' => $report['ok'] ? MarketplaceVersion::STATUS_PENDING : MarketplaceVersion::STATUS_CHANGES,
        ]);

        if (! $report['ok']) {
            $record->messages()->create([
                'author_type' => MarketplaceMessage::FROM_STAFF,
                'message' => __('The automatic checks found these problems: :problems', [
                    'problems' => collect($report['checks'])->where('level', 'fail')->map(fn (array $check): string => $check['title'].' ('.$check['text'].')')->implode('; '),
                ]),
            ]);
        }

        Activity::log('marketplace.submitted', "{$item->name} {$record->version} sent for review");

        return $record;
    }
}
