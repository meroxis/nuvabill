# Admin phone app

Nuvabill's admin area installs on a phone like an app, from the browser: no app store, on iPhone and Android. It opens
on the **Today** screen and can send push alerts about new orders, payments and tickets. Everything else in the admin
area works on the phone too, including ticket replies with AI drafts and signing in with a passkey or Face ID.

## Install it

Open **Your profile → Phone app** (click your name at the bottom of the menu). On a computer, scan the QR code there
with your phone's camera to open the admin area on the phone and sign in.

- **iPhone and iPad:** open the admin area in Safari, tap **Share**, then **Add to Home Screen**.
- **Android:** open the admin area in Chrome, open the menu, then **Install app** or **Add to Home screen**. Chrome may
  also offer an **Install the app** button on the profile page.

The app opens on **Today**, with a tab bar for Today, Support, Orders, Clients and More. Staff only see the tabs their
role allows.

## Today

- **Four numbers for today:** new orders, payments, open tickets (and how many have waited over 3 hours) and overdue
  invoices. Tap one to open that list.
- **Needs you:** the tickets that have waited longest, risky orders held by the fraud check with an **Approve**
  button, and the same warnings as the dashboard, such as services that were paid for but not set up.
- Staff only see what their role allows: someone who only answers tickets sees tickets, not payments.

## Push alerts

1. Install the app (on iPhone and iPad, alerts only work in the installed app, iOS 16.4 or newer).
2. Open **Your profile → Phone app** in the app and press **Turn on alerts on this device**. Allow notifications when
   the phone asks.
3. Press **Send a test alert** to check.

Choose which alerts you get:

| Alert | Who can get it |
|---|---|
| New orders | Staff who manage orders |
| Payments, optionally only from an amount you choose | Staff who can see invoices |
| New tickets | Staff who answer tickets |
| Client replies on tickets | Staff who answer tickets; a ticket assigned to someone only alerts them |

Alerts are written in each staff member's own language. Tapping one opens that order, invoice or ticket. Each staff
member can turn alerts on for several phones and computers and remove them in **Your profile → Phone app**.

Alerts need the cron job (they are sent in the background) and a site address that starts with `https://`.

## Privacy and security

- Alerts go through the push service of the phone's maker (Google, Apple, Mozilla or Microsoft). Each alert is
  encrypted for that one phone, so the push service delivers it but cannot read it.
- Nuvabill makes its own key pair for push alerts the first time it is needed, and keeps the private key encrypted.
- Nuvabill only sends alerts to the push services of Chrome, Firefox, Safari and Edge, never to other addresses.
- The app keeps no admin pages on the phone. Without a connection it shows a short "You are offline" page.
- The public demo shows the app and the Today screen, but it cannot turn on alerts.
