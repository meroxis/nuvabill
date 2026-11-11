# Themes and order forms

A **theme** changes how the client area and the store look. An **order form** changes how clients choose and order products. Both are folders of Blade views, plus optional CSS, scripts, images and translations.

This page shows how Nuvabill finds your views, the smallest theme you can make, how to serve files, and the store API that one-page order forms such as Swift use.

For the manifest fields, permissions and package checks, see [Packages](packages.md). For selling, see [Selling](selling.md).

## Contents

- [How Nuvabill finds a view](#how-nuvabill-finds-a-view)
- [Folder layout](#folder-layout)
- [The smallest theme](#the-smallest-theme)
- [Files in public/ and package_asset()](#files-in-public-and-package_asset)
- [Your own layout](#your-own-layout)
- [The "Powered by Nuvabill" credit](#the-powered-by-nuvabill-credit)
- [The brand colour](#the-brand-colour)
- [Website Builder styles](#website-builder-styles-websitecss)
- [Translations](#translations-0611)
- [Previewing](#previewing)
- [Order forms](#order-forms)
- [The store API](#the-store-api)
- [A small one-page order form](#a-small-one-page-order-form)
- [Real examples](#real-examples)

## How Nuvabill finds a view

The client area and the store load their views with the `theme::` prefix, for example `view('theme::store.index')`. Nuvabill looks for each view in these folders, in this order:

1. The active **order form**: `orderforms/{slug}/views`. This step is skipped when the built-in `standard` order form is on.
2. The active **theme**: `themes/{slug}/views`.
3. **Nova**, the built-in theme: `themes/nova/views`.

The first folder that has the file wins. So:

- A theme or order form only needs the views it changes. Every other page comes from Nova.
- When an order form is on, its store pages win over the theme's store pages.

The active theme is the setting `theme.active` (default `nova`). The active order form is the setting `orderform.active` (default `standard`). Staff switch them in the admin **Marketplace**. The theme can also be changed in **Settings**.

`standard` is not a folder. It means "use the store pages of the active theme". Do not name your order form `standard`.

If the saved theme folder is missing or has no `views` folder, Nuvabill quietly uses `nova`. A missing order form falls back to `standard`.

## Folder layout

A folder counts as a theme only if it has a `views` folder. The same is true for order forms. **The `views/` folder is required**, even if it holds only one file. Without it, Nuvabill does not see the package and shows Nova.

The slug (the folder name) must match `^[a-z0-9][a-z0-9_-]*$`: lower-case letters, digits, `-` and `_`, starting with a letter or digit.

A theme:

```text
themes/acme/
├── theme.json                       required
├── views/                           required
│   ├── partials/brand-style.blade.php   (optional) colours and your stylesheet link
│   ├── layouts/app.blade.php            (optional) your own page frame
│   └── client/dashboard.blade.php       (optional) any other page you want to change
├── public/                          (optional) CSS, JS, fonts, images
│   ├── acme.css
│   └── website.css                  (optional) styles for the Website Builder site
└── lang/                            (optional, 0.6.11+) translations
    ├── de.json
    └── fr.json
```

An order form:

```text
orderforms/acme-order/
├── orderform.json                   required
├── views/                           required
│   └── store/
│       ├── index.blade.php
│       ├── group.blade.php
│       └── product.blade.php
├── public/
│   ├── acme-order.css
│   └── acme-order.js
└── lang/
    └── de.json
```

For the theme lists in the admin, Nuvabill reads only `name`, `version`, `author` and `description` from `theme.json` or `orderform.json`. The marketplace also uses `slug`, `requires`, `paid` and `permissions` (see [Packages](packages.md)). Themes use the permission `client-area`. Order forms use `store`.

## The smallest theme

The smallest useful theme changes only `partials/brand-style.blade.php` and links one stylesheet. The built-in Paper and Midnight themes work this way. Every page still comes from Nova, so everything keeps working after Nuvabill updates.

`themes/acme/theme.json`:

```json
{
    "slug": "acme",
    "name": "Acme",
    "version": "1.0.0",
    "author": "Acme",
    "description": "Nova with rounder cards and the Acme fonts.",
    "requires": ">=0.6.11",
    "permissions": ["client-area"]
}
```

`themes/acme/views/partials/brand-style.blade.php`:

```blade
@php
    $accent = (string) setting('branding.accent', '#0B7A70');
    $accent = preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) ? $accent : '#0B7A70';
@endphp
<link rel="stylesheet" href="{{ package_asset('themes', 'acme', 'acme.css') }}">
<style>
    :root {
        --nb-accent: {{ $accent }};
        --nb-accent-2: color-mix(in srgb, {{ $accent }} 82%, #000);
        --nb-accent-soft: color-mix(in srgb, {{ $accent }} 10%, #fff);
        --nb-accent-ink: #fff;
    }
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme='light']) {
            --nb-accent: color-mix(in srgb, {{ $accent }} 72%, #fff);
            --nb-accent-2: color-mix(in srgb, {{ $accent }} 55%, #fff);
            --nb-accent-soft: color-mix(in srgb, {{ $accent }} 20%, #16161a);
            --nb-accent-ink: #0b1213;
        }
    }
    :root[data-theme='dark'] {
        --nb-accent: color-mix(in srgb, {{ $accent }} 72%, #fff);
        --nb-accent-2: color-mix(in srgb, {{ $accent }} 55%, #fff);
        --nb-accent-soft: color-mix(in srgb, {{ $accent }} 20%, #16161a);
        --nb-accent-ink: #0b1213;
    }
</style>
```

`themes/acme/public/acme.css`:

```css
.card {
    border-radius: 16px;
}
```

Nova's layout includes `theme::partials.brand-style` in the `<head>`, so your file replaces Nova's partial and your stylesheet loads on every page.

## Files in public/ and package_asset()

Nuvabill serves the files in your package's `public/` folder. Link them with `package_asset($kind, $slug, $path)`:

```blade
<link rel="stylesheet" href="{{ package_asset('themes', 'acme', 'acme.css') }}">
<script src="{{ package_asset('orderforms', 'acme-order', 'acme-order.js') }}" defer></script>
<img src="{{ package_asset('themes', 'acme', 'img/logo.svg') }}" alt="">
```

- `$kind` is `themes`, `orderforms` or `extensions`.
- The address is `/package-assets/{kind}/{slug}/{path}`.
- `$path` may use only letters, digits, `_`, `.`, `/` and `-`. No spaces.
- Only files inside `public/` are served. Paths with `../` or links that point out of the folder return 404.
- Only these file types are served: `css`, `js`, `json`, `woff2`, `woff`, `ttf`, `svg`, `png`, `jpg`, `jpeg`, `webp`, `gif`, `ico`. Any other type (for example `avif`, `mp4`, `html` or `map`) returns 404.
- A package file is served only while its theme or order form is installed.
- Responses send `X-Content-Type-Options: nosniff`. The route has no session and no cookies.

**Caching.** `package_asset()` adds `?v={file change time}` to the address. With `?v`, browsers cache the file for one year (`public, max-age=31536000, immutable`). When you change the file, the `?v` value changes and browsers fetch the new copy. A link written by hand without `?v` is cached for only one hour, so always use `package_asset()`.

**Load files from your package.** Nuvabill's content security policy allows scripts, styles, fonts and `fetch()` calls only from the site itself (`'self'`). The only other sources are the CAPTCHA provider, Cloudflare Web Analytics when the site is behind Cloudflare, and `data:` for fonts. Inline `<style>` and `<script>` are allowed. Images are the exception: `img-src` allows `'self'`, `data:` and any `https:` address. Themes cannot add more sources. Only add-ons can, with `contentSecurityPolicy()` (see [Add-ons](addons.md)). So bundle your fonts and scripts in `public/`. Do not load them from a CDN.

## Your own layout

To change the page frame (header, menu, footer), add `views/layouts/app.blade.php`. The other views extend `theme::layouts.app`, so they use your frame.

Keep Nova's `<html>` tag and these three `<head>` lines, in this order:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locales::direction() }}">
<head>
    <title>@yield('title') · {{ setting('company.name') }}</title>
    <meta name="description" content="@yield('description', setting('company.name'))">
    @include('partials.head', ['assets' => ['themes/nova/assets/theme.css', 'resources/js/app.js']])
    @include('theme::partials.brand-style')
    <x-extension-head area="client" />
</head>
```

- The `<html>` tag sets the page language, and `dir="rtl"` for Arabic and Kurdish.

- `partials.head` adds the charset and viewport tags, the CSRF meta tag `<meta name="csrf-token">`, the light/dark switch, fonts, and Nova's base CSS and JavaScript. Without it, pages lose their base styles, and order forms that read the CSRF meta tag (such as Swift) cannot get prices.
- `theme::partials.brand-style` adds the host's brand colour. Link your own stylesheet inside that partial, as in [The smallest theme](#the-smallest-theme), so it loads only once.
- `<x-extension-head area="client" />` adds the `<head>` HTML of all switched-on add-ons, for example a live chat widget. The default area is `client`. Without it, add-ons break on your theme.

Aurora (`themes/aurora/views/layouts/app.blade.php`) is a full example of an own layout. Start from Nova's layout (`themes/nova/views/layouts/app.blade.php`) and keep its `@yield` sections.

Escape everything you print with `{{ }}`. Never print a setting or any client data with `{!! !!}`.

## The "Powered by Nuvabill" credit

Themes must keep the "Powered by Nuvabill" credit. Do not remove it and do not hide it with CSS. Hosts with a White-label License hide it themselves. Nuvabill decides this for you with `Branding::showPoweredBy()`, so always wrap the credit in that check, as Nova does:

```blade
@if (\App\Support\Branding::showPoweredBy())
    <a class="powered" href="{{ \App\Support\Branding::PRODUCT_URL }}" target="_blank" rel="noopener">{{ __('Powered by') }} <b>{{ \App\Support\Branding::PRODUCT_NAME }}</b></a>
@endif
```

Themes that drop the credit are not accepted in the marketplace (see [Selling](selling.md)).

## The brand colour

Each host picks a brand colour in Settings. It is stored in `branding.accent`, as a hex colour like `#0B7A70` (the default).

- Read it with `setting('branding.accent', '#0B7A70')`.
- **Check it before you print it into CSS.** Nova, Paper and Midnight only accept `^#[0-9A-Fa-f]{6}$` and use the default otherwise, as in the example above. Never put an unchecked setting inside a `<style>` tag.
- Set the four tokens that Nova's CSS uses: `--nb-accent`, `--nb-accent-2`, `--nb-accent-soft` and `--nb-accent-ink`. Set them for light mode, for dark mode by system choice (`prefers-color-scheme`), and for dark mode chosen by the client (`[data-theme='dark']`).

If your theme has no `partials/brand-style.blade.php`, Nova's partial is used, so the brand colour still works.

## Website Builder styles (website.css)

The Website Builder add-on (needs Nuvabill 0.6.1+) builds the host's company website. Your theme can style it too:

- Add `public/website.css`. Website Builder links it **after** its own `website.css`, so you only change what you need.
- It is loaded only when the active theme is **not** Nova and the file exists.
- Website Builder pages also include your `theme::partials.brand-style`. Anything you put in that partial (fonts, colour tokens, your stylesheet link) also reaches the company website. Keep it free of client-area-only rules, or put those rules in your main stylesheet instead.

The builder's classes and tokens you can style:

| Kind | Names |
| --- | --- |
| Plan cards | `.wb-plan` |
| Section backgrounds | `.wb-bg-accent`, `.wb-bg-dark`, `.wb-bg-page`, `.wb-bg-soft` |
| Main colour tokens | `--nb-accent`, `--nb-accent-2`, `--nb-accent-soft`, `--nb-accent-ink`, `--nb-ink`, `--nb-muted`, `--nb-surface`, `--nb-surface-2`, `--nb-line`, `--nb-line-2` |

Example `themes/acme/public/website.css`:

```css
.wb-plan {
    border-radius: 16px;
}

.wb-bg-dark {
    --nb-surface: #10151c;
    --nb-ink: #f2f5f8;
}
```

Paper, Midnight and Aurora all ship a `website.css` you can read.

## Translations (0.6.11+)

Themes and order forms can bring their own translations. Put one JSON file per language in `lang/{locale}.json`. Wrap your texts in `__()`:

```blade
<h2>{{ __('Pick your plan') }}</h2>
```

`orderforms/acme-order/lang/de.json`:

```json
{
    "Pick your plan": "Wählen Sie Ihren Tarif"
}
```

- Nuvabill adds the `lang` folders of the active theme and the active order form.
- If Nuvabill's own files already have the same key, **Nuvabill's text wins**. So you cannot change a core text this way, and you do not need to copy core texts.
- Use the same locale codes as Nuvabill: `ar`, `ckb`, `de`, `es`, `fr`, `mn` (0.7.1+), `pt_BR`, `ru`, `th` (0.7.0+), `tr` and `zh_CN`. English is the key itself, so there is no `en.json`.
- `ar` and `ckb` are right-to-left. Nova's layout sets `dir="rtl"` on `<html>`. Keep this in your own layout. Use CSS logical properties (`margin-inline-start` instead of `margin-left`) so your theme works both ways.

Set `"requires": ">=0.6.11"` if your package depends on its own translations.

## Previewing

Staff can try a theme or order form on the live site before switching it on. Only their own browser session sees it.

| Route | What it does |
| --- | --- |
| `GET /preview/theme/{slug}` | Preview a theme. Goes to the client dashboard if a client is signed in, otherwise to the store. |
| `GET /preview/orderform/{slug}` | Preview an order form. `standard` is accepted too. Goes to the store. |
| `GET /preview/stop` | Ends the preview. Staff go back to the admin Marketplace, others to the store. |

- Starting a preview works only for a signed-in staff member, or for any visitor on the public demo. For everyone else, the two start routes return 404. `/preview/stop` works for everyone.
- A slug that is not installed returns 404.
- The preview is saved in the session key `preview.packages`, so it lasts for the browser session. You can preview a theme and an order form at the same time.

To test on your own copy, put your folder in `themes/{slug}` or `orderforms/{slug}`, sign in to the admin, and open `/preview/theme/{slug}`.

## Order forms

An order form can replace **any** `theme::` view. The store views that Nuvabill renders through `theme::` are:

| View | File | Page | Main data passed |
| --- | --- | --- | --- |
| `theme::store.index` | `views/store/index.blade.php` | Store home | `$groups`, `$currency` |
| `theme::store.group` | `views/store/group.blade.php` | One product group | `$group`, `$products`, `$currency` |
| `theme::store.product` | `views/store/product.blade.php` | One product | `$group`, `$product`, `$prices`, `$currency`, `$inStock`, and more |
| `theme::store.domains` | `views/store/domains.blade.php` | Domain search | |
| `theme::cart` | `views/cart.blade.php` | Cart | |
| `theme::checkout` | `views/checkout.blade.php` | Checkout | |

Swift replaces `store/index`, `store/group` and `store/product`, and adds its own partial `store/swift.blade.php`, which the three pages include. Views your order form does not have come from the active theme, then Nova.

Your store views should extend `theme::layouts.app`, so the order form fits inside any theme:

```blade
@extends('theme::layouts.app')

@section('title', __('Store'))

@section('content')
    {{-- your order form --}}
@endsection
```

You can build an order form in two ways:

- **Step by step**: keep Nova's cart and checkout, and only change how products are shown.
- **One page**: use the store API below to price the order live and place it in one step. This is how Swift works. The one-page API shipped in 0.3.0.

## The store API

All routes are on the normal web site and use the client's session. `POST` requests need the CSRF token.

### GET /store-api/domains

Checks a domain and suggests others.

| Item | Value |
| --- | --- |
| Query | `q`: required, text, at most 190 characters |
| Rate limit | 30 a minute |
| Route name | `store.api.domains` |

Reply:

```json
{
    "results": [
        {
            "domain": "acme.com",
            "available": true,
            "exact": true,
            "price": "$12.00",
            "transfer_price": "$12.00"
        }
    ]
}
```

- Up to 5 suggestions come with the exact match.
- `price` and `transfer_price` are formatted text for one year, in the signed-in client's currency or the store's default currency. They are `null` when the TLD is not sold.

### POST /store-api/quote

Prices an order with the choices so far. It changes nothing.

| Item | Value |
| --- | --- |
| Rate limit | 120 a minute |
| Route name | `store.api.quote` |

Fields:

| Field | Rules |
| --- | --- |
| `product_id` | Required. Product ID. The product and its group must be shown in the store, and the product must be in stock. |
| `billing_cycle` | Required. One of `monthly`, `quarterly`, `semiannually`, `annually`, `biennially`, `triennially`, `onetime`, `free`. The product must have a price for it. |
| `addons[]` | Optional. Up to 20 add-on IDs (integers). |
| `resources[kind]` | Optional (0.7.0+). Resource slider steps, for example `resources[ram]=4`. Unknown kinds and values out of range are refused. A hidden slider that sends `0` is fine. |
| `domain_action` | Optional. `register`, `transfer`, `own` or `none`. If left out, it is `own` when a domain is sent, otherwise `none`. |
| `domain` | Optional. A domain like `acme.com`, at most 190 characters. |
| `epp_code` | Optional. At most 128 characters. Needed when placing a transfer for a TLD that asks for one. |
| `coupon` | Optional. At most 40 characters. |
| `fields[key]` | Optional (0.7.0+). Server module order fields, at most 20. |
| `secret_fields[key]` | Optional (0.7.0+). Secret server module order fields (for example a root password), at most 20. |
| `country` | Optional. Two letters, for tax. |
| `state` | Optional. For tax. |

Reply:

```json
{
    "currency": "USD",
    "lines": [
        {
            "title": "Starter hosting",
            "summary": "acme.com · Annually",
            "amount": "$60.00",
            "addons": ["Daily backups"],
            "resources": ["4 GB RAM"]
        }
    ],
    "subtotal": "$60.00",
    "discount": 600,
    "discount_label": "$6.00",
    "tax": { "label": "VAT 10%", "amount": "$5.40" },
    "total": 5940,
    "total_label": "$59.40",
    "saving": "$12.00",
    "saved_label": "$18.00",
    "renewal": "$60.00 annually",
    "coupon": { "code": "WELCOME", "label": "10% off · first payment" },
    "coupon_problem": null,
    "payment": [
        { "slug": "stripe", "name": "Card", "pays": null }
    ]
}
```

- `total` and `discount` are numbers in minor units (cents). All `*_label`, `amount`, `subtotal` and `saving` values are text, already formatted. The example values above are only to show the shape.
- `tax` is `null` when no tax applies.
- `saving` is what a longer billing cycle saves against monthly. `saved_label` adds the coupon discount. Both are `null` when there is nothing saved.
- `renewal` is `null` for one-time and free cycles.
- `coupon` is `null` when no coupon applies. `coupon_problem` is a text that says why the coupon did not apply, or `null`.
- Too many coupon tries from one address drop the coupon and set `coupon_problem`. This stops people guessing codes.
- `payment` lists the active payment gateways. `pays` is the converted amount when a gateway charges in another currency, otherwise `null`.
- Bad input returns HTTP 422 with Laravel's normal JSON errors, for example `{"message": "...", "errors": {"product_id": ["..."]}}`.

**First quote in the page (0.4.7+).** To show prices without an extra request when the page loads, call `QuickOrderController::firstQuote()` in your view and put the result in the page:

```php
public function firstQuote(int $productId, string $billingCycle, ?string $coupon = null): ?array
```

It returns the same array as the quote reply, or `null` when that choice cannot be ordered. It prices the product alone: no add-ons, no domain, and resource sliders at their first steps. A short version of what Swift does:

```blade
@php
    $firstQuote = app(\App\Http\Controllers\Store\QuickOrderController::class)->firstQuote($product->id, 'monthly');
@endphp
```

Load your script with `defer` so it does not slow down the first paint.

### POST /order

Places the order. This is a **normal form post**. It answers with a redirect, not JSON.

| Item | Value |
| --- | --- |
| Rate limit | 5 a minute for guests, 10 a minute when signed in |
| Middleware | `auth.session`, `client.active`, `client.two-factor`, `captcha:checkout` |
| Route name | `order.store` |

Fields: all quote fields (except `country` and `state` for tax), plus:

| Field | Rules |
| --- | --- |
| `gateway` | Optional. A payment gateway slug from the quote's `payment` list, at most 64 characters. |
| `accept_terms` | Required (must be checked) only when the host has set a terms of service address (`orders.accept_terms_url`). |

For a **guest**, the form also creates the account:

| Field | Rules |
| --- | --- |
| `first_name` | Required, at most 100 characters |
| `last_name` | Required, at most 100 characters |
| `email` | Required, a valid email, at most 190 characters, must not be used by another client (in any letter case) |
| `password` | Required, at least 8 characters. There is **no** `password_confirmation` field. |
| `country` | Required, a two-letter country code |
| `company_name` | Optional, at most 150 characters |
| `phone` | Optional, at most 40 characters |

**CAPTCHA.** If the host protects checkout with a CAPTCHA, the route checks it. If checkout has no CAPTCHA but "Create an account" has one, a guest must pass that CAPTCHA. Add `<x-captcha form="checkout" />` to your form. It shows the widget only when one is needed, including the "Create an account" CAPTCHA for guests.

**What happens:**

1. The server module's order fields are checked first, so a mistake there never leaves a new account without its order.
2. A guest gets an account and is signed in.
3. The order is priced again on the server. Never trust prices from the browser.
4. Payment starts only when the invoice is payable, a gateway was chosen, and the site is not the public demo. Otherwise the client goes to the invoice page, or to the dashboard if nothing is due.

**Errors** come back as normal Laravel validation errors (a redirect back with `$errors` and old input). Show them next to the fields. The field names are:

| Field | When |
| --- | --- |
| `product_id` | Product not available or sold out |
| `billing_cycle` | No price for this cycle |
| `domain` | Missing domain, bad domain, or a domain to register that is already taken |
| `epp_code` | A transfer that needs an EPP code and has none |
| `resources` | An unknown resource kind, or more than 20 kinds |
| `resources.{kind}` | Bad slider value, or that resource is sold out |
| `fields.{key}`, `secret_fields.{key}` | Server module order field problems |
| `coupon` | The coupon stopped working, for example it was used up |
| `captcha` | CAPTCHA not passed |
| `accept_terms` | Terms not accepted |
| `first_name`, `last_name`, `email`, `password`, `country`, `company_name`, `phone` | Sign-up problems |

## A small one-page order form

This complete `views/store/product.blade.php` shows one product with its billing cycles. It asks for a live quote and places the order. It works inside any theme.

It is for a product that needs no domain, add-ons, resource sliders or server module order fields. For those, also send the matching fields from the tables above. Swift shows how.

```blade
@extends('theme::layouts.app')

@section('title', $product->name)

@section('content')
    @php
        $cycles = $prices->map(fn ($price) => $price->billing_cycle->value)->all();
        $first = $cycles[0] ?? null;
        $firstQuote = $first ? app(\App\Http\Controllers\Store\QuickOrderController::class)->firstQuote($product->id, $first) : null;
    @endphp

    <form method="POST" action="{{ route('order.store') }}" class="card" id="acme-order"
          data-quote-url="{{ route('store.api.quote') }}">
        @csrf
        <input type="hidden" name="product_id" value="{{ $product->id }}">

        <h1>{{ $product->name }}</h1>

        <label for="acme-cycle">{{ __('Billing cycle') }}</label>
        <select id="acme-cycle" name="billing_cycle" class="select">
            @foreach ($cycles as $cycle)
                <option value="{{ $cycle }}">{{ $cycle }}</option>
            @endforeach
        </select>
        @error('billing_cycle')<p class="error">{{ $message }}</p>@enderror
        @error('product_id')<p class="error">{{ $message }}</p>@enderror

        @guest('web')
            <x-input name="first_name" :label="__('First name')" required autocomplete="given-name" />
            <x-input name="last_name" :label="__('Last name')" required autocomplete="family-name" />
            <x-input name="email" type="email" :label="__('Email')" required autocomplete="email" />
            <x-input name="password" type="password" :label="__('Password')" required autocomplete="new-password" />
            <x-select name="country" :label="__('Country')" :options="\App\Support\Countries::all()" :placeholder="__('Choose')" required />
        @endguest

        @foreach ($firstQuote['payment'] ?? [] as $option)
            <label>
                <input type="radio" name="gateway" value="{{ $option['slug'] }}" @checked($loop->first)>
                {{ $option['name'] }}
            </label>
        @endforeach

        @if (filled(setting('orders.accept_terms_url')))
            <x-checkbox name="accept_terms" :label="__('I agree to the terms of service')" />
        @endif
        <x-captcha form="checkout" />

        <p>{{ __('Due today') }}: <strong id="acme-total">{{ $firstQuote['total_label'] ?? '' }}</strong></p>

        <button class="btn btn-primary" type="submit">{{ __('Order now') }}</button>
    </form>

    <script src="{{ package_asset('orderforms', 'acme-order', 'acme-order.js') }}" defer></script>
@endsection
```

`orderforms/acme-order/public/acme-order.js`:

```js
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('acme-order');
    if (! form) {
        return;
    }

    const total = document.getElementById('acme-total');
    const token = document.querySelector('meta[name="csrf-token"]').content;
    let request = 0;

    async function updateQuote() {
        const id = ++request;

        try {
            const response = await fetch(form.dataset.quoteUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    product_id: Number(form.elements.product_id.value),
                    billing_cycle: form.elements.billing_cycle.value,
                }),
            });

            // Only the newest answer counts.
            if (id === request && response.ok) {
                const quote = await response.json();
                total.textContent = quote.total_label; // textContent, never innerHTML
            }
        } catch (error) {
            // Keep the last total. The server prices the order again when it is placed.
        }
    }

    form.elements.billing_cycle.addEventListener('change', updateQuote);
});
```

Notes:

- The quote call sends `Accept: application/json` and the `X-CSRF-TOKEN` header, read from `<meta name="csrf-token">`. That meta tag comes from `partials.head` in the layout.
- The order is a plain `<form method="POST">` with `@csrf`, so validation errors and the payment redirect work without extra code.
- Put quote values on the page with `textContent`. Never use `innerHTML` with data from the server.
- The billing cycle names in the example are the raw values. Show friendly labels in a real form (Swift and Nova show how).
- Secret order fields (`secret_fields[key]`, such as a root password) need `type="password"` inputs. Never put their values back in the page, in old input or in a log.
- `$prices` is a list of the product's prices in the client's currency, sorted by billing cycle. Each one has `billing_cycle` (an `App\Enums\BillingCycle`).

## Real examples

The current, full examples are the marketplace packages, not the older copies inside the core repository:

| Package | Version | Shows |
| --- | --- | --- |
| Swift (order form) | 1.3.1 | One-page ordering, domain search, resource sliders, server module order fields, first quote in the page, 12 languages (English and 11 translations) |
| Aurora (theme) | 1.6.1 | Own layout, own dashboard, `website.css`, translations |
| Paper (theme) | 1.1.0 | Smallest theme: brand-style partial plus one stylesheet |
| Midnight (theme) | 1.1.0 | Smallest theme with a dark look |

The copies bundled in the core repository (`themes/aurora`, `orderforms/swift`) are older. For example, the bundled Swift is 1.1.0, without sliders or order fields, and the bundled Aurora is 1.4.0, with no `th.json` or `mn.json` yet. Nova (`themes/nova`) is always current and shows every view you can replace.

Next: [Payment gateways](payment-gateways.md) · Back to [Overview](README.md)
