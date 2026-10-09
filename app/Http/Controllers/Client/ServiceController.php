<?php

namespace App\Http\Controllers\Client;

use App\Billing\PlanChanges;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ServiceController extends Controller
{
    /**
     * Headers for answers with a one-time panel link or live server details.
     */
    private const PRIVATE_HEADERS = ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];

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

    /**
     * Sends the client to their control panel. A one-time link is a secret, so these answers are
     * never cached and do not pass this page's address on to the panel.
     */
    public function login(Request $request, Service $service, Provisioner $provisioner): RedirectResponse|Response
    {
        $this->authorizeOwner($request, $service);

        // Panels that sign in with a form get a page that sends it from the client's browser.
        if ($provisioner->usesLoginForm($service)) {
            $form = $provisioner->loginForm($service);

            return $form !== null
                ? response()->view('theme::client.services.panel-login', ['form' => $form, 'service' => $service])->withHeaders(self::PRIVATE_HEADERS)
                : $this->loginUnavailable($service);
        }

        $url = $provisioner->loginUrl($service);

        $response = $url !== null ? redirect()->away($url) : $this->loginUnavailable($service);

        return $response->withHeaders(self::PRIVATE_HEADERS);
    }

    /**
     * An action from the module's own panel, for example "restart" on a VPS. The panel's power
     * buttons ask for JSON: they get the message and the server's new state, never other data.
     */
    public function panel(Request $request, Service $service, string $action, Provisioner $provisioner): RedirectResponse|JsonResponse
    {
        $this->authorizeOwner($request, $service);

        $result = $provisioner->clientAction($service, $action, $request->except('_token'));

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $result->success,
                'message' => $result->message,
                'pending' => $result->success && in_array($result->data['pending'] ?? null, ['start', 'stop', 'restart', 'poweroff'], true) ? $result->data['pending'] : null,
                'state' => $result->success ? $this->panelState($result->data['state'] ?? null) : null,
            ], $result->success ? 200 : 422)->withHeaders(self::PRIVATE_HEADERS);
        }

        return back()
            ->with($result->success ? 'status' : 'error', $result->message)
            ->with('panel_result', $result->success ? $result->data : null);
    }

    /**
     * The live state of the module's panel, for the panel's checks after a power action until
     * the server gets there.
     */
    public function panelStatus(Request $request, Service $service, Provisioner $provisioner): JsonResponse
    {
        $this->authorizeOwner($request, $service);

        $state = $provisioner->clientPanelState($service);

        abort_if($state === null, 404);

        return response()->json(['state' => $this->panelState($state)])->withHeaders(self::PRIVATE_HEADERS);
    }

    /**
     * running, stopped, suspended, or unknown for anything else.
     */
    private function panelState(mixed $state): string
    {
        return in_array($state, ['running', 'stopped', 'suspended'], true) ? $state : 'unknown';
    }

    /**
     * Back to the service page with the reason. The sign-in opens in a new tab without a Referer,
     * so "back" could be any page the session saw last, even a background status check.
     */
    private function loginUnavailable(Service $service): RedirectResponse
    {
        return redirect()->route('client.services.show', $service)
            ->with('error', __('The control panel link is not available right now. Try again in a minute or open a ticket.'));
    }

    private function authorizeOwner(Request $request, Service $service): void
    {
        abort_unless($service->client_id === $request->user('web')->id, 404);
    }
}
