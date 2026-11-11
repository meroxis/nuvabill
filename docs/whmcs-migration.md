# Migrating from WHMCS

Nuvabill's importer reads your WHMCS database directly and copies your business across: clients with their passwords,
products and prices, services, domains, invoices, payments and support tickets. WHMCS keeps running while you import,
and you can run the import again before you switch; it updates what changed instead of making copies.

## What gets imported

The import runs in this order, because later steps need the records from earlier ones:

| Step | From WHMCS | Notes |
|---|---|---|
| Staff | `tbladmins` | Created as switched-off staff accounts, so ticket replies keep their author. Staff with the same email in Nuvabill are linked. |
| Clients | `tblclients` | Clients keep their password. WHMCS 7 and newer (bcrypt, and the account owner in WHMCS 8) work as they are; the salted MD5 passwords of WHMCS 4.2 to 6.2 are checked at the client's first sign-in and then replaced. Until then Nuvabill keeps them inside its own slow hash: the scheduler does this within minutes of the import, so plain MD5 is only kept until then (the cron job must run). WHMCS credit becomes a wallet entry; running the import again adds only what changed. |
| Product groups | `tblproductgroups` | |
| Servers | `tblservers` | cPanel, DirectAdmin, Plesk, Virtualizor and Proxmox servers, with their API token. Imported **switched off**, so you can check them first. Their passwords come across when you give WHMCS's encryption key (see below). |
| Products and prices | `tblproducts`, `tblpricing` | Monthly to three-yearly prices and setup fees, per currency. Quantity configurable options you map become [resource options](resource-options.md) with their prices (see below). |
| Services | `tblhosting`, `tblhostingconfigoptions` | Status, domain, username, server, billing cycle, next due date and suspension reason, and the password when you give the key. A Virtualizor `vpsid` custom field is kept, so the VPS stays linked. Mapped configurable options become the service's extras. |
| Domain prices | `tbldomainpricing` | One-year register, transfer and renew prices. Endings WHMCS does not sell are skipped. |
| Domains | `tbldomains` | With the registrar when it is ResellerClub, Namecheap, Enom or OpenSRS. |
| Invoices | `tblinvoices`, `tblinvoiceitems` | Status, dates, lines and the invoice number. Renewal lines keep their period, so Nuvabill never bills that period again; an invoice cancelled in WHMCS later frees it. |
| Payments | `tblaccounts` | PayPal, Stripe and bank transfer payments get Nuvabill's payment method; others keep their WHMCS name. |
| Support departments | `tblticketdepartments` | |
| Tickets | `tbltickets`, `tblticketreplies` | With every reply and its author. |

## What is not imported

- Server and service passwords, unless you give WHMCS's encryption key.
- Extra users of a client account (WHMCS 8 "users"). The account owner signs in.
- Saved cards. Clients pay their next invoice on the gateway's checkout page.
- Product addons and custom fields (except the Virtualizor `vpsid` field).
- Configurable options you do not map, and every dropdown or radio option. Their price stays in the service price.
- Email templates, knowledge base articles and announcements.

## Running the import

### In the admin area

1. **Settings → Import**. Choose **WHMCS** and enter the database host, port, name, username and password. A read-only
   database user is enough.
2. Optional: enter the **encryption key**, the value of `$cc_encryption_hash` in WHMCS's `configuration.php`. With it,
   server and service passwords come across too.
3. Press **Save and check**. Nuvabill makes a **dry run**: without changing anything, it shows how many records each
   step would add or update and the problems it would meet (clients without a valid email, server modules Nuvabill does
   not have, overdue services the first nightly run would suspend, and more). Problems marked in red, such as a wrong
   key, must be fixed first; **Check again** repeats the dry run.
4. Press **Start import**. It runs in small batches from the cron job, so large WHMCS databases do not time out and it
   keeps going if you close the page. The page shows each step's progress and lists any row that could not be
   imported, with the reason; **Run again** repeats it later.

The database details are stored encrypted.

### From the command line

```
php artisan nuvabill:import whmcs --host=127.0.0.1 --database=whmcs --username=whmcs_read --key="..." --dry-run
php artisan nuvabill:import whmcs --host=127.0.0.1 --database=whmcs --username=whmcs_read --key="..."
```

Without `--password` it asks for the password (or reads `IMPORT_DB_PASSWORD`). Without any options it uses the details
saved in **Settings → Import**. `--check` only counts the records. The older `nuvabill:import-whmcs` command still works.

## Configurable options

WHMCS sells extras such as "Extra RAM" as configurable options. Nuvabill sells them as
[resource options](resource-options.md): sliders with a price for each step. The importer can turn **quantity** options
(and yes/no options for backups) into resource options. It does this only when you say what each option is, because
WHMCS does not store the unit: for example, a Virtualizor `additional_ram` option counts MB, or GB when `ram_in_gb` is
on. A wrong guess could change a live server at its next plan change.

1. Run the dry run (**Save and check**). The **Configurable options** section lists each quantity and yes/no option of
   the products you import, and how many services use it.
2. For each option, choose what it is: CPU cores, RAM, disk, IPv4, IPv6, monthly traffic or backups (yes/no options
   only). Nuvabill suggests one from common names such as "CPU Cores", "RAM", "Space", "Number of IPs", "Bandwidth" and
   the Virtualizor `additional_...` names. The default, **Not imported**, keeps the old behaviour: the option's price
   stays in the plan price.
3. Enter the **units per quantity**: how much one step of the option is in Nuvabill's units. Nuvabill counts RAM in MB,
   and disk and traffic in GB. For example, enter 1024 when one RAM step in WHMCS is 1 GB.
4. Press **Save choices and check again**, look at the counts, then start the import. (The **Check again** button at
   the top does not save your choices.)

What the import then does:

- **Products**: each mapped option becomes a resource option. Its step is the units per quantity, its fewest and most
  steps come from WHMCS's minimum and maximum quantity (at most 1000), and its prices come from WHMCS for every
  currency and billing cycle.
- **Services**: each service gets the quantity it has in WHMCS as its steps. The extras' renewal price is the quantity
  times the option price in the service's currency and billing cycle, and the plan price is the WHMCS amount minus the
  extras.
- **The renewal total never changes.** Every imported service renews for exactly its recurring amount in WHMCS. When
  the extras would cost more than that amount (because prices changed since), the amount is not split: the extras get a
  renewal price of 0 and the plan keeps the whole amount. The dry run warns about these services, and each one gets an
  activity log entry: its extras are included in the plan price.
- **Dropdown and radio options** are never mapped, because they hold a total, not an extra. The dry run warns about
  each one; their price stays in the service price. Set up resource options for those products by hand.
- The import never changes a server. Nuvabill sets the plan plus the extras on a server only at a later change, such
  as a plan change or a change of the extras. That is why the units must be right.

## A safe switch-over plan

1. **Install Nuvabill** on a new subdomain, for example `billing-new.yourhost.com`. Keep WHMCS running.
2. **Run the import** and look around: clients, services, invoices and tickets.
3. **Connect your servers** (enter the passwords or API tokens and switch them on), your
   [payment gateways](integrations/payment-gateways.md) and [registrars](integrations/domain-registrars.md).
4. **Test**: sign in as a client with their WHMCS password, pay a small invoice in test mode, open cPanel with one
   click.
5. **Switch**: turn off automation in WHMCS (so clients do not get two invoices) and put it in maintenance mode, run
   the import one last time so the newest invoices and replies come across, then point your billing domain to
   Nuvabill and turn on its cron job.
6. **Tell your clients** about the new client area. Their passwords still work.
