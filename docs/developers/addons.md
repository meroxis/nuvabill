# Add-ons

An add-on is a feature that is not a payment gateway, server module or domain registrar. Chat alerts, a live chat
window, off-site backups, a website builder and reports are all add-ons.

This page lists everything an add-on can do. For the manifest, the permission codes and the store checks, read
[Packages](packages.md). To build your first add-on step by step, start with the [overview](README.md).

On this page:

- [How an add-on is loaded](#how-an-add-on-is-loaded)
- [The folder](#the-folder)
- [Method list](#method-list)
- [Settings](#settings)
- [Events](#events)
- [Head HTML and the content security policy](#head-html-and-the-content-security-policy)
- [Scheduled work](#scheduled-work)
- [Admin pages](#admin-pages)
- [Pages for visitors, the home page and the sitemap](#pages-for-visitors-the-home-page-and-the-sitemap)
- [Webhooks](#webhooks)
- [Hardware pages and hooks](#hardware-pages-and-hooks)
- [Boxes on the client's service page](#boxes-on-the-clients-service-page)
- [Backups](#backups)
- [Database tables](#database-tables)
- [Views, files and translations](#views-files-and-translations)
- [Demo sites](#demo-sites)
- [Safety rules](#safety-rules)
- [Example 1: order alerts](#example-1-order-alerts)
- [Example 2: a status page with a webhook](#example-2-a-status-page-with-a-webhook)

## How an add-on is loaded

Your main class extends the abstract class `App\Extensions\Addons\Addon`. Nuvabill creates it like this:

```php
public function __construct(
    protected ExtensionManifest $manifest,
    protected array $settings = [],
) {}
```

Do not add your own constructor arguments. Use `app(...)` when you need a service.

An add-on is **active** only when both are true:

1. Staff switched it on.
2. `isConfigured()` returns `true`. By default this means every settings field marked `required` has a value.

Only active add-ons run. If an add-on is off, or a required field is empty, Nuvabill does not call `boot()`, does not
load its routes, does not run its schedule and does not add its head HTML.

When an add-on throws an error while Nuvabill calls `boot()`, a route method, `schedule()`, `headHtml()` or most
other hooks, Nuvabill writes the error to the log and skips that add-on. The rest of the site keeps working. An error
inside one of your own pages, when someone opens it, shows a normal error page.

## The folder

An add-on is installed in `extensions/addons/{slug}`. A full add-on can look like this:

```text
extensions/addons/acme-status/
├── extension.json          the manifest (see packages.md)
├── src/
│   └── AcmeStatus.php      your main class, named in "class"
├── views/
│   └── status.blade.php    used as view('ext-acme-status::status')
├── lang/
│   ├── ar.json
│   ├── ckb.json
│   └── ...                 one file for each language
├── public/
│   └── status.css          linked with package_asset('extensions', 'acme-status', 'status.css')
└── database/
    └── migrations/         Laravel migrations, needs the "database" permission
```

Your PHP classes live in `src/` and load from the `namespace` in the manifest.

## Method list

Every method below is on `App\Extensions\Addons\Addon`. "Override" means you write your own version. "Use" means you
call it from your code.

| Method | Override or use | What it does | Since |
| --- | --- | --- | --- |
| `slug(): string` | use | The slug from the manifest. | |
| `name(): string` | use | The name from the manifest. | |
| `settingsFields(): array` | override | The settings form staff fill in. | |
| `isConfigured(): bool` | override | The add-on runs only when this is `true`. | |
| `boot(): void` | override | Runs once per request. Listen to events here. | |
| `headHtml(string $area): string` | override | HTML added at the end of `<head>`. | |
| `contentSecurityPolicy(): array` | override | Extra hosts for the content security policy. | |
| `schedule(Schedule $schedule): void` | override | Scheduled work, run by the site's cron job. | 0.4.2 |
| `adminRoutes(): void` | override | Admin pages under `/admin/addons/{slug}/`. | 0.4.2 |
| `routeName(string $name): string` | use | Full name of an admin route. | 0.4.2 |
| `settingsHtml(): string` | override | HTML under the settings form. | 0.4.2 |
| `setting(string $key, mixed $default = null): mixed` | use (protected) | Reads one setting. | |
| `remember(array $values): void` | use (protected) | Saves values the add-on keeps for itself. | 0.4.2 |
| `publicRoutes(): void` | override | Pages for visitors. | 0.6.1 |
| `publicRouteName(string $name): string` | use | Full name of a visitor route. | 0.6.1 |
| `servesHomePage(): bool` | override | Return `true` to show your own home page. | 0.6.1 |
| `homePage(Request $request): mixed` | override | The home page response. | 0.6.1 |
| `sitemapPages(): array` | override | Your visitor pages for `sitemap.xml`. | 0.6.1 |
| `webhookRoutes(): void` | override | Addresses other services send data to. | 0.6.10 |
| `webhookRouteName(string $name): string` | use | Full name of a webhook route. | 0.6.10 |
| `hardwareRoutes(): void` | override | Pages under Admin → Hardware. | 0.7.0 |
| `hardwareRouteName(string $name): string` | use | Full name of a hardware route. | 0.7.0 |
| `demoSettings(): array` | override | Settings for a public demo site. | 0.7.1 |

Set `"requires"` in your manifest to the oldest version that has every method you use. For example, an add-on with a
webhook needs `"requires": ">=0.6.10"`.

## Settings

### settingsFields()

Return one entry per field. The key is the setting name. Each field has:

| Key | Needed | Meaning |
| --- | --- | --- |
| `label` | yes | The label staff see. |
| `type` | yes | `text`, `password`, `textarea` or `select`. Any other type shows as a text field. |
| `help` | no | A short help line under the field. |
| `required` | no | `true` if the add-on cannot work without it. |
| `options` | for `select` | `['value' => 'Label', ...]`. |
| `owner` | no | `true` if only staff with the Owner role may change it (0.7.3+). See [Owner-only fields](#owner-only-fields-073). |

```php
public function settingsFields(): array
{
    return [
        'api_key' => ['label' => 'API key', 'type' => 'password', 'required' => true],
        'region' => ['label' => 'Region', 'type' => 'select', 'options' => ['eu' => 'Europe', 'us' => 'United States']],
        'footer' => ['label' => 'Footer text', 'type' => 'textarea', 'help' => 'Shown under each message.'],
    ];
}
```

What Nuvabill does when staff save the form:

- Every value must be a string.
- A `textarea` can be up to 5,000 characters. Every other field can be up to 1,000.
- A `select` value must be one of its `options` keys.
- A `password` field is never shown again. If staff leave it empty, the saved value stays.
- Values you saved with `remember()` are kept.

**All add-on settings are stored encrypted** in the database, not only password fields. Still, use the `password` type
for every secret (API keys, tokens, passwords), so the value is never shown on the page.

Use `'type' => 'password'` for secrets in add-on settings. Do not use the `'secret' => true` flag here: on the add-on
settings form, an empty `secret` field is saved as empty, so the stored value is lost.

### Owner-only fields (0.7.3+)

Every staff member with the `marketplace.manage` permission can open and save your settings form. Some settings are
too strong for that: a setting that decides **where site data goes** (a backup password, the account or app a copy is
sent to, a forwarding address) lets that person take the data. Mark such fields with `'owner' => true`:

```php
'password' => ['label' => 'Backup password', 'type' => 'password', 'owner' => true],
```

What Nuvabill does with a field marked `owner`:

- An owner (a staff member whose role has every permission) uses it like any other field.
- For other staff the field is shown switched off, with the note "Only an owner can change this setting."
- When other staff save the form, the saved value stays. A new value is a form error, and nothing is saved.
- Other staff can never fill in a `password` field marked `owner`, also not with the value that is already saved.
- A `required` field marked `owner` that is still empty stops other staff from switching the add-on on.

Nuvabill before 0.7.3 ignores the mark and saves the field for everyone. If your add-on supports older versions,
leave the field out of `settingsFields()` there for staff who are not owners. A field that is not in the list is
never changed:

```php
use App\Extensions\SettingsForm;

if (! method_exists(SettingsForm::class, 'isOwnerOnly') && auth('admin')->user()?->role?->isOwner() !== true) {
    unset($fields['password']);
}
```

### Reading settings

Use `$this->setting('key')` or `$this->setting('key', 'default')`. Values from the form are strings, or `null` when
empty.

### isConfigured()

Override it when "every required field has a value" is not the right test. For example, a live chat add-on that
needs either one of two IDs:

```php
public function isConfigured(): bool
{
    return filled($this->setting('tawk_id')) || filled($this->setting('crisp_id'));
}
```

### settingsHtml() (0.4.2+)

HTML shown under the settings form, for example a connection status and buttons. It is shown only while the add-on is
switched on. If it throws an error, the panel is left empty.

```php
public function settingsHtml(): string
{
    return view('ext-acme-status::panel', ['last' => $this->setting('last_update')])->render();
}
```

### remember() (0.4.2+)

`remember(array $values)` saves values the add-on keeps for itself, such as an OAuth token or the time of the last
run.

- They are stored encrypted with the other settings.
- Only the keys you give change. Settings that staff saved in the meantime are kept.
- The row is locked while it changes, so two runs at the same time do not overwrite each other.
- They survive when staff save the settings form.

```php
$this->remember(['last_sync' => now()->toIso8601String()]);
```

Do not use the same key as one of your form fields, or the form will overwrite it.

## Events

Listen to events in `boot()` with `Illuminate\Support\Facades\Event::listen()`. List `events` in your permissions.

All events are in the `App\Events` namespace. Their properties are public.

| Event | Properties | Sent when | Since |
| --- | --- | --- | --- |
| `ClientRegistered` | `$client` (Client) | A client signs up, also at checkout or with social sign-in. | |
| `OrderPlaced` | `$order` (Order) | An order is placed. | |
| `InvoicePaid` | `$invoice` (Invoice) | An invoice is paid. | |
| `ServiceActivated` | `$service` (Service) | A service is set up for the first time. | |
| `ServiceSuspended` | `$service` (Service) | A service is suspended. | |
| `ServiceTerminated` | `$service` (Service) | A service is terminated. | |
| `TicketOpened` | `$ticket` (Ticket), `$message` (TicketReply) | A ticket is opened. | |
| `TicketReplied` | `$ticket` (Ticket), `$reply` (TicketReply) | Someone replies to a ticket. | |
| `DedicatedServerActivated` | `$machine` (Machine), `$service` (Service) | A machine is given to a service. | 0.7.0 |
| `DedicatedServerSuspended` | `$machine`, `$service` | A dedicated server is suspended. | 0.7.0 |
| `DedicatedServerUnsuspended` | `$machine`, `$service` | A dedicated server is unsuspended. | 0.7.0 |
| `DedicatedServerReleased` | `$machine`, `$service` (or `null`), `$needsWipe` (bool), `$reason` (string) | A machine leaves its service: the service was terminated, cancelled or marked as fraud, its order was cancelled, or it moved to another machine. | 0.7.0 |

Notes:

- In `TicketReplied`, use `$event->reply->isFromStaff()` to see whether staff or the client replied.
- In `DedicatedServerReleased`, `$service` is `null` when the service is gone. `$reason` is plain English for logs,
  such as `terminated`. When `$needsWipe` is `true`, the machine was used and now waits for staff to wipe its disks.
  When it is `false`, the machine was only reserved and is on sale again.
- The dedicated server events are sent after the change is saved. A listener that fails never stops the change.
- Events often fire during checkout or a payment. Your listener must be fast and must never throw. See
  [Safety rules](#safety-rules).

```php
use App\Events\TicketReplied;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    Event::listen(TicketReplied::class, function (TicketReplied $event): void {
        if ($event->reply->isFromStaff()) {
            return;
        }

        // notifyTeam() is your own method. rescue() logs an error instead of throwing it.
        rescue(fn () => $this->notifyTeam('Client replied to ticket #'.$event->ticket->number));
    });
}
```

## Head HTML and the content security policy

### headHtml(string $area)

Return HTML for the end of `<head>`, or `''` to add nothing. `$area` is:

- `client`: the store and the client area.
- `admin`: the admin area.

Nuvabill joins the HTML of all active add-ons. Use it for a chat widget or an analytics tag. List `head-script` in
your permissions.

The HTML is printed as it is. **Never print a setting as raw HTML.** Check each value against a strict pattern, and
escape it with `e()` or `json_encode()`:

```php
public function headHtml(string $area): string
{
    $id = (string) $this->setting('widget_id');

    if ($area !== 'client' || ! preg_match('/^[a-z0-9]{8,40}$/i', $id)) {
        return '';
    }

    return '<script src="https://widget.acme.example/loader.js" data-id="'.e($id).'" async></script>';
}
```

Themes keep `<x-extension-head area="client" />` in their layout so this works. See [Themes](themes.md).

### contentSecurityPolicy()

Nuvabill's security headers only allow scripts, styles, fonts and connections from the site itself. Return the extra
hosts your HTML needs:

```php
public function contentSecurityPolicy(): array
{
    return [
        'script-src' => ['https://widget.acme.example'],
        'connect-src' => ['wss://*.acme.example', 'https://api.acme.example'],
        'frame-src' => ['https://widget.acme.example'],
    ];
}
```

Rules:

- A source must look like `https://host`, `wss://host`, with an optional `:port` and path. `*` is allowed in the
  host. Anything else (`'unsafe-inline'`, `http://`, spaces, `;`) is dropped without a message.
- The directives that take extra sources are `script-src`, `style-src`, `font-src`, `connect-src`, `frame-src` and
  `media-src`. Images from any `https:` address are already allowed.
- Sources from all add-ons are merged, and repeats are removed.

## Scheduled work

### schedule(Schedule $schedule) (0.4.2+)

Add work that runs through the site's cron job (`Illuminate\Console\Scheduling\Schedule`). List `schedule` in your
permissions.

- Give every closure a name. Use your slug in it.
- Use `withoutOverlapping()` so a slow run does not start twice.
- Keep each run short, or split long work into small parts.

```php
use Illuminate\Console\Scheduling\Schedule;

public function schedule(Schedule $schedule): void
{
    $schedule->call(fn () => $this->sync())
        ->hourly()
        ->name('acme-status-sync')
        ->withoutOverlapping();
}
```

## Admin pages

### adminRoutes() (0.4.2+)

Register admin pages inside this method. They are placed under `/admin/addons/{slug}/`, inside the admin area. Staff
must be signed in and have the `marketplace.manage` permission. List `admin-page` in your permissions.

Give each route a short name. Nuvabill adds `admin.addons.{slug}.` in front. Link to it with `routeName()`:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

public function adminRoutes(): void
{
    Route::get('report', fn () => view('ext-acme-status::report'))->name('report');
    Route::post('refresh', function (Request $request) {
        $this->sync();

        return back()->with('status', __('Refreshed.'));
    })->name('refresh');
}

// In PHP or Blade:
route($this->routeName('report')); // admin.addons.acme-status.report
```

Admin forms are normal Laravel forms: add `@csrf` to each one. Use the admin layout in your view:

```blade
<x-layouts.admin :title="__('Acme report')">
    ...
</x-layouts.admin>
```

### Owner-only actions

Your admin pages are open to every staff member with `marketplace.manage`. That permission is meant for installing
and setting up extensions, not for reading all the data of the site. So a page that **sends site data somewhere new**
or **starts a copy of it** (connect an outside account, disconnect it, "Back up now", an export) must check more
itself:

```php
Route::post('connect', function (Request $request) {
    // 1. Only an owner.
    abort_unless($request->user('admin')?->role?->isOwner() === true, 403);
    // 2. Who types their own password again, so a session someone else got hold of is not enough.
    $request->validate(['current_password' => ['required', 'string', 'current_password:admin']]);

    // ... connect ...
})->middleware('throttle:staff-reauth')->name('connect');
```

- Name the form field `current_password`: Nuvabill never keeps a field with that name in the session after a form
  error. Laravel checks the password for you. Never read, log or store it yourself.
- `throttle:staff-reauth` (0.6.12+) counts the tries together with every other password check of that staff member.
  On older versions use `throttle:6,1`. You can test for it with
  `RateLimiter::limiter('staff-reauth') !== null`.
- Show these buttons only to owners, and tell other staff why they are missing.
- Mark the settings fields that belong to the same choice with `'owner' => true`, see
  [Owner-only fields](#owner-only-fields-073).

For an add-on that lists `backups` in its permissions, Nuvabill 0.7.3 and newer does the first check itself: all of
its admin pages answer 403 for staff who are not owners. Keep your own check anyway, for older versions, and still ask
for the password. The settings form stays open to staff with `marketplace.manage`, which is why its sensitive fields
need the `owner` mark.

## Pages for visitors, the home page and the sitemap

All of these need `public-page` in your permissions. Without it, Nuvabill does not load your visitor routes, home
page or sitemap entries.

### publicRoutes() (0.6.1+)

Pages anyone can open. They use the normal `web` middleware (sessions, cookies, CSRF checks). They are registered
after all of Nuvabill's own pages, so they can never replace one.

Paths get **no prefix**: `Route::get('status', ...)` is the address `/status`. Choose paths that are unlikely to clash
with Nuvabill's pages or other add-ons. When a path is already taken, your page is not reached.

Route names get `addon.{slug}.` in front. Link to them with `publicRouteName()`:

```php
public function publicRoutes(): void
{
    Route::get('status', fn () => $this->statusPage())->name('status');
}

route($this->publicRouteName('status')); // addon.acme-status.status
```

Search engines are told not to index a page unless the page allows it. Call this in the page code:

```php
app(\App\Seo\Seo::class)->allowIndexing();
```

Visitor pages usually use the active theme's layout, so they match the site:

```blade
@extends('theme::layouts.app')

@section('title', __('Service status'))

@section('content')
    ...
@endsection
```

**Forms for visitors:** add the `captcha:contact` middleware and a throttle to a form's route. Hosts then turn the
CAPTCHA on or off for "Contact forms on your website" in Settings → Security:

```php
Route::post('contact', fn (Request $request) => $this->send($request))
    ->middleware(['throttle:5,1', 'captcha:contact'])
    ->name('contact.send');
```

Put `@csrf` and `<x-captcha form="contact" />` inside the form. Without `<x-captcha>`, the form fails when the host
turns the CAPTCHA on. Pass the form's address to the view, for example as `$action`:

```blade
<form method="POST" action="{{ $action }}">
    @csrf
    ...
    <x-captcha form="contact" />
    <button type="submit" class="btn btn-primary">{{ __('Send') }}</button>
</form>
```

### servesHomePage() and homePage() (0.6.1+)

Return `true` from `servesHomePage()` to show your own page at `/`. The store then moves to `/store`. `homePage()`
returns the response. By default it returns a 404 page.

```php
public function servesHomePage(): bool
{
    return $this->setting('use_as_home') === 'yes';
}

public function homePage(Request $request): mixed
{
    app(\App\Seo\Seo::class)->allowIndexing();

    return view('ext-acme-status::home');
}
```

Only one add-on can show the home page. If more than one says `true`, the first one by name wins. Let staff turn this
on with a setting, as above. The choice is made when the routes load, so a change works from the next request.

### sitemapPages() (0.6.1+)

Return your visitor pages for `sitemap.xml`. Each entry is a path (relative to the site address) and when it last
changed:

```php
public function sitemapPages(): array
{
    return [
        ['path' => 'status', 'updated' => null],
        ['path' => 'about-acme', 'updated' => \Illuminate\Support\Carbon::parse('2026-10-01')],
    ];
}
```

A leading `/` is removed. Entries without a string `path` are skipped. The sitemap is cached: call
`App\Seo\Sitemap::forget()` when your pages change.

## Webhooks

### webhookRoutes() (0.6.10+)

Addresses another service sends data to, for example a chat service that reports new messages. List `webhook` in your
permissions. Without it, these routes are not loaded.

- They are placed under `/webhooks/addons/{slug}/`.
- There is **no session, no cookies and no CSRF token**. Anyone on the internet can call them.
- They are limited to 240 requests per minute from one IP address.
- Route names get `webhooks.addon.{slug}.` in front. Link to them with `webhookRouteName()`.

Because anyone can call them, **you must check every request yourself**:

- Check a signature (for example an HMAC of the raw body with a shared secret) with `hash_equals()`.
- Or put a long random token in the address and compare it with `hash_equals()`.
- Refuse old requests, so nobody can send a captured request again. For example, sign a timestamp together with the
  body, and refuse the request when the timestamp is more than 5 minutes old.
- Limit route parameters with `->where()` or `->whereAlphaNumeric()`.
- Check every field of the body before you use it. Never trust it.
- Return quickly. Do slow work in your schedule.

```php
public function webhookRoutes(): void
{
    Route::post('events', fn (Request $request) => $this->receive($request))->name('events');
}

route($this->webhookRouteName('events')); // https://your-site/webhooks/addons/acme-status/events
```

Show the address in `settingsHtml()`, so staff can copy it into the other service. Example 2 below has a full
webhook with a signature check.

## Hardware pages and hooks

### hardwareRoutes() (0.7.0+)

Pages and form targets under Admin → Hardware, for example the form that saves a machine's switch port. List `hardware`
in your permissions. Without it, these routes are not loaded.

- They are placed under `/admin/hardware/addons/{slug}/`.
- Staff need the `hardware.manage` permission. The admin area's sign-in, two-factor and CSRF checks apply.
- They are limited to 30 requests per minute.
- On a public demo site, forms that change data (POST, PUT, DELETE) are turned away. Pages that only show data work.
- Route names are `admin.hardware-addons.{slug}.{name}`. Link to them with `hardwareRouteName()`.

```php
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

public function hardwareRoutes(): void
{
    // Laravel finds the machine by its ID, or shows a 404 page.
    Route::post('machines/{machine}/port', fn (Request $request, Machine $machine) => $this->savePort($request, $machine))
        ->whereNumber('machine')
        ->name('port');
}

route($this->hardwareRouteName('port'), ['machine' => $machine->id]); // admin.hardware-addons.{slug}.port
```

### Other hardware hooks (0.7.0+)

Register these in `boot()`. Each one is a shared object, so get it with `app(...)`, for example
`app(PowerDrivers::class)->register($driver)`. All classes are in the `App\Provisioning\Hardware` namespace.

| Registry | Method | What it adds |
| --- | --- | --- |
| `Drivers\PowerDrivers` | `register(PowerDriver $driver)` | A way to switch machines on and off. A driver can also choose a boot device, read health or read power use. |
| `MachineSections` | `add(Closure $resolver)` | Cards on an admin machine page. The resolver gets `Machine $machine, Admin $admin` and returns `null` or `['view' => ..., 'data' => [...]]`. The view must be `ext-{slug}::name`. |
| `DedicatedPanelExtensions` | `add(DedicatedPanelExtension $extension)` | Tabs in the client's dedicated server panel. The first add-on to claim a key owns it. |
| `MachineInstallers` | `register(MachineInstaller $installer)` | Automatic operating system installs. When an install is done, call `app(ServerSetup::class)->ready(...)`. When it fails, call `failed($service, $reason)`. |

These are described in [Dedicated servers](../hardware.md#for-add-on-makers). The marketplace packages Dedicated
Server Power, Switch Port Control and Automatic OS Install use them.

## Boxes on the client's service page

`App\Support\ServicePanels` adds a box to the client's service page. Register it in `boot()`. Return `null` when the
box does not belong on this service:

```php
use App\Models\Service;
use App\Support\ServicePanels;

public function boot(): void
{
    app(ServicePanels::class)->add(function (Service $service): ?array {
        if ((int) $service->product_id !== (int) $this->setting('product_id')) {
            return null;
        }

        return ['view' => 'ext-acme-status::service-box', 'data' => ['note' => __('Monitored by Acme')]];
    });
}
```

The view gets your `data` and `$service`. If your function throws, the error is logged and the box is skipped.
List `client-page` in your permissions. Custom themes must keep the service panels loop in `client/services/show`
for boxes to show.

## Backups

(0.4.2+) `create(string $type = SiteBackup::TYPE_SITE, ?string $password = null, bool $record = true): string`
makes a backup zip and returns its path. With a password, every file in the zip is encrypted with AES-256.

Since 0.7.3 a whole-site backup **without a password leaves out the `.env` file** (and its copies, such as the
cached configuration). That file holds the key that opens every saved password, so it must never leave the server
unencrypted next to a copy of the database. `SiteBackup::includesEnvFile($password)` tells you whether a backup made
with that password holds it, so you can tell staff. The `RESTORE.txt` in the zip explains it too.

```php
use App\Support\SiteBackup;

// Stop when this server cannot encrypt zip files. create() with a password throws an error then.
if (! SiteBackup::canEncrypt()) {
    return;
}

$backups = app(SiteBackup::class);

// TYPE_DATABASE for the database only, or TYPE_SITE for every file and the database.
// record: false, because the copy is not stored away from this server yet.
$path = $backups->create(SiteBackup::TYPE_DATABASE, $zipPassword, record: false);

// ... upload $path to the place staff chose ...

unlink($path);

// Tell site health that an encrypted copy is now stored away from this server.
$backups->record(SiteBackup::TYPE_DATABASE, encrypted: true, offsite: true);
```

List `backups` in your permissions. Staff then see that your add-on reads the whole site, including the `.env`
file. A whole-site backup holds every secret of the site, so:

- Always set a strong zip password, and store it only in a `password` setting marked `'owner' => true`.
- Send the file only to a place an owner chose, over HTTPS. Choosing or changing that place, and starting a backup by
  hand, are [owner-only actions](#owner-only-actions): check for an owner and ask for the password again.
- Delete the local file when you are done.

Since 0.7.3 the admin pages of an add-on with the `backups` permission are open to owners only.

## Database tables

(0.4.5+) There is no special method. Put normal Laravel migration files in `database/migrations/` in your package.

- List `database` in your permissions. A package that has migrations but does not list `database` is refused.
- Give every table a prefix of your own, for example `acme_status_events`.
- Nuvabill runs your migrations when the package is installed or updated from the marketplace. If a migration fails,
  the old version is put back.
- Set `"requires": ">=0.4.5"`.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acme_status_events', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20);
            $table->string('message', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acme_status_events');
    }
};
```

Use Eloquent or the query builder. Both use parameterized queries. Never build SQL by joining strings with user input.

## Views, files and translations

**Views.** A `views/` folder becomes the Blade namespace `ext-{slug}`. Render a view with
`view('ext-acme-status::status')`. Print values with `{{ }}`, which escapes them. Use `{!! !!}` only for HTML you
built and escaped yourself.

**Files.** Nuvabill serves the files in `public/` to anyone, even when the add-on is switched off. Link to them with
`package_asset('extensions', 'acme-status', 'status.css')`. Only these file types are served: `css`, `js`, `json`,
`woff2`, `woff`, `ttf`, `svg`, `png`, `jpg`, `jpeg`, `webp`, `gif` and `ico`. Never put a secret in `public/`.

**Translations.** Wrap every text in `__()`. Put a JSON file in `lang/` for each language. English is the source text,
so you do not need an English file. Nuvabill speaks these 11 other languages:

| Code | Language |
| --- | --- |
| `ar` | Arabic (right to left) |
| `ckb` | Kurdish, Sorani (right to left) |
| `de` | German |
| `es` | Spanish |
| `fr` | French |
| `mn` | Mongolian (0.7.1+, off until staff turn it on) |
| `pt_BR` | Portuguese (Brazil) |
| `ru` | Russian |
| `th` | Thai (0.7.0+) |
| `tr` | Turkish |
| `zh_CN` | Chinese (Simplified) |

```json
{
    "Service status": "Estado del servicio",
    "All systems are working.": "Todos los sistemas funcionan."
}
```

Views and translations are loaded for every extension on disk, even when it is switched off.

All JSON translation files share one list with Nuvabill and the other extensions. When Nuvabill already translates the
same English text (such as "Yes"), Nuvabill's translation is used. If you need your own wording, use an English text
that only your add-on uses.

## Demo sites

### demoSettings() (0.7.1+)

A public demo site resets its data often. After each reset, it switches on the add-ons listed in
`NUVABILL_DEMO_ADDONS`, with the settings from `demoSettings()`. Without this method, the add-on is switched on with
no settings, so each setting has its default.

```php
public function demoSettings(): array
{
    return ['use_as_home' => 'no', 'title' => 'Acme demo status'];
}
```

Visitors of a demo site can read these settings. **Never put a secret in `demoSettings()`.** This only works on a
site in demo mode (`NUVABILL_DEMO=true`). On a demo site, the add-on settings form and forms on add-on admin pages and
hardware pages are turned away, so visitors cannot change them.

## Safety rules

- **A broken add-on is skipped.** Nuvabill logs the error and leaves the add-on out, so it cannot take a site down.
  Do not rely on this: write code that does not fail.
- **Wrap every network call.** Use a short timeout and catch errors. An order, payment or ticket must never fail
  because another service is slow or down:

  ```php
  try {
      Http::timeout(5)->connectTimeout(3)->post($url, $data)->throw();
  } catch (\Throwable $exception) {
      report($exception);
  }
  ```

- **List every host** you connect to as `http:host` in your permissions. Reviewers check your code against it.
- **Keep secrets secret.** Store them in `password` settings or with `remember()`. Never write them to the log, never
  put them in a page, a URL query or `demoSettings()`.
- **Check webhook requests** with a signature or a long random token, compared with `hash_equals()`.
- **Never print settings or request data as raw HTML.** Escape with `{{ }}` or `e()`.
- **Check all input** in your routes with `$request->validate()`.
- **Ask only for the permissions you use.** Staff see them before they install, and reviewers check that your code
  does nothing else. Nuvabill itself only checks `public-page`, `webhook`, `hardware` and `database`. It runs the
  admin pages, schedule, head HTML and event listeners of any active add-on, so list `admin-page`, `schedule`,
  `head-script` and `events` honestly. See [Packages](packages.md).
- **Do not ask for passwords** of staff, clients or servers outside your settings form. The one exception is the
  signed-in staff member's own password before an [owner-only action](#owner-only-actions), checked by Laravel's
  `current_password:admin` rule in a field named `current_password`. Your code never reads or keeps it.
- **`marketplace.manage` is not "see everything".** Settings and pages that decide where site data goes are for
  owners only: see [Owner-only fields](#owner-only-fields-073) and [Owner-only actions](#owner-only-actions).

## Example 1: order alerts

This add-on posts a message to a team chat when an order is placed or an invoice is paid. It uses settings, events,
a network call with a timeout, and translations. It needs Nuvabill 0.4.2 or newer, because add-on translation
files came in 0.4.2.

`extensions/addons/acme-alerts/extension.json`:

```json
{
    "slug": "acme-alerts",
    "type": "addon",
    "name": "Acme order alerts",
    "version": "1.0.0",
    "namespace": "Acme\\Alerts\\",
    "class": "Acme\\Alerts\\AcmeAlerts",
    "description": "Posts new orders and payments to your Acme Chat room.",
    "author": "Acme",
    "requires": ">=0.4.2",
    "permissions": ["events", "admin-settings", "http:chat.acme.example"]
}
```

`extensions/addons/acme-alerts/src/AcmeAlerts.php`:

```php
<?php

namespace Acme\Alerts;

use App\Events\InvoicePaid;
use App\Events\OrderPlaced;
use App\Extensions\Addons\Addon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class AcmeAlerts extends Addon
{
    public function settingsFields(): array
    {
        return [
            'token' => [
                'label' => __('Acme Chat bot token'),
                'type' => 'password',
                'required' => true,
            ],
            'room' => [
                'label' => __('Room ID'),
                'type' => 'text',
                'required' => true,
                'help' => __('Letters, numbers and dashes only.'),
            ],
            'payments' => [
                'label' => __('Also post payments'),
                'type' => 'select',
                'options' => ['yes' => __('Yes'), 'no' => __('No')],
            ],
        ];
    }

    public function isConfigured(): bool
    {
        return parent::isConfigured()
            && preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) $this->setting('room')) === 1;
    }

    public function boot(): void
    {
        Event::listen(OrderPlaced::class, function (OrderPlaced $event): void {
            $this->post(__('New order #:number', ['number' => $event->order->number]));
        });

        if ($this->setting('payments', 'yes') === 'yes') {
            Event::listen(InvoicePaid::class, function (InvoicePaid $event): void {
                $this->post(__('Invoice #:number was paid', ['number' => $event->invoice->displayNumber()]));
            });
        }
    }

    private function post(string $text): void
    {
        try {
            Http::timeout(5)
                ->connectTimeout(3)
                ->withToken((string) $this->setting('token'))
                ->post('https://chat.acme.example/api/rooms/'.$this->setting('room').'/messages', [
                    'text' => $text,
                ])
                ->throw();
        } catch (\Throwable $exception) {
            // Never stop an order or a payment because the chat service is down.
            report($exception);
        }
    }
}
```

`extensions/addons/acme-alerts/lang/es.json` (add one file for each language code in the table above):

```json
{
    "Acme Chat bot token": "Token del bot de Acme Chat",
    "Room ID": "ID de la sala",
    "Letters, numbers and dashes only.": "Solo letras, números y guiones.",
    "Also post payments": "Publicar también los pagos",
    "Yes": "Sí",
    "No": "No",
    "New order #:number": "Nuevo pedido #:number",
    "Invoice #:number was paid": "La factura #:number fue pagada"
}
```

Why it is safe: the token is a `password` field and is stored encrypted. The room ID is checked before it is put in the
address. The call has short timeouts and a `try`/`catch`, so a slow chat service never stops a checkout.

## Example 2: a status page with a webhook

This add-on shows a public status page at `/status`. An outside monitor (Acme Monitor) sends the current status to a
webhook. The webhook checks a signed timestamp, checks the data, and saves it with `remember()`. Staff see the webhook address under the
settings form. The page is listed in the sitemap.

`extensions/addons/acme-status/extension.json`:

```json
{
    "slug": "acme-status",
    "type": "addon",
    "name": "Acme status page",
    "version": "1.0.0",
    "namespace": "Acme\\Status\\",
    "class": "Acme\\Status\\AcmeStatus",
    "description": "Shows a public status page, updated by Acme Monitor.",
    "author": "Acme",
    "requires": ">=0.6.10",
    "permissions": ["admin-settings", "public-page", "webhook"]
}
```

`extensions/addons/acme-status/src/AcmeStatus.php`:

```php
<?php

namespace Acme\Status;

use App\Extensions\Addons\Addon;
use App\Seo\Seo;
use App\Seo\Sitemap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

class AcmeStatus extends Addon
{
    private const STATUSES = ['ok', 'degraded', 'down'];

    public function settingsFields(): array
    {
        return [
            'signing_secret' => [
                'label' => __('Signing secret'),
                'type' => 'password',
                'required' => true,
                'help' => __('The secret from Acme Monitor. Use at least 32 random characters.'),
            ],
        ];
    }

    public function settingsHtml(): string
    {
        return view('ext-acme-status::panel', [
            'webhook' => route($this->webhookRouteName('update')),
            'page' => route($this->publicRouteName('status')),
            'updated' => $this->setting('updated_at'),
        ])->render();
    }

    public function publicRoutes(): void
    {
        Route::get('status', fn () => $this->page())->name('status');
    }

    public function webhookRoutes(): void
    {
        Route::post('update', fn (Request $request): JsonResponse => $this->receive($request))->name('update');
    }

    public function sitemapPages(): array
    {
        $updated = $this->setting('updated_at');

        return [['path' => 'status', 'updated' => $updated ? Carbon::parse($updated) : null]];
    }

    private function page(): mixed
    {
        app(Seo::class)->allowIndexing();

        return view('ext-acme-status::status', [
            'status' => $this->setting('status', 'ok'),
            'message' => $this->setting('message'),
            'updated' => $this->setting('updated_at'),
        ]);
    }

    private function receive(Request $request): JsonResponse
    {
        // 1. Check the signature: an HMAC-SHA256 of "timestamp.body", sent in a header.
        //    Refuse requests older than 5 minutes, so a captured request cannot be sent again later.
        $secret = (string) $this->setting('signing_secret');
        $timestamp = (string) $request->header('X-Acme-Timestamp');
        $given = (string) $request->header('X-Acme-Signature');

        if (strlen($secret) < 32 || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return response()->json(['error' => 'Bad signature.'], 403);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $given)) {
            return response()->json(['error' => 'Bad signature.'], 403);
        }

        // 2. Check the data. Never trust it.
        $validator = Validator::make($request->json()->all(), [
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Bad data.'], 422);
        }

        // 3. Save it. remember() only changes these keys and keeps the signing secret.
        $data = $validator->validated();
        $this->remember([
            'status' => $data['status'],
            'message' => $data['message'] ?? null,
            'updated_at' => now()->toIso8601String(),
        ]);

        Sitemap::forget();

        return response()->json(['ok' => true]);
    }
}
```

`extensions/addons/acme-status/views/status.blade.php`:

```blade
@extends('theme::layouts.app')

@section('title', __('Service status'))

@section('content')
    <link rel="stylesheet" href="{{ package_asset('extensions', 'acme-status', 'status.css') }}">
    <div class="page-title">
        <div>
            <h1>{{ __('Service status') }}</h1>
            <p class="acme-status acme-status-{{ $status }}">
                @switch($status)
                    @case('down') {{ __('Some services are down.') }} @break
                    @case('degraded') {{ __('Some services are slow.') }} @break
                    @default {{ __('All systems are working.') }}
                @endswitch
            </p>
            @if ($message)
                <p>{{ $message }}</p>
            @endif
            @if ($updated)
                <p><small>{{ __('Last update: :time', ['time' => \Illuminate\Support\Carbon::parse($updated)->diffForHumans()]) }}</small></p>
            @endif
        </div>
    </div>
@endsection
```

`extensions/addons/acme-status/views/panel.blade.php`:

```blade
<div class="card">
    <p>{{ __('Webhook address for Acme Monitor:') }}</p>
    <p><code>{{ $webhook }}</code></p>
    <p><a href="{{ $page }}" target="_blank" rel="noopener">{{ __('Open the status page') }}</a></p>
    <p>{{ $updated ? __('Last update: :time', ['time' => $updated]) : __('No update received yet.') }}</p>
</div>
```

`extensions/addons/acme-status/public/status.css`:

```css
.acme-status { font-weight: 600; }
.acme-status-ok { color: #15803d; }
.acme-status-degraded { color: #b45309; }
.acme-status-down { color: #b91c1c; }
```

Why it is safe:

- The webhook has no session or CSRF token, so it checks an HMAC signature with `hash_equals()` and refuses short
  secrets.
- The signature covers a timestamp, and requests older than 5 minutes are refused, so an old request cannot be sent
  again.
- The status must be one of three known values, and the message is limited to 500 characters.
- Every value is printed with `{{ }}`, so a message cannot add HTML or scripts to the page.
- The secret is a `password` field, and `remember()` never overwrites it.
- Nuvabill limits the webhook to 240 requests per minute from one IP address.

## Next steps

- Declare your permissions and pass the store checks: [Packages](packages.md).
- Make the store and client area look your way: [Themes and order forms](themes.md).
- Take payments: [Payment gateways](payment-gateways.md).
- Manage servers, domains and hardware: [Server modules](server-modules.md).
- Sell your add-on: [Selling](selling.md).
