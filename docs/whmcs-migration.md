# Migrating from WHMCS

Nuvabill's importer reads your WHMCS database directly and copies your business across: clients with their passwords,
products and prices, services, domains, invoices, payments and support tickets. WHMCS keeps running while you import,
and you can run the import again before you switch; it updates what changed instead of making copies.

## What gets imported

The import runs in this order, because later steps need the records from earlier ones:

| Step | From WHMCS | Notes |
|---|---|---|
| Staff | `tbladmins` | Created as switched-off staff accounts, so ticket replies keep their author. Staff with the same email in Nuvabill are linked. |
| Clients | `tblclients` | Clients keep their password when WHMCS stored it as bcrypt (WHMCS 7 and newer, and the account owner in WHMCS 8). Clients with an older password type set a new one with "Forgot password". |
| Product groups | `tblproductgroups` | |
| Servers | `tblservers` | cPanel, DirectAdmin, Plesk, Virtualizor and Proxmox servers. Imported **switched off**, because WHMCS encrypts server passwords with its own key: enter the password or API token again and switch each server on. |
| Products and prices | `tblproducts`, `tblpricing` | Monthly to three-yearly prices and setup fees, per currency. |
| Services | `tblhosting` | Status, domain, username, server, billing cycle, next due date and suspension reason. A Virtualizor `vpsid` custom field is kept, so the VPS stays linked. |
| Domain prices | `tbldomainpricing` | One-year register, transfer and renew prices. Endings WHMCS does not sell are skipped. |
| Domains | `tbldomains` | With the registrar when it is ResellerClub, Namecheap, Enom or OpenSRS. |
| Invoices | `tblinvoices`, `tblinvoiceitems` | Status, dates, lines and the invoice number. |
| Payments | `tblaccounts` | |
| Support departments | `tblticketdepartments` | |
| Tickets | `tbltickets`, `tblticketreplies` | With every reply and its author. |

## What is not imported

- Server and service passwords (WHMCS encrypts them with its own key). One-click login to cPanel, DirectAdmin and Plesk
  still works once the server's API token is entered.
- Saved cards. Clients pay their next invoice on the gateway's checkout page.
- Product addons, configurable options and custom fields (except the Virtualizor `vpsid` field).
- Email templates, knowledge base articles and announcements.

## Running the import

### In the admin area

1. **Settings → Import**. Enter the WHMCS database host, port, name, username and password. A read-only database user
   is enough.
2. Press **Save and check**. Nuvabill shows your WHMCS version and how many records each step will copy.
3. Press **Start import**. It runs in small batches from the cron job, so large WHMCS databases do not time out and it
   keeps going if you close the page. The page shows each step's progress; **Run again** repeats it later.

The database details are stored encrypted.

### From the command line

```
php artisan nuvabill:import-whmcs --host=127.0.0.1 --database=whmcs --username=whmcs_read --check
php artisan nuvabill:import-whmcs --host=127.0.0.1 --database=whmcs --username=whmcs_read
```

Without `--password` it asks for the password (or reads `WHMCS_DB_PASSWORD`). Without any options it uses the details
saved in **Settings → Import**.

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
6. **Tell your clients** about the new client area. Their passwords still work, except for the older password type
   described above.
