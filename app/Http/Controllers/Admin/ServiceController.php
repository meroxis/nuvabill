<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(Request $request): View
    {
        $status = ServiceStatus::tryFrom((string) $request->query('status'));

        return view('admin.services.index', [
            'services' => Service::query()
                ->with('client', 'product')
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($request->query('q'), fn ($query, string $term) => $query->where(fn ($query) => $query
                    ->where('domain', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%")))
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
            'status' => $status,
        ]);
    }

    public function show(Service $service): View
    {
        $service->load('client', 'product', 'server', 'order', 'invoiceItems.invoice', 'addons', 'coupon');

        return view('admin.services.show', [
            'service' => $service,
            'servers' => Server::query()->where('module', $service->product->server_module)->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:190'],
            'username' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:190'],
            'server_id' => ['nullable', 'exists:servers,id'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'recurring_amount' => ['required', 'numeric', 'min:0'],
            'next_due_date' => ['nullable', 'date'],
            'status' => ['required', Rule::enum(ServiceStatus::class)],
        ]);

        $data['recurring_amount'] = Money::toMinor($data['recurring_amount']);

        if (blank($data['password'])) {
            unset($data['password']);
        }

        $service->update($data);
        Activity::log('service.updated', "Service #{$service->id} details changed by staff", $service);

        return back()->with('status', __('Service saved. Only the billing record changed; use the buttons above to change the account on the server.'));
    }

    public function module(Request $request, Service $service, string $action, Provisioner $provisioner): RedirectResponse
    {
        $result = match ($action) {
            'create' => $provisioner->create($service),
            'suspend' => $provisioner->suspend($service, (string) ($request->input('reason') ?: __('Suspended by staff'))),
            'unsuspend' => $provisioner->unsuspend($service),
            'terminate' => $provisioner->terminate($service),
            'change-package' => $provisioner->changePackage($service),
        };

        return back()->with($result->success ? 'status' : 'error', $result->message);
    }
}
