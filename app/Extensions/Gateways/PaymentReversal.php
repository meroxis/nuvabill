<?php

namespace App\Extensions\Gateways;

/**
 * Money a gateway says went back to the client without this site sending it: a refund made at
 * the payment service, or a dispute (chargeback). Return it from handleWebhook() with
 * WebhookResult::reversed(). Nuvabill records it once on the payment's invoice and tells staff;
 * the invoice, its services and the wallet stay as they are.
 *
 * Only a payment recorded here with exactly this gateway and payment ID is touched, so a gateway
 * may report every notice of its account: the ones about other payments are ignored.
 */
final readonly class PaymentReversal
{
    public const REFUND = 'refund';

    public const CHARGEBACK = 'chargeback';

    public const CHARGEBACK_UNDONE = 'chargeback_undone';

    private function __construct(
        public string $kind,
        public string $paymentReference,
        public int $amount,
        public string $currency,
        public string $reference,
        public ?int $refundedSoFar = null,
    ) {}

    /**
     * A refund made at the payment service.
     *
     * @param  string  $paymentReference  The gateway's ID of the payment, as it was recorded here.
     * @param  int  $amount  The refund, in minor units.
     * @param  string  $reference  The gateway's own ID for the refund. Used to record it once.
     * @param  int|null  $refundedSoFar  Everything refunded on the payment up to now, when the gateway
     *                                   says it. Only the part that is not recorded here yet is then
     *                                   written, so a refund this site sent or recorded is never counted twice.
     */
    public static function refund(string $paymentReference, int $amount, string $currency, string $reference, ?int $refundedSoFar = null): self
    {
        return new self(self::REFUND, $paymentReference, $amount, $currency, $reference, $refundedSoFar);
    }

    /**
     * A dispute that took the money back.
     *
     * @param  string  $paymentReference  The gateway's ID of the payment, as it was recorded here.
     * @param  int  $amount  The disputed amount, in minor units.
     * @param  string  $reference  The gateway's own ID for the dispute. Used to record it once.
     */
    public static function chargeback(string $paymentReference, int $amount, string $currency, string $reference): self
    {
        return new self(self::CHARGEBACK, $paymentReference, $amount, $currency, $reference);
    }

    /**
     * A dispute that ended well: the money it took came back.
     *
     * @param  string  $reference  The dispute's ID, the same one given to chargeback().
     */
    public static function chargebackUndone(string $paymentReference, int $amount, string $currency, string $reference): self
    {
        return new self(self::CHARGEBACK_UNDONE, $paymentReference, $amount, $currency, $reference);
    }
}
