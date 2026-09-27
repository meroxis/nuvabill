<?php

namespace App\Http\Resources\Api;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->product_group_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type->value,
            'description' => $this->description,
            'visible' => (bool) $this->is_visible,
            'taxable' => (bool) $this->taxable,
            'prices' => $this->whenLoaded('prices', fn () => $this->prices->map(fn ($price): array => [
                'currency' => $price->currency,
                'billing_cycle' => $price->billing_cycle->value,
                'price' => $price->price,
                'setup_fee' => $price->setup_fee,
            ])),
        ];
    }
}
