<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Marketplace\PackageType;
use App\Marketplace\Store\ItemPublisher;
use App\Marketplace\Store\VersionUploader;
use App\Models\Developer;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceVersion;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Developers list items, send versions for review, and talk with reviewers.
 */
class DeveloperItemController extends Controller
{
    public function create(Request $request): View
    {
        $this->developer($request);

        return view('theme::developer.item-form', [
            'item' => new MarketplaceItem(['type' => PackageType::Theme, 'currency' => (string) setting('billing.currency')]),
            'types' => $this->types(),
            'categories' => MarketplaceItem::CATEGORIES,
            'share' => $this->developer($request)->share(),
        ]);
    }

    public function store(Request $request, VersionUploader $uploader): RedirectResponse
    {
        $developer = $this->developer($request);
        $data = $this->validated($request);
        $request->validate(['package' => ['required', 'file', 'mimes:zip', 'max:20480'], 'own_code' => ['accepted']], ['own_code.accepted' => __('Please confirm you own this code.')]);

        $item = DB::transaction(function () use ($developer, $data, $request): MarketplaceItem {
            $item = $developer->items()->create($this->attributes($data) + [
                'slug' => $data['slug'],
                'type' => $data['type'],
                'currency' => (string) setting('billing.currency'),
                'status' => MarketplaceItem::STATUS_DRAFT,
            ]);

            $this->saveScreenshots($item, $request->file('screenshots', []));

            return $item;
        });

        $version = $uploader->upload($item, $request->file('package'), $request->input('changelog'));

        return redirect()->route('developer.items.show', $item)->with($version->status === MarketplaceVersion::STATUS_PENDING ? 'status' : 'error', $version->status === MarketplaceVersion::STATUS_PENDING
            ? __('Sent for review. The automatic checks are done; a person tests it next.')
            : __('The automatic checks found problems. Fix them and upload a new version.'));
    }

    public function show(Request $request, MarketplaceItem $item): View
    {
        $this->authorizeItem($request, $item);
        $item->load(['versions' => fn ($query) => $query->latest('id'), 'versions.messages', 'latestVersion']);

        return view('theme::developer.item', [
            'item' => $item,
            'types' => $this->types(),
            'categories' => MarketplaceItem::CATEGORIES,
            'share' => $item->developer->share(),
        ]);
    }

    public function update(Request $request, MarketplaceItem $item, ItemPublisher $publisher): RedirectResponse
    {
        $this->authorizeItem($request, $item);
        $data = $this->validated($request, $item);

        $item->update($this->attributes($data));
        $this->saveScreenshots($item, $request->file('screenshots', []));

        if ($item->isLive()) {
            $publisher->syncProduct($item);
        }

        return back()->with('status', __('Listing saved.'));
    }

    public function upload(Request $request, MarketplaceItem $item, VersionUploader $uploader): RedirectResponse
    {
        $this->authorizeItem($request, $item);
        $request->validate(['package' => ['required', 'file', 'mimes:zip', 'max:20480'], 'changelog' => ['nullable', 'string', 'max:2000']]);

        $version = $uploader->upload($item, $request->file('package'), $request->input('changelog'));

        return back()->with($version->status === MarketplaceVersion::STATUS_PENDING ? 'status' : 'error', $version->status === MarketplaceVersion::STATUS_PENDING
            ? __('Version :version sent for review.', ['version' => $version->version])
            : __('The automatic checks found problems. Fix them and upload a new version.'));
    }

    public function message(Request $request, MarketplaceVersion $version): RedirectResponse
    {
        $this->authorizeItem($request, $version->item);
        $data = $request->validate(['message' => ['required', 'string', 'max:3000']]);

        $version->messages()->create([
            'author_type' => MarketplaceMessage::FROM_DEVELOPER,
            'author_id' => $version->item->developer_id,
            'message' => $data['message'],
        ]);

        return back()->with('status', __('Message sent to the review team.'));
    }

    private function developer(Request $request): Developer
    {
        $developer = $request->user('web')->developer;

        abort_if($developer === null || ! $developer->isActive(), 403, __('Join the developer program first.'));

        return $developer;
    }

    private function authorizeItem(Request $request, MarketplaceItem $item): void
    {
        abort_unless($item->developer_id === $this->developer($request)->id, 404);
    }

    /**
     * @return array<string, string>
     */
    private function types(): array
    {
        return collect(PackageType::cases())->reject(fn (PackageType $type): bool => $type === PackageType::License)->mapWithKeys(fn (PackageType $type): array => [$type->value => $type->label()])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MarketplaceItem $item = null): array
    {
        $request->merge(['slug' => Str::slug((string) $request->input('slug', $item?->slug))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => $item ? ['nullable'] : ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('marketplace_items', 'slug')],
            'type' => $item ? ['nullable'] : ['required', Rule::enum(PackageType::class)->except(PackageType::License)],
            'category' => ['nullable', Rule::in(MarketplaceItem::CATEGORIES)],
            'summary' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'update_price' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'demo_url' => ['nullable', 'url', 'max:255', 'starts_with:https://'],
            'docs_url' => ['nullable', 'url', 'max:255', 'starts_with:https://'],
            'icon_bg' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon_fg' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'screenshots' => ['nullable', 'array', 'max:6'],
            'screenshots.*' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:3072'],
            'changelog' => ['nullable', 'string', 'max:2000'],
        ], ['slug.unique' => __('Another item already uses this short name.')]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $price = Money::toMinor($data['price'] ?? 0);

        return [
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'summary' => $data['summary'],
            'description' => $data['description'] ?? null,
            'price' => $price,
            'update_price' => $price > 0 ? min($price, Money::toMinor($data['update_price'] ?? 0)) : 0,
            'demo_url' => $data['demo_url'] ?? null,
            'docs_url' => $data['docs_url'] ?? null,
            'icon' => ['glyph' => '', 'bg' => $data['icon_bg'] ?? '#EEF2F6', 'fg' => $data['icon_fg'] ?? '#0F1B2D'],
        ];
    }

    /**
     * @param  array<int, UploadedFile>|UploadedFile|null  $files
     */
    private function saveScreenshots(MarketplaceItem $item, array|UploadedFile|null $files): void
    {
        $files = array_filter(is_array($files) ? $files : [$files]);

        if ($files === []) {
            return;
        }

        $directory = MediaController::path($item->slug);
        $names = [];

        foreach (array_values($files) as $index => $file) {
            $name = ($index + 1).'-'.Str::random(6).'.'.strtolower($file->extension() === 'jpeg' ? 'jpg' : $file->extension());
            $file->move($directory, $name);
            $names[] = $name;
        }

        $item->update(['screenshots' => $names]);
    }
}
