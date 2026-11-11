<?php

namespace App\Http\Controllers\Client;

use App\Billing\PlanChanges;
use App\Contracts\HasPanelSections;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\StandardSections;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
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
        $panel = $provisioner->serverPanel($service, ClientContext::fromRequest($request, $service));

        return view('theme::client.services.show', [
            'service' => $service,
            'canChangePlan' => $changes->enabled() && $service->status === ServiceStatus::Active && $changes->targets($service)->isNotEmpty(),
            'pendingChange' => $changes->enabled() ? $changes->pendingFor($service) : null,
            // A server panel with sections has its own sign-in button in its header.
            'canLogin' => ! isset($panel['sections']) && filled($service->product->server_module) && $service->server !== null && $service->username !== null && $provisioner->hasLoginLink($service),
            'panel' => $panel,
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

        // A module that signs in with the client's context gets it; others give their loginUrl().
        $url = $provisioner->signInUrl($service, ClientContext::fromRequest($request, $service));

        $response = $url !== null ? redirect()->away($url) : $this->loginUnavailable($service);

        return $response->withHeaders(self::PRIVATE_HEADERS);
    }

    /**
     * An action from the module's own panel, for example "restart" on a VPS. The panel's buttons
     * ask for JSON: they get the message, the server's new state and, for an action that shows
     * something once (such as VNC details), that value. Such a value is only ever in a JSON answer
     * that is not cached: never in the session or the page.
     */
    public function panel(Request $request, Service $service, string $action, Provisioner $provisioner): RedirectResponse|JsonResponse
    {
        $this->authorizeOwner($request, $service);

        if ($provisioner->productSupports($service->loadMissing('product')->product, HasPanelSections::class)) {
            return $this->sectionAction($request, $service, $action, $provisioner);
        }

        $result = $provisioner->clientAction($service, $action, $request->except('_token'));
        $pending = $result->success && in_array($result->data['pending'] ?? null, ['start', 'stop', 'restart', 'poweroff'], true) ? $result->data['pending'] : null;
        $secret = $result->shownOnce();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $result->success,
                'message' => $result->message,
                'pending' => $pending,
                'state' => $result->success ? $this->panelState($result->data['state'] ?? null) : null,
            ] + ($secret !== [] ? ['secret' => $secret] : []), $result->success ? 200 : 422)->withHeaders(self::PRIVATE_HEADERS);
        }

        // Only the page's script can show a value once, so without it the value is dropped here.
        if ($secret !== []) {
            return back()->with('error', __('Turn on JavaScript to do this.'));
        }

        // The session gets the power action that is still running, and nothing else the module gave.
        return back()
            ->with($result->success ? 'status' : 'error', $result->message)
            ->with('panel_result', $result->success ? array_filter(['pending' => $pending]) : null);
    }

    /**
     * An old panel action ("restart", "hostname" and so on) for a module with panel sections: it is
     * mapped to its section action (StandardSections::LEGACY_ACTIONS) and goes through the panel gate,
     * so the product switches, input rules and limits apply here too. The answer keeps the old shape;
     * a secret is only ever in a JSON answer, never in the session.
     */
    private function sectionAction(Request $request, Service $service, string $action, Provisioner $provisioner): RedirectResponse|JsonResponse
    {
        $target = StandardSections::LEGACY_ACTIONS[$action] ?? null;
        $input = $request->except('_token');

        // The old reinstall form named the template os_id, and the old forms had no field to type a
        // new password twice. A destructive action still needs its own ticked confirm box.
        if ($target === 'reinstall.run' && ! array_key_exists('template', $input) && array_key_exists('os_id', $input)) {
            $input['template'] = $input['os_id'];
        }

        if (in_array($target, ['settings.root-password', 'reinstall.run'], true) && array_key_exists('password', $input) && ! array_key_exists('password_confirmation', $input)) {
            $input['password_confirmation'] = $input['password'];
        }

        $result = $target === null
            ? PanelResult::fail(__('This action is not available.'), null, 404)
            : $provisioner->panelAction($service, Str::before($target, '.'), Str::after($target, '.'), $input, ClientContext::fromRequest($request, $service), $request->expectsJson());

        if ($request->expectsJson()) {
            $body = [
                'ok' => $result->ok,
                'message' => $result->message,
                'pending' => $result->ok && in_array($result->pending, ['start', 'stop', 'restart', 'poweroff'], true) ? $result->pending : null,
                'state' => $result->ok ? $this->panelState($result->state) : null,
            ];

            if ($result->secret !== null) {
                $body['secret'] = $result->secret;
            }

            $headers = self::PRIVATE_HEADERS + ($result->retryAfter !== null ? ['Retry-After' => (string) $result->retryAfter] : []);

            return response()->json($body, $result->ok ? 200 : $result->status)->withHeaders($headers);
        }

        return back()
            ->with($result->ok ? 'status' : 'error', $result->message !== '' ? $result->message : __('Done.'))
            ->with('panel_result', $result->ok ? array_filter(['pending' => $result->pending]) : null);
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
