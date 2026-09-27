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

> **Status: v0.2.** Domains, four control panels, two VPS panels, Iraqi payment gateways and a WHMCS importer. See the [roadmap](#roadmap).
>
> **Try it:** the [live demo](https://demo.nuvabill.com/admin/login) has sample clients, invoices and tickets. One click signs you in, and the data resets every hour.

---

## Getting started

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

![Store](.github/screenshots/store.png)

### A professional client area

Clients see everything at a glance: their services, domains that expire soon, unpaid invoices and support tickets,
with shortcuts and their account details on the side. The menu bar and colours follow your brand.

![Client area](.github/screenshots/client-area.png)

### Built for phones first

The whole client area works on a phone: services, invoices, payments, tickets and account details. Light and dark mode follow the device.

![Client area on phones](.github/screenshots/mobile.png)

### Billing that runs itself

- Invoices with PDF download, partial payments, account credit for overpayments, drafts and manual invoices
- Renewal invoices created before the due date, grouped per client
- Overdue reminders, automatic suspension, optional termination
- **Paying unsuspends automatically**

![Invoice with payment recording](.github/screenshots/invoice.png)

### Domains

Clients search for a domain, see the price, and order it on its own or with a hosting plan. Nuvabill registers,
transfers and renews it at **ResellerClub, Namecheap, Enom or OpenSRS**, sends expiry reminders, and lets clients
change nameservers. Without a registrar, availability comes from free RDAP lookups.

### Get paid your way

**Stripe Checkout**, **PayPal Checkout** and **bank transfer** are built in, with refunds from the invoice page.
For Iraq: **FIB** (First Iraqi Bank, with QR code), **FastPay** and **Wayl**. Card numbers never touch your server.
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
- **Two-factor sign-in** for staff and clients: an authenticator app with recovery codes, or codes by email. Make it optional or required.
- **CAPTCHA** (Cloudflare Turnstile, reCAPTCHA or hCaptcha) on the forms you choose
- **Fraud checks** on new orders: throwaway email addresses, too many orders from one IP, and country mismatch
- **Sign in with Google, GitHub or Facebook**
- Activity log of everything that happens
- Security headers on every page (content security policy, no framing, strict HTTPS) and a safe setup for Cloudflare

![Clients](.github/screenshots/clients.png)

### Signed one-click updates

Nuvabill checks for new versions every day. Every release is **signed**: the updater refuses anything with a wrong signature,
backs up your files and database first, and **rolls back automatically** if something fails.
Security fixes can install themselves at night.

![Updates](.github/screenshots/updates.png)

### Extensions and themes

Payment gateways and server modules are drop-in folders in `extensions/`. Client-area themes live in `themes/`.
The built-in gateways, control panel modules and domain registrars use the same system, so they are complete examples.
A marketplace for themes and extensions is on the [roadmap](#roadmap).

---

## License

Nuvabill is free software under the **[GNU Affero General Public License v3.0 or later](LICENSE)**, with one additional term:
the **"Powered by Nuvabill"** credit in the client area, invoices and emails must stay visible. See [NOTICE](NOTICE).

**Want only your own brand?** A **White-label License** that removes the credit, plus priority support,
is coming soon. Watch [nuvabill.com](https://nuvabill.com) or star this project to hear first.

Copyright © 2026 RapidNet Ltd. Nuvabill is a product of RapidNet Ltd.

---

## Roadmap

| Version | Focus |
|---|---|
| v0.1 | First sale: store, billing, Stripe/PayPal/bank transfer, cPanel, tickets, 2FA, signed updater |
| **v0.2** | Domains and registrars, DirectAdmin, Plesk, Proxmox, Virtualizor, FIB/FastPay/Wayl, refunds, **WHMCS importer**, fraud checks, social login, CAPTCHA, client 2FA |
| v0.3 | Taxes, multi-currency, promo codes, quotes, client wallet, affiliates, REST API |
| v0.4 | White-label licenses, marketplace, extension SDK, right-to-left languages, passkeys |
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
</details>

<details>
<summary><strong>Writing a theme</strong></summary>

Copy `themes/nova` to `themes/yourtheme`, change `theme.json`, and edit the views. Any view your theme does not include
falls back to Nova. Choose the theme in **Settings → Look and feel**.
</details>

<details>
<summary><strong>Releasing (maintainers)</strong></summary>

1. Set the new version in `config/nuvabill.php`.
2. Commit, then push a tag with the same version: `git tag v0.1.1 && git push origin v0.1.1`.
3. The release workflow builds the zip, signs it with the `NUVABILL_SIGNING_KEY` secret and publishes the GitHub release.
4. For security releases, add `[security]` to the release notes so installs can apply them automatically.

The public half of the signing key is in `config/nuvabill.php`. Never commit the secret half.
</details>

## Support Nuvabill

Nuvabill is free. If it helps your business, please support its development on
[GitHub Sponsors](https://github.com/sponsors/meroxis). Sponsors keep new features and security updates coming.

## Contributing

Bug reports and pull requests are welcome. By sending a pull request you agree that your contribution may be
distributed by RapidNet Ltd under the AGPL-3.0 license and under the Nuvabill commercial White-label License.
