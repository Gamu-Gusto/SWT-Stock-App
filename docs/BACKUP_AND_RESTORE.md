# Backup and restore

The stock ledger is the business's record of what it owns. Treat backups as essential.

## What to back up

| Item | Where | How often |
|---|---|---|
| Database (all stock, sales, users, audit log) | MySQL/MariaDB `swtuser_stock` | **Nightly**, plus before every upgrade |
| Uploaded files (tyre photos, delivery notes, logo, import files) | `swt-app/storage/app/private/` | Nightly or weekly |
| Configuration | `swt-app/.env` | After every change (store securely – it contains secrets) |
| Application code | Your release zip / git repository | Each release |

## 1. DirectAdmin built-in backups (baseline)

DirectAdmin → *Advanced Features* → *Create/Restore Backups* → schedule a backup of **Databases** and
**Domains** (home directory). Choose a destination that is **not on the same server** if the host offers
FTP/remote backup. Keep at least 7 daily copies.

## 2. Nightly database dump (recommended addition)

`scripts/backup.sh` creates a compressed, timestamped dump **outside the web root** and deletes old copies.

1. Upload `scripts/backup.sh` to `/home/swtuser/swt-backups/backup.sh` and make it executable (`chmod 700`).
2. Create `/home/swtuser/swt-backups/.my.cnf` (permissions `600`) so the password is not on the command line:

```ini
[client]
user=swtuser_stock
password=THE_DATABASE_PASSWORD
host=localhost
```

3. Cron (DirectAdmin → *Cronjobs*), daily at 02:15:

```
15 2 * * * /home/swtuser/swt-backups/backup.sh swtuser_stock /home/swtuser/domains/stock.superwheeltyres.co.za/swt-app >> /home/swtuser/swt-backups/backup.log 2>&1
```

The script keeps 14 daily database dumps and 8 weekly file archives (change `KEEP_DAYS` / `KEEP_WEEKS`).
Backups live in `/home/swtuser/swt-backups/`, which is outside `public_html` and therefore never web-accessible.

### Retention recommendation

- 14 daily database copies on the server,
- 8 weekly copies,
- one monthly copy kept **off the server** (download it, or use the host's remote backup / cloud storage you control),
- keep the copy taken before each upgrade until the next upgrade has been running for a month.

### Secure storage

- Backups contain customer phone numbers and business financials: never e-mail them, never put them in
  `public_html`, and keep off-site copies encrypted (e.g. a password-protected 7-Zip archive).
- Restrict the backup folder to the account user (`chmod 700`).

## 3. Restore procedure

### Database only (e.g. data mistake or corruption)

1. Put the app in maintenance mode: `php artisan down` (or temporarily rename `public_html/index.php`).
2. **Take a fresh dump first**, even of the damaged database, so nothing is lost permanently.
3. Restore:
   ```bash
   gunzip -c /home/swtuser/swt-backups/db/swtuser_stock-2026-10-11_0215.sql.gz | mysql --defaults-extra-file=/home/swtuser/swt-backups/.my.cnf swtuser_stock
   ```
   Without SSH: DirectAdmin → *MySQL Management* → database → *Restore* (upload the `.sql.gz`), or phpMyAdmin → *Import*.
4. `php artisan migrate --force` (applies any newer migrations), then `php artisan optimize:clear`.
5. `php artisan up`, sign in, and check the dashboard totals and the latest stock movements.

Note: everything recorded after the backup time is lost. Use the paper/till records for that period to
re-enter sales and receipts, then do a stocktake of affected sizes.

### Full server loss (disaster recovery)

1. New hosting account with PHP 8.3+ and MySQL/MariaDB (see DEPLOYMENT_DIRECTADMIN.md steps 1–3).
2. Upload the release (code + `vendor/` + `public/build/`).
3. Restore `.env` from your secure copy (same `APP_KEY` – otherwise existing sessions/encrypted values are invalid;
   if lost, generate a new key and everyone simply signs in again).
4. Create the database and import the latest dump (above).
5. Restore `storage/app/private/` from the file archive (photos and attachments).
6. Permissions, cron and SSL as in the deployment guide; `php artisan optimize`.
7. Update DNS for `stock.superwheeltyres.co.za` if the server IP changed.
8. Do a stocktake of fast-moving sizes to confirm the restored figures.

## 4. Test your backups

At least every three months restore the latest dump into a **separate test database** (e.g. `swtuser_stocktest`)
and confirm it imports without errors and the number of products and sales looks right:

```sql
SELECT COUNT(*) FROM products; SELECT COUNT(*) FROM sales; SELECT MAX(created_at) FROM stock_movements;
```
