# Roadmap

Where Nuvabill is going, and what has to be true before we call it **1.0**. Dates are goals, not promises. What has
already shipped is in the [changelog](CHANGELOG.md).

## Done

| Version | What it brought |
|---|---|
| 0.1 | Store and checkout, invoices with PDFs, renewals, reminders and suspensions, Stripe, PayPal and bank transfer, cPanel automation, support tickets, staff roles, two-factor sign-in, signed one-click updates |
| 0.2 | Domains with ResellerClub, Namecheap, Enom and OpenSRS; DirectAdmin, Plesk, Proxmox VE and Virtualizor; FIB, FastPay and Wayl; refunds; the WHMCS importer; fraud checks; social sign-in; CAPTCHA; client two-factor sign-in |
| 0.3 | Marketplace with signed one-click installs and a developer program, add-on extensions, coupons, product add-ons, exchange rates, one-page ordering |
| 0.4 | Taxes, quotes, client wallet, affiliates, REST API, White-label License, passkeys, 26 languages with right-to-left pages, one-command install, backups, faster store pages, Site health (security and database checks), billing jobs that never bill twice, importers for Blesta, FOSSBilling and Paymenter with a dry run, all extensions on one page, free CyberPanel, HestiaCP, VirtFusion and SolusVM modules, search engine tools (sitemap, titles and descriptions, prices on Google, language links) |
| 0.5.0 | Automations ("when this happens, do that") with 9 templates, Try it and a runs log; client tags; ticket assignment |
| 0.5.1 | AI help with your own Claude key: reply drafts, two-way ticket translation, summaries, product texts, a monthly spending limit |
| 0.5.2 | Telegram and WhatsApp: invoices, reminders and ticket replies in chat apps, clients link by QR code, WhatsApp connects by QR code, tickets from chats, staff alerts in a Telegram group |
| 0.5.3 | A clear note while connecting WhatsApp by QR code is not available yet |
| 0.5.4 | Ready for WhatsApp by QR code on every site: the store's Meta app address, hourly template checks |
| 0.5.5 | Admin phone app: install the admin area on your phone, a Today screen, push alerts for orders, payments and tickets |
| 0.6.0 | Automatic payments: clients save a card or PayPal account, renewals are charged by themselves, wallet first, retries and notices |
| 0.6.1 | A grouped Settings menu with search; add-ons can show your company website and home page (the Website Builder); 10 languages |

## Next

| Version | Focus |
|---|---|
| 0.6.2 | **Client upgrades and downgrades** with prorated prices |
| 0.6.3 | **Emails and PDF invoices in the client's language** |
| 0.6.4 | **Knowledge base, announcements and a network status page** |
| 0.6.5 | **Credit notes, CSV exports** for accountants, and **privacy tools** (export or erase a client's data) |
| Later | **ClientExec importer**, once we have tested it against a real ClientExec database |

## The path to 1.0

1.0 means: you can run a real hosting business on Nuvabill, move to it from WHMCS without surprises, and trust it with
payments for years. These are the gaps we still see.

### Billing

- **Client self-service upgrades and downgrades** with prorated prices.
- **Credit notes** and accounting exports (CSV, and later Xero or QuickBooks).
- **Emails and PDF invoices in the client's language**; today they are in English.

### Clients and support

- **Knowledge base and announcements** in the client area.
- **Network status page** for planned work and outages.
- **Privacy tools**: export or erase a client's data on request.
- **SMS notifications** for reminders and sign-in codes.

### Integrations

- More payment gateways, registrars and server modules, starting with the ones WHMCS users ask for most.
- Tested end to end against real accounts: every server module, registrar and gateway in sandbox mode, on each release.

### Trust

- An **independent security audit** and a public `SECURITY.md` with a disclosure process.
- **Accessibility**: the client area and checkout meet WCAG 2.2 AA.
- **Performance**: tested with 10,000+ clients and 100,000+ invoices.
- A clear **upgrade promise**: every 0.x site updates to 1.0 with one click, and 1.x keeps working with 1.0 extensions.

### Project

- A contributing guide, issue templates and a code of conduct.
- Complete documentation on [nuvabill.com/docs](https://nuvabill.com/docs/) for every setting and integration.

### After 1.0

Theme editor, multi-brand (several companies on one Nuvabill), and a hosted cloud edition.

## Have an idea?

Open an issue on [GitHub](https://github.com/meroxis/nuvabill/issues). Tell us what you run and what you miss from
your current billing system; that is how we decide what comes next.
