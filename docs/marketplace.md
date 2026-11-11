# Marketplace and "Connect to marketplace"

Themes, order forms and extensions come from the Nuvabill marketplace (**Admin → Marketplace**). Your Nuvabill talks
to the marketplace store at `my.nuvabill.com` to list items, download them and check license keys.

## Why connect

The marketplace store sits behind Cloudflare. Cloudflare checks for bots, and it sometimes stops calls that come from
servers in data centres, which is where most billing sites run. When that happens, the Marketplace page says
"Cloudflare stopped this server from reaching the marketplace".

**Connect to marketplace** fixes this once, for free:

1. Open **Admin → Marketplace** and click **Connect to marketplace**.
2. Your browser goes to the store, which asks "Connect billing.yourname.com to the Nuvabill marketplace?". Click
   **Connect**.
3. The store calls your site back at its own web address to hand over a key for your site. Only the real owner of
   the address can connect it, because the call goes to your site, not to the browser.
4. You land back on your Marketplace page. Within a minute the box shows **Connected**.

The store then tells Cloudflare to let your server's address through. Nothing else changes: sites that never
connect work as before.

### What you need

- Nuvabill 0.7.0 or newer.
- Your site on a public web address with a valid HTTPS certificate, and `APP_URL` in `.env` set to that address
  (for example `https://billing.yourname.com`).
- Nuvabill at the root of that web address, not in a subfolder (`https://example.com/billing` cannot connect),
  because the store calls `https://your-address/nuvabill-marketplace/connect`.
- Staff permission **Install themes and extensions from the marketplace**.

### How the store checks your server's address

Your server notes the address it uses to reach the internet. The store lets that address through by itself when it
can reach your site at that very address over HTTPS, with a valid certificate for your site's name. This works for
most servers, also when your site is behind Cloudflare, as long as the server itself answers HTTPS for your site's
name. Being in your DNS alone is not enough.

Otherwise (for example when your server sends its traffic out from another address than the one your site runs
on) the connection waits for marketplace staff, which usually takes less than a day. The box shows **Waiting for
the store** until then.

### After connecting

- Your site checks in with the store once a day. Every 90 days it gets a new key by itself. The old key keeps
  working for a day, so a lost answer does not break the connection.
- If your server moves to a new address, the store checks it the same way, by calling your site at the new address.
  When that works, nothing changes for you. Otherwise the box shows **Address changed**: click **Connect again**.
- **Site health** warns when Cloudflare stops this server or the connection needs you.
- The store checks the license keys of paid items under the address your site connected with. If you move your site
  to a new web address, connect it again from the new address, then move your keys to it in your account on the store.
- **Connect with a QR code** for WhatsApp (Settings → Chat apps) only works for a connected site, see
  [Telegram and WhatsApp](chat-apps.md).
- If the store no longer accepts your key (for example the connection was not used for 60 days), the box shows
  **Connect again**.

### Security

- The key is stored encrypted in your database and is only sent to the marketplace store, never to other
  addresses. The store keeps only a hash of it.
- The one-time code from your click works once and only for 30 minutes.
- Starting to connect someone else's site changes nothing for that site: the store only saves a new key after the
  site itself took it.
- Connecting is turned off in demo mode.

## For the marketplace store operator

This part is only for the store itself (`NUVABILL_MARKETPLACE_STORE=true`).

Connected sites are listed under **Marketplace store → Connected sites** in the admin menu: their status, server address, whether the
address is in the site's DNS or was reached directly, and when the site was last seen. **Approve** lets a waiting
site through; **Remove** stops its key and removes its Cloudflare rule. A removed site that connects again waits
for staff until someone approves it. The menu shows how many sites wait, and the page shows why each one waits.

### Cloudflare token

Bot Fight Mode cannot be skipped with WAF rules. The free exception is an IP Access rule with the action **Allow**,
which the store adds and removes by itself. On the same page, under **Cloudflare**:

1. In Cloudflare, open the `nuvabill.com` zone and copy the **Zone ID** from its Overview page.
2. Under **My Profile → API Tokens**, create a custom token with only the permission
   **Zone > Firewall Services > Edit**, and under Zone Resources include **only the nuvabill.com zone**.
3. Paste the zone ID and token, click **Test connection**, then **Save**.

The token is stored encrypted, never shown again and never logged. Without it, sites still get keys but their
servers are not let through, and the page says that Cloudflare is not set up. Active sites that connected before
get their rules when you save.

One rule serves every site on the same address. A rule is only removed when no active site uses its address any
more. Rules you added by hand are used but never removed. When Cloudflare cannot remove a rule (it is down, or the
token expired), the store notes it, tries again every day and shows a warning on the page and in Site health. The
zone ID cannot be changed or cleared while the store has rules in the zone; the token can.

An IP Access rule with **Allow** lets that address past all of Cloudflare's security for the whole zone (WAF, rate
limits, challenges), not only the marketplace API. Keep the store's own rate limits on sign-in, and if you can,
serve the marketplace API from a host name in its own Cloudflare zone, so allowed addresses only skip checks there.

### Limits

Change these under **Limits** on the Connected sites page. A registered name (`example.com`) counts with all its
sub-domains.

- At most 200 new connections a day in total, and 10 per registered name.
- At most 20 connected sites per registered name.
- At most 5 active sites per server address; more wait for staff. This also applies when a site moves to a new
  address.
- The connect form can ask for a CAPTCHA: tick **Connect a site to the marketplace** under **Settings → Security**.
- A daily task ends sites not seen for 60 days and sites that waited 14 days without approval, and removes their
  rules.

### The signing key

The store signs every approved package, and every paid download, with one Ed25519 key. Every Nuvabill site installs
what is signed with it, so treat the key file like the master key of the marketplace.

- The key is made once with `php artisan nuvabill:marketplace-key`. It is a file that only the store's own account
  may read (mode `600`). By default it is `storage/app/private/marketplace-signing.key`.
- **Keep it outside the Nuvabill folder.** A whole-site backup copies every file in the Nuvabill folder, so a key
  inside it is in each backup too. Move the file to a folder that only the store's account can read and that the web
  server does not serve, and add its full path to `.env`:

  ```
  NUVABILL_MARKETPLACE_SIGNING_KEY_PATH=/home/store/keys/marketplace-signing.key
  ```

  If your config is cached, run `php artisan config:cache` again. Then run
  `php artisan nuvabill:marketplace-key --show`: it must print the same public key as before.
- **Keep one more copy offline**, for example on an encrypted USB stick. Once the key is outside the Nuvabill folder,
  backups no longer hold it. Without the key the store cannot sign anything.
- Give your whole-site backups a password while the key is still inside the Nuvabill folder.
- Never put the key in git, in a ticket or in a chat.

**Site health** on the store has a group **Marketplace signing key**. It warns when other accounts on the server can
read the file, when the file is in the Nuvabill folder or the public folder, and when the key on the server is not a
key that Nuvabill sites trust. The checks only look: they never move or change the key.

#### Changing the key

Sites trust the key in `config/nuvabill.php` → `marketplace.public_key`, and every key in the list
`marketplace.public_keys` (`NUVABILL_MARKETPLACE_PUBLIC_KEYS` in `.env`, separated by commas). So the key can be
changed without breaking sites, in two Nuvabill releases:

1. Make the new key in a new place: run `nuvabill:marketplace-key` with `NUVABILL_MARKETPLACE_SIGNING_KEY_PATH` set
   to a new file. Keep using the old key on the store.
2. Ship a Nuvabill release that has the new public key in `marketplace.public_keys`. Wait until sites have updated:
   a site on an older version only knows the old key.
3. Point `NUVABILL_MARKETPLACE_SIGNING_KEY_PATH` on the store at the new key. New approvals and paid downloads are
   now signed with it. Versions that were approved before keep the old key's signature, so sites must still trust
   the old key until each item has a version that was approved with the new key.
4. In a later release, make the new key `marketplace.public_key` and take the old one out.
