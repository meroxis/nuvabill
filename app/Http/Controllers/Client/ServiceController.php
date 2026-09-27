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

    public function show(Request $request, Service $service, Provisioner $provisioner): View
    {
        $this->authorizeOwner($request, $service);

        $service->load('product', 'server', 'invoiceItems.invoice', 'addons', 'coupon');

        return view('theme::client.services.show', [
            'service' => $service,
            'canLogin' => filled($service->product->server_module) && $service->server !== null && $service->username !== null && $provisioner->hasLoginLink($service),
            'panel' => $provisioner->clientPanel($service),
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

    /**
     * An action from the module's own panel, for example "restart" on a VPS.
     */
    public function panel(Request $request, Service $service, string $action, Provisioner $provisioner): RedirectResponse
    {
        $this->authorizeOwner($request, $service);

        $result = $provisioner->clientAction($service, $action, $request->except('_token'));

        return back()
            ->with($result->success ? 'status' : 'error', $result->message)
            ->with('panel_result', $result->success ? $result->data : null);
    }

    private function authorizeOwner(Request $request, Service $service): void
    {
        abort_unless($service->client_id === $request->user('web')->id, 404);
    }
}
