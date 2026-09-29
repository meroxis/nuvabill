# Telegram and WhatsApp

Nuvabill can send invoices, reminders, payment receipts and ticket replies to your clients on **Telegram** and
**WhatsApp**, next to the email they already get. Clients link their chat app by scanning a QR code in the client
area. They can then ask for their invoices, services and tickets, and write to your support team from the chat.

Everything is in **Settings → Chat apps**. You can use Telegram, WhatsApp or both.

## Telegram

Telegram is free and takes two minutes.

1. In Telegram, open **@BotFather** and send `/newbot`.
2. Pick a name, like "YourHost Support", and a username that ends in "bot".
3. Copy the token @BotFather sends, paste it into **Settings → Chat apps → Telegram** and press **Connect Telegram**.

Nuvabill checks the token and tells Telegram to send messages for the bot to your site. Your site must be reachable
from the internet over https for this to work.

### Staff alerts in a Telegram group

To get new tickets, client replies and orders in your team's Telegram group, add the bot to the group, reload Settings
→ Chat apps and pick the group under **Staff alerts go to**. Alerts are sent for the same things your staff get
emails about.

## WhatsApp

WhatsApp uses Meta's official **WhatsApp Business Platform**. There are two ways to connect.

### Connect with a QR code (recommended)

1. Press **Connect with a QR code**. Meta's WhatsApp signup opens in a new window.
2. Sign in with Facebook and pick your business.
3. Choose to connect your existing WhatsApp Business app number, then open the WhatsApp Business app on your phone and
   scan the QR code Meta shows.

Your number keeps working in the WhatsApp Business app: you can still chat with clients on your phone, and Nuvabill
sends its messages from the same number. If you connect a new number instead, Nuvabill shows its two-step PIN once.
Keep it somewhere safe.

The signup window runs on `my.nuvabill.com`, because Meta only allows it on the domains of an approved Meta app. It
hands the access key back to your own site and keeps nothing. Your site talks to Meta directly after that.

Until Meta has approved Nuvabill for this, Settings → Chat apps says so and shows the form for your own Meta app
instead. It asks the Nuvabill store again every 10 minutes.

### Use your own Meta app

For businesses that already have a Meta app with WhatsApp. Open **Use your own Meta app instead** and fill in:

| Field | Where to find it |
|---|---|
| WhatsApp Business account ID | Meta app dashboard → WhatsApp → API setup |
| Phone number ID | The same page |
| Permanent access token | A system user in Meta Business settings, with the `whatsapp_business_messaging` and `whatsapp_business_management` permissions |
| App secret | App settings → Basic. Nuvabill uses it to check that messages really come from Meta. |

Nuvabill points the account's webhooks at your site for you. You do not need to set a callback URL in the Meta app.

### Message templates

WhatsApp only lets a business send free text to someone who wrote to it in the last 24 hours. Messages you send first,
like an invoice reminder, must use a **template** Meta approved. When you connect WhatsApp, Nuvabill sends its seven
templates to Meta in English and in your store's main language. Meta usually checks them within a day. Settings → Chat
apps shows each template as **Waiting for Meta**, approved or **Rejected**; press **Check templates again** to refresh.

Until a template is approved, that message is not sent on WhatsApp. The email still goes out. Meta has no templates in
Kurdish, so Kurdish-speaking clients get the English template.

### What WhatsApp costs

Meta charges a small fee per template message, which depends on the client's country. Replies within 24 hours of a
client's message are free. Meta bills you directly; Nuvabill adds nothing. Because of this, **New invoice** and
**Payment received** are off for WhatsApp by default, and on for Telegram.

## Which messages go where

The table in Settings → Chat apps has a column for each app. Turn any message on or off.

| Message | Telegram | WhatsApp |
|---|---|---|
| New invoice | on | off |
| Invoice overdue | on | on |
| Payment received | on | off |
| Service is ready | on | on |
| Service suspended | on | on |
| Reply to a ticket | on | on |
| Domain expires soon | on | on |

The messages are short, in the client's own language, with a button to the invoice, service, ticket or domain. They
are sent next to the email: emails always go out as before.

## Clients link their chat app

Clients open **Account → Get alerts on your phone** in the client area. There is a QR code and a link for each
connected app. Scanning the code opens the chat with a one-time code ready; the client presses Start or send, and
the chat is linked to their account. The code only works for that client and for 30 minutes.

A client can disconnect from the client area, or by sending `/stop` in the chat.

### What clients can send

| Send | They get |
|---|---|
| `/invoices` | Their unpaid invoices with a link to pay |
| `/services` | Their services and status |
| `/tickets` | Their open tickets |
| `/help` | This list |
| `/stop` | Disconnects the chat |
| Anything else | Becomes a support ticket |

The slash is optional: "invoices" works too.

### Tickets from chats

When a linked client writes something that is not a command, it becomes a ticket in the department you choose under
**Tickets from chats go to**, or a reply on their open chat ticket from the last three days. Your answer on the ticket
page goes back to the chat, and by email. Replies that came in from a chat show a **via Telegram** or **via WhatsApp**
label.

If you answer clients yourself in the WhatsApp Business app, turn off **Turn WhatsApp messages from clients into
tickets**. Commands like "invoices" still work.

Unlinked people who write to your WhatsApp number get no automatic answer, except for "help", so your normal WhatsApp
chats are not disturbed.

## Privacy and security

- Tokens, the app secret and the webhook key are stored encrypted.
- Telegram messages are checked with a secret only Telegram and your site know. WhatsApp messages are sent to a secret
  address on your site and, with your own Meta app, checked with the app secret.
- Chat apps only get the short messages in the table above. PDF invoices and full ticket histories stay in email and
  the client area.

## For people who run their own signup page

The QR code button uses the Meta app of `my.nuvabill.com`. If you want the signup window on your own domain instead,
you need your own approved Meta app (a verified business, Tech Provider access and an Embedded Signup configuration for
"WhatsApp Business app onboarding"), and these lines in the `.env` of the site that shows the window:

```
META_APP_ID=
META_APP_SECRET=
META_CONFIG_ID=
```

Then set `NUVABILL_WHATSAPP_CONNECT_URL=https://your-site/connect/whatsapp` on the sites that should use it.
