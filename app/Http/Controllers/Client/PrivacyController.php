<?php

namespace App\Http\Controllers\Client;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\TicketDepartment;
use App\Support\Activity;
use App\Support\ClientPrivacy;
use App\Support\Locales;
use App\Support\TicketDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Clients download everything the company keeps about them, and ask for it to be erased. Erasing
 * is done by staff, who first close services and settle invoices.
 */
class PrivacyController extends Controller
{
    public function export(Request $request, ClientPrivacy $privacy): StreamedResponse
    {
        $client = $request->user('web');
        Activity::log('client.data_exported', 'Client downloaded their data', $client);
        $data = $privacy->export($client);

        return response()->streamDownload(function () use ($data): void {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, 'my-data-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function requestErasure(Request $request, TicketDesk $desk): RedirectResponse
    {
        $client = $request->user('web');
        // Written in the site's default language, for staff.
        [$subject, $message] = Locales::in(Locales::default(), fn (): array => [
            __('Please erase my personal data'),
            __('Please erase my personal data. I understand that invoices and payments stay, because the law requires them.'),
        ]);
        $open = $client->tickets()->where('subject', $subject)->where('status', '!=', TicketStatus::Closed)->first();

        if ($open !== null) {
            return redirect()->route('client.tickets.show', $open)->with('status', __('We already have your request. We answer here.'));
        }

        $department = TicketDepartment::query()->orderBy('sort_order')->orderBy('id')->firstOrFail();
        $ticket = $desk->open(
            $client,
            $department,
            $subject,
            $message,
        );

        return redirect()->route('client.tickets.show', $ticket)->with('status', __('Request sent. We answer in this ticket.'));
    }
}
