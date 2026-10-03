<?php

namespace App\Http\Controllers\Api;

use App\Auth\ClientRegistrar;
use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ClientResource;
use App\Models\Client;
use App\Support\Activity;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $clients = Client::query()
            ->when($request->query('email'), fn ($query, $email) => $query->where('email', $email))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return ClientResource::collection($clients);
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client);
    }

    public function store(Request $request): ClientResource
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ClientRegistrar::emailRules(),
            'phone' => ['nullable', 'string', 'max:40'],
            'address_1' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', Rule::in(array_keys(Countries::all()))],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', Password::min(8)],
        ]);

        $client = Client::create($data + [
            'password' => $data['password'] ?? Str::random(40),
            'currency' => setting('billing.currency'),
            'status' => ClientStatus::Active,
        ]);

        Activity::log('client.created', "Client {$client->name} created through the API", $client, $client);

        return new ClientResource($client);
    }
}
