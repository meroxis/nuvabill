<?php

namespace App\Http\Controllers\Admin;

use App\Billing\PlanChanges;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PDOException;
use RuntimeException;

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

        $changes = app(PlanChanges::class);
        $blocked = $changes->blockedReason($service);

        return view('admin.services.show', [
            'service' => $service,
            'planChange' => [
                'pending' => $changes->pendingFor($service),
                'blocked' => $blocked,
                'options' => $blocked === null ? $changes->targets($service, staff: true)->mapWithKeys(function (Product $product) use ($changes, $service): array {
                    $difference = $changes->quote($service, $product)['difference'];

                    return [$product->id => $product->name.' ('.($difference >= 0 ? '+' : '−').money(abs($difference), $service->currency).')'];
                })->all() : [],
            ],
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

    /**
     * Move the service to another product, invoicing the difference for the days left or not.
     */
    public function changePlan(Request $request, Service $service, PlanChanges $changes): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'charge' => ['required', 'boolean'],
        ]);

        try {
            $change = $changes->start($service->load('product', 'client'), Product::query()->findOrFail($data['product_id']), $request->user('admin'), (bool) $data['charge']);
        } catch (PDOException $exception) {
            // A database error (a lock that waited too long, say) goes to the error page and the log,
            // never into the message: it names the database server and the query. PDOException also
            // covers the one Laravel throws when the database stops a transaction inside another.
            throw $exception;
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', match (true) {
            $change->status === PlanChange::STATUS_APPLIED => __('The plan was changed.'),
            $change->mode === PlanChange::MODE_RENEWAL => __('The plan changes on the next renewal date.'),
            default => __('The plan changes once the client pays invoice :number.', ['number' => $change->invoice?->displayNumber()]),
        });
    }

    public function cancelPlanChange(Service $service, PlanChanges $changes): RedirectResponse
    {
        $pending = $changes->pendingFor($service);

        try {
            if ($pending !== null) {
                $changes->cancel($pending);
            }
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('The plan change was stopped.'));
    }

    public function module(Request $request, Service $service, string $action, Provisioner $provisioner): RedirectResponse
    {
        // Checked before the server is called: the reason is saved after the account is suspended there.
        $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

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
