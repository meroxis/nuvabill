<?php

namespace App\Http\Controllers\Admin;

use App\Billing\RenewalGenerator;
use App\Domains\DomainProvisioner;
use App\Enums\DomainStatus;
use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DomainController extends Controller
{
    public function index(Request $request): View
    {
        $status = DomainStatus::tryFrom((string) $request->query('status'));

        return view('admin.domains.index', [
            'domains' => Domain::query()
                ->with('client')
                ->when($status, fn ($query) => $query->where('status', $status))
                ->search($request->query('q'))
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
            'status' => $status,
        ]);
    }

    public function show(Domain $domain, ExtensionManager $extensions): View
    {
        $domain->load('client', 'order', 'invoiceItems.invoice');

        return view('admin.domains.show', [
            'domain' => $domain,
            'registrars' => $extensions->registrarNames()->all(),
        ]);
    }

    public function update(Request $request, Domain $domain, ExtensionManager $extensions): RedirectResponse
    {
        $data = $request->validate([
            'registrar' => ['nullable', Rule::in($extensions->registrarNames()->keys()->all())],
            'status' => ['required', Rule::enum(DomainStatus::class)],
            'years' => ['required', 'integer', 'between:1,10'],
            'recurring_amount' => ['required', 'numeric', 'min:0'],
            'registered_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'next_due_date' => ['nullable', 'date'],
            'auto_renew' => ['boolean'],
            'epp_code' => ['nullable', 'string', 'max:128'],
        ]);

        $data['recurring_amount'] = Money::toMinor($data['recurring_amount']);
        $data['auto_renew'] = $request->boolean('auto_renew');

        if (blank($data['epp_code'] ?? null)) {
            unset($data['epp_code']);
        }

        $domain->update($data);
        Activity::log('domain.updated', "Domain {$domain->name} details changed by staff", $domain);

        return back()->with('status', __('Domain saved. Only the billing record changed; use the buttons above to change it at the registrar.'));
    }

    public function action(Request $request, Domain $domain, string $action, DomainProvisioner $provisioner, RenewalGenerator $renewals): RedirectResponse
    {
        if ($action === 'invoice') {
            $invoice = $renewals->invoiceDomainRenewal($domain);

            return redirect()->route('admin.invoices.show', $invoice)->with('status', __('Renewal invoice ready.'));
        }

        $result = match ($action) {
            'register' => $provisioner->register($domain),
            'renew' => $provisioner->renew($domain, (int) $request->integer('years', $domain->years)),
            'sync' => $provisioner->sync($domain),
            'nameservers' => $provisioner->setNameservers($domain, array_values(array_filter((array) $request->input('nameservers', []), 'is_string'))),
            default => abort(404),
        };

        return back()->with($result->success ? 'status' : 'error', $result->message);
    }
}
