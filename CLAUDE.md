@AGENTS.md

# Nuvabill

Billing, automation and support platform for hosting companies (a from-scratch WHMCS alternative).
Free edition shows "Powered by Nuvabill"; a paid white-label license removes it (license server arrives in v0.4).

## Project rules

- Money is stored as integer minor units (cents) with a currency code. Never use floats for money.
- Client-area pages render through the active theme (`themes/{slug}/views`, view namespace `theme::`), falling back to `themes/nova`.
- Payment gateways and server modules are extensions in `extensions/{gateways|servers}/{slug}` with an `extension.json` manifest; built-in ones use the same mechanism as marketplace ones.
- Staff (admins) and clients are separate models and guards (`admin` and `web`).
- Email templates stored in the database use `{{ placeholder }}` substitution, never Blade compilation.
- Windows dev machine: prefix shell commands with a PATH refresh if `php`/`composer` are not found.
