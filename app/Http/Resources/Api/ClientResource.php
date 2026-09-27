<?php

namespace App\Http\Resources\Api;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address_1' => $this->address_1,
            'address_2' => $this->address_2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'tax_id' => $this->tax_id,
            'tax_exempt' => (bool) $this->tax_exempt,
            'wallet_balance' => $this->credit,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
