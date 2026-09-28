# Installation

## What you need

- **PHP 8.3 or newer** with `pdo`, `openssl`, `mbstring`, `intl`, `curl`, `zip`, `sodium`, `bcmath` and `fileinfo`
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
5. Open your domain in a browser. The installer checks your server, connects the database and creates your owner account.

Already unpacked Nuvabill yourself? Run `php artisan nuvabill:install` in its folder to install from the terminal.
It asks the same questions as the web installer, or takes options such as `--db=sqlite --email=you@example.com`.

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

## Next steps

Continue with [Configuration](configuration.md), then connect your [control panels](integrations/servers.md),
[payment gateways](integrations/payment-gateways.md) and [registrars](integrations/domain-registrars.md).

## Updating

Go to **Updates** in the admin area and press **Update now**, or run `php artisan nuvabill:update`. Every release is
signed; the updater refuses a release with a wrong signature, backs up your files and database first, and rolls back if
something fails. Security fixes can install themselves at night (**Updates → Settings**).
