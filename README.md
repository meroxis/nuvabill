# Nuvabill

**Billing, automation and support for hosting companies. Free to run.**

Nuvabill sells your hosting plans, bills clients every month, sets up their accounts automatically,
suspends late payers, and handles support tickets. It is a modern, open alternative to WHMCS,
written from scratch.

[![Tests](https://github.com/nuvabill/nuvabill/actions/workflows/tests.yml/badge.svg)](https://github.com/nuvabill/nuvabill/actions/workflows/tests.yml)

> Status: **v0.1** (first release). Good for small hosting companies and for testing.
> See the [roadmap](#roadmap) for what comes next.

## What you get in v0.1

- **Store and checkout.** Product groups, plans with monthly to three-yearly prices, setup fees, domain field, cart and checkout.
- **Billing.** Invoices with PDF, recurring renewals, partial payments, account credit for overpayments, manual invoices and drafts.
- **Payments.** Stripe Checkout, PayPal Checkout and bank transfer. Webhooks are signature-checked and each payment is recorded once.
- **Automation.** Every night: renewal invoices, overdue reminders, automatic suspension, optional termination. Paying unsuspends automatically.
- **cPanel & WHM.** Accounts are created when the first invoice is paid. Suspend, unsuspend, terminate, change package, and one-click login for clients.
- **Support tickets.** Departments, priorities, email notifications to clients and staff.
- **Client area.** Services, invoices, payments, tickets and account details, built for phones first. Light and dark mode.
- **Staff.** Roles with permissions, two-factor login (authenticator apps), activity log.
- **Editable emails.** Every email is a template with `{{ placeholders }}`.
- **Extensions and themes.** Payment gateways and server modules are drop-in folders in `extensions/`. Client themes live in `themes/`.
- **Web installer.** Upload, open the site, answer three screens.
- **Signed one-click updates.** Every release is signed. The updater verifies the signature, backs up files and database, and rolls back if anything fails.

## Requirements

- PHP 8.3 or newer with `pdo`, `openssl`, `mbstring`, `intl`, `curl`, `zip`, `sodium`, `bcmath`, `fileinfo`
- MySQL 8 / MariaDB 10.6 or newer (SQLite works for small sites and testing)
- A cron job that runs every minute

Most cPanel, DirectAdmin and Plesk hosting accounts meet these requirements.

## Install

1. Download `nuvabill-x.y.z.zip` from the [latest release](https://github.com/nuvabill/nuvabill/releases/latest).
2. Upload it to your hosting account and unzip it. Point your domain (or subdomain) to the `public` folder.
3. Create an empty MySQL database and user in your hosting panel.
4. Open your domain in a browser. The installer checks your server, connects the database and creates your owner account.
5. Add the cron job shown in **Settings → Automation**:

   ```
   * * * * * cd /path/to/nuvabill && php artisan schedule:run >> /dev/null 2>&1
   ```

6. In the admin area, add your cPanel server (**Servers**), turn on a payment gateway (**Settings → Payment gateways**) and set up outgoing email (**Settings → Sending email**).

The admin area is at `/admin`.

## Updating

Nuvabill checks GitHub for new releases every day. Go to **Updates** in the admin area and press **Update now**.
You can also let security fixes (or every update) install automatically at 03:00.

From the command line:

```
php artisan nuvabill:update
```

## Development

```
git clone https://github.com/nuvabill/nuvabill.git
cd nuvabill
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
```

Set `NUVABILL_INSTALLED=true` and `APP_ENV=local` in `.env`, then load the demo data:

```
php artisan migrate:fresh --seed
php artisan serve
```

Demo sign-ins: staff `admin@nuvabill.test`, client `client@nuvabill.test`, both with password `nuvabill-demo`.

Run the tests and the code style check:

```
php artisan test
vendor/bin/pint
```

### Writing an extension

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
The built-in Stripe, PayPal, bank transfer and cPanel extensions are complete examples.

### Writing a theme

Copy `themes/nova` to `themes/yourtheme`, change `theme.json`, and edit the views. Any view your theme does not
include falls back to Nova. Choose the theme in **Settings → Look and feel**.

### Releasing (maintainers)

1. Set the new version in `config/nuvabill.php`.
2. Commit, then push a tag with the same version: `git tag v0.1.1 && git push origin v0.1.1`.
3. The release workflow builds the zip, signs it with the `NUVABILL_SIGNING_KEY` secret and publishes the GitHub release.
4. For security releases, add `[security]` to the release notes so installs can apply them automatically.

The public half of the signing key is in `config/nuvabill.php`. Never commit the secret half.

## Roadmap

| Version | Focus |
|---|---|
| **v0.1** | First sale: store, billing, Stripe/PayPal/bank transfer, cPanel, tickets, 2FA, updater |
| v0.2 | DirectAdmin, Plesk, Proxmox, Virtualizor, domains and registrars, WHMCS importer, fraud checks |
| v0.3 | Taxes, multi-currency, promo codes, quotes, client wallet, affiliates, REST API |
| v0.4 | White-label licenses, marketplace, extension SDK, right-to-left languages, passkeys |
| v0.5 | Automation builder, AI ticket replies, WhatsApp and Telegram bot, admin phone app |
| v1.0 | Security audit, theme editor, status page, multi-brand, cloud edition |

## License

Nuvabill is free software under the **GNU Affero General Public License v3.0 or later**, with one additional term:
the **"Powered by Nuvabill"** credit in the client area, invoices and emails must stay visible. See [NOTICE](NOTICE).

Want your own brand only? A **White-label License** that removes the credit is available at [nuvabill.com](https://nuvabill.com).

## Contributing

Bug reports and pull requests are welcome. By sending a pull request you agree that your contribution may be
distributed under the AGPL-3.0 license and under the Nuvabill commercial White-label License.
