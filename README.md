# MINEX

Motorcycle service and workshop management platform for everyday riders.

## Current implementation

The core MINEX workflow is implemented: public workshop services and packages, an admin-only parts/inventory catalog, a manufacturer-to-trim motorcycle Garage, guest/member booking requests, capacity-aware scheduling, mechanic work orders and inspections, customer decisions on additional work and booking changes, manual payment verification, refunds, expense records, notifications, and audit history. Vehicle seed provenance and verification state are documented in [the vehicle catalog guide](docs/VEHICLE_CATALOG.md). Review the [project overview](docs/PROJECT_OVERVIEW.md) and workflow guides for behavior and remaining deployment decisions.

## Stack

- PHP 8.3+
- Laravel 13, Blade, Eloquent
- MariaDB / MySQL
- Custom CSS and vanilla JavaScript served from `public/`; no Node runtime or asset build is needed

## Start locally

1. Create a database and dedicated local user, then set the credentials in `.env` from `.env.example`.
2. Install dependencies with `composer install`.
3. Generate an application key: `php artisan key:generate`.
4. Run migrations and local demonstration records: `php artisan migrate --seed`.
5. Start the server: `php artisan serve --host=127.0.0.1 --port=8000`.

On the current workstation Composer is available at `~/.local/bin/composer`. Local demo accounts are controlled by `MINEX_DEMO_PASSWORD` in `.env`; the seeder runs only in local environments. See [deployment](docs/DEPLOYMENT.md) and [the decision register](docs/DECISIONS.md).

## Verification

Run `php artisan test` for the feature suite and `vendor/bin/pint --format agent` for PHP formatting. Unpaid booking expiration is scheduled every minute; production must run Laravel's scheduler as described in the deployment guide.

## Documentation

Architecture, data relationships, product workflows, security, and deployment notes live in `docs/`. See [project overview](docs/PROJECT_OVERVIEW.md) to continue phase by phase.
