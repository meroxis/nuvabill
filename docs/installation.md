# Installation

## What you need

- **PHP 8.3 or newer** with `pdo`, `pdo_mysql` (or `pdo_sqlite` for SQLite), `openssl`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`, `zip`, `intl`, `sodium` and `bcmath`
- **MySQL 8** or **MariaDB 10.6+**. SQLite also works for small sites and testing.
- A **cron job** that runs every minute
- A domain or subdomain, for example `billing.yourhost.com`, with HTTPS

Most cPanel, DirectAdmin and Plesk hosting accounts already have all of this.

## Option 1: one command

On a **fresh Ubuntu 22.04/24.04 or Debian 12/13 server**, as root:

```
curl -fsSL https://nuvabill.com/install.sh | bash
```

It installs Nginx, PHP 8.4, MariaDB and a free SSL certificate, adds the cron job, and asks for your company name and
owner account. To give the address up front: `curl -fsSL https://nuvabill.com/install.sh | bash -s -- --domain billing.yourhost.com`.

On a server that **already runs PHP 8.3+** (SSH, or **Terminal** in cPanel), the same command installs Nuvabill into a
`nuvabill` folder, asks a few questions and adds the cron job. Pick the folder with `--dir`.

Every download is checked against the release signature before anything is unpacked.

## Option 2: upload a zip

1. Download `nuvabill-x.y.z.zip` from the [latest release](https://github.com/meroxis/nuvabill/releases/latest).
2. Upload it to your hosting account and unzip it.
3. Point your domain or subdomain to the **`public`** folder. Never make the main Nuvabill folder public.
4. Create an empty MySQL or MariaDB database and a user for it in your hosting panel.
5. Open your domain in a browser. The installer checks your server, asks for the **setup code**, connects the database
   and creates your owner account.

**The setup code** shows that the site is yours. The first time the installer opens, it writes the code to the file
`storage/app/setup-code.txt` in the Nuvabill folder. Open that file in the File Manager of your hosting panel (or over
FTP or SSH) and copy the code into the installer. Visitors cannot open the file, so nobody else can finish the
installation of your site. The file is deleted when the installation is done.

Already unpacked Nuvabill yourself? Run `php artisan nuvabill:install` in its folder to install from the terminal.
It asks the same questions as the web installer, or takes options such as `--db=sqlite --email=you@example.com`.
The terminal (and the one command of option 1) never asks for a setup code.

## The cron job

Nuvabill needs one cron job that runs every minute. The exact line for your server is shown in **Settings → General → Automation**:

```
* * * * * cd /path/to/nuvabill && php artisan schedule:run >> /dev/null 2>&1
```

It creates renewal invoices, sends reminders, suspends and unsuspends services, sends queued email, checks marketplace
licenses and installs updates. The admin dashboard warns you when it has not run for a day.

## Behind Cloudflare

Add this line to `.env`, so sign-in limits and logs see your visitors' real IP addresses:

```
NUVABILL_TRUSTED_PROXIES=cloudflare
```

Other proxies: a comma-separated list of their IP addresses or ranges. Only use `*` when the server cannot be reached
directly.

<a id="nginx"></a>

## Your own web server

The one command of option 1 sets up Nginx like this for you. Read this when you set up Nginx, Apache or Cloudflare
yourself. **Site health → Search engines** checks all three points, when **Open my site from outside during the
check** is on.

### Security headers come from Nuvabill

Nuvabill sends `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`,
`Cross-Origin-Opener-Policy`, `Strict-Transport-Security` (on HTTPS) and `Content-Security-Policy` itself. Do not add
them again in Nginx, Apache or Cloudflare: browsers then get two copies, sometimes with different values.

If your web server or CDN must send some of them, list their names in `.env`, and Nuvabill leaves those out.
`Content-Security-Policy` is always sent by Nuvabill.

```
NUVABILL_SERVER_HEADERS=x-frame-options,strict-transport-security
```

The `Strict-Transport-Security` value is one year. To change it, set for example:

```
NUVABILL_HSTS="max-age=31536000; includeSubDomains"
```

Only `max-age=…` with `; includeSubDomains` and `; preload` is taken; any other value falls back to one year. Only add
`includeSubDomains` when every subdomain of your domain works over HTTPS.

### Browsers keep design files for a year

Files in `public/build/assets/` have a fingerprint in their name, so a new version always gets a new name. Let browsers
keep them for a year. In Nginx:

```nginx
location /build/assets/ {
    add_header Cache-Control "public, max-age=31536000, immutable";
    try_files $uri =404;
}
```

In Cloudflare, go to **Caching → Configuration** and set **Browser Cache TTL** to **Respect existing headers**.
Otherwise Cloudflare tells browsers to keep files for 4 hours only. On Apache and LiteSpeed, `public/.htaccess` does
this already.

An `add_header` line inside a `location` block drops every `add_header` line of the `server` block for that location.
That is one more reason to let Nuvabill send the security headers.

### Old .php addresses reach Nuvabill

After a move from WHMCS, people and search engines still open addresses such as `/clientarea.php`. Nuvabill forwards
them (**Settings → Search engines → Redirects**), but only when the web server passes them on. Send every `.php`
address to `index.php`; then no other PHP file in `public/` can run either:

```nginx
location = /index.php {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_param DOCUMENT_ROOT $realpath_root;
}

# Old addresses such as /clientarea.php go to Nuvabill, which forwards them. No other PHP file runs.
location ~ \.php$ {
    rewrite ^ /index.php last;
}
```

This replaces the usual `location ~ \.php$ { ... fastcgi_pass ... }` block. Change `php8.4-fpm.sock` to your PHP
version. On Apache and LiteSpeed, `public/.htaccess` already sends unknown files to Nuvabill.

## Next steps

Continue with [Configuration](configuration.md), then connect your [control panels](integrations/servers.md),
[payment gateways](integrations/payment-gateways.md) and [registrars](integrations/domain-registrars.md).

## Updating

Go to **Updates** in the admin area and press **Update now**, or run `php artisan nuvabill:update`. Every release is
signed; the updater refuses a release with a wrong signature, backs up your files and database first, and rolls back if
something fails. Security fixes can install themselves at night (**Updates → Settings**).
