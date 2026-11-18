<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductGroup;
use App\Seo\SeoText;
use App\Seo\SeoTranslations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductGroupController extends Controller
{
    public function create(): View
    {
        return $this->form(new ProductGroup(['is_visible' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data): void {
            $group = ProductGroup::create(Arr::except($data, 'seo_translations'));
            SeoTranslations::save($group, $data['seo_translations'] ?? []);
        });

        return redirect()->route('admin.products.index')->with('status', __('Group created.'));
    }

    public function edit(ProductGroup $productGroup): View
    {
        return $this->form($productGroup);
    }

    public function update(Request $request, ProductGroup $productGroup): RedirectResponse
    {
        $data = $this->validated($request, $productGroup);

        DB::transaction(function () use ($data, $productGroup): void {
            $productGroup->update(Arr::except($data, 'seo_translations'));
            SeoTranslations::save($productGroup, $data['seo_translations'] ?? []);
        });

        return redirect()->route('admin.products.index')->with('status', __('Group saved.'));
    }

    public function destroy(ProductGroup $productGroup): RedirectResponse
    {
        if ($productGroup->products()->exists()) {
            return back()->with('error', __('Move or delete the products in this group first.'));
        }

        $productGroup->delete();

        return redirect()->route('admin.products.index')->with('status', __('Group deleted.'));
    }

    private function form(ProductGroup $group): View
    {
        $mainTitle = $group->exists ? SeoText::groupTitle($group) : '';
        $mainDescription = $group->exists ? SeoText::groupDescription($group) : '';

        return view('admin.products.group-form', [
            'group' => $group,
            'seoTranslations' => SeoTranslations::form($group, $mainTitle, $mainDescription) + ['mainTitle' => $mainTitle, 'mainDescription' => $mainDescription],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ProductGroup $group = null): array
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', Rule::unique('product_groups', 'slug')->ignore($group?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_visible' => ['boolean'],
            'seo_title' => ['nullable', 'string', 'max:120'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'seo_hidden' => ['boolean'],
            ...SeoTranslations::rules(),
        ], [], SeoTranslations::attributes());

        // An empty field arrives as null, and the column needs a number.
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }
}
