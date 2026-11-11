# Packages: manifest, permissions and checks

This page is about the package itself: what goes in the zip, what the manifest says, which
permissions you list, and what the store and the buyer's site check before your code runs.

How to write the code inside the package is on the other pages:
[Add-ons](addons.md), [Themes and order forms](themes.md), [Payment gateways](payment-gateways.md)
and [Server modules, registrars and hardware drivers](server-modules.md).
How review, prices and payouts work is on [Selling](selling.md).
Start with the [overview](README.md) if this is your first package.

## Contents

- [Package types](#package-types)
- [Zip layout](#zip-layout)
- [The manifest files](#the-manifest-files)
- [Fields and their rules](#fields-and-their-rules)
- [Permissions](#permissions)
- [Database tables and migrations](#database-tables-and-migrations)
- [Automatic checks on upload](#automatic-checks-on-upload)
- [Listing limits](#listing-limits)
- [What the buyer's site checks at install](#what-the-buyers-site-checks-at-install)
- [Checklist before you upload](#checklist-before-you-upload)

## Package types

There are six kinds of package. Each one has one manifest file and one install folder.

| Type | Manifest | `type` value | Installed in |
| --- | --- | --- | --- |
| Client theme | `theme.json` | (none) | `themes/{slug}` |
| Order form | `orderform.json` | (none) | `orderforms/{slug}` |
| Payment gateway | `extension.json` | `gateway` | `extensions/gateways/{slug}` |
| Server module | `extension.json` | `server` | `extensions/servers/{slug}` |
| Domain registrar | `extension.json` | `registrar` | `extensions/registrars/{slug}` |
| Add-on | `extension.json` | `addon` | `extensions/addons/{slug}` |

The store also sells Nuvabill licences, such as the White-label licence. These have the type
`license`, but they have no package. An `extension.json` with `"type": "license"` is refused.

The folders above are the defaults. A site can move them with the `themes_path`,
`orderforms_path` and `extensions_path` settings in `config/nuvabill.php`.

## Zip layout

A package is one `.zip` file. The manifest must be in one of two places:

1. At the top of the zip.
2. Inside one single top folder that holds every other entry. This is what you get when you
   right-click a folder and make a zip of it.

The name of that top folder is not checked. Naming it after your slug is still a good habit.

Nuvabill looks for the manifest files in this order: `theme.json`, `orderform.json`,
`extension.json`. The first one it finds decides the package type. If it finds none, the upload
fails with "The package has no theme.json, orderform.json or extension.json." A manifest that is
not valid JSON is refused.

An add-on zip can look like this:

```text
acme-alerts-1.0.0.zip
└── acme-alerts/
    ├── extension.json
    ├── src/
    │   └── AcmeAlerts.php
    ├── views/
    │   └── status.blade.php
    ├── lang/
    │   └── ar.json
    └── database/
        └── migrations/
            └── 2026_10_01_000000_create_acme_alerts_log_table.php
```

A theme zip can look like this:

```text
acme-dark-1.0.0.zip
├── theme.json
├── views/
│   └── partials/
│       └── brand-style.blade.php
└── public/
    └── acme-dark.css
```

To build the zip from the folder, leave out files you do not want to ship:

```bash
cd extensions/addons
zip -r acme-alerts-1.0.0.zip acme-alerts \
  -x 'acme-alerts/.git/*' 'acme-alerts/.env' 'acme-alerts/node_modules/*' '*.DS_Store'
```

Do not ship a file named `.nuvabill-license`. The store writes a signed file with that name next
to the manifest in every paid download.

## The manifest files

### extension.json

Gateways, server modules, registrars and add-ons use `extension.json`. This is a complete,
working example:

```json
{
    "slug": "acme-alerts",
    "type": "addon",
    "name": "Acme alerts",
    "version": "1.0.0",
    "namespace": "Acme\\Alerts\\",
    "class": "Acme\\Alerts\\AcmeAlerts",
    "description": "Posts new orders and tickets to the Acme chat service.",
    "author": "Acme",
    "requires": ">=0.7.0",
    "paid": false,
    "permissions": ["events", "admin-settings", "http:api.acme.example"]
}
```

Required fields. Each must be a string that is not empty:

- `slug`
- `type`: `gateway`, `server`, `registrar` or `addon`
- `name`
- `version`
- `namespace`
- `class`

Optional fields: `description`, `author`, `requires`, `paid` and `permissions`.

Your PHP classes go in `src/`. Nuvabill loads them under `namespace`. A trailing backslash is
added to `namespace` if you leave it out, and a leading backslash is removed from `class`. In the
example above, `src/AcmeAlerts.php` holds the class `Acme\Alerts\AcmeAlerts`.

### theme.json and orderform.json

Themes and order forms have no PHP class, so they have no `type`, `namespace` or `class`.

```json
{
    "slug": "acme-dark",
    "name": "Acme Dark",
    "version": "1.0.0",
    "author": "Acme",
    "description": "A dark client area with large buttons.",
    "requires": ">=0.7.0",
    "paid": true,
    "permissions": ["client-area"]
}
```

```json
{
    "slug": "acme-quick",
    "name": "Acme Quick",
    "version": "1.0.0",
    "author": "Acme",
    "description": "A one-page order form.",
    "requires": ">=0.7.0",
    "paid": true,
    "permissions": ["store"]
}
```

On a site, the theme list shows `name`, `version`, `author` and `description`. The site takes the
installed slug from the folder name. For the marketplace, `slug` and `version` are still
required, because the store and the installer check them.

The built-in Nova theme has no `slug` in its `theme.json`. That works only because it is not a
marketplace package. Your package must have one.

## Fields and their rules

### slug

- Regex: `^[a-z0-9][a-z0-9_-]{1,63}$`
- 2 to 64 characters.
- Lowercase letters, numbers, dashes and underscores.
- Must start with a letter or a number.
- It must be the same as the slug of your item on the store, or the upload fails.
- It is also the install folder name.

Tip: use only letters, numbers and dashes. The developer form turns the slug you type into a
URL slug, which changes underscores into dashes. A manifest slug with an underscore would then not
match the item.

You cannot change the slug of an item yourself. Store staff can rename one, and the old slug then
keeps working for licences, downloads and links (0.6.12+). You cannot take a slug that is reserved:
the slugs of the packages that come with Nuvabill, slugs kept for official items, and the old slugs
of renamed items.

### version

- Regex: `^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$`
- Good: `1.0.0`, `2.4.13`, `1.0.0-beta.1`
- Bad: `1.0`, `v1.0.0`, `1.0.0 beta`
- At most 40 characters (0.7.1+).
- Every upload must be newer than the latest approved version on the store. Versions are compared with
  PHP's `version_compare()`, so `1.0.0-beta.1` counts as older than `1.0.0`.

### requires

The oldest Nuvabill your package works with.

- Only one form is understood: `>=x.y.z`, for example `">=0.7.0"`.
- Any other form, such as `^0.7` or `0.7.0`, is ignored. The package then counts as working with
  every version.
- A buyer's site that is older than `requires` refuses to install it with
  ":name needs Nuvabill :version or newer. Update Nuvabill first."
- An extension that is already on disk and needs a newer Nuvabill is skipped, with a warning in
  the log.
- A missing `requires` is not a refusal on upload. It is a warning for the reviewer.

Set `requires` to the first version that has every feature you use. For example, a package that
uses the `order-fields` or `hardware` permission needs `">=0.7.0"`.

### paid

- Only the JSON value `true` counts: `"paid": true`. The string `"true"` or the number `1` does
  not.
- It works in all three manifest files.

What it does: on a buyer's site, the licence check marks a paid package that was installed
without a licence key as having no valid licence. A paid package that was copied in by hand, and not installed
from the marketplace, is listed as an unlicensed copy.

What it does not do: it does not set a price. The store asks buyers to pay and to enter a key
only when your item's price on the store is above 0. Set the price in your developer area (see
[Selling](selling.md)), and set `"paid": true` in the manifest of every item you sell.

### permissions

A list of plain codes that say what your package does. Staff see them in plain words before they
install. Reviewers check that your code does nothing else. Entries that are not strings are
dropped. See [Permissions](#permissions) below.

## Permissions

List every code that fits your package. Do not list codes you do not use.

| Code | Staff see | Meaning | Added in |
| --- | --- | --- | --- |
| `client-area` | Changes client area pages | Replaces how the client area looks (themes). | - |
| `store` | Changes the store and order pages | Replaces how clients choose and order products (order forms). | - |
| `client-page` | Adds a client area page | Clients see a new page or panel. | - |
| `public-page` | Shows pages to visitors | Adds pages to the site, and may show the home page. The store then moves to `/store`. | 0.6.1 |
| `admin-settings` | Adds settings for staff | Staff set it up in the admin area. | - |
| `admin-page` | Adds an admin page | Staff see a new page in the admin area. | - |
| `order-fields` | Asks for details when clients order | A server module adds fields, such as an SSH key, to the order form. The answers are kept encrypted with the service. | 0.7.0 |
| `webhook` | Receives data from another service | An add-on gets its own webhook routes under `/webhooks/addons/{slug}/`. | 0.6.10 |
| `events` | Reacts to orders, payments and tickets | Runs when these things happen. | - |
| `head-script` | Adds a script to your pages | For example a chat window. | - |
| `payments` | Takes payments | Clients pay invoices with it. | - |
| `servers` | Manages accounts on your servers | Creates, suspends and removes hosting accounts. | - |
| `domains` | Registers domains | Registers, renews and transfers domains. | - |
| `database` | Adds its own database tables | Keeps its own data. Needed for migrations. | 0.4.5 |
| `schedule` | Runs on a schedule | Does its work at set times through the site's cron job. | 0.4.2 |
| `hardware` | Controls your dedicated server hardware | An add-on switches power, network ports or installs of the host's own machines, and adds to the Hardware pages. | 0.7.0 |
| `backups` | Copies your whole site | Reads the database and every file of the site, including `.env`, to make backups. | 0.4.2 |
| `http:{host}` | Connects to {host} | Sends and receives data from this address. One code per host, for example `http:api.acme.example`. | - |

A dash means no version is recorded for that code. It works on every current release.

An unknown code is shown to staff as the raw code with no text. A typo such as `admin-setting`
will look strange to buyers, so check your spelling.

Next to the permissions, the install screen also shows two limits:

- "No outside connections" when you list no `http:` code.
- "No payment details" when you do not list `payments`.

When a version is approved and it is the newest version, the store takes the item's permissions
from it. Buyers see the permissions of the version they get.

A `webhook` address can be reached by anyone on the internet. Check the sender's signature or
secret on every request, compare it with `hash_equals()`, and refuse the request when it does not
match. See [Add-ons](addons.md).

## Database tables and migrations

Extensions can bring their own tables with normal Laravel migrations (0.4.5+).

Rules:

- Put the migrations directly in `database/migrations/` in your package.
- Only extensions may have migrations. A theme or order form with `.php` files under
  `database/migrations/` is refused.
- List `database` in `permissions`. Without it, install is refused with "The package changes the
  database but does not ask for the database permission, so it was not installed."
- Give every table a prefix of your own, so it never clashes with Nuvabill or another package.
- Set `"requires": ">=0.4.5"` or newer.

Nuvabill runs your migrations after it puts the files in place, on install and on every update.
If a migration fails on an update, the files of the previous version are put back and the error
is shown. On a first install, the package is removed again. Write migrations that are safe to
stop halfway, because a step that already ran is not undone for you.

Example migration, `database/migrations/2026_10_01_000000_create_acme_alerts_log_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('acme_alerts_log')) {
            return;
        }

        Schema::create('acme_alerts_log', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 40);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acme_alerts_log');
    }
};
```

Never store secrets such as API keys in plain text in your own tables. Use Laravel's
`Crypt::encryptString()` or an `encrypted` cast, or keep them in your add-on settings, which are
stored encrypted (see [Add-ons](addons.md)).

## Automatic checks on upload

The store checks every upload at once, before a person reviews it. A **fail** blocks the version.
A **warning** is shown to the reviewer, who decides.

### Size and file limits (fail)

- The upload form takes a zip of at most 20 MB (20,480 KB). The check also fails a package over
  20 MB.
- The zip must have 1 to 5,000 entries.
- All files together must be at most 100 MB when unpacked.
- Paths use `/`. A file or folder name may not have a backslash (`\`), one of `: * ? " < > |`, a
  control character, a dot or a space at its end, or `~` followed by a digit. Paths with `.` or
  `..` parts, with `//`, or with `/` at the start are refused too.
- Each file name is in the zip only once. Upper and lower case count as the same name, so
  `Logo.png` next to `logo.png` is refused. A name cannot be a file and a folder at once.
- The zip is a plain one: no password, and files are stored or deflated, which is what zip tools
  do when you change no setting. Each file must be exactly as long as the zip says.
- No symbolic links.

If the store says your zip has a file name with a backslash, your zip tool wrote Windows paths.
PowerShell's `Compress-Archive` does that on Windows. Use `tar -a -c -f acme-alerts-1.0.0.zip
acme-alerts` there instead (it comes with Windows 10 and 11), or another zip tool.

The buyer's site runs the same zip checks again before it unpacks anything, and it unpacks
exactly the files the store checked.

### Manifest and version (fail)

- The manifest can be read and passes the slug and version rules above.
- The package slug and type are the same as your store item.
- The version is newer than the latest approved version on the store.

### Refused file types (fail)

These file types are refused anywhere in the package:

`.phar` `.phtml` `.pht` `.phps` `.php3` `.php4` `.php5` `.php7` `.php8` `.inc` `.exe` `.dll`
`.so` `.sh` `.bat` `.cmd` `.com` `.jar` `.htaccess`

Any file named `.htaccess` is refused too.

Archives are refused as well, because nobody can check what is inside them:

`.zip` `.tar` `.gz` `.tgz` `.bz2` `.xz` `.7z` `.rar`

A file that is an archive is refused under any other name too. Ship the files themselves.

### Images (fail)

A `.png`, `.jpg`, `.jpeg`, `.gif` or `.webp` file must be a real image of that kind. A file that
cannot be opened as one fails with "Images that cannot be opened".

### Encoded code (fail)

The marketplace only takes readable code. This check looks at `.php` files and at every other
file with PHP code in it. Such a file fails when it contains any of these:

- `ionCube Loader`, `sg_load(`, `SourceGuardian`, `zend_loader` or `<?php //0`
- `eval(` directly followed by a call to `base64_decode`, `gzinflate`, `gzuncompress`, `gzdecode`,
  `str_rot13` or `hex2bin`. Spaces, line breaks, comments, an `@` or a `\` in between still
  count, such as `eval (@\base64_decode(`.

### PHP code only in .php files (fail, 0.6.12+)

A file that does not end in `.php` fails with "PHP code only goes in .php files" when it contains:

- `<?php` or `<?=`. These count in every file, also in images and other binary files, or
- a short open tag `<?`. XML declarations such as `<?xml version="1.0"?>` are fine. A `<?` alone
  is only looked for in text files, not in binary files.

Blade views end in `.blade.php`, so they count as PHP files and are fine.

Markdown files (`.md` and `.markdown`) may show PHP examples. A Markdown file with PHP in it is
still checked for encoded code and risky functions.

Watch out for a JavaScript or CSS file with the text `<?` in it, for example a string that builds
XML. Move such text into a `.php` file or build it in another way.

### Flagged for the reviewer (warning)

The store looks for these in the same files as the encoded code check. Each kind is named once,
with the file name and line number of the first place and how often it is there, such as
`src/Backup.php:40 fwrite() ×3`. The ones that run commands or code come first.

- A call to one of these functions: `exec`, `shell_exec`, `system`, `passthru`, `proc_open`,
  `popen`, `pcntl_exec`, `eval`, `assert`, `create_function`, `dl`, `putenv`, `ini_set`,
  `unserialize`, `move_uploaded_file`, `curl_exec`.
  Method calls such as `$pdo->exec(` or `Foo::exec(` are not flagged. A `\` in front of the
  name, or a line break or comment before the bracket, changes nothing.
- An `include` or `require` of a file that does not end in `.php`, or of a path that is not
  fixed text. `require __DIR__.'/helpers.php';` is fine. `require $file;` is flagged.
- Backtick shell commands with a `$` variable inside.
- A call to a function that writes files, decodes text or calls another function by its name:
  `file_put_contents`, `fwrite`, `fputs`, `call_user_func`, `call_user_func_array`,
  `base64_decode`, `gzinflate`, `gzuncompress`, `gzdecode`, `str_rot13`, `hex2bin`.
- The classes `ZipArchive`, `PharData` and `Phar`.
- A call through a variable, such as `$callback(...)`.

Honest code uses many of these, so most packages get this warning. It does not block you. It
tells the reviewer where to look, and a short message that explains each one saves a round of
review. Use Laravel's `Http` client with a short timeout instead of `curl_exec`, and
`json_decode` instead of `unserialize`.

The scan is only a help. A person reads your code as well.

### Outside connections (warning)

The store collects every `http://` and `https://` host it finds in:

- `.php`, `.js`, `.mjs` and `.cjs` files, and
- any other file with PHP in it.

Each host must be covered by an `http:{host}` permission:

- `http:acme.example` covers `acme.example` and its subdomains, such as `api.acme.example`.
- `http:*.acme.example` covers only the subdomains, not `acme.example` itself.

These hosts are always ignored: `www.w3.org`, `schemas.xmlsoap.org`, `example.com`,
`nuvabill.com`, `my.nuvabill.com`.

If a host is only in a comment or a link for people, you can explain it to the reviewer in a
message instead of listing it.

### Requires line (warning)

A missing `requires`, or one that does not start with `>=`, gives a warning.

## Listing limits

The listing is what buyers read on the store: name, texts, prices and pictures. Since 0.7.1 the
same limits are used in the developer form, the staff form and the store's publish command
(`ListingLimits`). A text that is too long gets a clear message.

| Field | Limit | Required |
| --- | --- | --- |
| Name | 120 characters | Yes |
| Slug | 64 characters (the package rule above is stricter: 2 to 64) | Yes |
| Category | 40 characters. In the developer form, one of: Client themes, Order forms, Payments, Server modules, Domains, Notifications, Reports, Languages, Tools | No |
| Summary | 200 characters | Yes |
| Description | 5,000 characters | No |
| Changelog ("What is new") | 2,000 characters | No |
| Demo link, docs link | 255 characters, must be a valid URL that starts with `https://` | No |
| Version | 40 characters | Yes (from the package) |
| Price, update price | 0 to 10,000 in the store's currency (not cents). The update price may not be more than the price. | No (0 means free) |
| Screenshots | Up to 6. PNG, JPG/JPEG or WebP, real images, at most 3 MB (3,072 KB) each | No |
| Icon colours | Background and foreground as `#RRGGBB`, for example `#0F1B2D` | No |

The store operator's publish command reads a `listing.json` file and has two more rules:

- Each screenshot path is at most 255 characters and must point to a file inside the listing
  file's own folder.
- `icon` is an object with only `glyph`, `bg` and `fg`. `glyph` is an SVG path on a 24 x 24 grid,
  at most 1,000 characters, with only path letters, digits, spaces, dots, commas and minus signs.

That command is only for official items. Outside developers use the developer area (see
[Selling](selling.md)).

## What the buyer's site checks at install

When staff install or update your package from Admin > Marketplace, the site does this, in order.
If one step fails, nothing is installed.

1. The download address must use `https://`, and the slug must match `^[a-z0-9][a-z0-9_-]{1,63}$`.
2. The site must have the marketplace public key. Without it, nothing can be checked.
3. The store's answer must be about the item staff asked for. An item that was installed before
   the store gave it a new slug keeps its old slug on the site, so that slug is fine too.
4. The version must not be older than the one installed. An installed item never goes back to an
   older version.
5. The SHA-256 of the downloaded zip must match the one the store sent.
6. The store's Ed25519 signature must be valid. It covers the slug, the version and the SHA-256
   of the exact zip. When step 5 or 6 fails, the activity log says the package "failed the
   signature check".
7. The zip passes the same safety checks as on upload (file names, links, file count, unpacked
   size, plain zip), and the manifest passes the slug and version rules. The site does not
   repeat the code checks.
8. The slug, version and type in the manifest must be exactly what the store said.
9. The `requires` version must not be newer than the site's Nuvabill.
10. The files are unpacked into a temporary folder. The site reads the manifest again from there,
    and the slug, version and type must still be the same.
11. A package with `.php` files under `database/migrations/` must be an extension and list
    `database`.
12. For extensions, `extension.json` must have every required field, a known `type`, and a valid
    slug.
13. The files are swapped into the install folder. Then the migrations run. If they fail, the old
    files are put back.

Because of the signature, any change to the zip after approval, even one byte, makes the install
fail. So you cannot fix a file after review. Upload a new version instead.

## Checklist before you upload

- [ ] The manifest is at the top of the zip, or in one top folder.
- [ ] `slug` is 2 to 64 characters, lowercase, and the same as your store item.
- [ ] `version` looks like `1.2.3` and is newer than the last one on the store.
- [ ] `requires` is set, in the form `>=x.y.z`.
- [ ] `"paid": true` (a JSON boolean) if you sell the item.
- [ ] Every permission you use is listed, and spelled right.
- [ ] Every outside host your code calls is listed as `http:{host}`.
- [ ] No refused file types, no `.htaccess`, no `.nuvabill-license`, no `.env`, no `.git`.
- [ ] No archives inside the zip, and every image is a real image.
- [ ] File names use `/` in paths, and no name is in the zip twice.
- [ ] No PHP outside `.php` files (Markdown examples are fine).
- [ ] No encoded or hidden code.
- [ ] Migrations, if any, are in `database/migrations/`, use your own table prefix, and you
      listed `database`.
- [ ] Secrets are stored encrypted, HTTP calls have short timeouts, and settings are never printed
      as raw HTML.
- [ ] The zip is at most 20 MB, has at most 5,000 entries and is at most 100 MB unpacked.
- [ ] A `webhook` add-on checks the signature of every request.
