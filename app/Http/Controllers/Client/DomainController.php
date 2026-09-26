<?php

namespace App\Http\Controllers\Client;

use App\Billing\RenewalGenerator;
use App\Domains\DomainProvisioner;
use App\Enums\DomainStatus;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DomainController extends Controller
{
    public function index(Request $request): View
    {
        return view('theme::client.domains.index', [
            'domains' => $request->user('web')->domains()->latest('id')->paginate(20),
        ]);
    }

    public function show(Request $request, Domain $domain): View
    {
        $this->authorizeOwner($request, $domain);

        $domain->load('invoiceItems.invoice');

        return view('theme::client.domains.show', ['domain' => $domain]);
    }

    public function nameservers(Request $request, Domain $domain, DomainProvisioner $provisioner): RedirectResponse
    {
        $this->authorizeOwner($request, $domain);
        abort_unless(in_array($domain->status, [DomainStatus::Active, DomainStatus::Pending], true), 404);

        $data = $request->validate([
            'nameservers' => ['required', 'array', 'min:2', 'max:5'],
            'nameservers.*' => ['nullable', 'string', 'max:190', 'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i'],
        ], ['nameservers.*.regex' => __('Enter nameservers like ns1.example.com.')]);

        $nameservers = array_values(array_filter($data['nameservers']));

        if (count($nameservers) < 2) {
            return back()->withErrors(['nameservers' => __('Enter at least two nameservers.')])->withInput();
        }

        $result = $provisioner->setNameservers($domain, $nameservers);

        return $result->success
            ? back()->with('status', __('Nameservers saved. Changes can take a few hours to reach everyone.'))
            : back()->with('error', __('The nameservers could not be changed right now. Please try again later or open a ticket.'));
    }

    public function autoRenew(Request $request, Domain $domain): RedirectResponse
    {
        $this->authorizeOwner($request, $domain);

        $domain->update(['auto_renew' => $request->boolean('auto_renew')]);
        Activity::log('domain.auto_renew', "Auto-renew for {$domain->name} turned ".($domain->auto_renew ? 'on' : 'off'), $domain);

        return back()->with('status', $domain->auto_renew
            ? __('Auto-renew is on. We send the renewal invoice before the domain expires.')
            : __('Auto-renew is off. We remind you before the domain expires.'));
    }

    public function renew(Request $request, Domain $domain, RenewalGenerator $renewals): RedirectResponse
    {
        $this->authorizeOwner($request, $domain);
        abort_unless($domain->status->isRenewable(), 404);

        $invoice = $renewals->invoiceDomainRenewal($domain);

        return redirect()->route('client.invoices.show', $invoice)->with('status', __('Pay this invoice to renew :domain.', ['domain' => $domain->name]));
    }

    private function authorizeOwner(Request $request, Domain $domain): void
    {
        abort_unless($domain->client_id === $request->user('web')->id, 404);
    }
}
