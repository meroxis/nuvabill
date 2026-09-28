<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductGroupController extends Controller
{
    public function create(): View
    {
        return view('admin.products.group-form', ['group' => new ProductGroup(['is_visible' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        ProductGroup::create($this->validated($request));

        return redirect()->route('admin.products.index')->with('status', __('Group created.'));
    }

    public function edit(ProductGroup $productGroup): View
    {
        return view('admin.products.group-form', ['group' => $productGroup]);
    }

    public function update(Request $request, ProductGroup $productGroup): RedirectResponse
    {
        $productGroup->update($this->validated($request, $productGroup));

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

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ProductGroup $group = null): array
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', Rule::unique('product_groups', 'slug')->ignore($group?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_visible' => ['boolean'],
            'seo_title' => ['nullable', 'string', 'max:120'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'seo_hidden' => ['boolean'],
        ]) + ['sort_order' => 0];
    }
}
