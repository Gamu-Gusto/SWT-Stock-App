# Deploying to DirectAdmin – stock.superwheeltyres.co.za

This guide puts the stock system on its own subdomain without touching the public website
(`superwheeltyres.co.za`). Nothing in these steps edits the main domain's `public_html`.

Paths below assume the DirectAdmin user is `swtuser`; replace with yours.

---

## 1. Hosting prerequisites

| Requirement | Notes |
|---|---|
| PHP **8.3 or 8.4** | DirectAdmin → *Domain Setup* → select the subdomain → *PHP version selector* (or ask the host). |
| PHP extensions | `bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, tokenizer, xml, zip` (all standard on DirectAdmin CustomBuild). |
| MySQL 8.0+ or MariaDB 10.6+ | Needs InnoDB, CHECK constraints and row locking (standard). |
| Disk | ~150 MB for the app plus uploaded photos and backups. |
| Cron | One cron job (scheduler). |
| SSL | Let's Encrypt in DirectAdmin (free). |
| Not required | Node.js, Redis, Docker, queue workers, external cloud services. |

Check PHP from SSH with `php -v` and `php -m`. On many DirectAdmin servers the CLI binary for a specific
version is `/usr/local/php83/bin/php` – use that path in commands and cron if `php -v` shows an older version.

## 2. Create the subdomain

Recommended: add it as its **own domain** so it gets its own folder:

1. DirectAdmin → *Account Manager* → *Domain Setup* → **Add New** → `stock.superwheeltyres.co.za`.
   This creates `/home/swtuser/domains/stock.superwheeltyres.co.za/public_html`.
2. Make sure the DNS has an `A` record for `stock` pointing to the server (DirectAdmin *DNS Management*;
   if the DNS is hosted elsewhere, add it there).
3. Enable **SSL** for the new domain: *SSL Certificates* → *Free & automatic certificate from Let's Encrypt*,
   include `stock.superwheeltyres.co.za`. Enable **Force SSL with https redirect**.

> Alternatively use *Subdomain Management* → `stock`. Its folder is then
> `/home/swtuser/domains/superwheeltyres.co.za/public_html/stock` – inside the main site's web root – so the
> **application code must still go outside public_html** (step 3) and only the contents of `public/` go in that folder.

## 3. Folder layout (keep secrets out of the web root)

```
/home/swtuser/domains/stock.superwheeltyres.co.za/
├── swt-app/            ← the whole Laravel application (NOT web accessible): app/, vendor/, .env, storage/ …
│   └── public/         ← the only folder that should be public
└── public_html  →  swt-app/public      (symlink, or a copy – see 5b)
```

## 4. Build the release on your computer

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build            # creates public/build (fonts are downloaded and self-hosted here)
```

Upload everything **except**: `node_modules/`, `.env`, `tests/` (optional), `storage/logs/*`, `.git/`.
`vendor/` and `public/build/` **must** be uploaded if the server has no Composer/Node.

A zip is easiest: zip the project folder, upload with *File Manager* to `domains/stock.superwheeltyres.co.za/`, and extract to `swt-app/`.

## 5. Point the web root at `public/`

### 5a. With SSH (preferred)

```bash
cd ~/domains/stock.superwheeltyres.co.za
mv public_html public_html.empty-original
ln -s swt-app/public public_html
```

### 5b. Without SSH (File Manager only)

1. Copy **the contents** of `swt-app/public/` (including the hidden `.htaccess`) into `public_html/`.
2. Edit `public_html/index.php` and change the two paths so they point to the app folder:

```php
if (file_exists($maintenance = __DIR__.'/../swt-app/storage/framework/maintenance.php')) { require $maintenance; }
require __DIR__.'/../swt-app/vendor/autoload.php';
$app = require_once __DIR__.'/../swt-app/bootstrap/app.php';
```

3. After every future upload of a new build, copy `swt-app/public/build/` to `public_html/build/` again.

Either way: `.env`, `vendor/`, `storage/` and source code are **not** reachable from the browser. Test it:
`https://stock.superwheeltyres.co.za/.env` and `/../swt-app/.env` must return 403/404.

## 6. Database

DirectAdmin → *MySQL Management* → **Create new database**: e.g. `swtuser_stock` with user `swtuser_stock`
and a strong generated password. Character set `utf8mb4`.

## 7. Environment file

Copy `swt-app/.env.example` to `swt-app/.env` and set at least:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://stock.superwheeltyres.co.za
APP_KEY=                       # see below
DB_DATABASE=swtuser_stock
DB_USERNAME=swtuser_stock
DB_PASSWORD=…
SESSION_DOMAIN=stock.superwheeltyres.co.za
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=stock.superwheeltyres.co.za
```

`APP_KEY`: with SSH run `php artisan key:generate`. Without SSH generate one locally
(`php artisan key:generate --show`) and paste the `base64:…` value. Keep `.env` permissions at `640`/`600`.

## 8. Permissions

```bash
cd ~/domains/stock.superwheeltyres.co.za/swt-app
chmod -R 775 storage bootstrap/cache
find storage -type f -exec chmod 664 {} \;
chmod 600 .env
```

(Without SSH: set `storage/` and `bootstrap/cache/` to 755/775 in File Manager → *Permissions*.)

## 9. Migrations and first administrator

**With SSH:**

```bash
php artisan migrate --force
php artisan swt:install          # reference data + prompts for the owner account
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

**Without SSH:** put a long random `SETUP_TOKEN=` in `.env`, open `https://stock.superwheeltyres.co.za/setup`,
enter the token and the owner's details. The installer runs the migrations, installs reference data and creates
the owner. Then **delete the `SETUP_TOKEN` line**. The setup page stops working as soon as an owner exists.

The inventory starts **empty**. Do **not** run `DemoSeeder` in production (it refuses to run when `APP_ENV=production`).

## 10. Scheduled tasks (cron)

DirectAdmin → *Advanced Features* → *Cronjobs* → every minute (`* * * * *`):

```
cd /home/swtuser/domains/stock.superwheeltyres.co.za/swt-app && /usr/local/php83/bin/php artisan schedule:run >> /dev/null 2>&1
```

It expires reservations (every 5 min), raises reservation-expiry and stocktake reminders (every 15 min) and
cleans old password-reset tokens. The app also expires reservations whenever the dashboard, sales or
reservations are opened, so availability stays correct even if cron is delayed.

## 11. Caching, logging and maintenance

- After each deploy: `php artisan migrate --force && php artisan optimize` (config/route/view/event cache).
  If you change `.env`, run `php artisan config:clear` (or `optimize` again).
- Logs: `swt-app/storage/logs/laravel-YYYY-MM-DD.log` (daily, kept 30 days, level `warning`).
- Maintenance mode during upgrades: `php artisan down` … `php artisan up`.
- Production error pages show a generic message only (`APP_DEBUG=false`); details go to the log.

## 12. Updating to a new version

1. Build locally (step 4).
2. `php artisan down`
3. Upload and overwrite `app/, bootstrap/, config/, database/, resources/, routes/, vendor/, public/build/`
   (never overwrite `.env` or `storage/`). Without the symlink also copy `public/build` to `public_html/build`.
4. `php artisan migrate --force && php artisan optimize`
5. `php artisan up`

## 13. Backups

See [BACKUP_AND_RESTORE.md](BACKUP_AND_RESTORE.md). At minimum, enable DirectAdmin's own backups and add the
nightly database dump cron from `scripts/backup.sh`.

## 14. Go-live checklist

- [ ] `https://stock.superwheeltyres.co.za` loads the sign-in page over HTTPS; `http://` redirects.
- [ ] `/.env`, `/storage/logs/laravel.log`, `/composer.json` are **not** downloadable.
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `SETUP_TOKEN` removed.
- [ ] Owner can sign in; employees created with their real roles; temporary passwords changed.
- [ ] Business details, VAT setting, locations and brands checked in *Settings*.
- [ ] Opening stock imported or entered, and a first stocktake done to confirm it.
- [ ] Cron job running (Settings → Notifications shows reminders; reservations expire).
- [ ] Backups configured and **one restore tested**.
- [ ] Public website `https://superwheeltyres.co.za` still works exactly as before.
