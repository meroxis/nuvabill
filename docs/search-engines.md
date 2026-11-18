# Search engines

Nuvabill tells Google, Bing and the apps that show link previews what is on your store, so more people find you.
It works with every theme: nothing to install and nothing to change in your theme.

## What Nuvabill does by itself

- **Titles and descriptions.** Every store page gets a title and a description for search results. Products use
  their features (one per line in the product description) and their lowest price, for example
  "Starter: 1 website, 10 GB NVMe storage, Free SSL. From $3.99/mo."
- **One address per page.** Each page names its own address (a "canonical" link), built from `APP_URL` in `.env`.
  Visitors who reach your store at a second address (for example with `www.`) still send search engines to one.
- **sitemap.xml** lists your home page, product groups, products and the domain search, and updates by itself.
- **robots.txt** keeps search engines out of the client area, cart, checkout and sign-in steps, and points to the
  sitemap. Your admin address is never listed; admin pages tell search engines not to index them instead.
- **Private pages are hidden.** The client area, cart, checkout, sign-in pages and domain search results ask search
  engines not to show them.
- **Prices on Google.** Product pages carry product, price, stock and breadcrumb details, and the home page your
  company details, which Google can show in its results.
- **Language versions.** Each page links to its versions in the other languages you switched on (`?lang=de` and so
  on), so search engines show people the page in their own language. Only languages that are really translated are
  offered: a language counts once the page's search title and description are written in it. Until then its
  version points search engines to the main language, so Google never sees an English page under an Arabic address.
  The sitemap lists the same languages, plus an `x-default` address. The language menu holds plain links search
  engines can follow, and an address with an unknown or switched-off `?lang=` forwards to the clean address.
- **No copies of the same page.** Plan pages send search engines to their group page, because most order forms
  show the same page for every plan. When a website add-on shows your home page, `/store` is left out of search too.
  Turn on **List each plan as its own page** when your plan pages have their own text.
- **Empty pages stay out.** The knowledge base and announcements pages ask search engines not to show them while
  they are empty.
- **Footer links.** The footer links to every store group, domains and the help pages, so search engines find them.
- **Link previews.** WhatsApp, Facebook, X and LinkedIn show your page title, description and share image.
- **New addresses forward.** When you change the web address of a product or group, the old address sends visitors
  and search engines to the new one (a 301 redirect), so links and search results keep working.

## Settings → Search engines

- **Your home page on Google**: its title and description, with a live preview.
- **Let search engines show this site**: turn it off for a test site. Search engines are then asked to stay away
  from every page.
- **Sitemap**, **prices and company details** and **language links** can each be switched off.
- **Only link languages that are translated** (on by default) and **List each plan as its own page** (off by
  default).
- **The home page in other languages**: its search title and description in each language you switched on.
- **Page title pattern** for pages without a title of their own, for example `{page} · {company}`.
- **Share image**: 1200 × 630 pixels works best. JPG, PNG or WebP up to 2 MB.
- **Google Search Console and Bing Webmaster codes**: paste the code or the whole tag they give you. Then send them
  your sitemap address, `https://your-store/sitemap.xml`.
- **Your own robots.txt lines**.
- **Redirects** opens the redirect manager (see below).

## Redirects

**Settings → Search engines → Redirects** sends visitors and search engines from an old address to a new one.

- **Add a redirect**: the old address, the page on this site it goes to, and **Moved for good (301)** or **Moved for
  now (302)**. Tick **Keep the part after ?** to pass `?cycle=annually` and the like along. A redirect only works
  where the old address shows "page not found", so it never hides a page that exists. It can only go to a page on
  this site, and loops are refused.
- **Old WHMCS addresses**: one click adds the addresses people and search engines know from WHMCS
  (`/clientarea.php`, `/cart.php`, `/submitticket.php` and others), each going to the matching page here. Old
  `?rp=` links are forwarded too. On Nginx, old `.php` addresses reach Nuvabill only when your server sends them to
  `index.php`: see [Your own web server](installation.md#nginx).
- **The list** shows each redirect with its source (automatic, yours or a website add-on), its visits and the last
  visit.
- **Addresses people could not open**: the most visited missing addresses, with **Make a redirect** next to each.
  No personal data is kept.
- When robots.txt keeps search engines out of a part of the site, Nuvabill adds an `Allow` line for each old address
  that redirects from there, so search engines see the redirect and drop the old address.

## Products and groups

Each product and product group has a **Search appearance** box on its edit page: its own title and description
for search results, a Google preview, **Suggest from the details**, and **Hide this page from search engines**.
Below it you write the title and description in your other languages. A badge shows which languages are ready.

## Site health → Search engines

Site health checks your search engine setup every night and gives it a score: whether search engines may see the
store, a robots.txt file that hides Nuvabill's, the sitemap, the site address and HTTPS, products without a
description, pages with the same title, titles and descriptions that are too long, the share image, prices on Google
and language links. It also finds security headers sent twice, design files cached for too short a time, and old
addresses your web server answers itself instead of Nuvabill. With **Open my site from outside** on, it also checks that search engines really get your
robots.txt and sitemap, for example past a Cloudflare cache.

## Updating from an older version

Earlier versions shipped a `public/robots.txt` that allowed everything. The update removes it when you never changed
it. If you did, Site health shows it and can move it to quarantine, so the robots.txt Nuvabill makes takes over.
