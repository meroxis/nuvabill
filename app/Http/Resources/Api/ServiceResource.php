<?php

namespace App\Http\Resources\Api;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => $this->product->name),
            'domain' => $this->domain,
            'username' => $this->username,
            'status' => $this->status->value,
            'billing_cycle' => $this->billing_cycle->value,
            'currency' => $this->currency,
            'recurring_amount' => $this->recurring_amount,
            'registration_date' => $this->registration_date?->toDateString(),
            'next_due_date' => $this->next_due_date?->toDateString(),
            'suspension_reason' => $this->suspension_reason,
        ];
    }
}
