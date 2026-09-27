<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Your profile → API keys: keys for the REST API that act as you.
 */
class ApiKeyController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'can_write' => ['sometimes', 'boolean'],
        ]);

        $admin = $request->user('admin');
        [$token, $plain] = ApiToken::issue($admin, $data['name'], $request->boolean('can_write'));
        Activity::log('api.key_created', "API key \"{$token->name}\" created (".($token->can_write ? 'read and write' : 'read only').')', actor: $admin);

        return back()->with('new_api_key', $plain)->with('status', __('API key created. Copy it now: it is shown only once.'));
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        abort_unless($apiToken->admin_id === $request->user('admin')->id, 404);

        $apiToken->delete();
        Activity::log('api.key_deleted', "API key \"{$apiToken->name}\" deleted");

        return back()->with('status', __('API key deleted. Anything using it stops working now.'));
    }
}
