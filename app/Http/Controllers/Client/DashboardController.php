<?php

namespace App\Http\Controllers\Client;

use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\TldPrice;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $client = $request->user('web');
        $unpaidInvoices = $client->invoices()->where('status', InvoiceStatus::Unpaid)->orderBy('due_at')->get();

        return view('theme::client.dashboard', [
            'client' => $client,
            'country' => Countries::all()[$client->country] ?? null,
            'services' => $client->services()
                ->with('product')
                ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending])
                ->latest('id')
                ->limit(6)
                ->get(),
            'domains' => $client->domains()
                ->whereIn('status', [DomainStatus::Active, DomainStatus::Pending, DomainStatus::PendingTransfer, DomainStatus::Expired])
                ->orderByRaw('expires_at IS NULL, expires_at')
                ->limit(3)
                ->get(),
            'domainCount' => $client->domains()->whereNotIn('status', [DomainStatus::Cancelled, DomainStatus::TransferredAway])->count(),
            'recentInvoices' => $client->invoices()->where('status', '!=', InvoiceStatus::Draft)->latest('issued_at')->latest('id')->limit(3)->get(),
            'unpaidInvoices' => $unpaidInvoices,
            'unpaidTotal' => $unpaidInvoices->sum(fn ($invoice): int => $invoice->balance()),
            'openTickets' => $client->tickets()->where('status', '!=', TicketStatus::Closed)->latest('last_reply_at')->limit(3)->get(),
            'openTicketCount' => $client->openTicketsCount(),
            'sellsDomains' => TldPrice::query()->where('is_enabled', true)->exists(),
            'twoFactorReminder' => setting('security.client_two_factor') !== 'off' && ! $client->hasTwoFactorEnabled(),
        ]);
    }
}
