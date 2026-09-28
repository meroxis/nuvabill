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
- **Language versions.** Each page links to its versions in every language you switched on (`?lang=de` and so on),
  so search engines show people the page in their own language.
- **Link previews.** WhatsApp, Facebook, X and LinkedIn show your page title, description and share image.
- **New addresses forward.** When you change the web address of a product or group, the old address sends visitors
  and search engines to the new one (a 301 redirect), so links and search results keep working.

## Settings → Search engines

- **Your home page on Google**: its title and description, with a live preview.
- **Let search engines show this site**: turn it off for a test site. Search engines are then asked to stay away
  from every page.
- **Sitemap**, **prices and company details** and **language links** can each be switched off.
- **Page title pattern** for pages without a title of their own, for example `{page} · {company}`.
- **Share image**: 1200 × 630 pixels works best. JPG, PNG or WebP up to 2 MB.
- **Google Search Console and Bing Webmaster codes**: paste the code or the whole tag they give you. Then send them
  your sitemap address, `https://your-store/sitemap.xml`.
- **Your own robots.txt lines**, and the list of old addresses that forward.

## Products and groups

Each product and product group has a **Search appearance** box on its edit page: its own title and description
for search results, a Google preview, **Suggest from the details**, and **Hide this page from search engines**.

## Site health → Search engines

Site health checks your search engine setup every night and gives it a score: whether search engines may see the
store, a robots.txt file that hides Nuvabill's, the sitemap, the site address and HTTPS, products without a
description, pages with the same title, titles and descriptions that are too long, the share image, prices on Google
and language links. With **Open my site from outside** on, it also checks that search engines really get your
robots.txt and sitemap, for example past a Cloudflare cache.

## Updating from an older version

Earlier versions shipped a `public/robots.txt` that allowed everything. The update removes it when you never changed
it. If you did, Site health shows it and can move it to quarantine, so the robots.txt Nuvabill makes takes over.
