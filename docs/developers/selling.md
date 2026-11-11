# Selling on the Nuvabill Marketplace

This page explains how to sell (or give away) a theme or extension on the Nuvabill Marketplace. It covers your developer account, review, prices, payouts and licence keys.

Before you upload, build and test your package. See [the overview](README.md), [Packages](packages.md) for the manifest and permissions, and the pages for each type: [Add-ons](addons.md), [Themes](themes.md), [Payment gateways](payment-gateways.md) and [Server modules](server-modules.md).

On this page:

- [Where the Marketplace lives](#where-the-marketplace-lives)
- [Join as a developer](#join-as-a-developer)
- [Your developer dashboard](#your-developer-dashboard)
- [Submit a new item](#submit-a-new-item)
- [Upload a new version](#upload-a-new-version)
- [Automatic checks](#automatic-checks)
- [Human review and messages](#human-review-and-messages)
- [Signing and release](#signing-and-release)
- [Prices](#prices)
- [Revenue share](#revenue-share)
- [Refunds](#refunds)
- [Payouts](#payouts)
- [Licence keys](#licence-keys)
- [How buyers install and update](#how-buyers-install-and-update)
- [Free items](#free-items)
- [Rules](#rules)

## Where the Marketplace lives

The Marketplace store runs on **my.nuvabill.com**. The developer area only exists there. A normal Nuvabill install has no developer area. (The store part is switched on with `NUVABILL_MARKETPLACE_STORE`, and only the store operator uses it.)

Public pages on the store:

| Page | What it is |
| --- | --- |
| `/marketplace` | The catalog |
| `/marketplace/{item}` | One item's page |
| `/developers` | The developer landing page, with a **Become a developer** button |
| `/white-label` | The White-label License |

## Join as a developer

1. Make a normal client account on my.nuvabill.com and sign in. If the store asks for two-factor sign-in, set it up.
2. Open `/developers` and click **Become a developer**. This opens `/developer/join`.
3. Fill in the form and tick the box to accept the developer agreement.

The join form asks for:

| Field | Rules |
| --- | --- |
| Developer name | Required. Up to 120 characters. Buyers see this name. |
| Website | Optional. Must be a URL. Up to 190 characters. |
| Bio | Optional. Up to 500 characters. |
| Payout method | Optional. Wayl, FIB, Bank transfer or PayPal. |
| Payout details | Optional. Up to 1000 characters. For example your account number or IBAN. |
| Agree | You must tick it. |

Your developer account is active at once. Staff do not need to approve it. You get a public short name (slug) made from your developer name. The names `nuvabill`, `official`, `marketplace`, `admin`, `staff` and `support` are reserved. If your name gives a reserved or taken slug, a number is added (for example `acme-2`).

Your payout details are stored **encrypted** in the database. They are left out when the developer record is turned into JSON. When you edit your profile later, leave the payout details field empty to keep the saved details.

Store staff can mark a developer as **verified** (buyers see a "Verified developer" badge). Staff can also suspend a developer. A suspended developer cannot add items, upload versions, send messages or open their item pages.

## Your developer dashboard

Open `/developer`. The dashboard shows:

- what you earned this month, and the number of sales
- what you earned in total
- your share in percent (your real share, see [Revenue share](#revenue-share))
- live items and installs
- how many items are waiting for your changes
- a chart of the last 6 months
- the next payout: your unpaid balance and payout method
- your items, with status, price and sales
- the latest message from the review team
- the **Profile and payouts** form

Only earnings in the store's billing currency are counted.

## Submit a new item

In the dashboard, click **Submit a new item** (`/developer/items/new`). The form has these fields:

| Field | Rules |
| --- | --- |
| Name | Required. Up to 120 characters. |
| Slug (short name) | Required. 2 to 64 characters. Lowercase letters, numbers, `-` and `_`, starting with a letter or number. Must be unique and not reserved. Must match the `slug` in your manifest. |
| Type | Theme, order form, payment gateway, server module, domain registrar or add-on |
| Category | One of: Client themes, Order forms, Payments, Server modules, Domains, Notifications, Reports, Languages, Tools |
| Summary | Required. Up to 200 characters. |
| Description | Optional. Up to 5000 characters. |
| Price | 0 to 10000, in the store's currency (not cents). 0 means free. |
| Update price | 0 to 10000. Not higher than the price. See [Prices](#prices). |
| Demo link, docs link | Optional. Must start with `https://`. Up to 255 characters. |
| Icon colours | Background and text colour as `#RRGGBB`. |
| Screenshots | Up to 6. PNG, JPG or WebP, up to 3 MB each. |
| Changelog | Optional. Up to 2000 characters. |
| Package | Required. A `.zip` up to 20 MB. |
| I own this code | You must tick it. |

The package goes through the [automatic checks](#automatic-checks) right away. If the **first** package of a new item fails, the item is not kept. The short name stays free, and the form comes back with the problems listed under the package field. Fix the package and send the form again.

### Editing your listing

Before the first approval, every edit applies at once. Nothing is public yet: the listing and its screenshots are only shown to you and to the reviewer. The reviewer reads the listing with your first version, and the approval is for the listing they saw. If you change it while they are looking, they are asked to look again.

After the item is approved (0.6.12+):

- Changes to the **name, summary, description, demo link, docs link and screenshots** wait for a reviewer before buyers see them.
- Changes to the **price, update price, category and icon colours** apply at once.

You cannot change the slug yourself. If you really need a new slug, ask store staff. They can rename the item, and the old slug keeps working for licence checks, downloads and links (0.6.12+).

## Upload a new version

1. Raise the `version` in your manifest, for example from `1.0.0` to `1.0.1`.
2. Build the zip. See [Packages](packages.md) for the layout.
3. Open the item in your developer area, choose the zip and write **What is new** (up to 2000 characters).
4. Upload.

If the upload passes the checks, the version goes to **In review**. If it fails, it gets **Changes asked**, with an automatic message that lists the failed checks.

You can upload the same version number again while it is not approved yet. The new upload replaces the old one. A version number that was already approved cannot be used again: that upload fails the checks.

A version can have one of these statuses:

| Status | Meaning |
| --- | --- |
| In review | Passed the checks. Waiting for a reviewer. |
| Changes asked | A check failed, or the reviewer wants a change. Upload a fixed version. |
| Approved | Signed and live for buyers |
| Not accepted | The reviewer rejected this version |

## Automatic checks

Every upload is checked at once. The full list, and how to pass each check, is on [Packages](packages.md). In short:

**These checks refuse the package:**

- The zip cannot be read, or is over 20 MB, or is empty, or has more than 5000 files or more than 100 MB unpacked.
- There is no `theme.json`, `orderform.json` or `extension.json`, or it is not valid JSON.
- The manifest slug is not 2 to 64 lowercase letters, numbers, `-` or `_`, or the version does not look like `1.0.0`.
- Paths with `../`, absolute paths, drive letters, null bytes or symbolic links.
- File names with a backslash, the same file name twice (upper and lower case count as the same), or a zip with a password or special compression.
- The slug or type in the manifest does not match the item.
- The version is not newer than the latest approved version.
- Encoded or hidden code: ionCube, SourceGuardian, Zend loader, or code like `eval(base64_decode(...))`.
- PHP code in a file that is not `.php` (0.6.12+), also inside an image or another binary file. Markdown files may show PHP examples, but they are still checked for encoded code.
- Blocked file types: `.phar`, `.phtml`, `.pht`, `.phps`, `.php3` to `.php8`, `.inc`, `.exe`, `.dll`, `.so`, `.sh`, `.bat`, `.cmd`, `.com`, `.jar` and `.htaccess`.
- Archives inside the package: `.zip`, `.tar`, `.gz`, `.tgz`, `.bz2`, `.xz`, `.7z`, `.rar`, or an archive under another name.
- A `.png`, `.jpg`, `.jpeg`, `.gif` or `.webp` file that is not a real image of that kind.

**These checks only warn the reviewer:**

- No `requires` line in the manifest, or one that does not start with `>=`. (Add one anyway, for example `">=0.7.0"`. It stops older sites from installing your package.)
- Risky functions, such as `exec()`, `shell_exec()`, `system()`, `proc_open()`, `eval()`, `unserialize()`, `curl_exec()` and `ini_set()`. Shell commands in backticks, and an `include` or `require` of a file that is not `.php` or whose path is not fixed text, also warn.
- Code that writes files, decodes text, unpacks archives or calls a function through a variable, such as `file_put_contents()`, `base64_decode()`, `ZipArchive` and `$callback(...)`. Honest code uses these too, so most packages get this warning.
- Web addresses in your PHP or JavaScript that are not in your `http:` permissions.

A warning does not block review, but the reviewer will ask why. Explain it in a message, or remove the code.

The checks run again right before a version is approved. So a version that waited in the queue is always judged by the rules of that day.

## Human review and messages

After the checks, a person from the store team reviews your version. The reviewer downloads your zip and checks this list:

- Read the code
- Installs without errors
- Settings page works
- Does what the listing says
- No errors in the log
- Removing it leaves nothing behind
- Screenshots match the item

Test your package against this list before you upload. It saves a round of review.

The reviewer then does one of three things:

- **Approve**, with an optional message.
- **Ask for changes**, with a message (up to 3000 characters).
- **Reject**, with a message (up to 3000 characters).

A version that failed an automatic check cannot be approved. A version that is not newer than the live version cannot be approved either.

You get an **email for every outcome**, with the reviewer's message and a link to the item in your developer area.

### Messages to the review team

On each version you can send a **Message to the review team** (up to 3000 characters). Use it to explain a warning, answer a question, or tell the reviewer what changed.

Your message is saved on the version, and the reviewer sees it on the review page. It does not send an email or alert to staff. The reviewer's answer comes with the review outcome and its email.

## Signing and release

When a version is approved, the store signs it with its **Ed25519** key. The signature covers the slug, the version and the SHA-256 hash of the exact zip. The secret key stays on the store. Every Nuvabill has the store's public key built in.

At the same time:

- The item goes live, if it was a draft.
- The item's permissions are taken from the newest approved version.
- The live item appears in **Admin → Marketplace** on every Nuvabill.

A buyer's site refuses any package that was changed after signing. So never ask buyers to edit or patch your files by hand. Ship a new version instead.

## Prices

You set two numbers:

| Field | What it does |
| --- | --- |
| Price | What a buyer pays first. 0 means the item is free. |
| Update price | Optional. The price for each extra year of updates. It cannot be higher than the price. |

What buyers get depends on the update price:

| Update price | How it is sold | Buyers see |
| --- | --- | --- |
| 0 | One payment. Updates have no end date. | "Includes updates." |
| More than 0 | First payment is the price, with one year of updates. Each later year costs the update price. | "Includes 1 year of updates. After that, updates are ... a year. It keeps working if you stop." |

Every paid item is sold "one time, one site": one licence key for one website.

When a buyer's year of updates ends, the item keeps working. The buyer can still download the newest approved version that was released before their updates ended. A newer version is refused until they renew. A renewal moves their update date forward.

Example for Acme's "Chat alerts" add-on:

```text
Price:        49.00   (includes 1 year of updates)
Update price: 19.00   (each extra year)

Year 1: buyer pays 49.00
Year 2: buyer pays 19.00 to keep getting updates
        or pays nothing and keeps the version they have
```

Price changes on a live item apply at once.

## Revenue share

The default share for developers is **83%** of each sale and each update renewal. This is the store's default setting. Store staff can change the default, and can give one developer their own share. **Your dashboard shows your real share.**

How a sale is counted:

- The share is worked out on what the buyer paid for your item, **without any tax** on the invoice.
- Update renewals count as sales.
- Your part is rounded down to the cent (the smallest unit of the currency). The rest is the store's fee.

Example, with the default 83%:

```text
Buyer pays 49.00 (no tax)
Your share: 49.00 x 83% = 40.67
Store fee:  8.33
```

## Refunds

Refund handling was added in 0.6.12.

- A credit note takes back your share of that amount. If you were already paid, it comes out of your next payout.
- A full refund also revokes the buyer's licence key.
- A refunded renewal takes back the extra year of updates. The key keeps working.
- A credit note for **part** of an invoice with more than one line is not taken back automatically. Store staff are warned and check it by hand. Until then your share stays as it is.

## Payouts

Payout methods: **Wayl**, **FIB**, **Bank transfer** or **PayPal**. Set your method and details in **Profile and payouts** in your developer area.

Payouts are made by hand. Store staff see what each developer is owed. They send the money outside Nuvabill with your chosen method. Then they record the payout, with an optional reference. No automatic job sends money. The store aims to pay monthly. Payouts are in the store's billing currency only.

Keep your payout details correct. A wrong IBAN or account number can delay your payout.

## Licence keys

You do not write any licence code. The store does it for you.

- When a buyer pays for your item, the store makes one key in the form `NVB-XXXX-XXXX-XXXX-XXXX` and emails it to them.
- The key is for one website. The buyer can type the site on the item page ("Website address for this license"). If no site was set, the first real site that checks the key becomes its site.
- The buyer can move the key to a new site **3 times a year** from their client account.
- A site that is connected to the marketplace is checked under the address it connected with, whatever site name it sends.
- Staff are warned when one key is used on more than 3 different real sites, or from more than 5 different networks, within 30 days. Test sites count for the networks too. Staff can revoke, restore or release keys.
- Every paid download is a copy stamped with a signed `.nuvabill-license` file next to the manifest. It holds the licence id, item, version, site, the last 4 characters of the key and the time. The stamped copy is signed again. Free items are downloaded as they are.
- Download links are signed and work for 15 minutes.

### Test sites

Copies on a developer's own computer work with any key and never use it up:

```text
localhost
127.0.0.1
*.test
*.local
*.localhost
*.example
```

A key also works on the test copies of **its own site**: `staging.` or `dev.` in front of the site, or in front of a parent of it. For a key of `billing.acme.com` these are `staging.billing.acme.com`, `dev.billing.acme.com`, `staging.acme.com` and `dev.acme.com`. Once a key has a site, any other `staging.*` or `dev.*` name is a site of its own and needs its own key. A key that has no site yet works on any `staging.*` or `dev.*` name and stays free, so a buyer can set up a staging copy first and still use the key on the live site.

The site is lowercased and a leading `www.` is removed first. So buyers can test your item on `billing.test` or on `staging.` plus their own site for free.

Two limits keep one key from being used everywhere under a test name:

- Test sites can **download** with one key from 5 different networks in 30 days. A network that downloaded before keeps working, and the key's own site is never limited. The daily licence check of a test site always passes.
- Test sites count for the staff warning above.

A network is the `/24` of an IPv4 address or the `/48` of an IPv6 address.

### Install counts

The **Installs** number decides the order of items in the marketplace, after featured items.

- A paid item counts each site that downloads it with a key.
- A free item counts each site that is connected to the marketplace once.
- Other downloads of a free item count once per network, whatever site name they send, and at most 25 of them a day per item.
- `localhost`, `*.test`, `*.local`, `*.localhost`, `*.example`, `staging.*` and `dev.*` never count.

### The price decides, not the manifest

The store asks for a key because the item has a **price above 0** on the store. The manifest does not decide this.

Still set `"paid": true` in the manifest of an item you sell. Here is a full `extension.json` for an add-on:

```json
{
    "slug": "chat-alerts",
    "type": "addon",
    "name": "Chat alerts",
    "version": "1.0.1",
    "namespace": "Acme\\ChatAlerts\\",
    "class": "Acme\\ChatAlerts\\ChatAlerts",
    "author": "Acme",
    "requires": ">=0.7.0",
    "paid": true,
    "permissions": ["events", "admin-settings", "http:api.acme.example"]
}
```

With `"paid": true`, the buyer's site marks your package as **unlicensed** when someone copied it in by hand or installed it with no key. Staff then see a warning, and the package gets no updates. Themes and order forms use the same `paid` field in `theme.json` or `orderform.json`. See [Packages](packages.md) for every manifest field.

## How buyers install and update

On the buyer's Nuvabill, staff with the `marketplace.manage` permission:

1. Open **Admin → Marketplace** and choose your item.
2. Click **Install**. For a paid item, they enter the licence key. They can change the key later.
3. Later, click **Update**. The saved key is used again.

Before the files are put in place, the buyer's site checks:

- that the store's answer is about the item staff asked for, and not about an older version than the one installed (an installed item never goes back to an older version)
- the SHA-256 hash and the store signature
- that the package is the one the store described
- your `requires` line against its own Nuvabill version
- that a package with PHP files in `database/migrations` is an extension that asks for the `database` permission

Then it swaps the files in. If something fails, it rolls back to the old files.

Each site checks its licences once a day at 04:20 and looks for new versions in the same run. If the store cannot be reached, the last good answer counts for 14 days. So a short store outage never marks your item as unlicensed on a buyer's site.

When Cloudflare stops a site's server from reaching the store, staff can click **Connect to marketplace** once (0.7.0+). After that the site checks in once a day.

## Free items

Set the price to 0. Free items:

- go through the same checks and review
- need no licence key
- show "Free" on the store, with "Install it in one click from your Nuvabill"
- are downloaded as the approved zip, with no licence stamp

Free items are a good way to get known.

## Rules

- **Your own code only.** Upload only code you wrote or have the right to sell. Include the licences of any libraries you use.
- **No encoded code.** No ionCube, SourceGuardian or hidden code. The checks refuse it.
- **No hidden calls home.** List every outside host in your `http:` permissions. Only collect data that your description and permissions tell the host about.
- **Keep the credit.** Themes must keep the "Powered by Nuvabill" credit. Hosts with a White-label License can hide it themselves.
- **Never ask for passwords.** Never ask buyers for their Nuvabill passwords, server passwords or API keys outside your settings form.
- **Handle secrets safely.** Store API keys and secrets encrypted, and never write them to logs. Check the signature of every webhook. Use short HTTP timeouts. Validate all input. Never print settings as raw HTML; escape them.
- **Support your buyers.** Answer their questions. Items with serious problems can be hidden from the store until they are fixed.

### Official items

The command `php artisan nuvabill:marketplace-publish` is only for the store operator's own official items. It skips human review. Outside developers always use the web developer area described on this page.

Ready? Join as a developer at [my.nuvabill.com/developers](https://my.nuvabill.com/developers). Questions? Open an issue on GitHub.
