<?php

namespace App\Http\Controllers\Admin;

use App\Auth\Social\SocialLogin;
use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings → Social login: let clients sign in with Google, GitHub or Facebook.
 */
class SocialLoginController extends Controller
{
    public function edit(SocialLogin $social): View
    {
        $providers = [];

        foreach (array_keys(SocialLogin::PROVIDERS) as $slug) {
            $config = $social->config($slug);

            $providers[$slug] = [
                'provider' => $social->describe($slug),
                'enabled' => $config['enabled'],
                'client_id' => $config['client_id'],
                'has_secret' => $config['client_secret'] !== '',
                'callback' => SocialLogin::callbackUrl($slug),
            ];
        }

        return view('admin.settings.social', ['providers' => $providers]);
    }

    public function update(Request $request, SocialLogin $social, Settings $settings): RedirectResponse
    {
        $slugs = array_keys(SocialLogin::PROVIDERS);
        $rules = [];

        foreach ($slugs as $slug) {
            $rules[$slug.'.enabled'] = ['boolean'];
            $rules[$slug.'.client_id'] = ['nullable', 'string', 'max:255', 'required_if_accepted:'.$slug.'.enabled'];
            $rules[$slug.'.client_secret'] = ['nullable', 'string', 'max:255'];
        }

        $data = $request->validate($rules);
        $values = [];

        foreach ($slugs as $slug) {
            $saved = $social->config($slug);
            $secret = filled($data[$slug]['client_secret'] ?? null) ? $data[$slug]['client_secret'] : $saved['client_secret'];
            $enabled = (bool) ($data[$slug]['enabled'] ?? false);

            if ($enabled && $secret === '') {
                return back()->withInput()->withErrors([$slug.'.client_secret' => __('Enter the client secret to switch this on.')]);
            }

            $values['social.'.$slug] = [
                'enabled' => $enabled,
                'client_id' => trim((string) ($data[$slug]['client_id'] ?? '')),
                'client_secret' => trim($secret),
            ];
        }

        $settings->setMany($values);
        Activity::log('settings.social', 'Social login settings changed');

        return back()->with('status', __('Social login saved.'));
    }
}
