# Migrating from WHMCS

Nuvabill's importer reads your WHMCS database directly and copies your business across: clients with their passwords,
products and prices, services, domains, invoices, payments and support tickets. WHMCS keeps running while you import,
and you can run the import again before you switch; it updates what changed instead of making copies.

## What gets imported

The import runs in this order, because later steps need the records from earlier ones:

| Step | From WHMCS | Notes |
|---|---|---|
| Staff | `tbladmins` | Created as switched-off staff accounts, so ticket replies keep their author. Staff with the same email in Nuvabill are linked. |
| Clients | `tblclients` | Clients keep their password. WHMCS 7 and newer (bcrypt, and the account owner in WHMCS 8) work as they are; the salted MD5 passwords of WHMCS 4.2 to 6.2 are checked at the client's first sign-in and then replaced. WHMCS credit becomes a wallet entry; running the import again adds only what changed. |
| Product groups | `tblproductgroups` | |
| Servers | `tblservers` | cPanel, DirectAdmin, Plesk, Virtualizor and Proxmox servers, with their API token. Imported **switched off**, so you can check them first. Their passwords come across when you give WHMCS's encryption key (see below). |
| Products and prices | `tblproducts`, `tblpricing` | Monthly to three-yearly prices and setup fees, per currency. |
| Services | `tblhosting` | Status, domain, username, server, billing cycle, next due date and suspension reason, and the password when you give the key. A Virtualizor `vpsid` custom field is kept, so the VPS stays linked. |
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
- Product addons, configurable options and custom fields (except the Virtualizor `vpsid` field).
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
