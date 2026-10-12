# Installation and configuration reference

For production on DirectAdmin follow [DEPLOYMENT_DIRECTADMIN.md](DEPLOYMENT_DIRECTADMIN.md). This page covers a
developer or test installation and the configuration options.

## Requirements

- PHP 8.3 or 8.4 with extensions `bcmath ctype curl dom fileinfo gd intl mbstring openssl pdo_mysql pdo_sqlite tokenizer xml zip`
- Composer 2
- Node.js 20+ and npm (development/build machine only)
- MySQL 8.0+ or MariaDB 10.6+ (SQLite is used only for the fast automated test run)

## Steps

```bash
git clone <repository> swt-stock && cd swt-stock     # or unzip the source
composer install
npm install
cp .env.example .env
```

Edit `.env` for local use:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SANCTUM_STATEFUL_DOMAINS=localhost:8000,127.0.0.1:8000
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=swt_stock
DB_USERNAME=…
DB_PASSWORD=…
```

```bash
php artisan key:generate
php artisan migrate
php artisan swt:install                     # roles, permissions, locations, brands + first owner (prompts)
php artisan db:seed --class=DemoSeeder      # OPTIONAL fictional data for trying the system
npm run dev                                 # terminal 1 – Vite (hot reload)
php artisan serve                           # terminal 2 – http://localhost:8000
```

Run the scheduler locally if you need reservation expiry/alerts: `php artisan schedule:work`.

## Useful commands

| Command | Purpose |
|---|---|
| `php artisan swt:install` | Install/refresh reference data (safe to re-run; keeps custom role permissions) and optionally create an owner. |
| `php artisan swt:create-owner --name= --email=` | Create another owner account. |
| `php artisan swt:expire-reservations` | Release expired reservations now. |
| `php artisan swt:alerts` | Refresh reservation-expiry and stocktake reminders. |
| `php artisan migrate --force` | Apply database migrations in production. |
| `php artisan optimize` / `optimize:clear` | Build / clear config, route, view and event caches. |
| `php vendor/bin/phpunit` | Tests on SQLite. |
| `php vendor/bin/phpunit -c phpunit.mysql.xml` | Full tests on MySQL/MariaDB including concurrency. |
| `npx vue-tsc --noEmit` | Frontend type check. |
| `npm run build` | Production frontend build into `public/build`. |

## Configuration in the app (Settings)

| Setting | Default |
|---|---|
| Business name, phone, WhatsApp, address | Super Wheels and Tyres, +27 76 454 4272, 481 WF Nkomo Street, Pretoria West, 0184 |
| Logo / brand colour | Built-in Super Wheels and Tyres logo (`public/images/swt-logo.*`) / logo gold `#d19a3a` |
| VAT registered | **No** (rate 15.00 %, prices include VAT when switched on) |
| Reservation period | 24 hours |
| Quotation validity | 7 days |
| Default minimum stock | 4 |
| Used tyre grades | A, B, C |
| Stocktake locking | Pause stock transactions (block) |
| Slow-moving threshold | 90 days |
| Low-stock alerts | On (e-mail copies off) |
| Public availability API | **Off** (status only, no prices, origin https://superwheeltyres.co.za) |

## Security notes

- Never set `APP_DEBUG=true` on a public server.
- Keep `.env` outside the web root (only `public/` is served).
- Uploaded files are stored in `storage/app/private` with random names and served only through authorised routes.
- The SPA, API and sessions share one origin; cookies are `HttpOnly`, `SameSite=Lax` and `Secure` in production.
- Security headers (nosniff, frame-options, referrer-policy, HSTS on HTTPS) are added to every response; API
  responses are marked `no-store`.
