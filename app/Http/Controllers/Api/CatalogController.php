<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\OrderResource;
use App\Http\Resources\Api\ProductResource;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Smaller API endpoints: who the key belongs to, products and orders.
 */
class CatalogController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $admin = $request->user('admin');
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        return response()->json([
            'staff' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email],
            'key' => ['name' => $token->name, 'can_write' => $token->can_write],
            'nuvabill' => config('nuvabill.version'),
        ]);
    }

    public function products(): AnonymousResourceCollection
    {
        return ProductResource::collection(Product::query()->with('prices')->orderBy('sort_order')->orderBy('id')->get());
    }

    public function orders(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->when($request->integer('client_id'), fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return OrderResource::collection($orders);
    }
}
