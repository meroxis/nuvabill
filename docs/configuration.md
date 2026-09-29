# Configuration

Everything below is in the admin area (`/admin`). Staff need the right permission for each page; the **Owner** role
has all of them.

## Company details

**Settings → General → Your company**: company name, email address, postal address, phone and tax number (shown on
invoices and in emails), the default language, and which of the 10 languages clients can choose.

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

## Currencies and exchange rates

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

## Coupons

**Coupons**: percent or fixed amounts, for the first payment, every payment or a number of payments, limited by
product, billing cycle, dates, uses and new clients. A link like `yourhost.com/?coupon=WELCOME20` applies the coupon
for the client.

## Sending email

**Settings → General → Sending email**: SMTP details, then send yourself a test email. Email templates are in
**Settings → Email templates**, with a tab for each language. Emails and PDF invoices go out in the language each client
picked (staff emails in your default language). Arabic, Kurdish and Chinese PDFs stay in English for now, because the PDF engine cannot draw those scripts.

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
| `NUVABILL_TRUSTED_PROXIES` | `cloudflare`, a list of proxy IPs, or `*` |
| `DB_*` | Database connection, written by the installer |
