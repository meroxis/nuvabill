# Server modules, registrars and hardware drivers

This page is for developers who connect Nuvabill to other systems:

- **Server modules** create and manage accounts on a control panel or cloud, such as cPanel, DirectAdmin or a VPS
  platform.
- **Domain registrars** register, renew and transfer domains.
- **Hardware drivers** and other dedicated server hooks are parts of an add-on. They control your own machines under
  **Admin → Hardware** (0.7.0+).

Read [the overview](README.md) and [Packages](packages.md) first. They explain the `extension.json` manifest,
permissions and how packages are checked.

All class names on this page are from Nuvabill 0.7.1.

## Contents

- [Server modules](#server-modules)
  - [Files and manifest](#files-and-manifest)
  - [Methods you must write](#methods-you-must-write)
  - [Methods with a default](#methods-with-a-default)
  - [Results: `ModuleResult`](#results-moduleresult)
  - [Product settings](#product-settings)
  - [Helpers](#helpers)
  - [A complete minimal module](#a-complete-minimal-module)
  - [Optional contracts](#optional-contracts)
  - [Security rules for server modules](#security-rules-for-server-modules)
- [Domain registrars](#domain-registrars)
- [Dedicated servers and hardware drivers](#dedicated-servers-and-hardware-drivers)
- [Working examples](#working-examples)

## Server modules

A server module is a class that extends the abstract class `App\Extensions\Servers\Module`. This class implements the
interface `App\Contracts\ServerModule`. Nuvabill refuses a module class that does not implement `ServerModule`.

Core calls your module when something happens to a service: a paid order, an overdue invoice, an upgrade, a
cancellation, or a staff button. Core does the billing, locks, logs and emails. Your module only talks to the server.

The built-in server modules are `cpanel`, `directadmin`, `plesk`, `proxmox`, `virtualizor` and `dedicated`. They are in
`extensions/servers/` and are good examples. `directadmin` is a short one to read first.

### Files and manifest

A server module lives in `extensions/servers/{slug}`:

```text
extensions/servers/acmepanel/
├── extension.json
└── src/
    └── AcmePanelModule.php
```

```json
{
    "slug": "acmepanel",
    "type": "server",
    "name": "Acme Panel",
    "description": "Create, suspend and remove Acme Panel accounts.",
    "version": "1.0.0",
    "author": "Acme",
    "namespace": "Acme\\AcmePanel\\",
    "class": "Acme\\AcmePanel\\AcmePanelModule",
    "requires": ">=0.7.0",
    "permissions": ["servers"]
}
```

The constructor of `Module` receives the `ExtensionManifest`. You do not need your own constructor.

Set `"requires"` to the lowest Nuvabill version your module works with. The helper `orderField()` and every contract
marked 0.7.0 below need `">=0.7.0"`. See [Packages](packages.md) for all permission codes.

### Methods you must write

`Module` has no default for these six methods. Your class must write all of them.

| Method | When core calls it | What it must do |
|---|---|---|
| `defaultPort(): int` | The server form fills it in as the port. The status monitor uses it when the server has no port. | Return the panel's usual API port, for example `2222`. Your own code should also use it when `port` is empty. |
| `testConnection(Server $server): ModuleResult` | Staff press **Test connection** on the server form | Make one cheap API call and report if the login works. |
| `create(Service $service): ModuleResult` | A new service is set up, usually after payment | Make the account on the server. |
| `suspend(Service $service, string $reason): ModuleResult` | The invoice is overdue, or staff suspend | Block the account but keep its data. |
| `unsuspend(Service $service): ModuleResult` | The client pays, or staff unsuspend | Open the account again. |
| `terminate(Service $service): ModuleResult` | The service is cancelled or ends | Remove the account from the server. |

`testConnection()` gets an `App\Models\Server`. The other methods get an `App\Models\Service`. From a service you can
read `$service->server`, `$service->product`, `$service->client`, `$service->domain`, `$service->username` and
`$service->password`.

The `Server` record holds `hostname`, `ip_address`, `port`, `use_ssl`, `username`, `password` and `api_token`. The
password and API token are stored encrypted. When you read them in PHP you get the plain value, so never print them or
put them in a message.

### Methods with a default

`Module` already writes these. Override them only when you need to.

| Method | Default | Override it to |
|---|---|---|
| `slug(): string` | The manifest slug | (Do not override.) |
| `name(): string` | The manifest name | (Do not override.) |
| `productFields(): array` | `[]` | Ask for product settings, such as the package name. See [Product settings](#product-settings). |
| `serverHelp(): string` | `''` | Explain on the server form which login staff should enter. Plain text. |
| `changePackage(Service $service): ModuleResult` | Fails with "This module cannot change packages." | Move the account to the product's new package after an upgrade or downgrade. |
| `loginUrl(Service $service): ?string` | `null` | Return a one-time link that signs the client in to the panel. |
| `hasLoginLink(): bool` | `true` | Return `false` when your panel has no client login, so the "Open control panel" button is hidden. This method is on `Module` only, not on the `ServerModule` interface. |

### Results: `ModuleResult`

Every action returns an `App\Extensions\Servers\ModuleResult`:

```php
return ModuleResult::ok('Account created.', ['username' => $username, 'password' => $password]);

return ModuleResult::fail('The server refused the request.');
```

What core does with the `data` array:

- After `create()`, the keys `username` and `password` are saved on the service. The password is stored encrypted.
- After `create()`, an array under the key `module_data` is merged into `$service->module_data`. Use it for values
  you need later, such as the account ID on the server. Read them back with `moduleValue()`.
- On a failed result, `['busy' => true]` means "the server was busy and nothing changed". For unsuspend and package
  changes, core then tries again later in a background job. For other actions it is shown as a normal failure.

If one of these methods throws an exception, core catches it. It writes the exception to the log and turns it into a
failed result **with the exception's message**. Staff can see that message. So never put a password, key, token or
full API address in an exception or a result message. Catch HTTP client errors yourself, as the example below does.

### Product settings

`productFields()` returns the fields staff fill in on the product form. Each field is an array:

| Key | Meaning |
|---|---|
| `label` | The field name staff see. Required. |
| `type` | `text`, `textarea`, `select` or `password`. Required. An unknown type is shown as `text`. |
| `help` | A short help line. |
| `required` | `true` when the field must be filled. |
| `options` | For `select`: `value => label`. |
| `secret` | `true` to store the value encrypted (0.7.0+). A `password` field is always secret. |
| `max` | For `textarea` only: the longest value, up to 16384 characters (0.7.0+). |

Limits (from `App\Extensions\Servers\ModuleConfig`):

- A normal field, and a `textarea` without `max`, holds at most 190 characters.
- A `textarea` with `max` holds at most 16384 characters (`ModuleConfig::MAX_LONG`).
- All settings of one product together hold at most 65536 characters (`ModuleConfig::MAX_TOTAL`).

A secret field is never filled into the form again. If staff leave it empty, the saved value stays. Read settings with
`productSetting()`, which gives you the plain value of a secret field.

### Helpers

`Module` has three protected helpers:

| Helper | Reads |
|---|---|
| `moduleValue(Service $service, string $key, mixed $default = null): mixed` | A value your module saved in `module_data`, for example the account ID. |
| `productSetting(Service $service, string $key, mixed $default = null): mixed` | A product setting from `productFields()`. Secret values are decrypted for you. |
| `orderField(Service $service, string $key, ?string $default = null): ?string` | What the client typed in one of your order form fields (`HasOrderFields`). (0.7.0+) |

### A complete minimal module

This module talks to a made-up "Acme Panel" JSON API. It shows every required method, one product setting, short
timeouts, no redirects and safe messages. Change the API paths and fields to match your panel.

`extensions/servers/acmepanel/src/AcmePanelModule.php`:

```php
<?php

namespace Acme\AcmePanel;

use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Acme Panel accounts through its JSON API, signed in with an API token.
 */
class AcmePanelModule extends Module
{
    public function productFields(): array
    {
        return [
            'plan' => [
                'label' => 'Acme Panel plan',
                'type' => 'text',
                'required' => true,
                'help' => 'The plan name in Acme Panel, for example "starter".',
            ],
        ];
    }

    public function serverHelp(): string
    {
        return 'Create an API token in Acme Panel → Settings → API and paste it in "API token".';
    }

    public function defaultPort(): int
    {
        return 8443;
    }

    public function testConnection(Server $server): ModuleResult
    {
        $response = $this->send($server, 'get', '/api/v1/ping');

        return $response?->successful()
            ? ModuleResult::ok('Connected to Acme Panel.')
            : ModuleResult::fail($this->reason($response));
    }

    public function create(Service $service): ModuleResult
    {
        if ($service->server === null) {
            return ModuleResult::fail('No server is assigned.');
        }

        $username = $service->username ?: 'u'.Str::lower(Str::random(7));
        $password = $service->password ?: Str::password(20, symbols: false);

        $response = $this->send($service->server, 'post', '/api/v1/accounts', [
            'username' => $username,
            'password' => $password,
            'email' => $service->client->email,
            'domain' => (string) $service->domain,
            'plan' => (string) $this->productSetting($service, 'plan', ''),
        ], timeout: 60);

        if (! $response?->successful()) {
            return ModuleResult::fail($this->reason($response));
        }

        return ModuleResult::ok("Account {$username} created.", [
            'username' => $username,
            'password' => $password,
            'module_data' => ['account_id' => (string) $response->json('id')],
        ]);
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        return $this->accountAction($service, 'suspend', ['reason' => Str::limit($reason, 100)]);
    }

    public function unsuspend(Service $service): ModuleResult
    {
        return $this->accountAction($service, 'unsuspend');
    }

    public function terminate(Service $service): ModuleResult
    {
        return $this->accountAction($service, 'delete');
    }

    public function changePackage(Service $service): ModuleResult
    {
        return $this->accountAction($service, 'plan', [
            'plan' => (string) $this->productSetting($service, 'plan', ''),
        ]);
    }

    /**
     * Run one action on the account this module made in create().
     *
     * @param  array<string, mixed>  $body
     */
    private function accountAction(Service $service, string $action, array $body = []): ModuleResult
    {
        $id = (string) $this->moduleValue($service, 'account_id', '');

        if ($service->server === null || $id === '') {
            return ModuleResult::fail('This service has no Acme Panel account yet.');
        }

        // Only a safe ID goes into the address.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            return ModuleResult::fail('The saved Acme Panel account ID is not valid.');
        }

        $response = $this->send($service->server, 'post', "/api/v1/accounts/{$id}/{$action}", $body);

        return $response?->successful()
            ? ModuleResult::ok('Done.')
            : ModuleResult::fail($this->reason($response));
    }

    /**
     * One API call. Returns null when the server cannot be reached.
     *
     * @param  array<string, mixed>  $body
     */
    private function send(Server $server, string $method, string $path, array $body = [], int $timeout = 20): ?Response
    {
        try {
            return $this->client($server, $timeout)->{$method}($this->baseUrl($server).$path, $body);
        } catch (ConnectionException) {
            // The exception text can hold the full address, so it is not passed on.
            return null;
        }
    }

    private function client(Server $server, int $timeout): PendingRequest
    {
        return Http::withToken((string) $server->api_token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout($timeout)
            ->withoutRedirecting();
    }

    private function baseUrl(Server $server): string
    {
        $scheme = $server->use_ssl ? 'https' : 'http';
        $port = $server->port ?: $this->defaultPort();

        return "{$scheme}://{$server->hostname}:{$port}";
    }

    /**
     * A short, safe reason for staff: never the token, never raw HTML.
     */
    private function reason(?Response $response): string
    {
        if ($response === null) {
            return 'Could not connect to Acme Panel.';
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return 'Acme Panel refused the API token.';
        }

        $error = $response->json('error');
        $error = is_string($error) ? Str::limit(trim(strip_tags($error)), 200) : '';

        return $error !== '' ? "Acme Panel: {$error}" : "Acme Panel returned HTTP {$response->status()}.";
    }
}
```

Notes on the example:

- Keep **Use SSL** on for real servers. The module only uses plain `http` when staff turn SSL off.
- `create()` reuses `$service->username` and `$service->password` when they are already set, so a second try does not
  make a new login.
- The account ID is saved in `module_data` and checked before it goes into a URL.

### Optional contracts

A module can implement more interfaces from `App\Contracts` to do more. Core checks with `instanceof`, so you only add
the ones you need. Set `"requires"` to at least the version in the table.

The full details, with method signatures, value objects and examples, are in
[Control panels and VPS: For module authors](../integrations/servers.md#for-module-authors-panels-consoles-resources-and-hardware)
and [For module makers](../integrations/servers.md#for-module-makers-staff-pages-schedules-order-fields-long-settings-and-terminals).

**Client panel and sign-in**

| Contract | Purpose | Since |
|---|---|---|
| `HasClientPanel` | An own panel on the client's service page (`clientPanelView`, `clientPanel`, `clientActions`, `clientAction`). To show the client a value once, such as VNC details, `clientAction` answers with `ModuleResult::secret()`: it is shown by the page's script and never kept in the session. | 0.2.0 |
| `HasLoginForm` | The panel signs clients in with a posted form instead of a link (`loginForm()`). | 0.4.10 |
| `HasPanelState` | Reads only the server state for quick status checks (`clientPanelState()`). | 0.6.13 |
| `HasPanelSections` | A client server panel with tabs (`panelSections`, `availablePanelSections`, `readPanelSection`, `runPanelAction`). | 0.7.0 |
| `HasConsole` | A browser console (`consoleSession()` returns a `ConsoleSession`, VNC or terminal). | 0.7.0 |
| `SignsInWithContext` | A one-time panel sign-in link that knows who asks (`signInUrl(Service, ClientContext)`). | 0.7.0 |
| `HasResourceOptions` | Extra-resource sliders such as more CPU or RAM (`resourceKinds`, `baseResources`, `resourceLimits`, `applyResources`, `checkResources`). | 0.7.0 |

**Setup and lifecycle**

| Contract | Purpose | Since |
|---|---|---|
| `KeepsCreateServer` | An unclear create stays on the same server for the next try (`keepsServer()`). | 0.6.13 |
| `WaitsForPayment` | Setup runs again once the order invoice is paid (`waitsForPayment()`). | 0.6.13 |
| `UsesMachineInventory` | Marker: services run on machines from **Hardware**, not on a server record. | 0.7.0 |
| `FinishesSetupLater` | Someone finishes the setup after `create()`; the service stays Pending (`setupPending()`). | 0.7.0 |
| `ChoosesWelcomeEmail` | The module picks its own "ready" email (`welcomeEmail()`). | 0.7.0 |
| `LimitsPlanChanges` | Only some plan changes are offered (`allowsPlanChange(Service, Product)`). | 0.7.0 |
| `QueuesPackageChanges` | Marker: `changePackage()` is slow and runs in a background job (up to 900 seconds). | 0.7.0 |

**Staff tools**

| Contract | Purpose | Since |
|---|---|---|
| `ChecksClientAccess` | Rows for the staff button **Test panel sign-in** (`checkClientAccess()`). | 0.7.0 |
| `LinksExistingAccount` | Staff link a service to an account that already exists (`linkFields`, `linkExistingAccount`). | 0.7.0 |

**Module maker hooks** (0.7.0+). Core uses these only when `extension.json` lists the matching permission.

| Contract | Purpose | Permission |
|---|---|---|
| `HasAdminPermission` | The staff permission for your module's pages and card (`adminPermission`, `adminPermissionLabel`). The key is your slug, a dot and a short name. | `admin-page` |
| `HasAdminPages` | Admin pages and dashboard items (`adminRoutes`, `attentionItems`). | `admin-page` |
| `HasStaffPanel` | A card on the admin service page (`staffPanel`, `runStaffAction`). | `admin-page` |
| `RunsOnSchedule` | Scheduled work (`scheduledTasks()`), at most 10 tasks per module. | `schedule` |
| `HasOrderFields` | Fields on the order form, such as an SSH key (`orderFields(Product)`), at most 20 fields. | `order-fields` |

The value objects for these contracts are in `App\Extensions\Servers\Panel`, `App\Extensions\Servers\Resources` and
`App\Extensions\Servers\Staff`, plus `App\Extensions\Servers\ScheduledTask`.

### Security rules for server modules

- **Never read `request()`.** Who asks comes to you as a `ClientContext` (client ID, IP address, whether the IP can be
  trusted, return URL, locale).
- **Fixed API address.** Build URLs only from the server record. Keep TLS checks on (never call `withoutVerifying()`).
  Do not follow redirects.
- **Short timeouts.** Use a connect timeout of a few seconds and a total timeout that fits the action.
- **No secrets in messages.** Never put a password, key, token or full API URL in a result message, an exception or a
  log line. Staff see messages, and sometimes clients do too.
- **No raw HTML.** Strip tags from panel answers before you show them. In Blade views, print values with `{{ }}`,
  never `{!! !!}`.
- **Encrypt settings.** Mark API keys and passwords in `productFields()` as `secret` (or use type `password`).
- **Safe data keys.** Core removes data keys that look like secrets (`password`, `token`, `api_key`, `secret` and
  similar) from every panel result (`StandardSections::SCRUB_PATTERN`). Do not use those words in normal keys.
- **Check IDs** before you put them in a URL or a command.

## Domain registrars

A registrar is a class that extends the abstract class `App\Extensions\Registrars\Registrar`, which implements
`App\Contracts\DomainRegistrar` (0.2.0+). It lives in `extensions/registrars/{slug}`, with `"type": "registrar"` in
`extension.json` and the permission `domains`.

The built-in registrars are `enom`, `namecheap`, `opensrs` and `resellerclub`.

The constructor gets the manifest and the saved settings array. Settings are stored encrypted in the database.

### Methods you must write

| Method | What it does |
|---|---|
| `testConnection(): RegistrarResult` | Checks the API login, for **Test connection**. |
| `checkAvailability(array $domains): array` | Gets full names such as `["example.com", "example.net"]`. Returns `name => true` (free), `false` (taken) or `null` (unknown). |
| `register(Domain $domain, Contact $contact): RegistrarResult` | Registers the domain for its number of years. On success, `data` may hold `expires_at` (`Y-m-d`) and `registrar_data` (values to keep, such as the order ID). |
| `renew(Domain $domain, int $years): RegistrarResult` | Renews. On success, `data` may hold the new `expires_at`. |
| `setNameservers(Domain $domain, array $nameservers): RegistrarResult` | Sets the name servers. |

### Methods with a default

| Method | Default |
|---|---|
| `slug()`, `name()` | From the manifest. |
| `settingsFields(): array` | `[]`. Return the fields of the settings page, in the same format as product fields. |
| `isConfigured(): bool` | `true` when every `required` settings field is filled. |
| `transfer(Domain $domain, Contact $contact, string $eppCode): RegistrarResult` | Fails with ":registrar cannot transfer domains yet." |
| `sync(Domain $domain): RegistrarResult` | Fails with ":registrar cannot read domain details." On success, `data` may hold `expires_at`, `status` (`active`, `expired` or `transferred_away`) and `nameservers`. |

### Helpers and results

- `$this->setting($key, $default = null)` reads one saved setting.
- `$this->inTestMode()` is `true` when the setting `mode` is `test` or `sandbox`. Add a `mode` select field to offer a
  sandbox.
- `App\Extensions\Registrars\Contact` holds the owner contact, built from the client's account: names, company, email,
  phone code and number, address, city, state, postcode and country.
- Return `RegistrarResult::ok($message, $data)` or `RegistrarResult::fail($message)`.

The same rules as for server modules apply: short timeouts, no redirects, and no API key in any message.

## Dedicated servers and hardware drivers

Since 0.7.0 Nuvabill can sell your own dedicated machines (the built-in `dedicated` server module, which implements
`UsesMachineInventory`). Add-ons can extend this. Register each piece in your add-on's `boot()`. List the
permission `hardware` in `extension.json` for anything under Hardware, and `database` if you keep your own tables. See
[Add-ons](addons.md) for how add-ons work.

### Power drivers

A power driver switches a machine's power through its BMC, for example Redfish or IPMI. Core ships no driver: without
one, staff switch the power by hand.

Implement `App\Provisioning\Hardware\Drivers\PowerDriver` and register it:

```php
app(\App\Provisioning\Hardware\Drivers\PowerDrivers::class)->register(new AcmeBmcDriver);
```

| Method | What it does |
|---|---|
| `type(): string` | The value of `machines.bmc_type` this driver serves. It must match `^[a-z0-9][a-z0-9_-]{0,39}$` and must not be `none`, or the driver is ignored. |
| `label(): string` | The name staff see in the machine form. |
| `available(): bool` | Whether this site can use the driver now. Only available drivers are offered. |
| `test(Machine $machine): BmcTestResult` | Tests the connection for **Test connection**. Never throws. |
| `powerState(Machine $machine): PowerState` | `On`, `Off`, `Changing` or `Unknown` (enum `App\Enums\PowerState`). Throws `BmcException` on errors. |
| `supportedActions(Machine $machine): array` | The `App\Enums\PowerAction` values the BMC offers: `On`, `Shutdown`, `Restart`, `Reset`, `Off`. |
| `run(Machine $machine, PowerAction $action): void` | Runs one power action. Throws `BmcException` on errors. |

Optional driver contracts, in the same namespace:

| Contract | Methods | Purpose |
|---|---|---|
| `ChoosesBootDevice` | `bootDevices(Machine)`, `setBootDevice(Machine, BootDevice)` | Boot from a device (such as the network) on the next boot only. `BootDevice` is the enum `App\Enums\BootDevice`. |
| `ReadsHealth` | `health(Machine): array` | Up to 50 `HealthReading`s. Use keys in no language (`part:id`), and a `skipped:part` reading for a part that did not answer. |
| `ReadsPowerUsage` | `powerUsage(Machine): ?int` | Watts used now, or `null`. |

Rules for drivers:

- **Only `App\Provisioning\Hardware\PowerControl` calls drivers.** Never call a driver yourself. `PowerControl` adds the
  power lock, cool-down, cache, activity log and power history. Its main methods are `run()`, `state()`,
  `setBootDevice()` and `health()`. For `run()` and `setBootDevice()`, pass the client, the staff member or the
  machine as the actor.
- Send requests only to the machine's checked BMC address. Never follow a redirect.
- Run programs (such as `ipmitool`) with an argument list, never through a shell.
- Never put the BMC login or a raw error in a message or log.

### Other hardware extension points

| Extension point | Purpose |
|---|---|
| `MachineInstallers::register(MachineInstaller)` | Take over the "Setting up" step and install the system yourself. `MachineInstaller` has `key()`, `label()`, `takes(Machine, Service)` and `start(Machine, Service)`. When done, call `ServerSetup::ready()`; when it fails, call `ServerSetup::failed()`. |
| `DedicatedPanelExtensions::add(DedicatedPanelExtension)` | Tabs in the client panel of dedicated servers: `sections`, `availableSections`, `readSection`, `runAction`. The keys `overview`, `network`, `reinstall` and `power` are reserved. |
| `MachineSections::add(Closure)` | A card on a machine's admin page. The closure gets `(Machine $machine, Admin $admin)` and returns `['view' => 'ext-{slug}::name', 'data' => [...]]` or `null`. |
| `Addon::hardwareRoutes()` | Your pages and forms under `/admin/hardware/addons/{slug}/`, for staff with the hardware management permission. Needs `hardware`. |
| Events `DedicatedServerActivated`, `DedicatedServerSuspended`, `DedicatedServerUnsuspended`, `DedicatedServerReleased` | Sent after a machine change is saved, with the machine and the service. For example, close a switch port on suspend. |
| `Addon::demoSettings()` | Settings a public demo saves when it switches your add-on on (0.7.1+). Visitors can read them, so never return a secret. |

The registries and `ServerSetup` are in `App\Provisioning\Hardware`. The events are in `App\Events`, and `Addon` is
`App\Extensions\Addons\Addon`. Never send the BMC address, a switch name or a
machine's name to clients. Full details are in [Dedicated servers: For add-on makers](../hardware.md#for-add-on-makers).

## Working examples

- `extensions/servers/directadmin`: a small, complete server module.
- `extensions/registrars/namecheap`: a registrar with a sandbox `mode` setting.
- `tests/Fixtures/extensions/servers/panelfake`: implements the 0.7.0 panel, console, resource and lifecycle contracts.
- `tests/Fixtures/extensions/servers/hookfake`: uses the module maker hooks (admin pages, staff card, schedules, order
  fields).
- `tests/Fixtures/extensions/servers/formpanel`: a form-based panel sign-in.

When your module is ready, see [Selling](selling.md) to publish it on the Marketplace.
