<h1 align="center">Nuvabill</h1>

<p align="center">
  <strong>Billing, automation and support for hosting companies.<br>Free to run. Open source. Built for phones first.</strong>
</p>

<p align="center">
  <a href="https://github.com/meroxis/nuvabill/actions/workflows/tests.yml"><img src="https://github.com/meroxis/nuvabill/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-0b7a70" alt="License: AGPL-3.0"></a>
  <img src="https://img.shields.io/badge/PHP-8.3%20%7C%208.4-0b7a70" alt="PHP 8.3 and 8.4">
  <a href="https://github.com/sponsors/meroxis"><img src="https://img.shields.io/badge/sponsor-meroxis-0b7a70" alt="Sponsor"></a>
</p>

<p align="center">
  <a href="https://nuvabill.com">Website</a> ·
  <a href="https://demo.nuvabill.com/admin/login">Live demo</a> ·
  <a href="#getting-started">Getting started</a> ·
  <a href="#whats-in-nuvabill">What's in Nuvabill</a> ·
  <a href="#license">License</a>
</p>

![Nuvabill admin dashboard on desktop and the client area on a phone](.github/screenshots/hero.png)

Nuvabill sells your hosting plans and domains, bills clients every month, creates their cPanel, DirectAdmin, Plesk
or VPS accounts the moment they pay, suspends late payers, and answers support tickets. It is a modern, open alternative to WHMCS, written from scratch.
There is **no per-client pricing** and no license fee to run it.

**Install it with a single command:**

```
curl -fsSL https://nuvabill.com/install.sh | bash
```

> **Status: v0.4.** Taxes, a client wallet, quotes, affiliates, a REST API, passkeys, and Arabic and Kurdish with right-to-left pages. See the [roadmap](#roadmap).
>
> **Try it:** the [live demo](https://demo.nuvabill.com/admin/login) has sample clients, invoices and tickets. One click signs you in, and the data resets every hour.

---

## Getting started

### Install with one line

On a **fresh Ubuntu 22.04/24.04 or Debian 12/13 server**, as root:

```
curl -fsSL https://nuvabill.com/install.sh | bash
```

It sets up Nginx, PHP 8.4, MariaDB, a free SSL certificate and the cron job, then asks for your company name and
your owner account. Give your address up front with `bash -s -- --domain billing.yourhost.com`.

On a server that **already runs PHP 8.3+** (SSH, or **Terminal** in cPanel), the same line installs Nuvabill into a
`nuvabill` folder, asks a few questions and adds the cron job. Choose the folder with `--dir`. Every download is
checked against the release signature before anything is unpacked.

Already unpacked Nuvabill yourself? Run `php artisan nuvabill:install` in its folder to install from the terminal
instead of the browser.

Prefer to upload a zip? Follow the steps below.

### 1. Check your hosting

- PHP **8.3 or newer** with `pdo`, `openssl`, `mbstring`, `intl`, `curl`, `zip`, `sodium`, `bcmath`, `fileinfo`
- **MySQL 8** or **MariaDB 10.6+** (SQLite works for small sites and testing)
- A **cron job** that runs every minute

Most cPanel, DirectAdmin and Plesk hosting accounts already have all of this.

### 2. Upload and install

1. Download `nuvabill-x.y.z.zip` from the [latest release](https://github.com/meroxis/nuvabill/releases/latest).
2. Upload it to your hosting account and unzip it. Point your domain or subdomain (for example `billing.yourhost.com`) to the `public` folder.
3. Create an empty MySQL database and user in your hosting panel.
4. Open your domain in a browser. The installer checks your server, connects the database and creates your owner account.

Using **Cloudflare**? Add `NUVABILL_TRUSTED_PROXIES=cloudflare` to `.env`, so sign-in limits and logs see your visitors' real IP addresses.

### 3. Turn on automation

Add this cron job (cPanel → **Cron Jobs**). The exact line for your server is shown in **Settings → Automation**.

```
* * * * * cd /path/to/nuvabill && php artisan schedule:run >> /dev/null 2>&1
```

It creates renewal invoices, sends reminders, suspends overdue services, sends email and installs updates.

### 4. Connect your business

In the admin area (`/admin`):

1. **Servers** → add your cPanel/WHM, DirectAdmin, Plesk, Proxmox or Virtualizor server and press **Test connection**.
2. **Products** → create your plans (or edit the example plans) and choose the package or VPS plan for each.
3. **Settings → Domains** and **Registrars** → set your domain prices and connect ResellerClub, Namecheap, Enom or OpenSRS.
4. **Settings → Payment gateways** → turn on Stripe, PayPal, FIB, FastPay, Wayl or bank transfer.
5. **Settings → Sending email** → add your SMTP details and send yourself a test email.
6. **Settings → Security** → choose two-factor rules and CAPTCHA. Then turn on two-factor login on **Your profile**.

Moving from WHMCS? **Settings → Import** copies your clients (with their passwords), products, services, domains,
invoices and tickets. You can run it again before you switch; it never makes copies.

That's it. Your store is live at your domain.

---

## What's in Nuvabill

### A store your clients enjoy

Plans with monthly to three-yearly prices, setup fees, a domain field, cart and checkout. Clients register in one step,
or with **Google, GitHub or Facebook**.

- **Coupons**: percent or fixed, for the first payment, every payment or a number of payments, limited by product,
  billing cycle, dates, uses and new clients. Share a link like `yourhost.com/?coupon=WELCOME20` and it is applied for the client.
- **Product add-ons** such as backups, a dedicated IP or priority support, billed and renewed with the service.
- Want everything on one page? The **Swift** order form in the marketplace puts plans, domain search, add-ons, coupons,
  sign-up and payment on one screen.

![Store](.github/screenshots/store.png)

### A professional client area

Clients see everything at a glance: their services, domains that expire soon, unpaid invoices and support tickets,
with shortcuts and their account details on the side. The menu bar and colours follow your brand.

![Client area](.github/screenshots/client-area.png)

### Built for phones first

The whole client area works on a phone: services, invoices, payments, tickets and account details. Light and dark mode follow the device.

![Client area on phones](.github/screenshots/mobile.png)

### Billing that runs itself

- Invoices with PDF download, partial payments, drafts and manual invoices
- Renewal invoices created before the due date, grouped per client
- Overdue reminders, automatic suspension, optional termination
- **Paying unsuspends automatically**
- **Taxes**: VAT, GST or sales tax by country and state, prices with or without tax, tax-exempt clients, and tax numbers on invoices
- **Client wallet**: clients add funds and pay invoices with one click; overpayments and refunds land there too

![Invoice with payment recording](.github/screenshots/invoice.png)

### Quotes and affiliates

Send a **quote** for custom work, like a dedicated server or a migration. The client accepts it in their account and
the invoice is created for them. The **affiliate program** gives every client a link: when someone they send pays,
they earn a commission that moves to their wallet after the refund window.

### Domains

Clients search for a domain, see the price, and order it on its own or with a hosting plan. Nuvabill registers,
transfers and renews it at **ResellerClub, Namecheap, Enom or OpenSRS**, sends expiry reminders, and lets clients
change nameservers. Without a registrar, availability comes from free RDAP lookups.

### Get paid your way

**Stripe Checkout**, **PayPal Checkout** and **bank transfer** are built in, with refunds from the invoice page.
For Iraq: **FIB** (First Iraqi Bank, with QR code), **FastPay** and **Wayl**. Card numbers never touch your server.
Show prices in US dollars and charge in Iraqi dinar at **the exchange rate you set** (Settings → Currencies);
the invoice stays in dollars and is marked paid in full.
Webhooks are signature-checked, and each payment is recorded exactly once.

![Payment gateways](.github/screenshots/gateways.png)

### Control panel and VPS automation

**cPanel & WHM, DirectAdmin and Plesk** accounts are created when the first invoice is paid. Suspend, unsuspend,
terminate and change package from the admin area. Clients open their control panel with one click, no password needed.

**Virtualizor and Proxmox VE** virtual servers are managed **inside the client area**, like in WHMCS: start, stop,
restart, live CPU, memory, disk and bandwidth, IP addresses, root password, OS reinstall and VNC details.
API keys stay on your server.

![Product pricing and automatic setup](.github/screenshots/products.png)

### Support tickets

Departments, priorities, email notifications for clients and staff, and a clean conversation view.

![Support ticket](.github/screenshots/support.png)

### A dashboard that tells you what matters

Revenue for the last 12 months, unpaid invoices, tickets waiting for a reply, and a **Needs your attention** list:
failed setups, pending orders, overdue invoices, and a warning if the cron job stops running.

![Light and dark mode](.github/screenshots/light-dark.png)

### Clients, staff and security

- Client profiles with services, invoices, payments, tickets and activity in one place
- Staff **roles and permissions** (Owner, Billing, Support, or your own)
- **Passkeys**: sign in with a fingerprint, face or phone, which fake sign-in pages cannot steal
- **Two-factor sign-in** for staff and clients: an authenticator app with recovery codes, or codes by email. Make it optional or required.
- **CAPTCHA** (Cloudflare Turnstile, reCAPTCHA or hCaptcha) on the forms you choose
- **Fraud checks** on new orders: throwaway email addresses, too many orders from one IP, and country mismatch
- **Sign in with Google, GitHub or Facebook**
- Activity log of everything that happens
- Security headers on every page (content security policy, no framing, strict HTTPS) and a safe setup for Cloudflare

![Clients](.github/screenshots/clients.png)

### Arabic and Kurdish

The whole client area and admin area speak **English, Arabic and Kurdish (Sorani)**, and pages read **right to left**
in Arabic and Kurdish. Clients and staff pick their language from the top bar; you choose the default in
**Settings → General**. Prices keep Latin digits in every language.

### REST API

Connect your own tools with the API at `/api/v1`: clients, services (suspend, unsuspend, terminate), invoices and
payments, products, orders and tickets. Staff make keys under **Your profile → API keys**; a key can do what their
role allows, and can be read-only.

```
curl -H "Authorization: Bearer nb_…" https://billing.yourhost.com/api/v1/invoices?status=unpaid
```

See the [API guide](https://nuvabill.com/docs/api/).

### Signed one-click updates

Nuvabill checks for new versions every day. Every release is **signed**: the updater refuses anything with a wrong signature,
backs up your files and database first, and **rolls back automatically** if something fails.
Security fixes can install themselves at night.

![Updates](.github/screenshots/updates.png)

### Marketplace: themes, order forms and add-ons

Open **Marketplace** in the admin area to browse themes, order forms, gateways and add-ons, and **install them with one click**.
Try a theme on your own site first with **Live preview**, which only you see.

- Every package is **reviewed by people** and **signed**. Nuvabill refuses anything that was changed after signing.
- Before you install, you see in plain words what a package can do, for example "Connects to api.telegram.org".
- Paid items come with a license key for one site. Test sites (`localhost`, `*.test`, `staging.*`, `dev.*`) are always free.
- Updates show on the Marketplace page and install with one click.

Free to start: the **Paper** and **Midnight** themes, **Team chat alerts** (orders, payments and tickets in Telegram or Discord)
and a **Live chat widget** (Tawk.to or Crisp). Premium: the **Aurora** client area and the **Swift** one-page order form.

**Developers** can sell their own themes and extensions on [my.nuvabill.com](https://my.nuvabill.com/developers).
You keep **83%** of every sale. See the [developer guide](https://nuvabill.com/docs/developers/).

![Marketplace](.github/screenshots/marketplace.png)

---

## License

Nuvabill is free software under the **[GNU Affero General Public License v3.0 or later](LICENSE)**, with one additional term:
the **"Powered by Nuvabill"** credit in the client area, invoices and emails must stay visible. See [NOTICE](NOTICE).

**Want only your own brand?** A **[White-label License](https://my.nuvabill.com/white-label)** removes the credit
from your client area, invoices and emails, and adds priority support. Enter the key under **Settings → License**.

Copyright © 2026 RapidNet Ltd. Nuvabill is a product of RapidNet Ltd.

---

## Roadmap

| Version | Focus |
|---|---|
| v0.1 | First sale: store, billing, Stripe/PayPal/bank transfer, cPanel, tickets, 2FA, signed updater |
| v0.2 | Domains and registrars, DirectAdmin, Plesk, Proxmox, Virtualizor, FIB/FastPay/Wayl, refunds, **WHMCS importer**, fraud checks, social login, CAPTCHA, client 2FA |
| v0.3 | Marketplace with one-click installs and a developer program, add-on extensions, coupons, product add-ons, exchange rates, one-page ordering |
| **v0.4** | **Taxes, quotes, client wallet, affiliates, REST API, White-label License, Arabic and Kurdish (right to left), passkeys** |
| v0.5 | Automation builder, AI ticket replies, WhatsApp and Telegram bot, admin phone app |
| v1.0 | Security audit, theme editor, status page, multi-brand, cloud edition |

## Updating

Go to **Updates** in the admin area and press **Update now**, or let updates install automatically.
From the command line: `php artisan nuvabill:update`.

## For developers

```
git clone https://github.com/meroxis/nuvabill.git
cd nuvabill
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
```

Set `NUVABILL_INSTALLED=true` and `APP_ENV=local` in `.env`, then load the demo data and start the server:

```
php artisan migrate:fresh --seed
php artisan serve
```

Demo sign-ins: staff `admin@nuvabill.test`, client `client@nuvabill.test`, both with password `nuvabill-demo`.

Run the tests and the code style check with `php artisan test` and `vendor/bin/pint`.

<details>
<summary><strong>Writing an extension</strong></summary>

An extension is a folder with an `extension.json` manifest and PHP classes in `src/`:

```
extensions/gateways/mygateway/
├── extension.json
└── src/MyGateway.php
```

```json
{
    "slug": "mygateway",
    "type": "gateway",
    "name": "My Gateway",
    "version": "1.0.0",
    "namespace": "Acme\\MyGateway\\",
    "class": "Acme\\MyGateway\\MyGateway",
    "requires": ">=0.1.0"
}
```

Payment gateways extend `App\Extensions\Gateways\Gateway`. Server modules extend `App\Extensions\Servers\Module`.

**Add-ons** (`"type": "addon"`, in `extensions/addons/`) extend `App\Extensions\Addons\Addon`. In `boot()` they can
listen to `OrderPlaced`, `InvoicePaid`, `ServiceActivated`, `TicketOpened` and `TicketReplied` (in `App\Events`),
add HTML to page heads with `headHtml()` and allow extra hosts with `contentSecurityPolicy()`.

List what your package does in `"permissions"`, for example `["events", "http:api.example.com"]`. Staff see it before installing.
</details>

<details>
<summary><strong>Selling on the marketplace</strong></summary>

1. Create a client account on [my.nuvabill.com](https://my.nuvabill.com) and open **Developers → Join**.
2. Upload your package zip. Automatic checks run first, then our team reviews the code.
3. When it is approved, the store signs it and it appears in every Nuvabill admin area.

You set the price and the yearly update price. You keep 83% of each sale, and we pay out monthly.
Read the full [developer guide](https://nuvabill.com/docs/developers/).
</details>

<details>
<summary><strong>Writing a theme</strong></summary>

Copy `themes/nova` to `themes/yourtheme`, change `theme.json`, and edit the views. Any view your theme does not include
falls back to Nova. Choose the theme in **Settings → Look and feel**.
</details>

<details>
<summary><strong>Releasing (maintainers)</strong></summary>

1. Set the new version in `config/nuvabill.php`.
2. Add a `## x.y.z` section to `CHANGELOG.md` in plain words. Site owners see it on their **Updates** page.
3. Commit, then push a tag with the same version: `git tag v0.1.1 && git push origin v0.1.1`.
4. The release workflow builds the zip, signs it with the `NUVABILL_SIGNING_KEY` secret and publishes the GitHub release with that section as its notes.
5. For security releases, add `[security]` to the section so installs can apply them automatically.

The public half of the signing key is in `config/nuvabill.php`. Never commit the secret half.
</details>

## Support Nuvabill

Nuvabill is free. If it helps your business, please support its development on
[GitHub Sponsors](https://github.com/sponsors/meroxis). Sponsors keep new features and security updates coming.

## Contributing

Bug reports and pull requests are welcome. By sending a pull request you agree that your contribution may be
distributed by RapidNet Ltd under the AGPL-3.0 license and under the Nuvabill commercial White-label License.
