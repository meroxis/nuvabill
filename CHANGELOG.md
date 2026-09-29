# Changelog

What changed in each Nuvabill release. The release workflow copies the section for a version into its GitHub
release, and the **Updates** page in the admin area shows it. Write for hosting companies, in plain words.
Add `[security]` to a section to let installs apply it automatically as a security fix.

Every version is also a signed [GitHub release](https://github.com/meroxis/nuvabill/releases). What comes next is in
the [roadmap](ROADMAP.md).

| Version | Released | Highlights |
|---|---|---|
| [0.5.4](https://github.com/meroxis/nuvabill/releases/tag/v0.5.4) | 29 Sep 2026 | Ready for WhatsApp by QR code on every site: the store's Meta app address, hourly template checks |
| [0.5.3](https://github.com/meroxis/nuvabill/releases/tag/v0.5.3) | 29 Sep 2026 | WhatsApp: clear note while connecting by QR code is not available yet |
| [0.5.2](https://github.com/meroxis/nuvabill/releases/tag/v0.5.2) | 29 Sep 2026 | Telegram and WhatsApp: invoices, reminders and ticket replies in chat apps; clients link by QR code; tickets from chats |
| [0.5.1](https://github.com/meroxis/nuvabill/releases/tag/v0.5.1) | 29 Sep 2026 | AI help with your own Claude key: reply drafts, two-way ticket translation, summaries, product texts |
| [0.5.0](https://github.com/meroxis/nuvabill/releases/tag/v0.5.0) | 29 Sep 2026 | Automations: "when this happens, do that", with 9 ready-made templates; client tags; ticket assignment |
| [0.4.11](https://github.com/meroxis/nuvabill/releases/tag/v0.4.11) | 28 Sep 2026 | Search engine tools: sitemap, titles and descriptions, prices on Google, link previews |
| [0.4.10](https://github.com/meroxis/nuvabill/releases/tag/v0.4.10) | 28 Sep 2026 | Free CyberPanel, HestiaCP, VirtFusion and SolusVM modules; remove extensions and themes added by hand |
| [0.4.9](https://github.com/meroxis/nuvabill/releases/tag/v0.4.9) | 28 Sep 2026 | Import from Blesta, FOSSBilling and Paymenter; a dry run before every import; all extensions on one page |
| [0.4.8](https://github.com/meroxis/nuvabill/releases/tag/v0.4.8) | 28 Sep 2026 | Site health (security and database checks); billing jobs never bill twice |
| [0.4.7](https://github.com/meroxis/nuvabill/releases/tag/v0.4.7) | 28 Sep 2026 | Faster, steadier store pages; easier-to-read colours |
| [0.4.6](https://github.com/meroxis/nuvabill/releases/tag/v0.4.6) | 28 Sep 2026 | Marketplace updates show up right away |
| [0.4.5](https://github.com/meroxis/nuvabill/releases/tag/v0.4.5) | 28 Sep 2026 | Extensions can bring their own database tables |
| [0.4.4](https://github.com/meroxis/nuvabill/releases/tag/v0.4.4) | 28 Sep 2026 | 26 languages and a searchable language menu |
| [0.4.3](https://github.com/meroxis/nuvabill/releases/tag/v0.4.3) | 27 Sep 2026 | Fix for MySQL and MariaDB installs |
| [0.4.2](https://github.com/meroxis/nuvabill/releases/tag/v0.4.2) | 27 Sep 2026 | Backups and the Google Drive backup add-on |
| [0.4.1](https://github.com/meroxis/nuvabill/releases/tag/v0.4.1) | 27 Sep 2026 | Install with a single command |
| [0.4.0](https://github.com/meroxis/nuvabill/releases/tag/v0.4.0) | 27 Sep 2026 | Taxes, wallet, quotes, affiliates, REST API, passkeys, Arabic and Kurdish |
| [0.3.3](https://github.com/meroxis/nuvabill/releases/tag/v0.3.3) | 27 Sep 2026 | One update at a time |
| [0.3.2](https://github.com/meroxis/nuvabill/releases/tag/v0.3.2) | 27 Sep 2026 | Clearer payment errors for staff |
| [0.3.1](https://github.com/meroxis/nuvabill/releases/tag/v0.3.1) | 27 Sep 2026 | SQLite install fix |
| [0.3.0](https://github.com/meroxis/nuvabill/releases/tag/v0.3.0) | 27 Sep 2026 | Marketplace, coupons, product add-ons, exchange rates |
| [0.2.3](https://github.com/meroxis/nuvabill/releases/tag/v0.2.3) | 27 Sep 2026 | New design |
| [0.2.2](https://github.com/meroxis/nuvabill/releases/tag/v0.2.2) | 27 Sep 2026 | Updated demo data |
| [0.2.1](https://github.com/meroxis/nuvabill/releases/tag/v0.2.1) | 26 Sep 2026 | Terms and privacy links |
| [0.2.0](https://github.com/meroxis/nuvabill/releases/tag/v0.2.0) | 26 Sep 2026 | Domains, more control panels, Iraqi gateways, WHMCS importer |
| [0.1.2](https://github.com/meroxis/nuvabill/releases/tag/v0.1.2) | 26 Sep 2026 | Faster demo resets |
| [0.1.1](https://github.com/meroxis/nuvabill/releases/tag/v0.1.1) | 26 Sep 2026 | Demo mode and security headers |
| [0.1.0](https://github.com/meroxis/nuvabill/releases/tag/v0.1.0) | 26 Sep 2026 | First release |

## 0.5.4

- **Getting WhatsApp by QR code ready for every Nuvabill site.** The store that runs the QR signup now has the webhook
  address Meta needs for its app, and a switch (`META_WHATSAPP_QR_OPEN`) so its own staff can try the signup before it
  opens to everyone.
- **Template decisions arrive by themselves.** Meta only reports template decisions to the Meta app, not to each site,
  so sites connected by QR code now ask Meta every hour until all their templates are approved or rejected. Before,
  they stayed at "Waiting for Meta" until someone pressed **Check templates again**.
- The guide now says to add a payment method in WhatsApp Manager, which Meta needs before it delivers template
  messages.

## 0.5.3

- **Settings → Chat apps now tells you when connecting WhatsApp by QR code is not available yet.** Before, the
  button opened a window that did nothing. The page now asks the Nuvabill store first; until Meta has approved
  Nuvabill for QR signup, it shows a note and opens the form for your own Meta app instead.
- If the signup window is closed before WhatsApp is connected, the page says so, so you are not left waiting.

## 0.5.2

- **Telegram and WhatsApp.** A new **Settings → Chat apps** page sends invoices, overdue reminders, payment receipts,
  "your service is ready", suspensions, ticket replies and domain renewal reminders to clients on Telegram and
  WhatsApp, next to the email, in each client's own language, with a button to the invoice or ticket. A table lets you
  turn each message on or off per app.
- **Telegram in two minutes.** Make a bot with @BotFather, paste its token, done. Add the bot to your team's Telegram
  group to get new tickets, client replies and orders there too.
- **WhatsApp by QR code.** Press **Connect with a QR code**, sign in with Facebook and scan the code with the WhatsApp
  Business app on your phone. Your number keeps working in the app. Businesses with their own Meta app can enter its
  details instead. Nuvabill sends its message templates to Meta for approval and shows their status. The QR code
  signup opens once Meta has approved Nuvabill for it; until then, use your own Meta app.
- **Clients link by QR code.** The client area's Account page has a QR code and a link for each app. Linked clients can
  send `/invoices`, `/services` and `/tickets`, and disconnect with `/stop`.
- **Tickets from chats.** Anything else a linked client writes becomes a ticket, or a reply on their open chat ticket,
  and your answer goes back to the chat. Replies from a chat show **via Telegram** or **via WhatsApp**. You can turn this
  off for WhatsApp if you answer clients in the WhatsApp Business app yourself.
- Tokens and keys are stored encrypted, and Nuvabill only accepts messages that carry your site's secret Telegram or
  WhatsApp key. See the [Telegram and WhatsApp guide](docs/chat-apps.md).
## 0.5.1

- **AI help, with your own Claude key.** Add an Anthropic API key in the new **Settings → AI** and pick a model:
  Claude Haiku 4.5 (fast and low cost), Claude Sonnet 5.5 (best answers) or Claude Opus 5.5 (most capable). You pay
  Anthropic for what you use; Nuvabill adds nothing.
- **Reply drafts on the ticket page.** Press **Write a draft** and Claude writes a reply from the whole ticket and the
  client's service and invoices. Make it **shorter**, **friendlier** or **more detailed**, or tell it what to say under
  **Ask AI**. Staff always check and send the reply; AI never replies by itself.
- **Tickets in two languages.** A client's message in another language is translated for your team, with **Show
  the original**. Your reply can go out in the client's language: the first press shows the translation to check, the
  second sends exactly that. The ticket keeps both versions.
- **AI summary** of a ticket with a suggested department and priority.
- **Write with AI** on product pages fills in the store description and the title and description for search results.
- **Private details stay on your server.** Email addresses, phone numbers, card and bank numbers, passwords and keys
  are taken out before anything goes to the AI. When a reply that contains them is translated, they are put back
  after.
- **A monthly spending limit** (default $20). When it is reached, AI help pauses until the next month, and your company
  email gets a note at 80%. Settings → AI shows what AI help cost this month.
- A new staff right, **Use AI help for ticket replies and product texts**, which roles that answer tickets or edit
  products get. The demo shows sample answers, so you can try every button there.

## 0.5.0

- **Automations.** A new **Automations** page in the admin area: when something happens, Nuvabill does the next
  step for you. No code, and every run is logged.
  - **13 starting points**: a client signs up, an order is placed, an invoice is paid, due soon or overdue, a service
    is set up, suspended, terminated or has been active for a while, a domain expires soon, a client opens or replies
    to a ticket, and a quote is not answered.
  - **Conditions** so it only runs sometimes, for example "the invoice total is more than 10" or "the client does not
    have the tag VIP". You can check the client's tags, country, paid invoices, active services and open tickets, and
    the invoice, order, service, ticket, quote or domain itself.
  - **14 steps**: email the client, email staff, add a fee to the invoice, add wallet credit, tag or untag the client,
    open a ticket, assign a ticket, change its priority, suspend or unsuspend the service, send the details to a web
    address, **wait** hours or days, and **only go on if** something is still true.
  - A run that waits **stops by itself** when what started it is no longer true, for example when the late invoice
    was paid in the meantime.
  - **9 ready-made templates**: late fee after 7 days, welcome new clients, thank you after the first payment,
    domain expires soon, ask for a review after 30 days, chase quotes that were not answered, win back clients who
    left, tell the team about big orders, and VIP tickets first. Pick one, change what you like, and switch it on.
  - **Try it** on a real invoice, client, service or ticket before you switch it on: it shows what each step would
    do, and nothing changes and nothing is sent.
  - **Runs**: every time an automation starts, what each step did, and the runs that are waiting.
  - **Nothing runs twice for the same event**, even if a payment is reported twice, and a late fee is added only
    once per invoice.
- **Client tags**, like VIP or Reseller, on the client's page. Only staff see them, and automations can check them.
- **Assign tickets** to a staff member from the ticket page. The ticket list shows who has each ticket.
- A new permission, **Create and switch automations on and off**, which roles that can manage settings get.
- The cron job you already have runs automations every five minutes (`php artisan nuvabill:automations`). Timed
  starting points, like "an invoice is overdue", are checked once a day from 9:00.

## 0.4.11

- **Search engines, built in.** Nuvabill now tells Google, Bing and the apps that show link previews what is on your store, with every theme and nothing to set up:
  - Every store page gets a **title and description** for search results. Products use their features and lowest price, for example "Starter: 1 website, 10 GB NVMe storage, Free SSL. From $3.99/mo."
  - **sitemap.xml** lists your store pages and updates by itself, and **robots.txt** keeps search engines out of the client area, cart and checkout. Your admin address is never listed.
  - **Prices on Google**: product pages carry product, price, stock and breadcrumb details, and the home page your company details.
  - **Language versions** link to each other (`?lang=de`), so search engines show people the page in their own language.
  - **Link previews** on WhatsApp, Facebook, X and LinkedIn show the page title, description and your share image.
  - Each page names **one address** from `APP_URL`, and the client area, cart, checkout, sign-in pages and the admin area are hidden from search engines.
- **Settings → Search engines**: your home page on Google with a live preview, the share image, Google Search Console and Bing codes, the title pattern, your own robots.txt lines, and a switch to hide a test site from search engines.
- **Search appearance** on every product and product group: its own title and description, a Google preview, **Suggest from the details**, and **Hide this page from search engines**.
- **A new web address forwards.** When you change the address of a product or group, the old one sends visitors and search engines to the new one, so links and search results keep working.
- **Site health → Search engines**, the third tab: a score and checks for the sitemap, robots.txt, the site address and HTTPS, products without a description, pages with the same title, titles and descriptions that are too long, the share image, prices on Google and language links, many with a **Fix it** button.
- The old `public/robots.txt` that allowed everything is removed on update when you never changed it. If you did, Site health can move it to quarantine.

## 0.4.10

- **Four new server modules, free on the Marketplace.** Install one with a click and it shows up in **Servers → Add server** next to the built-in modules. The [servers guide](docs/integrations/servers.md) says what to enter for each.
  - **CyberPanel:** websites with their own CyberPanel user, the package, PHP version and website limit per product, and one-click sign-in for clients.
  - **HestiaCP:** users with their website, DNS and email. If the domain cannot be added, the new user is removed again.
  - **VirtFusion:** virtual servers built on payment. Clients start, shut down, restart and power off their server in your client area, and open VirtFusion with one click.
  - **SolusVM:** KVM, OpenVZ and Xen servers. Clients manage power, hostname, root password and the VNC console in your client area.
  - All four suspend, unsuspend, change package and remove with the service, like the built-in modules.
- **The WHMCS import brings these panels along.** Servers and products that use CyberPanel, HestiaCP, VirtFusion or SolusVM in WHMCS come across once the module is installed, and SolusVM servers keep their link to the VPS.
- **Remove extensions that were added by hand.** **Setup → Extensions** now shows where each one came from: built in, the Marketplace, or copied into the `extensions` folder by hand. An extension added by hand that is switched off and not used can be removed with one click. Its folder moves to `storage/app/quarantine`; nothing is deleted, so you can move it back. Built-in extensions and ones still in use cannot be removed.
- **Site health moves unused themes added by hand to quarantine** with one click, and tells them apart from Marketplace themes, which you still remove on the Marketplace page.
- Server modules can now sign clients in to their control panel with a form, for panels that need it (CyberPanel does).
- The encryption key help on the import page uses the normal help text style.

## 0.4.9

- **Import from Blesta, FOSSBilling and Paymenter**, next to WHMCS. In **Settings → Import**, choose the system you are leaving and enter its database details. Clients (with their passwords), products and prices, services, domains, invoices, payments, wallet credit and tickets come across. The other system is only read, and you can run the import again before you switch: it updates what changed instead of making copies.
  - **Blesta:** add the system key from `config/blesta.php` and clients keep their passwords. Services of registrar modules become domains, and domain prices come from the Domain Manager.
  - **FOSSBilling:** hosting and other orders become services, domain orders become domains, and the client balance becomes the wallet.
  - **Paymenter:** everyone becomes a client, users with a role also become staff, and addresses come from user properties. Set up your servers in Nuvabill, then choose them on the products.
- **A dry run before every import.** **Save and check** now shows, without changing anything, how many records each step would add or update, and the problems it would meet: clients without a valid email, clients who already have an account, passwords that will not work, server modules and registrars Nuvabill does not have, overdue services that the first nightly run would suspend, currencies and payment methods that are not set up, and more. Problems marked in red, such as a wrong WHMCS key, must be fixed before the import can start.
- **A stronger WHMCS import:**
  - Passwords from WHMCS 4.2 to 6.2 now work too. Nuvabill checks them at the client's first sign-in and then replaces them with its own.
  - With WHMCS's encryption key (`$cc_encryption_hash` from `configuration.php`), server and service passwords come across too. Server API tokens always do.
  - WHMCS credit becomes a wallet entry. Running the import again adds only what changed in WHMCS since.
  - An unpaid renewal invoice from WHMCS is never billed a second time by Nuvabill, and an invoice cancelled in WHMCS later frees its period again.
  - Payments made with PayPal, Stripe or bank transfer in WHMCS keep the right payment method.
- **One bad row no longer stops an import.** It is skipped, and the import page lists it with the reason.
- From the command line: `php artisan nuvabill:import blesta --dry-run` shows the dry run, and `--key` passes the encryption key. `nuvabill:import-whmcs` still works.
- **Extensions**, a new page under **Setup**. Every payment gateway, server module, registrar and add-on in one place, with tabs, a search box and, for each one, whether it is on, how much it is used (payments in the last 30 days, servers and accounts, domains), new versions and license problems. Switch them on and off from the list, or open their settings. The sidebar shows how many are switched on but missing settings, and add-ons with their own page get a link under Extensions. Payment gateways and registrars moved here from Settings; old links still work.
- Site health says why a check was skipped.
- The ClientExec importer moves to a later release: we want to test it against a real ClientExec database first.

## 0.4.8

- **Site health**, a new page under **Setup → Site health**. Every night (and after every update) Nuvabill looks for weak spots and gives your site a score:
  - **Staff and access:** staff with full access but no two-factor sign-in, unused accounts and API tokens, sample email addresses, many failed sign-ins, CAPTCHA on client forms.
  - **Files and folders:** a `.env` file other accounts on the server can read, folders anyone can change (777), and backups, `.sql` files or database tools left in the public folder.
  - **Your site from outside:** Nuvabill opens `/.env`, `/.git`, log files, `composer.json` and the database file like a stranger would, to be sure they are blocked, and checks the security headers and your Cloudflare setup.
  - **Site settings, payments and servers:** debug mode, HTTPS, secure cookies, the PHP version, gateways and registrars left in test mode, and server connections without HTTPS.
  - **Core files:** from this release on, every download includes a signed list of Nuvabill's files. Site health spots changed files and unknown program files where visitors can run them. Put back the original with one click, move an unknown file to quarantine, or mark a change as your own.
  - **Extensions, updates, backups and email:** unlicensed or hand-copied packages, waiting updates, the nightly cron job, recent and off-site backups, and email sending with SPF and DMARC.
- **Database health** on its own tab: who can reach the database, whether it still gets security fixes, whether passwords and keys are stored encrypted, damaged tables and missing database changes. **Optimize now** rebuilds tables with free space (after a backup), and **Clean up** removes old logs, ended sessions and history, every night if you like. Clients, invoices, payments, services and tickets are never removed.
- Many problems have a **Fix it** button. Checks you choose to accept can be **ignored** with a reason. Staff with the new **See site health and fix security issues** right get an email about new urgent issues, and the dashboard shows them too.
- From the command line: `php artisan nuvabill:security-check` (exits with code 1 while something is urgent, for your own monitoring) and `php artisan nuvabill:database --optimize --clean`.
- **Billing jobs never bill twice.** Only one automation run works at a time, each renewal period can only be invoiced once (the database itself refuses a second invoice), reminders, domain notices and affiliate commissions are claimed before they go out, and a wallet payment clicked twice takes the money once.
- Failed staff sign-ins are now in the activity log, without what was typed.
- On the Marketplace, the cPanel add-on has a new name: **cPanel Integrated** (1.1.1). Licenses, settings and features stay the same.

## 0.4.7

- **Faster, steadier store pages.** Order forms can now put their first prices in the page, so nothing jumps when the page finishes loading and no extra request is needed. Themes and order forms can load their scripts without holding up the page.
- **Easier to read.** The light grey, green and teal texts are a little darker, so every label passes the contrast rule for readable text.
- **Browsers keep the site's design files for a year.** Their names change with every release, so visitors always get the newest ones and pages open faster on the next visit.
- **Works with Cloudflare Web Analytics.** Sites behind Cloudflare no longer block the analytics script that Cloudflare adds.
- On the Marketplace: **Aurora 1.2.0** (easier to read, and the Cart and account buttons keep their names on phones) and **Swift 1.1.0** (no layout jumps, prices from the first moment, underlined links).

## 0.4.6

- **Marketplace updates show up right away.** The Updates and Installed tabs now ask the store for new versions every time you open them, so you no longer need to remove and reinstall an extension to get its new version. The rest of the marketplace is kept for 10 minutes instead of 30.
- The daily license check also looks for new versions, so the Marketplace menu shows how many updates are waiting even if nobody opened the marketplace. When the store cannot be reached, the last list it sent is used.

## 0.4.5

- **Marketplace extensions can bring their own database tables.** When an extension that asks for the **database** permission is installed or updated, Nuvabill makes or updates its tables for you, so no command line is needed. A package that changes the database without asking for that permission is refused.
- New on the marketplace: **Discord Notifications** (free), **Crystal Mail**, **MikroTik VPN** and **cPanel Integrated**.

## 0.4.4

- **26 languages:** the client area and admin area now also speak Azerbaijani, Catalan, Chinese (Simplified), Croatian, Czech, Danish, Dutch, Estonian, French, German, Hebrew, Hungarian, Italian, Macedonian, Norwegian, Portuguese (Brazil and Portugal), Romanian, Russian, Spanish, Swedish, Turkish and Ukrainian, next to English, Arabic and Kurdish. Hebrew pages read right to left, and form error messages are translated too.
- **A new language menu** in the top bar: every language by its own name and by its name in your language, with a search box. On phones it opens from the bottom of the screen.
- New installs offer every language to clients. Sites that already saved their language list keep it: turn on more languages in **Settings → General**.
- Invoices, PDFs and emails stay in English.

## 0.4.3

- **Fix for MySQL and MariaDB:** installing (and the one-line server install) stopped while making the product add-on tables, because an index name was longer than MySQL allows. The index now has a short name, and sites where that step stopped halfway finish it on the next update.
- A new test builds every database change for MySQL, so this kind of problem is caught before a release.

## 0.4.2

- **Backups:** `php artisan nuvabill:backup` backs up the whole site (every file and the database), or only the database with `--database`. Add `--password=…` to encrypt it with AES-256. Each backup has a RESTORE.txt with the steps to put the site back.
- **Google Drive backup**, free on the Marketplace: back up the whole site and the database only, each on its own schedule (or switched off), to your own Google Drive, keeping the newest copies.
- For developers: add-ons can now run on a schedule, add admin pages, show a panel on their settings page, keep their own values (such as a connection token) and ship Arabic and Kurdish translations. New permission codes: `schedule` and `backups`.

## 0.4.1

- **Install with a single command:** `curl -fsSL https://nuvabill.com/install.sh | bash`. On a fresh Ubuntu or Debian server it also sets up Nginx, PHP, MariaDB, a free SSL certificate and the cron job. On a server that already runs PHP, such as cPanel Terminal, it installs into a folder and adds the cron job. Every download is checked against the release signature first.
- **Install from the terminal:** `php artisan nuvabill:install` runs the same steps as the web installer, with questions or with options such as `--db=sqlite --email=you@example.com`.

## 0.4.0

- **Taxes:** add VAT, GST or sales tax rules by country and state (Settings → Taxes). Choose whether your prices already include tax, which products and domains are taxed, and mark clients as tax exempt. Invoices, PDFs and emails show the tax and both tax numbers.
- **Client wallet:** clients add funds and pay invoices from their wallet in one click. Overpayments and refunds of wallet payments go back to the wallet, and staff can add or remove credit on the client page.
- **Quotes:** send a price offer for custom work. The client accepts or declines it in their account, and accepting creates the invoice.
- **Affiliates:** clients join from their account and share a link. When the people they send pay, they earn a commission that becomes available after the hold days you set and can be moved to their wallet.
- **REST API** at `/api/v1` for clients, services, invoices, payments, products, orders and tickets. Staff create keys under **Your profile → API keys**; each key follows the staff member's role and can be read-only.
- **Passkeys:** staff and clients can sign in with a fingerprint, face or phone. Fake sign-in pages cannot steal them, and they skip the two-factor step.
- **Arabic and Kurdish (Sorani):** the client area and admin area are translated, and pages read right to left. Clients and staff pick their language in the top bar; choose the default in Settings → General. PDFs and emails stay in English.
- **White-label License:** remove the "Powered by Nuvabill" credit with a license key from my.nuvabill.com, entered under **Settings → License**.
- The **Updates** page now shows what changed in every version you are about to install, instead of a link.

## 0.3.3

- Only one update can be installed at a time. A second click or the nightly run waits instead of unpacking over a running update.
- The marketplace store opens on the marketplace, with no hosting plans or domain links.

## 0.3.2

- When a payment gateway refuses to start a payment, staff now see the gateway's own reason in **Settings → Activity**, for example "Invalid authentication key". Clients still see a short, friendly message.
- Wayl: the details Wayl sends with an error are kept in that reason.

## 0.3.1

- Installing with an SQLite database now continues to the last step. Before, it created the tables and then asked for the database again.
- Settings files saved with Windows line endings are read correctly.
- The public demo can show premium themes for live previews.

## 0.3.0

- **Marketplace:** browse themes, order forms, gateways and extensions in the admin area and install them in one click. Try themes first with a live preview only you can see.
- Every marketplace package is reviewed by people and signed. Nuvabill refuses anything changed after signing and shows what a package can do before you install it.
- Paid items use a license key for one site. Test sites are always free.
- **Coupons:** percent or fixed, for the first payment, every payment or a number of payments, with limits by product, billing cycle, dates, uses and new clients. Share a link like `?coupon=WELCOME20`.
- **Product add-ons** such as backups or a dedicated IP, billed and renewed with the service.
- **Exchange rates:** show prices in US dollars and charge in Iraqi dinar at the rate you set.
- New add-on extensions that react to orders, payments and tickets, and a one-page ordering API for order forms.
- Links always use https when your site address does, also behind a proxy.

## 0.2.3

- A new, more professional design for the client area and the admin area.

## 0.2.2

- Updated demo data.

## 0.2.1

- A setting for your privacy policy link, and terms and privacy links for clients.

## 0.2.0

- **Domains:** search, register, transfer and renew at ResellerClub, Namecheap, Enom or OpenSRS, with expiry reminders and nameserver changes.
- **DirectAdmin** and **Plesk**, next to cPanel.
- **Proxmox VE** and **Virtualizor** virtual servers, managed inside the client area.
- **FIB**, **FastPay** and **Wayl** payments, and refunds from the invoice page.
- **WHMCS importer:** clients with their passwords, products, services, domains, invoices and tickets.
- Fraud checks on new orders, sign in with Google, GitHub or Facebook, CAPTCHA, and two-factor sign-in for clients.

## 0.1.2

- Faster demo resets and a safe root `.htaccess`.

## 0.1.1

- Demo mode, security headers on every page and Cloudflare proxy support.

## 0.1.0

- First release: store and checkout, invoices with PDFs, renewals, reminders and suspensions, Stripe, PayPal and bank transfer, automatic cPanel accounts, support tickets, staff roles, two-factor sign-in for staff, and signed one-click updates.
