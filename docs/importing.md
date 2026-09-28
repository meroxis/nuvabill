# Moving from Blesta, FOSSBilling or Paymenter

Nuvabill reads the database of the system you are leaving and copies your business across: clients with their
passwords, products and prices, services, domains, invoices, payments, wallet credit and support tickets. The other
system is only read, so it keeps running while you import. You can run the import again before you switch; it updates
what changed instead of making copies. Coming from WHMCS? See [Migrating from WHMCS](whmcs-migration.md).

## Running the import

1. **Settings → Import**. Choose the system and enter its database host, port, name, username and password. A
   read-only database user is enough. If the tables start with a prefix, enter it too.
2. **Blesta only:** enter the **encryption key**, the value of `Blesta.system_key` in `config/blesta.php`. Blesta
   hashes client passwords with it, so without it clients choose a new password on the sign-in page.
3. Press **Save and check**. Nuvabill makes a **dry run**: without changing anything, it shows how many records each
   step would add or update and the problems it would meet. Problems marked in red must be fixed first; **Check again**
   repeats the dry run.
4. Press **Start import**. It runs in small batches from the cron job, and the page lists any row that could not be
   imported, with the reason.

From the command line:

```
php artisan nuvabill:import blesta --host=127.0.0.1 --database=blesta --username=blesta_read --key="..." --dry-run
php artisan nuvabill:import fossbilling --host=127.0.0.1 --database=fossbilling --username=fb_read
php artisan nuvabill:import paymenter --host=127.0.0.1 --database=paymenter --username=paymenter_read --check
```

`--dry-run` shows the dry run, `--check` only counts the records, and without `--password` it asks for the password
(or reads `IMPORT_DB_PASSWORD`).

## Blesta

| Step | From Blesta | Notes |
|---|---|---|
| Staff | `staff` | Created as switched-off staff accounts, so ticket replies keep their author. |
| Clients | `clients`, `contacts`, `contact_numbers`, `users` | The primary contact's name, company, address and phone. Passwords work at the first sign-in when you gave the system key. Money paid but not yet applied to an invoice becomes wallet credit. |
| Product groups and products | `package_groups`, `packages`, `pricings` | Names, descriptions, the cPanel, DirectAdmin or Plesk package, and monthly to three-yearly prices per currency. |
| Servers | `module_rows` | cPanel, DirectAdmin, Plesk, Virtualizor and Proxmox servers, switched off. Blesta encrypts their passwords and keys, so enter them in **Servers**. |
| Services | `services`, `service_fields` | Status, domain, username, server, billing cycle and next due date. |
| Domain prices | `domains_tlds` | One-year prices from the Domain Manager plugin. |
| Domains | `services` of registrar modules | With the registrar when it is ResellerClub (LogicBoxes), Namecheap, Enom or OpenSRS. |
| Invoices and payments | `invoices`, `invoice_lines`, `transactions` | Approved payments, linked to the invoice they paid. |
| Support | `support_departments`, `support_tickets`, `support_replies` | Replies only; staff notes stay behind. |

## FOSSBilling

| Step | From FOSSBilling | Notes |
|---|---|---|
| Staff | `admin` | Switched-off staff accounts. |
| Clients | `client`, `client_balance` | The client balance becomes wallet credit. Passwords keep working. |
| Product groups and products | `product_category`, `product`, `product_payment` | Monthly to three-yearly prices in the default currency. Add-on products and weekly prices are not imported. |
| Servers | `service_hosting_server` | cPanel (WHM), DirectAdmin and Plesk servers, switched off, with their password and access hash. |
| Services | `client_order`, `service_hosting` | Every order that is not a domain: status, domain, username, server, period and expiry date. |
| Domain prices and domains | `tld`, `client_order`, `service_domain` | With the registrar when Nuvabill has it. |
| Invoices and payments | `invoice`, `invoice_item`, `transaction` | Processed payments only. |
| Support | `support_helpdesk`, `support_ticket`, `support_ticket_message` | Public tickets from guests are not imported. |

## Paymenter

| Step | From Paymenter | Notes |
|---|---|---|
| Staff and clients | `users`, `properties`, `credits` | Everyone becomes a client; users with a role also become switched-off staff. Addresses come from user properties. Passwords keep working. |
| Product groups and products | `categories`, `products`, `plans`, `prices` | Monthly to three-yearly plans per currency. Hourly, daily and weekly plans are not imported. |
| Services | `services` | Status, plan, price and expiry date. |
| Invoices and payments | `invoices`, `invoice_items`, `invoice_transactions` | Payments that went through; payments made from credit are already in the wallet. |
| Support | `tickets`, `ticket_messages` | Departments are matched by name, or made. |

Paymenter keeps server connections in its extensions, so they are not imported: add your servers in **Servers** and
choose them on the products.

## After the import

- Check the servers and switch on the ones Nuvabill should use. Staff are imported switched off too.
- Connect your [payment gateways](integrations/payment-gateways.md) and [registrars](integrations/domain-registrars.md)
  in **Setup → Extensions**.
- When you switch, turn off automation in the other system and run the import one last time, so clients do not get two
  invoices.
