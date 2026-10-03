# Automatic payments

Clients can save a card or a PayPal account, and Nuvabill charges it for their renewal invoices by itself. The card
number never touches your server: Stripe keeps cards and PayPal keeps PayPal accounts. Nuvabill only keeps a
reference, the card brand, the last four digits and the expiry date.

## How clients save a card or PayPal account

- **When they pay an invoice.** Under the payment methods on the invoice page there is a box: **Save it and pay my
  renewals automatically**. It is never ticked for them.
- **In Account → Payment methods.** **Add a card** or **Add PayPal** opens Stripe or PayPal to save it without paying
  anything. The same page shows the saved methods, the next automatic payments, and a switch to turn automatic payments
  off. The newest saved method pays renewals; clients can pick another with **Make default**, or remove one.

Only gateways that can charge a saved method offer this: **Stripe** and **PayPal**. Bank transfer, FIB, FastPay and Wayl
stay "pay by hand".

## What gets charged, and when

The nightly job (the cron job from the [installation guide](installation.md)) does this before it sends overdue
reminders:

1. **Renewal invoices only.** First orders and invoices you make by hand are never charged by themselves.
2. **The wallet first.** If the client has wallet credit, it pays the invoice, or part of it. Only the rest is charged.
3. **On the due date**, or a number of days before it that you choose.
4. **An email a few days before** (3 by default) says the amount, the date and which card or PayPal account pays.

If a charge fails, the client gets an email, and a Telegram or WhatsApp message if they linked one, with a **Pay now**
button. Nuvabill tries again after 3 and 7 days (counted from the first try, and you can change the days). No overdue
reminder is sent while a retry is waiting. After the last try, your usual overdue reminders and suspension rules take
over.

If the client's bank wants them to confirm the payment themselves (3-D Secure), Nuvabill does not try again: the client
gets the email and pays on the invoice page.

Thirty days before a saved card expires, the client gets an email asking them to add a new one.

## Settings

**Settings → Automatic payments**:

| Setting | Default |
|---|---|
| Charge saved cards and PayPal accounts for renewals | On |
| Charge this many days before the due date | 0 (on the due date) |
| Email clients this many days before a charge | 3 (0 sends no email) |
| If a charge fails, try again after (days) | 3, 7 |
| Remind clients 30 days before a saved card expires | On |
| Offer "Save it" when clients pay an invoice | On |

The same page lists which payment methods can charge automatically, what will be charged tomorrow, and how many
charges worked and failed in the last 30 days.

The emails are **Automatic payment coming up**, **Automatic payment failed** and **Saved card expires soon** in
**Settings → Email templates**. The chat message is **Automatic payment failed** in **Settings → Chat apps**. On a site
connected to WhatsApp, Nuvabill sends its template to Meta for approval by itself within an hour of the update.

## For staff

- **The invoice page** has an **Automatic payment** card on unpaid invoices: which method pays it and when, or why a
  charge failed and when it tries again. **Charge now** charges it right away.
- **The client page** lists the client's saved methods. Staff can remove one; only the client can add one.
- Every saved method, removal and automatic payment is written to the activity log.

## Gateway setup

**Stripe.** Nothing new to set up: the same secret key and webhook are used. The webhook needs the events
`checkout.session.completed`, `checkout.session.async_payment_succeeded` and `payment_intent.succeeded`. Cards are
saved to a Stripe customer, and charges use the card the client saved.

**PayPal.** Saving PayPal accounts needs PayPal's vault. In **developer.paypal.com → Apps & Credentials**, open your
app and turn on **Save payment methods** (under Features). Without it, PayPal still takes normal payments, and saving
shows an error. A payment PayPal is still processing (for example an eCheck) is checked with PayPal every night, and
the account is not charged again meanwhile. Also turn on **Block accidental payments** (block more than one payment per
invoice ID) in your PayPal account's payment settings: when PayPal did not answer a charge, the next try then cannot
take the money twice.

## Demo

The demo shows saved cards and a failed charge, but never charges anything.
