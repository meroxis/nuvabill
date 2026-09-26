# Contributing to Nuvabill

Thanks for helping. Bug reports, ideas and pull requests are welcome.

## Before you start

- For a bug, open an issue with the steps to reproduce.
- For a bigger change, open an issue first so we can agree on the approach.
- Report security problems privately. See [SECURITY.md](SECURITY.md).

## Local setup

Follow [For developers](README.md#for-developers) in the README.

## Pull requests

- Run the tests with `php artisan test` and the code style check with `vendor/bin/pint --test`.
- Add or update tests for changes in behavior.
- Store money as integer minor units (cents), never as floats.
- Migrations must work on MySQL/MariaDB and SQLite.
- Keep the "Powered by Nuvabill" credit. It is a license requirement (see [NOTICE](NOTICE)).
- Write user-facing text in plain, short English.

By sending a pull request you agree that your contribution may be distributed by RapidNet Ltd under the
AGPL-3.0 license and under the Nuvabill commercial White-label License.
