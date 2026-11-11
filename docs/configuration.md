# Configuration

Everything below is in the admin area (`/admin`). Staff need the right permission for each page; the **Owner** role
has all of them.

## Company details

**Settings → General → Your company**: company name, email address, postal address, phone and tax number (shown on
invoices and in emails), the default language, and which of the 12 languages clients can choose (Mongolian is off until you tick it). When staff pick
a language in the admin area and no client language was picked in that browser, the client area uses it too, if
clients can choose it. See [Which languages does it speak?](faq.md#which-languages-does-it-speak) for the full order.

**Settings → General → Your icon**: every page shows the Nuvabill icon in the browser tab. With a White-label License
you can upload your own square PNG (at least 512 × 512 pixels). It becomes the browser tab icon, the icon next to your
company name in the admin area and client area, and the phone app icon. If the license ends, the Nuvabill icon comes
back and your icon is kept until the license is valid again.

## Upgrades and downgrades

**Products → a product → Upgrades and downgrades**: tick the plans clients with this product can switch to (only plans
on the same kind of server are shown). Clients then see **Upgrade or downgrade** on the service page. They pay only the
difference for the days left in the current period; an upgrade happens once its invoice is paid. For a cheaper plan,
**Settings → General → Billing** decides: change now and add the unused amount to the client's wallet, or change on
the next renewal date. Staff can change any service's plan from its page, with or without an invoice.

The same rules apply when clients add or remove extras with [resource options](resource-options.md). **Settings →
General → Billing → Clients can add and remove resources** turns that on or off for all clients.

## Knowledge base, announcements and network status

All three are under **Support** in the admin area.

- **Knowledge base** (`/knowledgebase`): add categories, then articles in Markdown. Clients see matching articles
  while they type the subject of a new ticket. Anyone can read published articles.
- **Announcements** (`/announcements`, RSS at `/announcements/feed`): the newest one shows on the client dashboard
  for two weeks. A date in the future publishes it on that day.
- **Network status** (`/network-status`): tick the servers the page shows and give each a public name. Nuvabill
  checks each switched-on server's control panel port every 5 minutes (needs the cron job) and emails the company
  address when one goes down and when it is back. **Report an issue** or **Plan maintenance** to tell clients, then
  post updates; clients with services on the servers you pick see it on their dashboard.

Articles, categories and announcements have a tab for each other language you offer.

## Credit notes, exports and privacy

- **Credit notes**: open a paid invoice. **Refund** sends back what is left and makes a credit note. **Issue a credit
  note** takes back part of it: the money goes back to the client, into their wallet, or nowhere (you settle it
  yourself). Invoices never change. The number prefix is in **Settings → General → Billing**.
- **Exports**: **Invoices → Exports** downloads CSV files of invoices, payments and refunds, credit notes or clients
  for a date range.
- **Privacy**: on a client's page, **Download their data** (JSON) and **Erase personal data**. Invoices, payments and
  credit notes are kept, as tax law requires. Clients can download their own data and ask for erasure under **Your
  details**.

## Currencies and exchange rates

**Settings → General** lists 155 currencies (current ISO 4217 codes). Each payment method only shows for the
currencies it charges correctly: for example Stripe is only offered for currencies Stripe takes with two decimal
places, so not for JPY, KWD, ISK or currencies Stripe does not take, and FIB, FastPay and Wayl take Iraqi dinar only.

**Settings → Currencies**: your main currency, and exchange rates to other currencies. With a rate set you can, for
example, show prices in US dollars and charge in Iraqi dinar: the invoice stays in dollars and is marked paid in full.

## Taxes

**Settings → Taxes**: VAT, GST or sales tax rules by country and state. Choose whether your prices already include tax
and which products and domains are taxed. Mark clients as tax exempt on their profile. Invoices, PDFs and emails show
the tax and both tax numbers.

## Products

**Products**: product groups, plans with prices from monthly to three-yearly, setup fees, stock, and whether a plan
needs a domain. Each plan can be set up automatically on a [server](integrations/servers.md) when its first invoice
is paid. **Product add-ons** (for example backups or a dedicated IP) are billed and renewed with the service.

- **Resource options**: sliders that let clients add CPU cores, RAM, disk space, IP addresses and more, with a price
  for each step. See [Resource options](resource-options.md).
- **Dedicated servers**: plans that sell your own machines take their stock from free machines under **Hardware**. See
  [Dedicated servers](hardware.md).

## Client server panel

**Settings → General → Client server panel**: whether clients get a console in the browser, how often live figures
refresh (every 10 seconds by default), and whether clients get an email when their server changes. Each product can
turn single tabs off too. See [the client server panel](integrations/servers.md#the-client-server-panel).

## Coupons

**Coupons**: percent or fixed amounts, for the first payment, every payment or a number of payments, limited by
product, billing cycle, dates, uses and new clients. A link like `yourhost.com/?coupon=WELCOME20` applies the coupon
for the client.

## Sending email

**Settings → General → Sending email**: SMTP details, then send yourself a test email. Email templates are in
**Settings → Email templates**, with a tab for each language. Emails and PDF invoices go out in the language each client
picked (staff emails in your default language). Arabic, Kurdish, Thai and Chinese PDFs stay in English for now, because the PDF engine cannot draw those scripts; Mongolian PDFs are in Mongolian.

## Automation

**Settings → General → Automation**: when renewal invoices are made, the days reminders go out, and how many days after the due
date services are suspended (and optionally terminated). Paying an invoice unsuspends the service automatically.
This needs the [cron job](installation.md#the-cron-job).

## Security

**Settings → Security**:

- **Two-factor sign-in** for staff (optional or required) and for clients (off, optional or required; an authenticator
  app or codes by email)
- **CAPTCHA** on the forms you choose: Cloudflare Turnstile, reCAPTCHA or hCaptcha

Every staff member and client can also add **passkeys** (fingerprint, face or phone) under their profile.

Turning two-factor sign-in on or off asks for the password. Clients who signed up with Google, GitHub or Facebook and
have no password use a code sent to their email instead. After 10 wrong emailed codes, a client waits an hour before
a new code is sent.

A new password ends the other ways into an account. After a password reset, or a password set by someone else, a
client's passkeys, connected Google, GitHub or Facebook sign-ins and linked chat apps are removed, and so are a staff
member's passkeys, API keys and phone alert devices. People who change their own password choose this with a box
that is ticked by default. They can add everything again afterwards.

**Settings → Staff** and **Roles**: staff accounts and what each role may do.

**Settings → Social login**: let clients sign in with Google, GitHub or Facebook.

## Look and feel

**Settings → General → Look and feel**: your brand colour and the client area theme. More themes and order forms are on the
**Marketplace**, where you can try them with a live preview only you see.

## License

**Settings → License**: enter a White-label License key to remove the "Powered by Nuvabill" credit. Without it the
credit must stay visible (see the [license](../LICENSE) and [NOTICE](../NOTICE)).

## Environment settings (`.env`)

| Setting | Use |
|---|---|
| `APP_URL` | Your site address, with `https://` |
| `APP_DEBUG` | Keep `false` on a live site |
| `SESSION_ENCRYPT` | `true` keeps session data encrypted (new installs since 0.7.2). Older sites switch it on with the **Site health** fix; everyone signs in again once |
| `NUVABILL_TRUSTED_PROXIES` | `cloudflare`, a list of proxy IPs, or `*` (see [below](#behind-cloudflare-or-another-proxy)) |
| `NUVABILL_DEMO` | `true` only on a public demo site: it locks settings, sends no email and puts fresh demo data in every hour |
| `NUVABILL_DEMO_ADDONS` | Demo sites only: add-ons to switch on again after every reset, separated by commas, such as `dedicated-server-power` |
| `DB_*` | Database connection, written by the installer |

`NUVABILL_DEMO_ADDONS` is ignored unless `NUVABILL_DEMO=true`. Copy each add-on into `extensions/addons/` yourself: the
demo never downloads one, and it makes no license or marketplace install for it. An add-on that adds database tables
gets them again at every reset, and only when it asks for the database permission.

## Behind Cloudflare or another proxy

When a proxy sits in front of Nuvabill (Cloudflare, a load balancer or a web firewall), every request seems to come from
the proxy's IP address. The proxy writes the visitor's real address in the `X-Forwarded-For` header. Nuvabill reads
that header only from proxies you trust: anyone can send it, so trusting it from everyone would let any visitor fake
their address.

**Why the client's IP address matters:**

- **Sign-in limits** and other limits count tries per address. With the proxy's address, all visitors count as one,
  so the limits can block real clients.
- **Logs** show the address: the activity log, and the "From IP address" line of the **Server change** email that
  tells a client their server changed.
- **The Virtualizor panel sign-in** ties the client's panel session to the client's address. With the wrong address
  the sign-in fails, or every client is tied to the proxy's address.
- **The console and panel limits** of some server modules count per address.

**Set it in `.env`:**

| Value | When |
|---|---|
| `NUVABILL_TRUSTED_PROXIES=cloudflare` | The site is behind Cloudflare. Nuvabill knows Cloudflare's address ranges. |
| `NUVABILL_TRUSTED_PROXIES=10.0.0.5,10.1.0.0/16` | Your own proxies: their IP addresses or ranges, separated by commas. Behind Cloudflare **and** your own proxy, list Cloudflare's ranges ([cloudflare.com/ips](https://www.cloudflare.com/ips/)) and your proxy here. |
| `NUVABILL_TRUSTED_PROXIES=*` | Any proxy. Use it only when nobody can reach the server directly, for example when its firewall allows only the load balancer. Otherwise anyone can fake their address. |

Leave it out when no proxy sits in front of Nuvabill. Nuvabill reads only `X-Forwarded-For` (the visitor's address)
and `X-Forwarded-Proto` (whether the visitor used HTTPS) from trusted proxies. It never reads `X-Forwarded-Host`,
`X-Real-IP` or `Forwarded`, so make sure your proxy sends `X-Forwarded-For`. If you cache the configuration
(`php artisan config:cache`), run that command again after you change `.env`.

**How to check it:**

- **Site health** warns when your site is behind Cloudflare but Cloudflare's addresses are not trusted.
- On a service page, **Test panel sign-in** shows **Proxy in front of this site** when your own request came through a
  proxy that Nuvabill does not trust. Its **Client IP address** line fails when a client's panel sign-in was refused in
  the last day because Nuvabill saw a Cloudflare address.
- Behind Cloudflare without this setting, Nuvabill never sends a Cloudflare address to Virtualizor as the client's
  address: it refuses the panel sign-in, and notes why in the activity log once a day. Nuvabill cannot detect other
  proxies by itself, so set this whenever you add one.
