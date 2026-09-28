# Automations

An automation is a rule: **when this happens, and these conditions are true, do these steps.** Nuvabill runs it for
you and logs what it did. Open **Automations** in the admin area. Staff need the **Create and switch automations on
and off** permission, which roles that can manage settings have.

## Start with a template

The Automations page lists nine ready-made templates. Pick one, change what you like, save it, try it, and switch
it on.

| Template | What it does |
|---|---|
| Late fee after 7 days | Adds a 5% fee (at least 1, at most 20) to invoices over 10 that are 7 days late, unless the client has the tag VIP, and emails the client. A week later, if the invoice is still unpaid, it sends a last reminder. |
| Welcome new clients | A welcome email when a client signs up, and tips three days later. |
| Thank you after the first payment | A short thank-you when a new client pays for the first time. |
| Domain expires soon | A reminder 30 days before a domain expires. |
| Ask for a review after 30 days | Asks for a review 30 days after a service is set up, only if the client has no open tickets. Change the review link to your own page. |
| Chase quotes that were not answered | A reminder 5 days after sending a quote. |
| Win back clients who left | A coupon 14 days after a service is terminated. Create the coupon in **Coupons** first. |
| Tell the team about big orders | An email to staff when an order total is over 200. |
| VIP tickets first | Tickets from clients tagged VIP get high priority and one staff member. |

A new automation is **off** until you switch it on, so you can try it first.

## What starts an automation

| Starting point | When |
|---|---|
| A client signs up | Right away |
| An order is placed | Right away |
| An invoice is paid | Right away |
| An invoice is due soon | A number of days before the due date |
| An invoice is overdue | A number of days after the due date |
| A service is set up | Right away |
| A service is suspended | Right away, also for suspensions by the nightly billing job |
| A service is terminated | Right away |
| A service has been active for a while | A number of days after it was set up |
| A domain expires soon | A number of days before it expires |
| A client opens a ticket | Right away |
| A client replies to a ticket | Right away, for every reply |
| A quote is not answered | A number of days after it was sent |

Starting points with a number of days are checked **once a day, from 9:00**. When you switch an automation on, it
picks up nothing older than a week, so a new late fee rule does not charge invoices that were late months ago.

## Conditions

Add conditions when an automation should only run sometimes. All conditions must be true.

- **The client**: has or does not have a tag, their country, how many paid invoices, active services and open
  tickets they have.
- **The invoice**: its total and status. **The order**: its total. **The service**: its product and status.
  **The ticket**: its department, priority and status. **The quote** and **the domain**: their status.

Amounts are compared in the invoice's or order's own currency.

## Steps

| Step | What it does |
|---|---|
| Email the client | Sends your subject and message. Placeholders like `{{ client.first_name }}`, `{{ invoice.number }}`, `{{ invoice.total }}` and `{{ invoice.url }}` are filled in, and Markdown works, for example `**bold**`. |
| Email staff | The company address or one staff member. |
| Add a fee to the invoice | A percent of the total or a fixed amount, with an optional lowest and highest amount. Only for unpaid invoices, and by default only once per invoice. |
| Add wallet credit | Up to 1,000, with the line the client sees in their wallet. |
| Tag the client / Take a tag off the client | For example VIP or Reseller. |
| Open a ticket | In the department you choose, with a priority, and an optional email to the client. |
| Assign the ticket | To a staff member. |
| Change the ticket priority | Low, medium or high. |
| Suspend the service / Unsuspend the service | Also on the control panel, with the reason you give. |
| Send to a web address | A POST request with JSON to an HTTPS address on the internet (see below). |
| Wait | 1 to 365 hours or days. |
| Only go on if | Stops the run here unless a condition is true, for example "the invoice is unpaid". |

**Waiting runs stop by themselves.** When a run goes on after a wait, it first checks that what started it is still
true. If the late invoice was paid, the suspended service is back on or the quote was answered, the run stops and
the log says why.

Automations do not start other automations. A **Suspend the service** step, for example, does not start your
"a service is suspended" automations.

## Placeholders

| Placeholder | Filled in with |
|---|---|
| `{{ client.first_name }}`, `{{ client.name }}`, `{{ client.email }}`, `{{ client.company_name }}` | The client |
| `{{ invoice.number }}`, `{{ invoice.total }}`, `{{ invoice.balance }}`, `{{ invoice.due_date }}`, `{{ invoice.url }}` | The invoice |
| `{{ service.product }}`, `{{ service.domain }}`, `{{ service.next_due_date }}`, `{{ service.url }}` | The service |
| `{{ ticket.number }}`, `{{ ticket.subject }}`, `{{ ticket.department }}`, `{{ ticket.url }}` | The ticket |
| `{{ order.number }}`, `{{ order.total }}` | The order |
| `{{ company.name }}`, `{{ company.email }}`, `{{ company.url }}` | Your company |

## Try it

On an automation's page, **Try it** takes an invoice number, a client's email address or number, a service number or
domain, a ticket, order or quote number, or a domain name. It shows whether the conditions are met and what each step would do, for example "Would add
$2.50 'Late payment fee' to INV-0313". Nothing changes and nothing is sent.

## Runs

**Automations → Runs** lists every run: when it started, what it was about, what each step did, and runs that are
waiting with the time they go on. Filter by automation or status. Switching an automation off, or deleting it, stops
its waiting runs.

**Nothing runs twice for the same event.** Each automation runs once per invoice, client, service or ticket for each
event (and once per reply for ticket replies), even if a payment gateway reports the same payment twice.

## Send to a web address

Nuvabill sends a POST request with a JSON body to the HTTPS address you enter, for example to Zapier, Make or your
own system. Addresses on your own network are refused, redirects are not followed, and it waits 10 seconds at most.

```json
{
    "trigger": "invoice.overdue",
    "subject": {"type": "invoice", "id": 313, "label": "INV-0313"},
    "client": {"id": 7, "name": "Mer Las", "email": "mer@example.com", "country": "IQ", "tags": ["VIP"]},
    "data": {"invoice": {"number": "INV-0313", "total": "$52.50", "due_date": "22 Sep 2026", "url": "..."}},
    "sent_at": "2026-09-29T09:00:12+00:00"
}
```

If the address answers with an error, the run is marked **Failed** and the log shows the status code.

## Client tags and ticket assignment

- **Client tags**: edit a client and add tags separated by commas, for example `VIP, Reseller`. They show on the
  client's page. Only staff see them.
- **Assign a ticket**: on the ticket page, choose a staff member under **Assigned to**. The ticket list shows who
  has each ticket.

## The cron job

Automations use the cron job you already have (see [installation](installation.md)). It runs
`php artisan nuvabill:automations` every five minutes. To check the timed starting points right now, run:

```
php artisan nuvabill:automations --scan
```
