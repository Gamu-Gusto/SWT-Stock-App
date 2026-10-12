# Super Wheels and Tyres – Stock Management System

A private, web-based stock management and stocktaking system for **Super Wheels and Tyres**
(481 WF Nkomo Street, Pretoria West). Staff can open it on a phone, tablet or computer, type a tyre
size, see exactly what is physically there, what is reserved and what can be sold, and then reserve
or sell it. Managers can see what was received, what was sold, what remains and whether anything has
gone missing.

Live address (planned): **https://stock.superwheeltyres.co.za** – login required. The public website
https://superwheeltyres.co.za is not touched by this application.

## What it does

| Area | Highlights |
|---|---|
| Tyre inventory | Brand, model, width/profile/rim (size such as `205/55 R16` generated automatically), load index, speed rating, type, run-flat, condition, prices, minimum stock, locations, photos. New and used tyres are always separate records. |
| Used tyres | Every used tyre is its own unit: tyre code, tread depth, grade (A/B/C – configurable, internal only), DOT code, defects, photos, cost and price. Rejected tyres can never be sold. |
| Search | One box understands `205/55 R16`, `205/55R16`, `205 55 16`, `R16`, `Falken 205/55 R16`, `used 205/55 R16`, SKUs and tyre codes. Server-side, indexed and paginated. |
| Receiving | Supplier deliveries with invoice/delivery-note numbers, drafts, review, attachments, duplicate-reference warning, single posting, traceable reversals. |
| Sales | Cart with tyres and services (fitting, balancing, alignment…), discounts, cash/card/EFT, optional VAT, drafts, voids, customer returns with inspection. Historical prices are preserved. |
| Reservations | Hold tyres for a customer for a configurable period (default 24 h). Reduces available stock only; auto-expires; converts to a sale without double deduction. |
| Quotations | Printable quotes, WhatsApp share link (`wa.me`), convert to reservation or sale. Quotes never change stock. |
| Stocktaking | Full / brand / size / location / used-tyre counts, blind counting, phone-friendly counting screen, duplicate-count protection, recounts, discrepancy report, manager approval with reasons, adjustments posted through the ledger. |
| Stock movements | Immutable ledger of every change (opening balance, receipt, sale, returns, transfers, adjustments, write-offs…) with before/after quantities, reference, reason, employee and approver. |
| Reports | Current stock, stock by size, by brand, low stock, out of stock, movements, sales (by date/employee/brand/size), stocktake variances, inventory valuation (FIFO), slow-moving stock. Print, CSV and Excel. |
| Import | CSV/XLSX opening stock with column mapping, validation preview, duplicate handling (skip or add – never overwrite), downloadable template. |
| Administration | Roles and permissions (Owner, Manager, Inventory Clerk, Sales Employee, Stock Counter – editable), users, audit log, business settings, VAT, built-in Super Wheels and Tyres logo (replaceable), notification centre, optional public availability API. |
| PWA | Installable on Android and desktop. Only static files are cached; stock figures always come from the server. |

## Technology

- **Backend:** Laravel 13 (PHP 8.3+), REST API, Laravel Sanctum (same-origin session authentication + CSRF), MySQL 8 / MariaDB 10.6+
- **Frontend:** Vue 3, TypeScript, Vite, Pinia, Vue Router, Tailwind CSS 4, Lucide icons, Chart.js
- **Spreadsheets:** PhpSpreadsheet (import and Excel export)
- **Hosting:** any DirectAdmin (or cPanel/Apache/Nginx) host with PHP 8.3. No Node.js, Redis, Docker or cloud services in production – the frontend is compiled to static files in `public/build`.

## Quick start (development)

Requirements: PHP 8.3+ with `pdo_mysql, mbstring, openssl, fileinfo, zip, gd, intl, bcmath, xml, curl`, Composer 2, Node.js 20+, MySQL/MariaDB.

```bash
composer install
npm install
cp .env.example .env          # then set APP_ENV=local, APP_DEBUG=true, APP_URL=http://localhost:8000,
                              # SESSION_SECURE_COOKIE=false, SESSION_DOMAIN=null and your DB details
php artisan key:generate
php artisan migrate            # creates all tables
php artisan swt:install        # roles, permissions, default locations & brands; prompts for the first owner
npm run dev                    # Vite dev server (hot reload) – in a second terminal:
php artisan serve              # http://localhost:8000
```

Optional fictional demo data (never in production – it refuses to run when `APP_ENV=production`):

```bash
php artisan db:seed --class=DemoSeeder   # demo accounts: owner@demo.test … password DemoPass2026
```

## Creating the first administrator

There are **no default credentials**. Create the owner with either:

- `php artisan swt:install` (or `php artisan swt:create-owner --name="…" --email="…"`), or
- the one-time browser installer when SSH is not available: set `SETUP_TOKEN` in `.env`, open
  `/setup`, enter the token and owner details, then remove `SETUP_TOKEN`. The page refuses to work
  once an owner exists.

The owner then creates every employee under **Users** (a temporary password is generated and must be changed at first sign-in).

## Tests

```bash
php vendor/bin/phpunit                        # fast suite on in-memory SQLite (concurrency tests skipped)
php vendor/bin/phpunit -c phpunit.mysql.xml   # full suite on MySQL/MariaDB incl. multi-process concurrency tests
npx vue-tsc --noEmit                          # frontend type check
```

`phpunit.mysql.xml` uses the database `swt_stock_test`; put `APP_KEY`, `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` for your local test server in `.env.testing` (git-ignored).
The suite covers login and rate limiting, role permissions, cost-price confidentiality, size generation and
search normalisation, receiving, sales deductions, insufficient-stock rejection, reservations (create, cancel,
expire), stocktake discrepancy calculation and approval, duplicate-transaction prevention, two processes
selling the last tyre at the same instant, movements, transfers, imports, financial calculations (VAT, FIFO)
and report permissions.

## Production build

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build        # writes public/build – upload it; Node is not needed on the server
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Documentation

| Document | For |
|---|---|
| [docs/DEPLOYMENT_DIRECTADMIN.md](docs/DEPLOYMENT_DIRECTADMIN.md) | Step-by-step DirectAdmin deployment, with and without SSH |
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Local installation and configuration reference |
| [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md) | Owner/administrator setup: users, roles, settings, opening stock |
| [docs/USER_GUIDE.md](docs/USER_GUIDE.md) | Daily use for counter, workshop and stock staff |
| [docs/INVENTORY_RULES.md](docs/INVENTORY_RULES.md) | How stock is calculated: ledger, availability, FIFO valuation, locking, stocktakes |
| [docs/API.md](docs/API.md) | Private API overview, public availability API and website integration |
| [docs/BACKUP_AND_RESTORE.md](docs/BACKUP_AND_RESTORE.md) | Backups, retention, restore and disaster recovery |
| [docs/templates/stock-import-template.csv](docs/templates/stock-import-template.csv) | Spreadsheet import template |

## Project structure

```
app/
  Exceptions/InventoryException.php   business-rule errors returned as clear JSON
  Http/Controllers/Api/               thin REST controllers
  Http/Requests, Http/Resources       validation and output shaping (hides cost prices)
  Http/Middleware/                    active-user check, security headers
  Models/                             Eloquent models (StockMovement and ActivityLog are immutable)
  Policies/                           record-level authorisation (sales, stocktakes)
  Services/Inventory/                 StockLedger (only place stock changes), Receiving, Sales, Reservations,
                                      Returns, Transfers, Adjustments, Stocktakes, Search, Products, Tyre units
  Services/                           Dashboard, Reports, Exports, Import, Quotations, Notifications, Users, Installer
  Support/                            Money (exact cents), TyreSize parser, AppSettings, Audit, DocumentNumber, permissions
database/migrations, seeders          schema; reference data only (DemoSeeder is fictional and dev-only)
resources/js/                         Vue SPA: api/, stores/, router/, layouts/, pages/, components/, composables/, utils/, types/
routes/api.php, public.php, web.php   private API, public availability API, SPA shell + one-time setup
tests/                                PHPUnit unit + feature tests, multi-process concurrency worker
public/                               web root only: index.php, build/, icons, manifest, service worker
```
