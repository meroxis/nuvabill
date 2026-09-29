# FAQ

## Is Nuvabill really free?

Yes. Nuvabill is free software under the [GNU AGPL v3.0 or later](../LICENSE). There is no license fee and no
per-client pricing. The one condition: the "Powered by Nuvabill" credit in the client area, invoices and emails must
stay visible (see [NOTICE](../NOTICE)).

## Can I remove "Powered by Nuvabill"?

Yes, with a [White-label License](https://my.nuvabill.com/white-label). Enter its key under **Settings → License**.

## What do I need to run it?

PHP 8.3 or newer, MySQL 8 or MariaDB 10.6+ (or SQLite for small sites), and a cron job every minute. Most shared hosting
accounts with cPanel, DirectAdmin or Plesk have this. See [Installation](installation.md).

## Can I move from WHMCS, Blesta, FOSSBilling or Paymenter?

Yes. The importer first shows a dry run of what would come across, then copies clients (with their passwords),
products, services, domains, invoices, payments, wallet credit and tickets, and can run again before you switch. See
[Migrating from WHMCS](whmcs-migration.md) and [Moving from Blesta, FOSSBilling or Paymenter](importing.md).

## Which control panels, gateways and registrars work?

- Control panels and VPS: cPanel & WHM, DirectAdmin, Plesk, Proxmox VE and Virtualizor, plus CyberPanel, HestiaCP,
  VirtFusion and SolusVM free on the Marketplace ([guide](integrations/servers.md))
- Payments: Stripe, PayPal, FIB, FastPay, Wayl and bank transfer ([guide](integrations/payment-gateways.md))
- Registrars: ResellerClub, Namecheap, Enom and OpenSRS ([guide](integrations/domain-registrars.md))

More can be added as extensions or installed from the Marketplace.

## Does it charge saved cards automatically?

Not yet. Clients pay each invoice on the gateway's checkout page, or from their Nuvabill wallet (renewals and orders use
the wallet balance first). Automatic card payments are on the [roadmap](../ROADMAP.md).

## Which languages does it speak?

The client area and the admin area speak 26 languages, including Arabic, Hebrew and Kurdish (Sorani), which read right
to left. Emails and PDF invoices are in English.

## How do updates work?

**Updates** in the admin area shows new versions and what changed. Every release is signed; the updater checks the
signature, backs up your files and database first, and rolls back if something fails. Security fixes can install
themselves at night.

## How do I back up my site?

`php artisan nuvabill:backup` makes a backup of the whole site, or only the database with `--database`; add
`--password=…` to encrypt it. The free **Google Drive backup** add-on on the Marketplace runs backups on a schedule and
keeps the newest copies in your Google Drive.

## Is it secure?

Staff and clients can use two-factor sign-in and passkeys, forms can have a CAPTCHA, every page sends security headers,
payment notices are checked before they are trusted, and marketplace packages are reviewed and signed. **Setup → Site
health** checks the site every night (staff access, file permissions, what strangers can open, settings, Nuvabill's own
files, backups and the database) and emails staff about new urgent issues. Found a security problem? Please email
security@nuvabill.com instead of opening a public issue (see [SECURITY.md](../SECURITY.md)).

## Will Google find my store?

Yes. Nuvabill makes a sitemap and robots.txt, gives every store page a title and description, shows prices on Google
and links your language versions, with every theme. Add your site to Google Search Console and send it your
sitemap. See the [search engines guide](search-engines.md).

## Can Nuvabill add late fees or send follow-up emails by itself?

Yes. **Automations** do "when this happens, do that": add a late fee after 7 days, welcome new clients, chase quotes,
remind clients before a domain expires, give VIP tickets high priority, and more. Start from one of nine templates,
try it on a real invoice first, and see every run in the log. See the [automations guide](automations.md).

## Does it have AI?

Yes, with your own Anthropic key: Claude drafts ticket replies, translates tickets both ways, summarizes tickets and
writes product texts. Staff always check and send; AI never answers by itself. Passwords, card details, email addresses
and phone numbers are taken out before anything goes to the AI, and a monthly limit keeps spending in check. See the
[AI help guide](ai-help.md).

## Can clients get invoices on WhatsApp or Telegram?

Yes. Connect Telegram with a bot token, and WhatsApp by scanning a QR code with the WhatsApp Business app or with your
own Meta app. Clients scan a QR code in the client area to link their chat app, then get invoices, reminders and ticket
replies there next to the email, and can write to support from the chat. Telegram is free; Meta charges a small fee for
WhatsApp messages you send first. See the [Telegram and WhatsApp guide](chat-apps.md).

## Is there a phone app for staff?

Yes. The admin area installs on iPhone and Android from the browser, with no app store. It opens on a Today screen with
the day's orders, payments and tickets, and can send push alerts for new orders, payments and tickets. See the
[phone app guide](phone-app.md).

## Can the nightly job bill a client twice?

No. Only one automation run works at a time, and each renewal period can only be invoiced once: the database itself
refuses a second invoice for the same period, even if two runs meet. Reminders and notices are claimed before they are
sent, so they go out once.

## Is there an API?

Yes: a REST API at `/api/v1`. See the [API guide](https://nuvabill.com/docs/api/).

## Who makes Nuvabill?

RapidNet Ltd, a hosting company. Nuvabill is developed in the open on
[GitHub](https://github.com/meroxis/nuvabill).
