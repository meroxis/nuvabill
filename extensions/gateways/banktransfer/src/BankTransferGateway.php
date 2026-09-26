<?php

namespace Nuvabill\Extensions\BankTransfer;

use App\Extensions\Gateways\Gateway;
use App\Extensions\Gateways\PaymentStart;
use App\Models\Invoice;

/**
 * Offline payment: shows bank details. Staff record the payment by hand when it arrives.
 */
class BankTransferGateway extends Gateway
{
    public function settingsFields(): array
    {
        return [
            'display_name' => [
                'label' => 'Name shown to clients',
                'type' => 'text',
                'help' => 'Leave empty to show "Bank transfer".',
            ],
            'instructions' => [
                'label' => 'Payment instructions',
                'type' => 'textarea',
                'required' => true,
                'help' => 'Your bank details. Use {invoice} for the invoice number and {amount} for the amount due.',
            ],
        ];
    }

    public function startPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart
    {
        $instructions = strtr((string) $this->setting('instructions', ''), [
            '{invoice}' => $invoice->displayNumber(),
            '{amount}' => money($invoice->balance(), $invoice->currency),
        ]);

        return PaymentStart::instructions($instructions);
    }
}
