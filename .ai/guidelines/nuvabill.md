# Nuvabill project rules

Nuvabill is a billing, automation and support platform for hosting companies (a from-scratch WHMCS alternative)
by RapidNet Ltd, licensed AGPL-3.0 with an attribution term (see NOTICE).

## Architecture

- Staff (`App\Models\Admin`, guard `admin`, `routes/admin.php` under `/admin`) and clients (`App\Models\Client`, guard `web`) are separate models and guards. Never let one sign in as the other.
- Staff permissions are listed in `Role::PERMISSIONS`. Protect admin routes with the `admin.can:{permission}` middleware.
- Billing lives in `app/Billing`: `OrderPlacer`, `InvoiceManager`, `PaymentRecorder`, `InvoicePaidHandler`, `RenewalGenerator`. Record every payment through `PaymentRecorder` with the gateway's reference so duplicates are ignored and services, invoices and emails stay in step.
- Control panel actions go through `App\Provisioning\Provisioner`. Never call server modules directly from controllers.
- The nightly run is `App\Automation\DailyAutomation` (`php artisan nuvabill:cron`).
- Read settings with the `setting('key')` helper (`App\Support\Settings`: JSON values, cached, secrets encrypted). Add every new key with its default to `Settings::DEFAULTS`.

## Security and demo mode

- `App\Http\Middleware\SecurityHeaders` sets the CSP and other headers on every response. Load scripts, styles and fonts from this site only (built by Vite), never from a CDN.
- Proxies are trusted through `NUVABILL_TRUSTED_PROXIES` (`config/trustedproxy.php`). Only the forwarded IP and protocol headers are read.
- The public demo runs with `NUVABILL_DEMO=true` (`App\Support\Demo`). Add every new route that changes settings, sign-ins or calls another server to `Demo::LOCKED_ROUTES`.

## Money

- Store money as integer minor units (cents) with a currency code. Never use floats.
- Format with the `money()` helper and convert input with `App\Support\Money`.

## Extensions and themes

- Payment gateways and server modules are extensions in `extensions/{gateways|servers}/{slug}` with an `extension.json` manifest. Built-in ones use the same mechanism as marketplace ones.
- Gateways extend `App\Extensions\Gateways\Gateway`. Server modules extend `App\Extensions\Servers\Module`.
- Client-area views render through the `theme::` namespace from `themes/{active}/views`, falling back to `themes/nova`. Admin views live in `resources/views/admin`.
- Shared UI classes are in `resources/css/components.css`. Colors are CSS variables in `resources/css/tokens.css` with light and dark values.

## Email

- Email templates are rows in `email_templates` with `{{ dotted.placeholders }}` replaced by `TemplateMailer::render()`. Never compile templates with Blade.

## Updates and releases

- The version is in `config/nuvabill.php`. Releases are signed zips built by `php artisan nuvabill:package`, and the updater verifies the Ed25519 signature before installing. Never commit the signing secret key.
- Migrations must work on MySQL/MariaDB and SQLite and must be safe to run during an automatic update.

## Branding

- Keep the "Powered by Nuvabill" credit (`App\Support\Branding`) in the client area, invoice PDFs and emails. It is a license requirement.

## Tests

- Feature tests use factories and the `DefaultDataSeeder`. Real HTTP calls are blocked with `Http::preventStrayRequests()`, so fake gateway and cPanel APIs with `Http::fake()`.

## Writing

- Write user-facing text in plain, short English. Many users read English as a second language.
