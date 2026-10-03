<?php

namespace App\Http\Controllers\Client;

use App\Billing\PlanChanges;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        // Drafts are not issued yet, so the client does not see them.
        $service->load([
            'product', 'server', 'addons', 'coupon',
            'invoiceItems' => fn ($query) => $query->whereHas('invoice', fn ($invoice) => $invoice->where('status', '!=', InvoiceStatus::Draft)),
            'invoiceItems.invoice',
        ]);
        $changes = app(PlanChanges::class);

        return view('theme::client.services.show', [
            'service' => $service,
            'canChangePlan' => $changes->enabled() && $service->status === ServiceStatus::Active && $changes->targets($service)->isNotEmpty(),
            'pendingChange' => $changes->enabled() ? $changes->pendingFor($service) : null,
            'canLogin' => filled($service->product->server_module) && $service->server !== null && $service->username !== null && $provisioner->hasLoginLink($service),
            'panel' => $provisioner->clientPanel($service),
        ]);
    }

    public function login(Request $request, Service $service, Provisioner $provisioner): RedirectResponse|Response
    {
        $this->authorizeOwner($request, $service);

        // Panels that sign in with a form get a page that sends it from the client's browser.
        if ($provisioner->usesLoginForm($service)) {
            $form = $provisioner->loginForm($service);

            return $form !== null
                ? response()->view('theme::client.services.panel-login', ['form' => $form, 'service' => $service])->header('Cache-Control', 'no-store, private')
                : back()->with('error', __('The control panel link is not available right now. Try again in a minute or open a ticket.'));
        }

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
