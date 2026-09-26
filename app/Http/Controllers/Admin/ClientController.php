<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClientRequest;
use App\Mail\TemplateMailer;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $status = ClientStatus::tryFrom((string) $request->query('status'));

        $clients = Client::query()
            ->search($request->query('q'))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->withCount(['services as active_services_count' => fn ($query) => $query->where('status', 'active')])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.clients.index', [
            'clients' => $clients,
            'status' => $status,
            'search' => (string) $request->query('q'),
        ]);
    }

    public function create(): View
    {
        return view('admin.clients.form', ['client' => new Client(['status' => ClientStatus::Active])]);
    }

    public function store(ClientRequest $request, TemplateMailer $mailer): RedirectResponse
    {
        $data = $request->safe()->except(['password', 'send_welcome']);

        $client = Client::create($data + [
            'password' => $request->filled('password') ? $request->input('password') : Str::password(24),
            'currency' => setting('billing.currency'),
        ]);

        Activity::log('client.created', "Client #{$client->id} {$client->name} created", $client);

        if ($request->boolean('send_welcome')) {
            $mailer->send('client.welcome', $client, ['login_url' => route('client.login'), 'reset_url' => route('client.password.request')]);
        }

        return redirect()->route('admin.clients.show', $client)->with('status', __('Client created.'));
    }

    public function show(Client $client): View
    {
        $client->load([
            'services' => fn ($query) => $query->with('product')->latest('id'),
            'invoices' => fn ($query) => $query->latest('id')->limit(10),
            'tickets' => fn ($query) => $query->with('department')->latest('updated_at')->limit(5),
            'transactions' => fn ($query) => $query->latest('paid_at')->limit(10),
        ]);

        return view('admin.clients.show', [
            'client' => $client,
            'unpaid' => $client->unpaidInvoicesTotal(),
            'activity' => ActivityLog::query()->where('client_id', $client->id)->with('actor')->latest('id')->limit(10)->get(),
        ]);
    }

    public function edit(Client $client): View
    {
        return view('admin.clients.form', ['client' => $client]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $data = $request->safe()->except(['password', 'send_welcome']);

        if ($request->filled('password')) {
            $data['password'] = $request->input('password');
        }

        $client->update($data);
        Activity::log('client.updated', "Client #{$client->id} {$client->name} updated", $client);

        return redirect()->route('admin.clients.show', $client)->with('status', __('Client saved.'));
    }
}
