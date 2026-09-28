# Control panels and VPS

Nuvabill sets up hosting accounts and virtual servers the moment the first invoice of an order is paid, suspends them
when invoices are overdue, unsuspends them when they are paid, and removes them when a service is terminated.

## Adding a server

1. **Servers → Add server**. Choose the module, then fill in the hostname, port, username and password or API token.
   Each module shows what to enter under the form (the same notes are below).
2. Press **Test connection**.
3. **Products** → open a plan → choose **Automatic setup** and the server, then fill in the module's plan settings.

Connections use HTTPS by default, and the server password and API token are stored encrypted.

## cPanel & WHM

- **Connection**: username `root` (or a reseller), and a WHM API token from **WHM → Development → Manage API Tokens**.
  Port 2087.
- **Plan setting**: the WHM package.
- **What it does**: create, suspend, unsuspend, terminate and change package. Clients open cPanel with one click,
  without a password.

## DirectAdmin

- **Connection**: your admin or reseller name, and a login key from **DirectAdmin → Login Keys** (or the account
  password; a login key is safer). Set **IP address** to the shared IP for new accounts. Port 2222.
- **Plan setting**: the DirectAdmin package.
- **What it does**: create, suspend, unsuspend, terminate and change package, with one-click login for clients.

## Plesk

- **Connection**: an API key made on the Plesk server with
  `plesk bin secret_key -c -ip-address <this server's IP>`, or the admin username and password. Also fill in the
  server's IP address. Port 8443.
- **Plan settings**: the service plan and the IPv4 address.
- **What it does**: create, suspend, unsuspend and remove Plesk customers and subscriptions, change plan, and
  one-click login for clients.

## Proxmox VE

- **Connection**: an API token from **Proxmox → Datacenter → Permissions → API Tokens**. Put the token ID in
  **Username** (for example `root@pam!nuvabill`) and the secret in **API token**. Port 8006, with a valid SSL
  certificate (Proxmox can get one with ACME).
- **Plan settings**: node, template VM ID (a cloud-init template), storage, CPU cores, RAM, disk size, disk device and
  network (`ipconfig0`).
- **What it does**: creates KVM virtual servers from the template; suspend, unsuspend, terminate and change plan.
- **In the client area**: start, shut down, restart, power off and change the root password, with live CPU and memory
  use and the IP addresses.

## Virtualizor

- **Connection**: the Virtualizor master server as hostname; the Admin API key in **API token** and the API password in
  **Password** (**Virtualizor admin → Configuration → Server Info**). Allow Nuvabill's server IP there too. Port 4085,
  with a valid SSL certificate.
- **Plan settings**: virtualization type, Virtualizor plan ID, operating system ID, number of IPv4 addresses, RAM, disk,
  CPU cores and bandwidth.
- **What it does**: create, suspend, unsuspend, terminate and change plan.
- **In the client area**: start, shut down, restart, power off, change hostname, change root password, reinstall the
  operating system and show VNC details, with live usage and IP addresses.

## Not listed here?

Other control panels can be added as extensions. See the
[developer guide](https://nuvabill.com/docs/developers/) (server modules extend `App\Extensions\Servers\Module`).
