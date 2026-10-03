<?php

namespace App\Http\Requests\Admin;

use App\Auth\ClientRegistrar;
use App\Enums\ClientStatus;
use App\Models\Client;
use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Staff creating or editing a client.
 */
class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Client|null $client */
        $client = $this->route('client');

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ClientRegistrar::emailRules($client?->id),
            'phone' => ['nullable', 'string', 'max:40'],
            'address_1' => ['nullable', 'string', 'max:190'],
            'address_2' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', Rule::in(array_keys(Countries::all()))],
            'status' => ['required', Rule::enum(ClientStatus::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:700'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'tax_exempt' => ['sometimes', 'boolean'],
            'password' => ['nullable', Password::min(8)],
            'send_welcome' => ['sometimes', 'boolean'],
        ];
    }
}
