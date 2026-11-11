# Nuvabill developer guide

Nuvabill packages are plain folders of Blade views, CSS and PHP. You build one, test it on your own copy of
Nuvabill, and then sell it (or give it away) on the Marketplace. You do not need to change Nuvabill itself.

This page explains what you can build, how to set up a local copy, and walks you through your first add-on.
This guide is for Nuvabill 0.7.1. Features added in a later version are marked, for example "(0.7.0+)".

## What you can build

| Type | Manifest file | Installed in |
|---|---|---|
| Client theme | `theme.json` | `themes/{slug}` |
| Order form | `orderform.json` | `orderforms/{slug}` |
| Add-on | `extension.json` with `"type": "addon"` | `extensions/addons/{slug}` |
| Payment gateway | `extension.json` with `"type": "gateway"` | `extensions/gateways/{slug}` |
| Server module | `extension.json` with `"type": "server"` | `extensions/servers/{slug}` |
| Domain registrar | `extension.json` with `"type": "registrar"` | `extensions/registrars/{slug}` |

- A **theme** changes the look of the client area and the store.
- An **order form** changes the store and order pages.
- An **add-on** is any feature that is not a gateway, server module or registrar: chat alerts, a live chat window,
  reports, visitor pages, dedicated server tools and so on.
- **Gateways, server modules and registrars** take payments, set up hosting accounts and register domains.

The built-in Nova theme, gateways, server modules and registrars use the same system. They are complete, real
examples. Read them in the Nuvabill repository.

## Set up a local copy

Follow [Setting up a development copy](../../CONTRIBUTING.md#setting-up-a-development-copy) in `CONTRIBUTING.md`.
It installs Nuvabill on your computer and loads the demo data, with a staff account and a client account to sign in
with.

Then:

1. Put your package folder in the place from the table above, for example `extensions/addons/acme-slack-alerts`.
2. Sign in as staff. Your package now shows in **Marketplace → Installed** (`/admin/marketplace?tab=installed`).
3. To try a theme or order form without switching it on for everyone, open `/preview/theme/{slug}` or
   `/preview/orderform/{slug}` while signed in as staff. Open `/preview/stop` to end the preview.

In the Nuvabill repository, git ignores `themes/*` (except Nova), `orderforms/*` and `extensions/addons`. It also
ignores every folder in `extensions/gateways`, `extensions/servers` and `extensions/registrars` except the built-in
ones. So your package will not end up in a Nuvabill commit by mistake.

## Your first add-on in 15 minutes

You will build **Acme Slack alerts**. It posts a short message to a Slack channel when a client places an order or
opens a ticket. It shows every basic part of an add-on: the manifest, the PHP class, settings, an event listener,
translations, switching it on, the zip, and the upload.

### Step 1: Make the folder

Create this folder inside your local Nuvabill:

```
extensions/addons/acme-slack-alerts/
├── extension.json
├── src/
│   └── SlackAlerts.php
└── lang/
    ├── ar.json
    ├── ckb.json
    ├── de.json
    ├── es.json
    ├── fr.json
    ├── mn.json
    ├── pt_BR.json
    ├── ru.json
    ├── th.json
    ├── tr.json
    └── zh_CN.json
```

The folder name is the **slug**, and it must be the same as `slug` in `extension.json`. For the Marketplace it must
be 2 to 64 characters: lowercase letters, numbers, `-` and `_`, starting with a letter or number. Use `-`, not `_`:
the store turns `_` into `-` in the item's slug, and then your package no longer matches the item. You cannot change
the slug later, so choose it well. Putting your company name first (`acme-...`) keeps it unique.

### Step 2: Write extension.json

```json
{
    "slug": "acme-slack-alerts",
    "type": "addon",
    "name": "Acme Slack alerts",
    "version": "1.0.0",
    "namespace": "Acme\\SlackAlerts\\",
    "class": "Acme\\SlackAlerts\\SlackAlerts",
    "description": "Posts new orders and tickets to a Slack channel.",
    "author": "Acme",
    "requires": ">=0.7.0",
    "permissions": ["events", "admin-settings", "http:hooks.slack.com"]
}
```

- **Required:** `slug`, `type`, `name`, `version`, `namespace` and `class`.
- **Optional:** `description`, `author`, `requires`, `paid` and `permissions`.
- `version` looks like `1.0.0` or `1.0.0-beta.1`, at most 40 characters. Every upload to the store needs a higher
  version than the last one.
- `requires` is the oldest Nuvabill your package works with. Only the form `>=x.y.z` is understood. Older sites
  will not install the package.
- `namespace` and `class`: Nuvabill loads your PHP classes from `src/` under this namespace. The class
  `Acme\SlackAlerts\SlackAlerts` is loaded from `src/SlackAlerts.php`.
- `permissions` says in plain codes what your package does. Staff see these in plain words before they install it,
  and the reviewer checks that your code does nothing else. `http:hooks.slack.com` means "Connects to
  hooks.slack.com". See [Packages](packages.md) for the full list.
- For an item you sell, add `"paid": true` (the JSON value `true`, not the text `"true"`). Nuvabill then handles the
  licence key for you.

### Step 3: Write the PHP class

Save this as `src/SlackAlerts.php`:

```php
<?php

namespace Acme\SlackAlerts;

use App\Events\OrderPlaced;
use App\Events\TicketOpened;
use App\Extensions\Addons\Addon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SlackAlerts extends Addon
{
    private const WEBHOOK_PATTERN = '#^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$#';

    public function settingsFields(): array
    {
        return [
            'webhook_url' => [
                'label' => __('Slack webhook URL'),
                'type' => 'password',
                'required' => true,
                'help' => __('In Slack: Apps → Incoming Webhooks → Add to Slack → copy the webhook URL.'),
            ],
            'alerts' => [
                'label' => __('Send alerts for'),
                'type' => 'select',
                'options' => [
                    'all' => __('Orders and tickets'),
                    'orders' => __('Orders only'),
                    'tickets' => __('Tickets only'),
                ],
            ],
            'prefix' => [
                'label' => __('Text before each alert'),
                'type' => 'text',
                'help' => __('Optional, for example the name of your shop.'),
            ],
        ];
    }

    public function isConfigured(): bool
    {
        // Run only when every required field is filled in AND the address really is a Slack webhook.
        return parent::isConfigured()
            && preg_match(self::WEBHOOK_PATTERN, (string) $this->setting('webhook_url')) === 1;
    }

    public function boot(): void
    {
        $alerts = (string) $this->setting('alerts', 'all');

        if ($alerts !== 'tickets') {
            Event::listen(OrderPlaced::class, fn (OrderPlaced $event) => $this->send(__('New order #:number from :client: :total', [
                'number' => $event->order->number,
                'client' => $event->order->client?->name ?? __('a client'),
                'total' => money($event->order->total, $event->order->currency),
            ])));
        }

        if ($alerts !== 'orders') {
            Event::listen(TicketOpened::class, fn (TicketOpened $event) => $this->send(__('New ticket #:number: :subject', [
                'number' => $event->ticket->number,
                'subject' => $event->ticket->subject,
            ])));
        }
    }

    private function send(string $text): void
    {
        $prefix = trim((string) $this->setting('prefix', ''));
        $text = $prefix !== '' ? $prefix.' · '.$text : $text;

        try {
            // A short timeout: a slow chat service must never slow down an order.
            // throw() turns an error answer from Slack (4xx or 5xx) into an exception, so it is reported too.
            Http::timeout(5)->asJson()->post((string) $this->setting('webhook_url'), [
                // Clients write ticket subjects and names. Escape them so Slack does not
                // turn them into links or @channel mentions.
                'text' => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text),
            ])->throw();
        } catch (Throwable $exception) {
            // Never stop an order or a ticket because Slack is down,
            // and never write the secret webhook address into the log.
            report(new RuntimeException('Slack alert could not be sent: '.class_basename($exception)));
        }
    }
}
```

What each part does:

- Your class **extends `App\Extensions\Addons\Addon`**. Do not add a constructor; Nuvabill passes the manifest and
  the saved settings for you.
- **`settingsFields()`** describes the settings form staff fill in. Read a value with `$this->setting('key')` or
  `$this->setting('key', 'default')`.
- **`isConfigured()`**: by default it is `false` while any `required` field is empty. You can add your own checks,
  as above. An add-on runs only when it is switched on **and** `isConfigured()` is `true`.
- **`boot()`** runs once on every request while the add-on runs. Listen to events here.
- The order total is a whole number in the smallest unit (cents). `money($minor, $currency)` turns it into text such
  as `$12.50`.

### Step 4: Settings fields

Each field is `key => [...]` with these parts:

| Part | Meaning |
|---|---|
| `label` | The field name staff see. |
| `type` | Always needed: `text`, `password`, `textarea` or `select`. Any other type is shown as a text field. |
| `help` | Optional short help under the field. |
| `required` | Optional. `true` means the add-on does not run until this field is filled in. |
| `options` | For `select` only: `value => label`. Staff can only save one of these values. |

When staff save the form, Nuvabill checks that each value is text, at most 5,000 characters for a `textarea` and
1,000 for any other field.

All add-on settings are **stored encrypted** in the database, not only password fields. Use the `password` type for
secrets such as tokens and webhook addresses: the saved value is never shown again, and an empty field keeps the
saved value. (For add-on settings use `password`, not `'secret' => true` on a text field. Today the saved value of
such a text field is not kept when staff save the form empty.)

Never print a setting into a page as raw HTML. In Blade use `{{ $value }}`, never `{!! $value !!}`.

### Step 5: Listen to an event

Nuvabill sends these events from `App\Events`. Each one gives you the objects in brackets as public properties.

| Event | Properties | Sent when |
|---|---|---|
| `ClientRegistered` | `$client` | A client signs up, at checkout, or with social sign-in. |
| `OrderPlaced` | `$order` | A client places an order. |
| `InvoicePaid` | `$invoice` | An invoice is paid. |
| `ServiceActivated` | `$service` | A service is set up for the first time. |
| `ServiceSuspended` | `$service` | A service is suspended. |
| `ServiceTerminated` | `$service` | A service is ended. |
| `TicketOpened` | `$ticket`, `$message` | A ticket is opened. |
| `TicketReplied` | `$ticket`, `$reply` | Someone replies to a ticket. `$reply->isFromStaff()` tells you who. |
| `DedicatedServerActivated` (0.7.0+) | `$machine`, `$service` | A dedicated server is handed to a client. |
| `DedicatedServerSuspended` (0.7.0+) | `$machine`, `$service` | A dedicated server is suspended. |
| `DedicatedServerUnsuspended` (0.7.0+) | `$machine`, `$service` | A dedicated server is unsuspended. |
| `DedicatedServerReleased` (0.7.0+) | `$machine`, `$service` (can be null), `$needsWipe`, `$reason` | A dedicated server is taken back. |

Rules for listeners:

- Keep them fast. Use short HTTP timeouts (5 seconds or less).
- Wrap outside calls in `try`/`catch` and `report()` the error. An add-on that throws in `boot()` is reported and
  skipped, but a listener that fails could still break the action that sent the event.
- Never write secrets (tokens, webhook addresses, passwords) into the log or an error message.

### Step 6: Add translations

Wrap every text people see in `__()`, as in the class above. The English text is the key. Then put one
`lang/{code}.json` file in your package for each language Nuvabill speaks:

`ar`, `ckb`, `de`, `es`, `fr`, `mn`, `pt_BR`, `ru`, `th`, `tr` and `zh_CN`

English is the source text, so it needs no file. Thai (`th`) came in 0.7.0 and Mongolian (`mn`) in 0.7.1
(Mongolian is off until staff turn it on). Arabic
(`ar`) and Kurdish Sorani (`ckb`) are written right to left.

For example, `lang/de.json`:

```json
{
    "Slack webhook URL": "Slack-Webhook-URL",
    "Send alerts for": "Meldungen senden für",
    "Orders and tickets": "Bestellungen und Tickets",
    "Orders only": "Nur Bestellungen",
    "Tickets only": "Nur Tickets",
    "Text before each alert": "Text vor jeder Meldung",
    "Optional, for example the name of your shop.": "Optional, zum Beispiel der Name Ihres Shops.",
    "In Slack: Apps → Incoming Webhooks → Add to Slack → copy the webhook URL.": "In Slack: Apps → Incoming Webhooks → Add to Slack → die Webhook-URL kopieren.",
    "New order #:number from :client: :total": "Neue Bestellung #:number von :client: :total",
    "New ticket #:number: :subject": "Neues Ticket #:number: :subject",
    "a client": "einem Kunden"
}
```

Keep placeholders such as `:number` and `:client` exactly as they are. Nuvabill loads your `lang/` folder (and a
`views/` folder, as `ext-{slug}::`) for every extension on disk.

### Step 7: Switch it on

1. Sign in to your local copy as staff.
2. Open **Marketplace → Installed**, or **Extensions → Add-ons** (`/admin/extensions?tab=addons`).
3. Open **Acme Slack alerts**. Its settings page is `/admin/marketplace/acme-slack-alerts/settings`.
4. Paste your Slack webhook URL, tick **Switch Acme Slack alerts on**, and click **Save**.
5. Place a test order as the demo client. The message shows up in your Slack channel.

Nothing happens? Check `storage/logs/laravel.log`. A manifest with an error, or one whose `requires` is newer than
your Nuvabill, is skipped with a warning there.

### Step 8: Make the zip

The store wants a zip with `extension.json` at the top of the zip, **or** inside one single top folder that holds
everything. Zipping the folder itself (right-click → compress) gives you the second form, which is fine.

On Windows 10 or 11, in the `extensions/addons` folder:

```
tar -a -c -f acme-slack-alerts-1.0.0.zip acme-slack-alerts
```

Do not use PowerShell's `Compress-Archive`: it writes paths with a backslash, which the store refuses.

On macOS or Linux, in the `extensions/addons` folder:

```
zip -r acme-slack-alerts-1.0.0.zip acme-slack-alerts -x "*/.git/*"
```

Before you upload, check that the zip:

- has `extension.json` at the top, or in the one top folder;
- has at most 5,000 files, is at most 100 MB unpacked and at most 20 MB zipped;
- has no program files or server files (`.phar`, `.exe`, `.sh`, `.bat`, `.htaccess` and others are refused);
- has no archives inside (`.zip`, `.tar`, `.gz` and others are refused), and only real images as `.png`, `.jpg`, `.gif` or `.webp`;
- uses `/` in its paths (the commands above do) and holds each file name only once;
- has no PHP code in any file that is not `.php` (`.md` files may show PHP examples);
- has no encoded code (ionCube, SourceGuardian, `eval(base64_decode(...))` and the like);
- has no file named `.nuvabill-license` (the store adds one to paid downloads);
- has no links or paths that point outside the package.

[Packages](packages.md) lists every check.

### Step 9: Upload it

1. Sign in to [my.nuvabill.com](https://my.nuvabill.com) and join as a developer at
   [my.nuvabill.com/developer/join](https://my.nuvabill.com/developer/join).
2. In your developer area, add a new item: name, slug, type, category, a short summary and a price (0 for free).
3. On the same form, choose your zip, add a short changelog if you like (at most 2,000 characters), and confirm
   that you own the code.
4. The store checks the zip at once. Errors stop the upload; warnings go to the reviewer.
5. A person then reviews your code. You get any questions in your developer area and can reply or upload a fixed
   version. When it is approved, the store signs the package and it shows in every Nuvabill admin area.

For each update, raise `version` in `extension.json`, make a new zip and upload it to the same item. Read
[Selling](selling.md) for reviews, prices, payouts and licences.

## Where next

- [Packages](packages.md): every manifest field, all permission codes, and the automatic checks.
- [Add-ons](addons.md): all add-on methods, such as head HTML, schedules, admin pages, visitor pages, webhooks
  (0.6.10+), dedicated server hooks (0.7.0+), database tables and service panels.
- [Themes and order forms](themes.md): views, styles, files, previews and the one-page ordering API.
- [Payment gateways](payment-gateways.md): taking payments and recording them safely.
- [Server modules](server-modules.md): server modules, domain registrars and hardware drivers.
- [Selling](selling.md): your developer account, review, prices, payouts and licences.
