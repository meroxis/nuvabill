<?php

namespace App\Auth;

use App\Billing\Affiliates;
use App\Enums\ClientStatus;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Support\Activity;
use App\Support\Countries;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creates client accounts, for the sign-up page and for order forms that sign up and order on one page.
 */
class ClientRegistrar
{
    public function __construct(private TemplateMailer $mailer) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $confirmPassword = true): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:clients,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country' => ['required', Rule::in(array_keys(Countries::all()))],
            'password' => array_values(array_filter(['required', $confirmPassword ? 'confirmed' : null, Password::min(8)])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Validated with rules().
     */
    public function register(array $data): Client
    {
        $client = Client::create(array_intersect_key($data, array_flip(['first_name', 'last_name', 'company_name', 'email', 'phone', 'country', 'password'])) + [
            'currency' => setting('billing.currency'),
            'status' => ClientStatus::Active,
        ]);

        Activity::log('client.registered', "{$client->name} created an account", $client, $client, $client);
        app(Affiliates::class)->recordReferral($client, request()->cookie(Affiliates::COOKIE));
        $this->mailer->send('client.welcome', $client, ['login_url' => route('client.login'), 'reset_url' => route('client.password.request')]);

        return $client;
    }
}
