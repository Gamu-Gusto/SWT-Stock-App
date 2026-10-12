# Super Wheels and Tyres – Stock Management System

Laravel 13 (PHP 8.3) API + Vue 3/TypeScript SPA (resources/js) served from the same origin. MySQL/MariaDB.
Read `README.md` and `docs/INVENTORY_RULES.md` before changing anything stock-related.

## Non-negotiable rules

- **All stock changes go through `App\Services\Inventory\StockLedger`** inside a DB transaction, after locking
  product rows with `lockProducts()` (ascending id). Never update `stock_balances`, `tyre_units.status/location_id`
  or `products.qty_*` directly elsewhere.
- `stock_movements` and `activity_logs` are immutable. Corrections = new movements.
- Money: decimal strings + `App\Support\Money` (integer cents). No float arithmetic.
- Cost prices/margins only in API output when `financials.view` (see `ProductResource`, `MovementResource`, `SaleResource`).
- Every endpoint needs server-side authorisation (`can:` middleware with names from `App\Support\PermissionRegistry`, or a policy).
- Mutating POSTs that create documents accept an `idempotency_key`.
- Production must start with an empty inventory; `DemoSeeder` is fictional and refuses to run in production.

## Commands

- Tests: `php vendor/bin/phpunit` (SQLite) and `php vendor/bin/phpunit -c phpunit.mysql.xml` (MySQL, incl. concurrency).
- Frontend: `npm run dev`, `npm run build`, `npx vue-tsc --noEmit`.
- Code style: `php vendor/bin/pint`.
