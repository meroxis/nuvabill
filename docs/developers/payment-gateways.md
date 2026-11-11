# Payment gateways

A payment gateway lets clients pay invoices: by card, by bank app, by crypto, or by bank transfer.
This page shows how to build one. It covers the base class, how a payment starts and finishes,
webhooks, currencies, refunds, saved cards and automatic payments, and a security checklist.

At the end there is a complete skeleton for an example provider called **Acme Pay**.

New to Nuvabill packages? Read the [overview](README.md) and [packages](packages.md) first.
They explain the manifest, permissions and the checks every package must pass.

## Contents

- [What a gateway is](#what-a-gateway-is)
- [Start from a built-in gateway](#start-from-a-built-in-gateway)
- [The Gateway base class](#the-gateway-base-class)
- [Settings fields](#settings-fields)
- [How a payment starts](#how-a-payment-starts)
- [How a payment finishes](#how-a-payment-finishes)
- [Record each payment exactly once](#record-each-payment-exactly-once)
- [Payment intents](#payment-intents)
- [Webhooks](#webhooks)
- [Currencies](#currencies)
- [Refunds](#refunds)
- [Saved payment methods and automatic payments](#saved-payment-methods-and-automatic-payments)
- [Worked example: Bank transfer](#worked-example-bank-transfer)
- [Complete skeleton: Acme Pay](#complete-skeleton-acme-pay)
- [Security checklist](#security-checklist)

## What a gateway is

A gateway is a package with `"type": "gateway"` in its `extension.json`. It lives in
`extensions/gateways/{slug}`.

```text
extensions/gateways/acmepay/
├── extension.json
└── src/
    └── AcmePayGateway.php
```

The manifest names a `namespace` and a `class`. Nuvabill autoloads every class under that
namespace from the package's `src/` folder.

```json
{
    "slug": "acmepay",
    "type": "gateway",
    "name": "Acme Pay",
    "description": "Cards through Acme Pay's hosted payment page.",
    "version": "1.0.0",
    "author": "Acme",
    "namespace": "Acme\\AcmePay\\",
    "class": "Acme\\AcmePay\\AcmePayGateway",
    "requires": ">=0.6.12",
    "permissions": ["payments", "http:api.acmepay.example", "http:sandbox.acmepay.example"]
}
```

Nuvabill creates your class as `new YourClass($manifest, $settings)`. `$manifest` is an
`App\Extensions\ExtensionManifest` and `$settings` is the array of saved settings. Nuvabill refuses
the class unless it implements `App\Contracts\PaymentGateway`. In practice you always extend
`App\Extensions\Gateways\Gateway`, which does most of the work.

For a Marketplace package, list these permissions:

- `payments`: staff see "Takes payments" before they install. Every gateway must list it.
- `http:{host}`: one entry for each API host your gateway calls.

The NOWPayments package is a real Marketplace gateway. Its manifest has `"requires": ">=0.6.12"`
and the permissions `payments`, `http:api.nowpayments.io` and `http:api-sandbox.nowpayments.io`.

## Start from a built-in gateway

The built-in gateways in `extensions/gateways/` are complete examples. Copy the one closest to your
provider and change it.

| Gateway | What it shows |
|---|---|
| Bank transfer (`banktransfer`) | The smallest gateway: only `settingsFields()` and `startPayment()`. Staff record the payment by hand. |
| FIB (`fib`) | A QR code, polling while the client pays in an app, amounts converted to whole Iraqi dinars, and an unsigned webhook that is checked again with FIB's API. |
| Stripe (`stripe`) | A redirect to a hosted page, a signed webhook, refunds, saved cards and automatic payments. |
| NOWPayments (Marketplace package) | A third-party style package with an HMAC-SHA512 webhook signature and the `payments` and `http:` permissions. |

FastPay (`fastpay`), PayPal (`paypal`) and Wayl (`wayl`) are built in too.

## The Gateway base class

`App\Extensions\Gateways\Gateway` implements the `PaymentGateway` contract. It gives a default for
every method except `startPayment()`. **`startPayment()` is the only method you must write.**

### Contract methods

| Method | Must you write it? | Default in the base class |
|---|---|---|
| `slug(): string` | No | The slug from the manifest. |
| `name(): string` | No | The `display_name` setting when it is filled in, otherwise the manifest name. |
| `settingsFields(): array` | Usually | `[]` (no settings). |
| `isConfigured(): bool` | No | `false` while any field marked `required` is empty. |
| `supportsCurrency(string $currency): bool` | Usually | `true` (every currency). Change this, see [Currencies](#currencies). |
| `chargeCurrencyFor(string $currency): ?string` | No | Uses `supportsCurrency()` and `convertsCurrency()`, see [Currencies](#currencies). |
| `startPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart` | **Yes** | None. |
| `handleReturn(Request $request, Invoice $invoice): ?PaymentResult` | When the client comes back from the provider | `null` (not paid yet). |
| `handleWebhook(Request $request): WebhookResult` | When the provider sends notifications | `WebhookResult::ignored()`. |
| `supportsRefunds(): bool` | For refunds | `false`. |
| `refund(Transaction $payment, int $amount): RefundResult` | For refunds | Throws a `RuntimeException`. |

### Extra methods in the base class

These are not part of the contract. You can override the public ones and call the protected ones.

| Method | What it does |
|---|---|
| `convertsCurrency(): bool` | Default `true`. Return `false` when your gateway always charges the invoice's own currency. (0.6.12+) |
| `checksWhileWaiting(): bool` | Default `false`. Return `true` to let the invoice page poll `handleReturn()` while the client pays in another app. (0.2.0+) |
| `quote(Invoice $invoice): array` | The amount to charge: `['currency' => ..., 'amount' => ..., 'rate' => ...]`. Converted at the staff exchange rate when needed. |
| `chargeDetails(array $quote, int $chargedMinor): array` (protected) | What to keep in a payment intent's `meta` about a converted charge. |
| `resultFor(PaymentIntent $intent, int $paidMinor, string $paidCurrency, string $reference, array $meta = []): ?PaymentResult` (protected) | Turns a confirmed payment into a result in the invoice currency. Refuses a converted payment that is short. (0.3.0+) |
| `wholeUnits(int $minor): int` (protected) | Rounds minor units up to whole units, for providers that take only whole dinars. |
| `setting(string $key, mixed $default = null): mixed` (protected) | Reads one saved setting. |

All amounts in Nuvabill are **integers in minor units**. Nuvabill stores two decimal places for
every currency, so `1250` means 12.50.

## Settings fields

`settingsFields()` returns an array of `key => field`. Each field has:

| Key | Meaning |
|---|---|
| `label` | The label staff see. |
| `type` | Required: `text`, `textarea`, `select` or `password`. Always set it. A field without `type` breaks the settings page. |
| `help` | Optional help text under the field. |
| `required` | Optional. When `true`, `isConfigured()` is false until it is filled in. |
| `options` | For `select`: `value => label`. |

```php
public function settingsFields(): array
{
    return [
        'display_name' => [
            'label' => 'Name shown to clients',
            'type' => 'text',
            'help' => 'Leave empty to show "Acme Pay".',
        ],
        'api_key' => [
            'label' => 'Secret API key',
            'type' => 'password',
            'required' => true,
        ],
        'mode' => [
            'label' => 'Mode',
            'type' => 'select',
            'options' => ['live' => 'Live', 'test' => 'Test'],
        ],
    ];
}
```

How the settings page treats them:

- Every value must be a string of at most 1000 characters, or 5000 for a `textarea`.
- `required` is checked only when staff switch the gateway on.
- A `select` value must be one of its `options`.
- Only keys listed in `settingsFields()` are saved.
- A `password` value is never shown again after it is saved. When staff leave it empty, the saved
  value stays.
- Gateway settings are **encrypted at rest** (the `settings` column is cast as `encrypted:array`).
- Use a `display_name` field so staff can change the name clients see. The base `name()` uses it.

**Use `'type' => 'password'` for every API key and secret.** Do not use the `'secret' => true`
flag on a gateway field. The gateway settings page keeps a saved value only for the `password`
type. A `secret` text field is wiped when staff save without typing it again, and a required one
fails validation.

Read settings with `$this->setting('api_key')`. Never print a setting into a page as raw HTML, and
never write keys or secrets to the log or into an exception message.

## How a payment starts

1. The client picks a gateway on the invoice and posts to `/client/invoices/{invoice}/pay` with the
   field `gateway`. The route needs a signed-in client, and only the invoice owner can use it.
2. Nuvabill checks that the gateway is active for the invoice currency and saves its slug in
   `invoice.payment_method`.
3. Nuvabill calls `startPayment($invoice, $returnUrl, $cancelUrl)`.
   - `$returnUrl` is `/client/invoices/{invoice}/return/{slug}` (route `client.invoices.return`).
   - `$cancelUrl` is the invoice page.
   - When the client ticked "save", the setting `billing.autopay_offer_save` is on, and your gateway
     implements `SavesPaymentMethods`, Nuvabill calls `startSavingPayment()` instead.

Clients only see gateways that are switched on, pass `isConfigured()`, and return a non-null
`chargeCurrencyFor()` for the invoice currency. They are listed in the order staff chose. A gateway
that throws while it is being created is skipped.

### Three ways to start

`startPayment()` returns an `App\Extensions\Gateways\PaymentStart`:

| Factory | What the client sees |
|---|---|
| `PaymentStart::redirect(string $url)` | The client is sent to the provider's payment page. |
| `PaymentStart::qr(string $qrImage, ?string $code = null, array $appLinks = [], ?string $expiresAt = null)` | A QR code (a data URL such as `data:image/png;base64,...`), a code to type in, and buttons (`label => URL`) that open an app. (0.2.0+) |
| `PaymentStart::instructions(string $markdown)` | Text on the invoice page, such as bank details. |

Instructions are Markdown. Nuvabill renders them with HTML escaped (`html_input` is `escape`) and
unsafe links turned off, so raw HTML in a setting cannot reach the page.

### When startPayment() fails

Throw a `RuntimeException` with a short, clear reason. Nuvabill catches any exception here. It then:

- reports the error,
- writes your message (cut to 300 characters) to the activity log, so staff can see why,
- shows the client a friendly "not available right now" message.

Do not put keys, tokens or full API responses in the message.

## How a payment finishes

Nuvabill learns about a payment in three ways. All three end with a `PaymentResult` passed to
`PaymentRecorder::recordGatewayResult()`. You never call the recorder yourself: you return the result.

1. **Return page.** The client comes back to `$returnUrl`. Nuvabill calls
   `handleReturn($request, $invoice)`. A non-null result is recorded, and the client sees
   "Thank you! Your payment was received." A `null` result shows a message that the invoice is
   marked paid as soon as the payment is confirmed.
2. **Webhook.** The provider calls your webhook URL. See [Webhooks](#webhooks).
3. **Polling.** When `checksWhileWaiting()` returns `true`, the invoice page calls
   `/client/invoices/{invoice}/payment-status` while the client pays in another app. The route
   allows 30 requests a minute, and Nuvabill calls `handleReturn()` at most once every 8 seconds
   for each invoice. Polling only works for gateways that extend the base `Gateway` class. FIB and
   NOWPayments use it. (0.2.0+)

Rules for `handleReturn()`:

- **It must check that the payment belongs to the invoice it was given.** Stripe returns `null`
  when the invoice IDs differ.
- Do not trust the query string. A client can change it. Use it at most to find a payment, then ask
  the provider's API for the real status.
- When polling, the request is the poll request, not a return from the provider. FIB and the
  skeleton below ignore the request and look up their own payment intent for the invoice.
- If `handleReturn()` throws, the error is reported and treated as "not paid yet".

## Record each payment exactly once

A confirmed payment is an `App\Extensions\Gateways\PaymentResult`:

```php
new PaymentResult(
    invoiceId: $invoice->id,
    amount: 1250,          // minor units: 12.50
    currency: 'USD',
    reference: 'pay_123',  // the provider's own ID for this payment
    fee: 0,                // optional, minor units
    meta: [],              // optional, stored on the transaction
);
```

**The `reference` is what stops a payment from being counted twice.** The recorder looks for a
transaction with the same gateway and reference and returns it instead of saving a new one. The
`transactions` table also has a unique index on `(gateway, reference)`. If a webhook and the return
page arrive at the same moment, the database refuses the second insert, and the first one counts.

So:

- Always use the provider's **stable, non-empty payment ID** as the reference. Use the same value on
  the return page, in the webhook and in polling.
- Never use a random value or a time stamp. Then the same payment would be recorded twice.
- Return the result from `handleReturn()`, `handleWebhook()` (through `WebhookResult::paid()`) or
  `chargeSaved()` (through `ChargeResult::paid()`).

### What the recorder checks

Before it records anything, `recordGatewayResult()`:

- drops a payment for an unknown invoice and writes a log line,
- does not record a payment whose currency is not the invoice currency, or whose amount is 0 or less,
- takes a `saved_method` entry out of `meta` and saves it as its own row (see
  [saved payment methods](#saved-payment-methods-and-automatic-payments)),
- stores the rest of `meta` on the transaction.

The recorder does **not** check that the amount equals the invoice balance. Partial payments are
allowed. **Your gateway must check the amount against what it asked for.**

### What happens after a payment is stored

- The invoice row is locked while it changes.
- A payment on a cancelled or refunded invoice goes into the client's wallet, with a matching refund
  line. If there is no exchange rate for the wallet currency, it stays on the invoice and is logged.
- A payment that brings an Unpaid invoice to its total marks it Paid.
- Any amount over the total goes into the wallet. Only the new surplus is credited.
- After the database transaction ends, the payment is logged. When the invoice became paid,
  `InvoicePaidHandler` runs and the `InvoicePaid` event fires.

## Payment intents

`App\Models\PaymentIntent` (table `payment_intents`) lets your gateway remember a payment it started
and find the invoice again from the provider's reference.

| Column | Meaning |
|---|---|
| `invoice_id` | The invoice. |
| `gateway` | Your slug. |
| `reference` | The provider's payment ID. |
| `amount`, `currency` | What the intent was made for, in the invoice currency. |
| `status` | `pending`, `paid` or `failed` (`PaymentIntent::STATUS_*`). |
| `meta` | Your own data, for example a checkout URL or `chargeDetails()`. |
| `expires_at` | When the payment can no longer be used. |

Two helpers:

- `PaymentIntent::findFor(string $gateway, string $reference): ?PaymentIntent` finds an intent from
  the provider's ID. Use it in a webhook.
- `PaymentIntent::latestOpenFor(Invoice $invoice, string $gateway): ?PaymentIntent` returns the newest
  pending intent that has not expired and is for the current balance. Use it in `startPayment()`, so
  a page reload shows the same payment instead of starting a new one.

## Webhooks

### The route

| | |
|---|---|
| URL | `POST /webhooks/{gateway}`, where `{gateway}` is your slug (`[a-z0-9_-]+`) |
| Route name | `webhooks.gateway` |
| Session and CSRF | None. The route is outside the `web` middleware group. |
| Rate limit | 240 requests a minute for each gateway slug and IP (limiter `gateway-webhooks`, 0.6.12+) |

Staff see the webhook URL on your gateway's settings page, so they can copy it to the provider. In
code, build it with `route('webhooks.gateway', $this->slug())`. FIB sends this URL to FIB as its
status callback when it starts a payment.

### What Nuvabill does with a webhook

- Unknown or switched-off gateway: answers `404`.
- Calls your `handleWebhook($request)`.
- If your code throws: the error is reported and Nuvabill answers `500`, so the provider sends the
  webhook again.
- A result with status 400 or more is logged as "Rejected {gateway} webhook".
- When the result carries a payment, it goes to `recordGatewayResult()`.
- When the result carries a reversal (a refund made at the provider, or a dispute), it goes to
  `RefundIssuer::recordReversal()`.
- The HTTP answer is the result's message and status.

### Webhook results

| Factory | HTTP answer | Use it when |
|---|---|---|
| `WebhookResult::paid(PaymentResult $payment)` | `200 ok` | The payment is confirmed. It is recorded. |
| `WebhookResult::reversed(PaymentReversal $reversal)` | `200 ok` | Money of a payment went back without Nuvabill sending it: a refund made at the provider, or a dispute. It is recorded once. |
| `WebhookResult::ignored(string $message = 'ignored')` | `200` | A valid notification that needs no action, such as an event you do not use. |
| `WebhookResult::invalid(string $message)` | `400` | The notification failed verification. Nothing is recorded. |

### Refunds and disputes made at the provider

When the provider tells you that money went back without Nuvabill sending it, return
`WebhookResult::reversed()` with an `App\Extensions\Gateways\PaymentReversal`:

| Factory | Use it when |
|---|---|
| `PaymentReversal::refund($paymentReference, $amount, $currency, $reference, $refundedSoFar = null)` | A refund was made at the provider. Pass the provider's total for the payment as `$refundedSoFar` when it gives one. |
| `PaymentReversal::chargeback($paymentReference, $amount, $currency, $reference)` | A dispute took the money back. |
| `PaymentReversal::chargebackUndone($paymentReference, $amount, $currency, $reference)` | The dispute ended well and the money came back. `$reference` is the dispute's ID again. |

- `$paymentReference` is the provider's ID of the payment, the same `reference` you gave in the
  `PaymentResult`. Only a payment recorded with exactly this gateway and ID is touched, so notices
  about other payments on the same provider account change nothing.
- `$reference` is the provider's own ID of the refund or dispute. It is recorded once.
- Verify the notification first, as for a payment.

Nuvabill writes a refund line on the payment's invoice, never for more than the payment, and tells
staff (activity log, dashboard, email to the company address). A refund Nuvabill sent itself
through your `refund()` is not recorded twice. The invoice, its services and the wallet stay as
they are: staff decide what happens next.

When staff choose the events of the webhook at the provider, return their names from
`webhookEvents()`. The settings page lists them under the webhook URL, also after the settings are
saved, so a site that set the webhook up earlier sees which events a newer version needs.

### Verify the signature yourself

**The core never checks webhook signatures.** Anyone on the internet can post to your webhook URL.
Each gateway must prove the notification really came from the provider.

When the provider signs its notifications:

- Read the **raw body** with `$request->getContent()`. Do not re-encode parsed JSON unless the
  provider says to.
- Compute the HMAC with the webhook secret from your settings.
- Compare with `hash_equals()`, never with `==` or `===`.
- Reject a missing secret or a missing header.
- When the provider sends a time stamp, reject old ones to stop replays. Stripe allows 300 seconds.

Stripe's check, in full:

```php
public function hasValidSignature(string $payload, string $header): bool
{
    $secret = (string) $this->setting('webhook_secret', '');

    if ($secret === '' || $header === '') {
        return false;
    }

    $timestamp = null;
    $signatures = [];

    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

        if ($key === 't') {
            $timestamp = (int) $value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}
```

Other built-in patterns:

- **NOWPayments** compares an HMAC-SHA512 of the key-sorted JSON body with the `x-nowpayments-sig`
  header, also with `hash_equals()`.
- **FIB** notifications are not signed. FIB only uses the posted ID to find its own payment intent.
  Then it asks FIB's API for the status before anything counts. Do the same when your provider does
  not sign notifications: **treat the webhook only as a hint to check again.**

### Only trust data you marked yourself

One provider account can hold payments that are not from your site: payment links, another shop,
another Nuvabill site. A buyer can also set some fields, such as Stripe's `client_reference_id` on a
Payment Link. Stripe therefore never trusts `client_reference_id`. It puts its own marker in the
metadata of each Checkout session it starts (`nuvabill_site`, an HMAC of the app key) and refuses
sessions without it (0.6.12+). Sessions made before 0.6.12 have no marker. They count only when they
send the client back to this invoice on this site.

The skeleton below does the same in a simpler way: it only accepts payments it created itself and
saved as a payment intent.

## Currencies

### supportsCurrency()

Nuvabill sends amounts with **two decimal places** for every currency. Some currencies have zero or
three decimal places at a provider (for example JPY or KWD). If you send them a two-decimal amount,
the client is charged the wrong amount.

So **`supportsCurrency()` must only return `true` for currencies the provider charges correctly with
two decimal places.** Use an allowlist that you checked with the provider. Refuse every other
currency until someone checks it.

```php
private const CURRENCIES = ['USD', 'EUR', 'GBP'];

public function supportsCurrency(string $currency): bool
{
    return in_array(strtoupper($currency), self::CURRENCIES, true);
}
```

Stripe does this with a long checked list. Its comment explains why currencies such as JPY, KWD,
ISK, UGX and IQD are left out. The base class default (`true` for every currency) is not safe for a
real card gateway.

### chargeCurrencyFor() and convertsCurrency()

`chargeCurrencyFor($invoiceCurrency)` decides which currency your gateway charges:

1. When `supportsCurrency($invoiceCurrency)` is true, it returns the invoice currency.
2. Otherwise, only when `convertsCurrency()` is true, it returns another currency you support that
   has a staff-set exchange rate.
3. Otherwise it returns `null`, and clients do not see your gateway for that invoice.

**If your gateway always charges the invoice's own currency, return `false` from
`convertsCurrency()`** (0.6.12+). Stripe does this. Otherwise an exchange rate makes your gateway
appear for currencies it then charges wrongly.

### Charging a converted amount

Exchange-rate charging (for example a USD invoice paid in IQD) arrived in 0.3.0. **It is not
automatic.** A converting gateway must do these steps itself. FIB is the example.

1. Call `$this->quote($invoice)` to get the currency, amount and rate to charge.
2. Charge that amount. Round with `$this->wholeUnits()` if the provider takes only whole units.
3. Save a payment intent in the **invoice** currency and amount, and put
   `$this->chargeDetails($quote, $chargedMinor)` in its `meta`.
4. When the payment is confirmed, call
   `$this->resultFor($intent, $paidMinor, $paidCurrency, $reference, $meta)`.

`resultFor()` credits the intent's amount in the invoice currency only when the paid currency matches
`charged_currency` and at least `charged_amount` arrived. Otherwise it logs a warning and returns
`null`. When the paid currency is the invoice currency, it returns a result for the amount that was
paid.

The converted amount is the price at the exchange rate of the moment the payment was started. A
payment page can stay open long after that, so `resultFor()` checks the price again at the rate
staff have set now. When what arrived is more than 1% short of it, the result is for what the
payment is worth at the new rate (never more than the intent's amount), and the rest of the invoice
stays due. Its `meta` then also has `quoted_amount` and `quoted_rate`, and `rate` is the rate used.
Staff see it in the activity log, and the client sees why a part is still to pay. Without a rate
for the currency any more, the price of the day the payment was started stands. A gateway that shows
a waiting payment again (as FIB does) should make a new one when its price is no longer today's.

Shortened from FIB:

```php
$quote = $this->quote($invoice);
$amount = $this->wholeUnits($quote['amount']);   // whole dinars

// ... ask FIB to charge $amount IQD ...

PaymentIntent::create([
    'invoice_id' => $invoice->id,
    'gateway' => $this->slug(),
    'reference' => $response->json('paymentId'),
    'amount' => $invoice->balance(),
    'currency' => $invoice->currency,
    'status' => PaymentIntent::STATUS_PENDING,
    'expires_at' => now()->addMinutes(15),  // FIB uses its own validUntil time when it sends one
    'meta' => [/* QR code and app links */] + $this->chargeDetails($quote, $amount * 100),
]);

// later, when FIB says the payment is PAID:
$result = $this->resultFor($intent, $paidMinor, 'IQD', $intent->reference);
```

## Refunds

Gateway refunds arrived in 0.2.0.

1. Return `true` from `supportsRefunds()`.
2. Write `refund(Transaction $payment, int $amount): RefundResult`. `$amount` is in minor units.
3. Return `new RefundResult(amount: ..., reference: ..., meta: [...])` with the provider's own
   refund ID as the reference.
4. Throw a `RuntimeException` when the provider refuses.

**Safe retries (0.7.2+):** if the provider takes an idempotency key, also implement
`App\Contracts\SendsRefundsOnce`: `refundOnce(Transaction $payment, int $amount, string $attemptKey): RefundResult`
sends the refund with `$attemptKey` as the provider's idempotency key, and `refundKeyHours(): int` says how long the
provider keeps keys. Throw `App\Extensions\Gateways\RefundUnclear` when the provider gives no clear answer (no
connection, a timeout, HTTP 5xx or 429). Nuvabill saves each try before it is sent and sends an unclear try again
with the same key, so the client is refunded once. Stripe and PayPal are the examples.

Nuvabill calls your `refund()` only when:

- the refund was asked to go through the gateway,
- the payment has a reference,
- your gateway supports refunds and is configured.

Then Nuvabill writes a refund transaction with a negative amount, your refund reference, and the meta
`refund_of` and `through_gateway`. That transaction uses the same `(gateway, reference)` unique index,
so the refund ID must be different from every payment ID.

Nuvabill also protects against double refunds:

- A cache lock of 120 seconds for each invoice stops two refunds running at once.
- Payments already refunded are skipped, so trying again is safe.
- Only Paid invoices can be refunded.

New code starts refunds with `CreditNotes::issue()`. `RefundIssuer::refund()` is kept for add-ons
written before 0.6.7.

## Saved payment methods and automatic payments

### SavesPaymentMethods (0.6.0+)

Implement `App\Contracts\SavesPaymentMethods` when your provider can keep a card or PayPal account for
later charges. Card numbers stay with the provider. Nuvabill keeps only the reference, plus the brand,
last 4 digits, expiry or email.

```php
public function startSavingPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart;
public function startSavingMethod(Client $client, string $returnUrl, string $cancelUrl): PaymentStart;
public function finishSavingMethod(Request $request, Client $client): ?SavedMethod;
public function chargeSaved(PaymentMethod $method, Invoice $invoice, string $attemptKey): ChargeResult;
public function forgetSaved(PaymentMethod $method): void;
```

| Method | What it does |
|---|---|
| `startSavingPayment()` | Pays the invoice now and keeps the method. |
| `startSavingMethod()` | Keeps a method without paying (Payment methods, Add a card). |
| `finishSavingMethod()` | The client came back from saving. Return `null` when nothing was saved. Check that the saved method belongs to this client, as Stripe does. |
| `chargeSaved()` | Charges the invoice balance to a saved method, without the client. |
| `forgetSaved()` | Deletes the method at the provider. If it throws, the error is reported and the method is still deleted in Nuvabill. |

When you pay and save in one step, put the method in the payment result's meta under `saved_method`:

```php
use App\Extensions\Gateways\SavedMethod;

$saved = SavedMethod::card($reference, $customer, $brand, $last4, $month, $year);
// or: SavedMethod::paypal($reference, $customer, $email);

return new PaymentResult($invoiceId, $amount, $currency, $paymentId, meta: [
    'saved_method' => $saved->toArray(),
]);
```

The `payment_methods` table has a unique index on `(gateway, reference)`.

### ChargeResult

`chargeSaved()` returns an `App\Extensions\Gateways\ChargeResult`:

| Factory | Meaning |
|---|---|
| `ChargeResult::paid(PaymentResult $payment)` | Paid. The payment is recorded. |
| `ChargeResult::settled()` | Nothing was left to charge. |
| `ChargeResult::failed(string $message)` | Failed. Write the message so a client understands it, for example "The bank declined the card." |
| `ChargeResult::needsClient(string $message)` | The bank wants the client to confirm (for example 3-D Secure). Nuvabill does not retry these. |
| `ChargeResult::pending(string $message, ?string $reference = null)` | The result is not known yet. Pass the provider's payment ID when you have it. |

How AutoPay calls you:

- It saves the attempt (gateway, method, customer, key) on the invoice **before** it calls
  `chargeSaved()`.
- If `chargeSaved()` throws, the attempt is treated as pending.
- A pending charge is checked before any new charge, so the invoice is not paid twice.
- The attempt key looks like `invoice-{id}-{balance}-try-{n}` or `...-staff-{ms}`.

**Send the attempt key to the provider as an idempotency key.** Then the same attempt sent twice
(a timeout, then a retry) charges the card once. Stripe sends it in its `Idempotency-Key` header,
with a site part added so two Nuvabill sites on one Stripe account do not clash.

When the provider gives no clear answer (a server error, a timeout, "too many requests"), return
`pending`, not `failed`. The card may have been charged.

### ChecksSavedCharges (0.6.12+)

Implement `App\Contracts\ChecksSavedCharges` so Nuvabill can ask what became of an unclear automatic
payment before it charges again:

```php
public function checkSavedCharge(Invoice $invoice, string $attemptKey, ?string $reference, ?string $customer): ?ChargeResult;
```

- Return the payment's result now, as a `ChargeResult`.
- Return `null` only when the provider has no payment for that attempt.
- Even then, AutoPay waits 10 minutes after the try before it trusts a `null`.
- Check that the payment you find belongs to this invoice. Stripe compares the `invoice_id` in its
  metadata.

For a gateway without this interface:

- When the unclear try brought back a reference, Nuvabill waits. Your webhook must settle the invoice.
- When it brought back no reference, the same try is sent again with the same key. That only stops a
  second payment when the provider remembers keys at least that long.

### RepeatsUnclearCharges (0.6.12+)

`App\Contracts\RepeatsUnclearCharges` is a marker interface with no methods. Use it when your
provider can look up a charge only by the ID it sent back, as PayPal can. Then a try that ended
without that ID is always sent again with the same key, also when staff press "Charge now". The
provider's own guard against repeats can then stop a second payment.

## Worked example: Bank transfer

The built-in Bank transfer gateway is the smallest real gateway. It has two methods.

```php
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
```

What it shows:

- `isConfigured()` comes from the base class. It is false until staff fill in `instructions`.
- `name()` comes from the base class and uses `display_name`.
- The instructions are Markdown, and Nuvabill escapes any HTML in them.
- There is no webhook and no return page. Staff record the payment by hand.

## Complete skeleton: Acme Pay

This is a full gateway for an example provider, Acme Pay. Acme Pay has a hosted payment page, signs
its webhooks with HMAC-SHA256, and can refund. The API at `acmepay.example` is made up. Change the
URLs, field names and signature format to match your provider's documentation.

It shows the safe pattern:

- It only offers currencies on a checked two-decimal list, and never converts.
- It saves a payment intent for every payment it starts.
- The return page and the webhook both use the intent and ask the API for the real status.
- It checks the amount, currency and invoice before it reports a payment.
- It uses the provider's payment ID as the reference, so each payment is recorded once.
- It verifies the webhook signature with `hash_equals()` and a time limit.
- It uses short HTTP timeouts and keeps secrets out of messages.

`extensions/gateways/acmepay/extension.json` is the manifest shown in
[What a gateway is](#what-a-gateway-is).

`extensions/gateways/acmepay/src/AcmePayGateway.php`:

```php
<?php

namespace Acme\AcmePay;

use App\Extensions\Gateways\Gateway;
use App\Extensions\Gateways\PaymentResult;
use App\Extensions\Gateways\PaymentStart;
use App\Extensions\Gateways\RefundResult;
use App\Extensions\Gateways\WebhookResult;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Acme Pay hosted checkout. The client pays on Acme Pay's page, so card numbers never reach
 * this server. Webhooks are signed with HMAC-SHA256.
 */
class AcmePayGateway extends Gateway
{
    /**
     * Currencies Acme Pay charges with exactly two decimal places. Check each one with Acme Pay
     * before you add it here.
     */
    private const CURRENCIES = ['USD', 'EUR', 'GBP'];

    /**
     * Webhooks older than this many seconds are rejected, to stop replayed requests.
     */
    private const SIGNATURE_TOLERANCE = 300;

    public function settingsFields(): array
    {
        return [
            'display_name' => [
                'label' => 'Name shown to clients',
                'type' => 'text',
                'help' => 'Leave empty to show "Acme Pay".',
            ],
            'api_key' => [
                'label' => 'Secret API key',
                'type' => 'password',
                'required' => true,
                'help' => 'From the Acme Pay dashboard, under API keys.',
            ],
            'webhook_secret' => [
                'label' => 'Webhook signing secret',
                'type' => 'password',
                'required' => true,
                'help' => 'Add a webhook in Acme Pay for the URL shown on this page, then paste its signing secret.',
            ],
            'mode' => [
                'label' => 'Mode',
                'type' => 'select',
                'options' => ['live' => 'Live', 'test' => 'Test'],
            ],
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::CURRENCIES, true);
    }

    /**
     * Acme Pay always charges the invoice's own currency, so an exchange rate must never make
     * this gateway appear for other currencies.
     */
    public function convertsCurrency(): bool
    {
        return false;
    }

    public function startPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart
    {
        // A client who reloads the page gets the same payment, not a second one.
        $open = PaymentIntent::latestOpenFor($invoice, $this->slug());

        if ($open !== null && is_string($open->meta['checkout_url'] ?? null)) {
            return PaymentStart::redirect($open->meta['checkout_url']);
        }

        $response = $this->api()->post($this->baseUrl().'/payments', [
            'amount' => $invoice->balance(),
            'currency' => $invoice->currency,
            'description' => __('Invoice :number', ['number' => $invoice->displayNumber()]),
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'webhook_url' => route('webhooks.gateway', $this->slug()),
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ]);

        $id = $response->json('id');
        $url = $response->json('checkout_url');

        if (! $response->successful() || ! is_string($id) || $id === '' || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            // This message goes to the activity log. Keep keys and full responses out of it.
            throw new RuntimeException('Acme Pay could not start the payment (HTTP '.$response->status().').');
        }

        PaymentIntent::create([
            'invoice_id' => $invoice->id,
            'gateway' => $this->slug(),
            'reference' => $id,
            'amount' => $invoice->balance(),
            'currency' => $invoice->currency,
            'status' => PaymentIntent::STATUS_PENDING,
            'expires_at' => now()->addHour(),
            'meta' => ['checkout_url' => $url],
        ]);

        return PaymentStart::redirect($url);
    }

    public function handleReturn(Request $request, Invoice $invoice): ?PaymentResult
    {
        // The query string is not trusted. Find our own payment for this invoice and ask Acme Pay.
        $intent = PaymentIntent::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', $this->slug())
            ->where('status', PaymentIntent::STATUS_PENDING)
            ->latest('id')
            ->first();

        return $intent === null ? null : $this->confirm($intent);
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $payload = $request->getContent();

        if (! $this->hasValidSignature($payload, (string) $request->header('Acme-Signature', ''))) {
            return WebhookResult::invalid('Invalid Acme Pay signature.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ($event['type'] ?? null) !== 'payment.paid') {
            return WebhookResult::ignored();
        }

        // Only payments this site started count.
        $intent = PaymentIntent::findFor($this->slug(), (string) ($event['data']['id'] ?? ''));

        if ($intent === null) {
            return WebhookResult::ignored('Unknown payment.');
        }

        $result = $this->confirm($intent);

        return $result ? WebhookResult::paid($result) : WebhookResult::ignored('Not paid yet.');
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(Transaction $payment, int $amount): RefundResult
    {
        $response = $this->api()->post($this->baseUrl().'/refunds', [
            'payment' => $payment->reference,
            'amount' => $amount,
        ]);

        $id = $response->json('id');

        if (! $response->successful() || ! is_string($id) || $id === '') {
            throw new RuntimeException('Acme Pay could not refund the payment (HTTP '.$response->status().').');
        }

        return new RefundResult(
            amount: (int) $response->json('amount', $amount),
            reference: $id,
            meta: ['status' => (string) $response->json('status')],
        );
    }

    /**
     * Ask Acme Pay for the payment's real status. Only a paid payment for the exact amount,
     * currency and invoice of the intent becomes a result.
     */
    private function confirm(PaymentIntent $intent): ?PaymentResult
    {
        $response = $this->api()->get($this->baseUrl().'/payments/'.rawurlencode($intent->reference));

        if (! $response->successful() || $response->json('status') !== 'paid') {
            return null;
        }

        $amount = $response->json('amount');
        $currency = strtoupper((string) $response->json('currency'));

        if (! is_int($amount)
            || $amount !== $intent->amount
            || $currency !== strtoupper($intent->currency)
            || (string) $response->json('metadata.invoice_id') !== (string) $intent->invoice_id) {
            Log::warning("Acme Pay payment {$intent->reference} does not match invoice {$intent->invoice_id}; not recorded.");

            return null;
        }

        $intent->update(['status' => PaymentIntent::STATUS_PAID]);

        // The reference is Acme Pay's own payment ID, the same on the return page and in the
        // webhook, so Nuvabill records this payment only once.
        return new PaymentResult($intent->invoice_id, $amount, $currency, $intent->reference);
    }

    /**
     * Acme Pay signs "timestamp.body" with HMAC-SHA256 and sends "t=...,v1=..." in Acme-Signature.
     */
    private function hasValidSignature(string $payload, string $header): bool
    {
        $secret = (string) $this->setting('webhook_secret', '');

        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function api(): PendingRequest
    {
        return Http::withToken((string) $this->setting('api_key'))
            ->connectTimeout(5)
            ->timeout(15)
            ->acceptJson()
            ->asJson();
    }

    private function baseUrl(): string
    {
        return $this->setting('mode') === 'test'
            ? 'https://sandbox.acmepay.example/v1'
            : 'https://api.acmepay.example/v1';
    }
}
```

Notes on the skeleton:

- A paid payment whose amount does not match is logged and not recorded. Staff can check it in
  Acme Pay and record it by hand. If your provider allows partial payments on purpose, record the
  amount that arrived instead, but still check the currency and the invoice.
- To add saved cards, implement `SavesPaymentMethods` (and ideally `ChecksSavedCharges`) as
  described above. Look at Stripe for a full example.
- If your provider does not sign webhooks, remove the signature check and keep `confirm()`. The
  webhook then only tells you which payment to check again, like FIB.

### Try it on your own copy

1. Put the folder in `extensions/gateways/acmepay`.
2. Switch the gateway on and fill in its settings on the gateway's settings page.
3. Copy the webhook URL shown on that page into the provider's test dashboard.
4. Use the provider's test mode and test keys. Never test with live keys.

See [packages](packages.md) for the automatic checks and how to send it to the Marketplace, and
[selling](selling.md) for prices and payouts.

## Security checklist

Go through this list before you ship a gateway.

**Webhooks**

- [ ] Verify every webhook signature on the raw body (`$request->getContent()`), with `hash_equals()`.
- [ ] Reject a missing secret, a missing header and old time stamps.
- [ ] Return `WebhookResult::invalid()` for a bad signature. Nothing is recorded.
- [ ] Keep webhook result messages short and free of secrets. The message is sent back to the caller.
- [ ] If the provider does not sign webhooks, use the webhook only as a hint and read the status from
      the provider's API, like FIB.
- [ ] Only accept payments your site started (a payment intent, or your own marker in metadata).
      Never trust fields a buyer can set.

**Amounts and currencies**

- [ ] Check the paid amount and currency against what you asked for (the intent or the invoice). The
      core checks the currency, but allows any positive amount.
- [ ] `supportsCurrency()` allows only currencies the provider charges correctly with two decimals.
- [ ] Return `false` from `convertsCurrency()` if you never convert. If you do convert, use `quote()`,
      `chargeDetails()` and `resultFor()`.

**Return pages**

- [ ] Never treat a return to `$returnUrl` as proof of payment. Ask the provider's API.
- [ ] `handleReturn()` checks that the payment belongs to the invoice it was given.

**Idempotency**

- [ ] Set `reference` to the provider's stable, non-empty payment ID, the same in every path.
- [ ] Return results. Do not call `PaymentRecorder` yourself.
- [ ] Reuse an open intent with `PaymentIntent::latestOpenFor()` so a reload does not start a second payment.
- [ ] Send the AutoPay attempt key as the provider's idempotency key, and return `pending` when the
      answer is unclear.

**Secrets and errors**

- [ ] Use `'type' => 'password'` for every key and secret. Settings are encrypted at rest.
- [ ] Never log keys, tokens or full API responses, and never put them in exception messages (those
      go to the activity log).
- [ ] Never print a setting into a page as raw HTML. Use `PaymentStart::instructions()` for text.
- [ ] Use short HTTP timeouts (for example `connectTimeout(5)` and `timeout(15)`).
- [ ] Keep TLS certificate checks on. Never call `withoutVerifying()`.
- [ ] Only redirect to `https://` URLs that came from the provider's API.
- [ ] For `PaymentStart::qr()`, pass only an image and app links that came from the provider.
      Nuvabill puts each app link in a button as it is, so never build one from client input.
- [ ] List `payments` and one `http:{host}` permission for each API host in the manifest.
