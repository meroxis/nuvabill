<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Models\NetworkIncident;
use App\Models\Server;
use App\Seo\Sitemap;
use App\Support\Activity;
use App\Support\NetworkStatus;
use App\Support\ServerMonitor;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Staff post network issues and planned maintenance, with updates as work goes on, and choose
 * which servers the public status page shows.
 */
class NetworkStatusController extends Controller
{
    public function index(NetworkStatus $status): View
    {
        $servers = Server::query()->orderBy('name')->get();

        return view('admin.network.index', [
            'servers' => $servers,
            'uptime' => Server::uptime($servers->modelKeys()),
            'open' => $status->open(),
            'recent' => NetworkIncident::query()->whereNotNull('resolved_at')->latest('resolved_at')->limit(15)->get(),
        ]);
    }

    public function settings(Request $request, Settings $settings): RedirectResponse
    {
        foreach (['enabled', 'checks', 'alerts'] as $key) {
            $settings->set("status.{$key}", $request->boolean($key));
        }

        Sitemap::forget();

        return back()->with('status', __('Saved.'));
    }

    /**
     * Which servers the status page shows, and the name it shows for each.
     */
    public function servers(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'servers' => ['array'],
            'servers.*.public' => ['boolean'],
            'servers.*.name' => ['nullable', 'string', 'max:100'],
        ]);

        foreach (Server::query()->whereIn('id', array_map('intval', array_keys($data['servers'] ?? [])))->get() as $server) {
            $row = $data['servers'][$server->id] ?? [];
            $server->update(['status_public' => (bool) ($row['public'] ?? false), 'status_name' => filled($row['name'] ?? null) ? $row['name'] : null]);
        }

        return back()->with('status', __('Saved.'));
    }

    public function check(ServerMonitor $monitor): RedirectResponse
    {
        $result = $monitor->checkAll();

        return back()->with('status', trans_choice('Checked :count server.|Checked :count servers.', $result['checked'], ['count' => $result['checked']]));
    }

    public function create(Request $request): View
    {
        $kind = $request->query('kind') === NetworkIncident::KIND_MAINTENANCE ? NetworkIncident::KIND_MAINTENANCE : NetworkIncident::KIND_ISSUE;

        return view('admin.network.form', [
            'incident' => new NetworkIncident(['kind' => $kind, 'impact' => NetworkIncident::IMPACT_MINOR, 'starts_at' => $kind === NetworkIncident::KIND_ISSUE ? now() : null]),
            'servers' => Server::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $kind = $request->input('kind') === NetworkIncident::KIND_MAINTENANCE ? NetworkIncident::KIND_MAINTENANCE : NetworkIncident::KIND_ISSUE;
        $status = $request->validate(['status' => ['required', Rule::in(array_map(fn (IncidentStatus $case): string => $case->value, IncidentStatus::forKind($kind)))]])['status'];
        $message = $request->validate(['message' => ['required', 'string', 'max:5000']])['message'];

        $incident = DB::transaction(function () use ($data, $kind, $status, $message, $request): NetworkIncident {
            $incident = NetworkIncident::query()->create([...$data, 'kind' => $kind, 'status' => $status, 'resolved_at' => IncidentStatus::from($status)->isClosed() ? now() : null]);
            $incident->updates()->create(['admin_id' => $request->user('admin')->id, 'status' => $status, 'message' => $message]);

            return $incident;
        });

        Activity::log('network.posted', "Network note {$incident->title} posted");

        return redirect()->route('admin.network.incidents.show', $incident)->with('status', __('Posted. Clients see it on the network status page.'));
    }

    public function show(NetworkIncident $incident): View
    {
        $incident->load('updates.admin');

        return view('admin.network.show', [
            'incident' => $incident,
            'servers' => Server::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(Request $request, NetworkIncident $incident): RedirectResponse
    {
        $incident->update($this->validated($request));

        return back()->with('status', __('Saved.'));
    }

    /**
     * A new note on the timeline, which also moves the status on.
     */
    public function addUpdate(Request $request, NetworkIncident $incident): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (IncidentStatus $case): string => $case->value, IncidentStatus::forKind($incident->kind)))],
            'message' => ['required', 'string', 'max:5000'],
        ]);
        $status = IncidentStatus::from($data['status']);

        DB::transaction(function () use ($incident, $status, $data, $request): void {
            $incident->updates()->create(['admin_id' => $request->user('admin')->id, 'status' => $status, 'message' => $data['message']]);
            $incident->update(['status' => $status, 'resolved_at' => $status->isClosed() ? ($incident->resolved_at ?? now()) : null]);
        });

        return back()->with('status', __('Update posted.'));
    }

    public function destroy(NetworkIncident $incident): RedirectResponse
    {
        $incident->delete();
        Activity::log('network.deleted', "Network note {$incident->title} deleted");

        return redirect()->route('admin.network.index')->with('status', __('Deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'impact' => ['required', Rule::in([NetworkIncident::IMPACT_MINOR, NetworkIncident::IMPACT_MAJOR])],
            'server_ids' => ['nullable', 'array'],
            'server_ids.*' => ['integer', 'exists:servers,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        return [
            'title' => $data['title'],
            'impact' => $data['impact'],
            'server_ids' => array_values(array_unique(array_map('intval', $data['server_ids'] ?? []))) ?: null,
            'starts_at' => filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at']) : null,
            'ends_at' => filled($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at']) : null,
        ];
    }
}
