# Payment gateways

Turn gateways on in **Settings → Payment gateways**. Clients choose one when they pay an invoice. Card numbers never
touch your server: clients pay on the gateway's own checkout page. Payment notices (webhooks) are checked before they
are trusted, and each payment is recorded only once, even if the gateway sends it twice.

Every gateway has a **Name shown to clients** field, so you can call it, for example, "Credit or debit card".

## Stripe

- Cards, Apple Pay and Google Pay through **Stripe Checkout**.
- **Secret key**: from **Stripe Dashboard → Developers → API keys** (starts with `sk_live_` or `sk_test_`).
- **Webhook signing secret**: add a webhook in Stripe for the URL shown on the gateway's settings page, with the event
  `checkout.session.completed`, and paste its signing secret (`whsec_…`).
- Refunds can be made from the invoice page.

## PayPal

- PayPal balance, cards and Pay Later through **PayPal Checkout**.
- **Mode**: live payments or sandbox (testing).
- **Client ID** and **Secret**: from **developer.paypal.com → Apps & Credentials**.
- **Webhook ID** (optional): add a webhook for the URL shown on the settings page with the event
  `PAYMENT.CAPTURE.COMPLETED` and paste its ID.
- Refunds can be made from the invoice page.

## FIB (First Iraqi Bank)

- Clients scan a **QR code** or enter a code in the FIB app. Iraqi dinar only.
- **Client ID** and **Client secret**: FIB sends them after you apply with FIB's integration request form.
- **Mode**: live, or test on FIB's stage system.

## FastPay

- FastPay wallet checkout. Iraqi dinar only.
- **Store ID** and **Store password**: FastPay emails them when your merchant account is ready.
- In the FastPay merchant panel, set the IPN URL to the webhook URL shown on the settings page.
- **Mode**: live, or test on FastPay's staging system.

## Wayl

- Wayl payment links: cards, wallets and bank transfers in Iraq. Iraqi dinar only.
- **API token**: email Wayl from your Wayl account to get one.
- **Mode**: live or test.

## Bank transfer

- Shows your bank details on the invoice. Staff mark the invoice paid when the money arrives.
- **Payment instructions**: your bank details. `{invoice}` becomes the invoice number and `{amount}` the amount due.

## Prices in dollars, payment in dinar

FIB, FastPay and Wayl only take Iraqi dinar. Set a US dollar to dinar rate in **Settings → Currencies**: the invoice
stays in dollars, the client pays the converted amount in dinar, and the invoice is marked paid in full.

## Not listed here?

Other gateways can be added as extensions (gateways extend `App\Extensions\Gateways\Gateway`); see the
[developer guide](https://nuvabill.com/docs/developers/).
