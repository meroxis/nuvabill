<?php

namespace App\Http\Controllers\Client;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $client = $request->user('web');

        return view('theme::client.dashboard', [
            'client' => $client,
            'services' => $client->services()
                ->with('product')
                ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending])
                ->latest('id')
                ->limit(6)
                ->get(),
            'unpaidInvoices' => $client->invoices()->where('status', InvoiceStatus::Unpaid)->orderBy('due_at')->limit(5)->get(),
            'openTickets' => $client->tickets()->where('status', '!=', TicketStatus::Closed)->latest('last_reply_at')->limit(3)->get(),
            'unpaidTotal' => $client->unpaidInvoicesTotal(),
        ]);
    }
}
