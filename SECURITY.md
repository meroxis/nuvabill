# Security policy

Nuvabill handles invoices, payments and client accounts for hosting companies, so we take security reports seriously
and thank everyone who sends one.

## Reporting a vulnerability

**Please do not open a public issue for a security problem.** Report it privately, in one of these ways:

- **GitHub:** open the [Security tab](https://github.com/meroxis/nuvabill/security) of this repository and choose
  **Report a vulnerability**.
- **Email:** [security@nuvabill.com](mailto:security@nuvabill.com).

Please include:

- the Nuvabill version (shown on the **Updates** page) and how it is installed (shared hosting, one-line installer, …)
- what an attacker can do, and the steps or a small proof of concept to show it
- anything you know about how to fix it

You may write in English, Arabic or Kurdish.

## What happens next

- We aim to confirm that we received your report within **3 working days**, and to tell you within **10 working days**
  whether we can reproduce it and how serious we think it is.
- We fix confirmed problems as fast as we can and keep you updated. Serious problems get a release of their own.
- When the fix is out, we publish a GitHub security advisory. We credit you by name if you want to be credited.
- Please give us time to release a fix before you talk about the problem in public. We ask for up to **90 days**, and
  we will usually need much less.

## Supported versions

Security fixes go into the **newest release**. Nuvabill has not reached 1.0 yet, so please keep your site up to date.

| Version | Security fixes |
|---|---|
| Latest 0.4.x release | Yes |
| Older releases | No: update from the **Updates** page |

## How fixes reach your site

Every release is signed with Ed25519, and your site checks the signature before it installs anything. Releases that
fix a security problem are marked as security releases, and sites with **Install security fixes automatically**
turned on (the default) install them at night on their own. You can also run **Site health** to find weak spots in
your own setup.

## Scope

In scope:

- The Nuvabill code in this repository, including the built-in themes, gateways, server modules and registrars.
- Packages on the official [marketplace](https://nuvabill.com/marketplace/), and the signing of releases and packages.

Out of scope:

- Extensions and themes from other sources. Please report those to their developer.
- The public demo at demo.nuvabill.com resets every hour and runs with most settings locked. Do not attack other
  visitors, send spam, or try to overload it.
- Denial of service, social engineering, and reports from automated scanners without a working attack.

## Safe harbour

If you act in good faith, only test against your own installation or the public demo, do not look at or change other
people's data, and give us a chance to fix the problem first, we will not take legal action against you for your
research.
