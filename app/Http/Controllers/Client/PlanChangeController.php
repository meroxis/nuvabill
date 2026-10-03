<?php

namespace App\Http\Controllers\Client;

use App\Billing\PlanChanges;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Client area → a service → Change plan: pick a bigger or smaller plan and see what it costs.
 */
class PlanChangeController extends Controller
{
    public function show(Request $request, Service $service, PlanChanges $changes): View
    {
        $this->authorizeOwner($request, $service);
        abort_unless($changes->enabled(), 404);

        $service->load('product', 'client');
        $blocked = $changes->blockedReason($service);
        $options = $blocked === null ? $changes->targets($service)->map(fn (Product $product): array => [
            'product' => $product,
            'quote' => $changes->quote($service, $product),
        ]) : collect();

        return view('theme::client.services.change-plan', [
            'service' => $service,
            'blocked' => $blocked,
            'pending' => $changes->pendingFor($service),
            'options' => $options,
            'modeFor' => fn (int $difference): string => $changes->modeFor($difference, $service),
        ]);
    }

    public function store(Request $request, Service $service, PlanChanges $changes): RedirectResponse
    {
        $this->authorizeOwner($request, $service);
        abort_unless($changes->enabled(), 404);

        $data = $request->validate(['product_id' => ['required', 'integer']]);
        $product = Product::query()->findOrFail($data['product_id']);

        try {
            $change = $changes->start($service->load('product', 'client'), $product);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return match (true) {
            $change->status === PlanChange::STATUS_APPLIED => redirect()->route('client.services.show', $service)
                ->with('status', __('Your plan is now :plan.', ['plan' => $product->name])),
            $change->mode === PlanChange::MODE_RENEWAL => redirect()->route('client.services.show', $service)
                ->with('status', __('Your plan changes to :plan on :date.', ['plan' => $product->name, 'date' => $change->apply_on->translatedFormat('d M Y')])),
            $change->invoice !== null => redirect()->route('client.invoices.show', $change->invoice)
                ->with('status', __('Pay this invoice and your plan changes to :plan straight away.', ['plan' => $product->name])),
            default => redirect()->route('client.services.show', $service),
        };
    }

    public function destroy(Request $request, Service $service, PlanChanges $changes): RedirectResponse
    {
        $this->authorizeOwner($request, $service);

        $pending = $changes->pendingFor($service);

        if ($pending !== null) {
            try {
                $changes->cancel($pending);
            } catch (RuntimeException $exception) {
                // Money on the invoice that cannot go back to the wallet by itself needs staff.
                $stuck = $pending->invoice?->refresh()->status === InvoiceStatus::Unpaid && $pending->invoice->amount_paid > 0;

                return back()->with('error', $stuck ? __('Part of the invoice for this change is already paid. Contact us to stop the change.') : $exception->getMessage());
            }
        }

        return redirect()->route('client.services.show', $service)->with('status', __('The plan change was stopped.'));
    }

    private function authorizeOwner(Request $request, Service $service): void
    {
        abort_unless($service->client_id === $request->user('web')->id, 404);
    }
}
