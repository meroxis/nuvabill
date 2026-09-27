# Changelog

What changed in each Nuvabill release. The release workflow copies the section for a version into its GitHub
release, and the **Updates** page in the admin area shows it. Write for hosting companies, in plain words.
Add `[security]` to a section to let installs apply it automatically as a security fix.

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
