# Deployment and local development

## Requirements

- PHP 8.3+ with `ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, and `zip`.
- Composer 2.x.
- MariaDB 10.11+ or a supported MySQL release.
- A web server configured to serve only Laravel's `public/` directory.
- Node is not required at runtime; the current design uses assets in `public/`.
- To rebuild the optional Vite-managed font and CSS artifacts during development, install Node 20.19+ and run `npm ci` followed by `npm run build`.

## Local development

1. Copy `.env.example` to `.env` and configure a dedicated development database account. Never put credentials in source control.
2. Run `composer install`.
3. Run `php artisan key:generate`.
4. Run `php artisan migrate --seed`.
5. Run `php artisan serve --host=127.0.0.1 --port=8000`.

Run `php artisan storage:link` when public gallery uploads are enabled so files on Laravel's public disk can be served. Inspection photos, payment proofs, and expense receipts remain on the private local disk.

On this workstation, Composer is installed in `~/.local/bin/composer`; add `~/.local/bin` to `PATH` or use the full path. The `minex` schema and local-scoped account are provisioned locally. The actual database password is in the ignored `.env` only.

In local development only, `php artisan db:seed` creates `owner@minex.test`, mechanic accounts, and `rider@minex.test` when `MINEX_DEMO_PASSWORD` is set to a password you choose. The template leaves it empty. Demo account and catalog seeders return without creating demo records unless `APP_ENV=local`.

Feature tests use a separate `minex_test` database. Never point the test suite at production or a database containing records that must be retained.

## Production

Set `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, strong database credentials, HTTPS, secure cookie settings, a public asset disk for gallery photos, and private storage for inspection media, payment proofs, and receipts. Run `composer install --no-dev --optimize-autoloader`, `php artisan config:cache`, `php artisan route:cache`, `php artisan storage:link`, and reviewed migrations. Restrict filesystem permissions, expose only `public/`, schedule backups, and test restore procedures. Never run demo seeders in production.

The unpaid-booking expiry command is scheduled every minute in `routes/console.php`. Configure the host scheduler to invoke Laravel each minute:

```cron
* * * * * cd /srv/minex && php artisan schedule:run >> /dev/null 2>&1
```

Without this scheduler, unpaid temporary appointments will remain held after their deadline until an operator runs `php artisan minex:expire-unpaid-bookings` manually. Confirm local production settings for operating days/hours, break times, service-bay capacity, payment deadline, deposit percentages, catalog prices, exact vehicle fitment, contact details, and refund policy before accepting real bookings.
