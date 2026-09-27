# Changelog

What changed in each Nuvabill release. The release workflow copies the section for a version into its GitHub
release, and the **Updates** page in the admin area shows it. Write for hosting companies, in plain words.
Add `[security]` to a section to let installs apply it automatically as a security fix.

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
