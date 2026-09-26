<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(Request $request): View
    {
        return view('theme::client.services.index', [
            'services' => $request->user('web')->services()->with('product')->latest('id')->paginate(20),
        ]);
    }

    public function show(Request $request, Service $service): View
    {
        $this->authorizeOwner($request, $service);

        $service->load('product', 'server', 'invoiceItems.invoice');

        return view('theme::client.services.show', [
            'service' => $service,
            'canLogin' => filled($service->product->server_module) && $service->server !== null && $service->username !== null,
        ]);
    }

    public function login(Request $request, Service $service, Provisioner $provisioner): RedirectResponse
    {
        $this->authorizeOwner($request, $service);

        $url = $provisioner->loginUrl($service);

        return $url !== null
            ? redirect()->away($url)
            : back()->with('error', __('The control panel link is not available right now. Try again in a minute or open a ticket.'));
    }

    private function authorizeOwner(Request $request, Service $service): void
    {
        abort_unless($service->client_id === $request->user('web')->id, 404);
    }
}
