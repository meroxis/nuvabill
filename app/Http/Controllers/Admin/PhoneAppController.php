<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PushSubscription;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Push\StaffAlerts;
use App\Push\WebPush;
use App\Support\Activity;
use App\Support\AttentionList;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The admin area as an app on staff phones: the install file and the service worker that shows
 * push alerts, the Today screen the app opens on, and each staff member's phones and alerts.
 */
class PhoneAppController extends Controller
{
    /**
     * What the phone needs to install the admin area as an app. Public: browsers fetch it without
     * the sign-in cookie.
     */
    public function manifest(): JsonResponse
    {
        $company = (string) setting('company.name');
        $icon = fn (string $file, string $size, ?string $purpose = null): array => array_filter([
            'src' => asset('images/app/'.$file),
            'sizes' => $size,
            'type' => 'image/png',
            'purpose' => $purpose,
        ]);

        return response()->json([
            'id' => route('admin.today', absolute: false),
            'name' => __(':company admin', ['company' => $company]),
            'short_name' => $company !== '' ? $company : 'Nuvabill',
            'start_url' => route('admin.today', absolute: false),
            'scope' => self::scope(),
            'display' => 'standalone',
            'background_color' => '#0e2b47',
            'theme_color' => '#0e2b47',
            'icons' => [
                $icon('icon-192.png', '192x192', 'any'),
                $icon('icon-512.png', '512x512', 'any'),
                $icon('maskable-512.png', '512x512', 'maskable'),
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Shows push alerts and opens the page an alert points to. It keeps no admin pages on the
     * phone; without a connection it only shows a short "you are offline" page.
     */
    public function serviceWorker(): Response
    {
        return response()->view('admin.phone.service-worker', [
            'scope' => self::scope(),
            'start' => route('admin.today', absolute: false),
            'icon' => asset('images/app/icon-192.png'),
            'badge' => asset('images/app/badge-96.png'),
            'version' => (string) config('nuvabill.version'),
            'offline' => view('admin.phone.offline')->render(),
        ], 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => self::scope(),
        ]);
    }

    public function today(Request $request): View
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $currency = (string) setting('billing.currency');
        $today = now()->startOfDay();
        $waiting = [TicketStatus::Open, TicketStatus::CustomerReply];
        $numbers = [];

        if ($admin->hasPermission('orders.manage')) {
            $orders = Order::query()->where('created_at', '>=', $today);
            $numbers[] = [
                'label' => __('New orders'),
                'value' => (string) (clone $orders)->count(),
                'note' => money((int) (clone $orders)->where('currency', $currency)->sum('total'), $currency),
                'url' => route('admin.orders.index'),
            ];
        }

        if ($admin->hasPermission('billing.view')) {
            $payments = Transaction::query()->where('type', 'payment')->where('currency', $currency)->where('paid_at', '>=', $today);
            $invoicesPaid = (clone $payments)->whereNotNull('invoice_id')->distinct()->count('invoice_id');
            $numbers[] = [
                'label' => __('Payments'),
                'value' => money((int) (clone $payments)->sum('amount'), $currency),
                'note' => trans_choice(':count invoice paid|:count invoices paid', $invoicesPaid, ['count' => $invoicesPaid]),
                'url' => route('admin.invoices.index', ['status' => 'paid']),
            ];
        }

        if ($admin->hasPermission('support.manage')) {
            $open = Ticket::query()->whereIn('status', $waiting);
            $late = (clone $open)->where('last_reply_at', '<', now()->subHours(3))->count();
            $numbers[] = [
                'label' => __('Open tickets'),
                'value' => (string) (clone $open)->count(),
                'note' => $late > 0 ? trans_choice(':count waiting over 3 hours|:count waiting over 3 hours', $late, ['count' => $late]) : __('None waiting long'),
                'tone' => $late > 0 ? 'warn' : null,
                'url' => route('admin.tickets.index'),
            ];
        }

        if ($admin->hasPermission('billing.view')) {
            $overdue = Invoice::query()->where('status', InvoiceStatus::Unpaid)->whereDate('due_at', '<', today());
            $numbers[] = [
                'label' => __('Overdue'),
                'value' => (string) (clone $overdue)->count(),
                'note' => money((int) (clone $overdue)->where('currency', $currency)->selectRaw('coalesce(sum(total - amount_paid), 0) as balance')->value('balance'), $currency),
                'url' => route('admin.invoices.index', ['status' => 'overdue']),
            ];
        }

        $tickets = $admin->hasPermission('support.manage')
            ? Ticket::query()->with('client')->whereIn('status', $waiting)->orderBy('last_reply_at')->limit(3)->get()
            : collect();
        $orders = $admin->hasPermission('orders.manage')
            ? Order::query()->with('client')->where('status', OrderStatus::Pending)->where('needs_review', true)->latest('id')->limit(3)->get()
            : collect();
        // Risky orders and overdue invoices are already on this screen.
        $attention = array_values(array_filter(AttentionList::items(), fn (array $item): bool => ! in_array($item['key'], ['orders.review', 'orders.pending', 'invoices.overdue'], true)));

        return view('admin.today', [
            'numbers' => $numbers,
            'tickets' => $tickets,
            'orders' => $orders,
            'attention' => $attention,
            'alerts' => $this->alertNames($admin),
            'devices' => $admin->pushSubscriptions()->count(),
            'pushKey' => rescue(fn (): string => app(WebPush::class)->publicKey(), '', report: false),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:40'],
        ]);

        if (! WebPush::isPushEndpoint($data['endpoint']) || strlen(WebPush::decode($data['keys']['p256dh'])) !== 65 || strlen(WebPush::decode($data['keys']['auth'])) !== 16) {
            return response()->json(['message' => __('This browser cannot get alerts from Nuvabill.')], 422);
        }

        /** @var Admin $admin */
        $admin = $request->user('admin');

        // A phone that another staff member used before now alerts only whoever turned it on last.
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => PushSubscription::hashOf($data['endpoint'])], [
            'admin_id' => $admin->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'device' => self::deviceName((string) $request->userAgent()),
        ]);
        Activity::log('admin.push_on', "{$admin->name} turned on phone alerts on ".self::deviceName((string) $request->userAgent()), actor: $admin);

        return response()->json(['message' => __('Alerts are on for this device.')]);
    }

    /**
     * "Turn off on this device": the page knows its push address, not the row.
     */
    public function forget(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');
        $request->user('admin')->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashOf($endpoint))->delete();

        return response()->json(['message' => __('Alerts are off for this device.')]);
    }

    public function destroy(Request $request, PushSubscription $pushSubscription): RedirectResponse
    {
        abort_unless($pushSubscription->admin_id === $request->user('admin')->id, 404);
        $pushSubscription->delete();

        return back()->with('status', __('That device will not get alerts anymore.'));
    }

    public function test(Request $request, WebPush $push): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $message = StaffAlerts::message('test', ['url' => route('admin.today'), 'tag' => 'test'], app()->getLocale());
        $sent = 0;

        foreach ($admin->pushSubscriptions as $subscription) {
            $sent += rescue(fn (): bool => $push->send($subscription, $message), false, report: false) ? 1 : 0;
        }

        return back()->with($sent > 0 ? 'status' : 'error', $sent > 0
            ? trans_choice('A test alert went to :count device.|A test alert went to :count devices.', $sent, ['count' => $sent])
            : __('No device could get the test alert. Turn alerts on again on your phone.'));
    }

    public function alerts(Request $request): RedirectResponse
    {
        $data = $request->validate(['payments_over' => ['nullable', 'numeric', 'min:0', 'max:1000000000']]);

        $request->user('admin')->forceFill(['push_alerts' => [
            'orders' => $request->boolean('orders'),
            'payments' => $request->boolean('payments'),
            'payments_over' => Money::toMinor($data['payments_over'] ?? 0),
            'tickets' => $request->boolean('tickets'),
            'replies' => $request->boolean('replies'),
        ]])->save();

        return back()->with('status', __('Your alerts are saved.'));
    }

    /**
     * Where the app lives: the admin area, also when Nuvabill runs in a folder.
     */
    public static function scope(): string
    {
        return rtrim(route('admin.dashboard', absolute: false), '/').'/';
    }

    /**
     * "iPhone · Safari", so staff can tell their devices apart.
     */
    public static function deviceName(string $userAgent): string
    {
        $device = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => __('Device'),
        };
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox') || str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'Chrome') || str_contains($userAgent, 'CriOS') => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => null,
        };

        return $browser ? $device.' · '.$browser : $device;
    }

    /**
     * @return list<string>
     */
    private function alertNames(Admin $admin): array
    {
        $alerts = $admin->pushAlerts();
        $currency = (string) setting('billing.currency');

        return array_values(array_filter([
            $alerts['orders'] ? __('new orders') : null,
            $alerts['payments'] ? ($alerts['payments_over'] > 0 ? __('payments of :amount or more', ['amount' => money($alerts['payments_over'], $currency)]) : __('payments')) : null,
            $alerts['tickets'] ? __('new tickets') : null,
            $alerts['replies'] ? __('client replies') : null,
        ]));
    }
}
