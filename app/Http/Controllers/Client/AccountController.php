<?php

namespace App\Http\Controllers\Client;

use App\Auth\Social\SocialLogin;
use App\Chat\LinkCodes;
use App\Chat\Telegram;
use App\Chat\WhatsApp;
use App\Http\Controllers\Controller;
use App\Models\ChatLink;
use App\Models\Client;
use App\Security\EmailCode;
use App\Security\Totp;
use App\Support\Activity;
use App\Support\Countries;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function edit(Request $request, SocialLogin $social, EmailCode $codes): View
    {
        $client = $request->user('web');
        $settingUpApp = $client->two_factor_method === Client::TWO_FACTOR_APP && $client->two_factor_confirmed_at === null && $client->two_factor_secret !== null;

        return view('theme::client.account', [
            'client' => $client,
            'countries' => Countries::all(),
            'socialProviders' => $social->enabled(),
            'socialAccounts' => $client->socialAccounts()->get()->keyBy('provider'),
            'passkeys' => $client->passkeys()->latest('id')->get(),
            'twoFactor' => [
                'mode' => (string) setting('security.client_two_factor'),
                'methods' => (array) setting('security.client_two_factor_methods'),
                'settingUpApp' => $settingUpApp,
                'qrCode' => $settingUpApp ? Totp::qrCodeSvg(Totp::provisioningUri((string) $client->two_factor_secret, $client->email, (string) setting('company.name'))) : null,
                'emailPending' => ! $client->hasTwoFactorEnabled() && $codes->isPending($client, 'setup'),
                'recoveryCodes' => session('recovery_codes'),
            ],
            'chat' => $this->chatApps($client),
        ]);
    }

    /**
     * Disconnect a Telegram chat or WhatsApp number from the account.
     */
    public function disconnectChat(Request $request, ChatLink $chatLink): RedirectResponse
    {
        abort_unless($chatLink->client_id === $request->user('web')->id, 404);
        $chatLink->delete();

        return back()->with('status', __(':app is disconnected. You will not get messages there anymore.', ['app' => $chatLink->channelLabel()]));
    }

    /**
     * "Get alerts on your phone": a QR code and a link per chat app the company connected, and the
     * chats this client already linked. Null when the company uses no chat app.
     *
     * @return array{apps: list<array{name: string, url: string, qr: string}>, links: Collection<int, ChatLink>}|null
     */
    private function chatApps(Client $client): ?array
    {
        $telegram = app(Telegram::class);
        $whatsApp = app(WhatsApp::class);
        $links = ChatLink::query()->where('client_id', $client->id)->oldest('id')->get();

        if (! $telegram->isConnected() && ! $whatsApp->isConnected() && $links->isEmpty()) {
            return null;
        }

        $code = LinkCodes::for($client);
        $apps = [];

        if ($telegram->isConnected()) {
            $apps[] = ['name' => 'Telegram', 'url' => $telegram->link($code)];
        }

        if ($whatsApp->isConnected() && $whatsApp->number() !== '') {
            $apps[] = ['name' => 'WhatsApp', 'url' => $whatsApp->link($code)];
        }

        return [
            'apps' => array_map(fn (array $app): array => $app + ['qr' => Totp::qrCodeSvg($app['url'])], $apps),
            'links' => $links,
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        $client = $request->user('web');

        $client->update($request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', Rule::unique('clients', 'email')->ignore($client->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'address_1' => ['nullable', 'string', 'max:190'],
            'address_2' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['required', Rule::in(array_keys(Countries::all()))],
            'tax_id' => ['nullable', 'string', 'max:64'],
        ]));

        Activity::log('client.profile', 'Client updated their details', $client);

        return back()->with('status', __('Your details were saved.'));
    }

    public function password(Request $request): RedirectResponse
    {
        $client = $request->user('web');

        // Clients who signed up with Google, GitHub or Facebook choose their first password without an old one.
        $request->validate([
            'current_password' => $client->has_password ? ['required', 'current_password:web'] : ['nullable'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $client->forceFill(['password' => $request->input('password'), 'has_password' => true])->save();

        return back()->with('status', __('Password saved.'));
    }
}
