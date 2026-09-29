<?php

namespace App\Http\Controllers\Admin;

use App\Ai\AiUnavailable;
use App\Ai\ProductWriter;
use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Write with AI" on the product page. Works from what is in the form, saved or not; the page
 * fills the fields and staff save them as usual.
 */
class ProductAiController extends Controller
{
    public function write(Request $request, ProductWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'product_group_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:5000'],
            'instruction' => ['nullable', 'string', 'max:500'],
            'product' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        try {
            return response()->json($writer->write([
                'name' => $data['name'],
                'group' => (string) ProductGroup::query()->whereKey($data['product_group_id'] ?? 0)->value('name'),
                'type' => ProductType::tryFrom((string) ($data['type'] ?? ''))?->label() ?? '',
                'description' => (string) ($data['description'] ?? ''),
                'instruction' => $data['instruction'] ?? null,
            ], isset($data['product']) ? Product::query()->find($data['product']) : null));
        } catch (AiUnavailable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
