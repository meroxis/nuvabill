<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\Activity;
use App\Support\ClientPrivacy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A client's data as a file, and erasing it when they ask (GDPR and similar laws).
 */
class ClientPrivacyController extends Controller
{
    public function export(Client $client, ClientPrivacy $privacy): StreamedResponse
    {
        Activity::log('client.data_exported', "Data of client #{$client->id} downloaded", $client);
        $data = $privacy->export($client, forStaff: true);

        return response()->streamDownload(function () use ($data): void {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, "client-{$client->id}-data.json", ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function erase(Request $request, Client $client, ClientPrivacy $privacy): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:ERASE']], ['confirm.in' => __('Type ERASE to confirm.')]);

        try {
            $privacy->erase($client, $request->user('admin'));
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.clients.show', $client)->with('status', __('Personal data erased. Invoices and payments stay, as the law requires.'));
    }
}
