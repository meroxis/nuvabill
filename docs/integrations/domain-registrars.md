# Domain registrars

Clients search for a domain, see the price, and order it on its own or with a hosting plan. With a registrar
connected, Nuvabill registers, transfers and renews domains there, sends expiry reminders and lets clients change their
nameservers. Without a registrar, domain availability comes from free RDAP lookups and staff register domains by hand.

1. **Settings → Domains**: add the endings you sell (for example `.com`) with their register, transfer and renew prices,
   and choose the registrar for each.
2. **Setup → Extensions → Registrars**: connect the registrar and press **Test connection**.

## ResellerClub (and other LogicBoxes brands)

- Works with ResellerClub and other LogicBoxes brands such as NetEarthOne and ResellerCamp.
- **Reseller ID** and **API key**, and **Mode** (live or test with a demo account).

## Namecheap

- Namecheap reseller API, with free WhoisGuard privacy.
- **API user** (your Namecheap username) and **API key** from **Profile → Tools → Namecheap API Access**. Turn the API
  on first.
- Add this server's IP address to the allowed list on the Namecheap API Access page, and in the setting of the same name.
- **Mode**: live or sandbox (`sandbox.namecheap.com` has its own account and API key).

## Enom

- Enom (Tucows) reseller API.
- **Login ID** and an **API token** from your Enom account under **Resellers → API Tokens**. Allow this server's IP
  address there.
- **Mode**: live or test (`resellertest.enom.com`).

## OpenSRS

- OpenSRS (Tucows) reseller API.
- **Reseller username** and **API key**.
- **Mode**: live or test (`horizon.opensrs.net`).
