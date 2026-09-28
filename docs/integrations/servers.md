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

## Free modules on the Marketplace

These four modules are free. Install them with one click from **Marketplace** in the admin area: after that they
show in the module list on **Servers → Add server**, like the built-in ones.

### CyberPanel

- **Connection**: a CyberPanel admin username and password, with API access turned on for that user
  (**Users → API Access**). Port 8090, with a valid SSL certificate for the one-click sign-in.
- **Plan settings**: the CyberPanel package, the PHP version (empty uses PHP 8.3) and how many websites the client may
  add.
- **What it does**: creates a website and its own CyberPanel user; suspend, unsuspend, change package and remove.
  Clients open CyberPanel with one click, without a password. Needs Nuvabill 0.4.10 or newer.

### HestiaCP

- **Connection**: an access key in **API token**, written as `ACCESS_KEY:SECRET_KEY` (in Hestia: your admin user →
  **Access keys**, or `v-add-access-key admin '*' nuvabill` on the server). The admin username and password also
  work. Allow Nuvabill's server IP under **Server settings → Security → API**. Port 8083.
- **Plan setting**: the Hestia package.
- **What it does**: creates a Hestia user with the client's domain (website, DNS and email); suspend, unsuspend, change
  package and remove. If the domain cannot be added, the new user is removed again. Clients open Hestia with one
  click.

### VirtFusion

- **Connection**: your VirtFusion panel as hostname (for example `panel.example.com`) and an API token from
  **Settings → API** in VirtFusion. The panel needs a valid SSL certificate.
- **Plan settings**: the VirtFusion package ID, the hypervisor group ID, the operating system ID (empty lets the client
  choose in VirtFusion) and the number of IPv4 addresses.
- **What it does**: makes a VirtFusion user for each client and builds the server; suspend, unsuspend, change package
  and remove.
- **In the client area**: start, shut down, restart and power off, with the state, operating system and resources, and
  one-click sign-in to VirtFusion.

### SolusVM

- **Connection**: the SolusVM master as hostname, the API ID in **Username** and the API key in **API token**
  (**Configuration → API Access** in SolusVM). Allow Nuvabill's server IP there too. Port 5656 with HTTPS. For
  SolusVM 1.
- **Plan settings**: virtualization type (KVM, OpenVZ or Xen), node group or node, the SolusVM plan name, the OS
  template file name and the number of IPv4 addresses.
- **What it does**: create, suspend, unsuspend, terminate and change plan.
- **In the client area**: boot, shut down, reboot, change hostname, change root password and open the VNC console,
  with the state, IP addresses and memory and disk use, and one-click sign-in to the SolusVM client panel.

When you import from WHMCS, servers and products that use these panels come across once the module is installed.
Install it first, then run the import (or run it again).

## Not listed here?

Other control panels can be added as extensions. See the
[developer guide](https://nuvabill.com/docs/developers/) (server modules extend `App\Extensions\Servers\Module`).
