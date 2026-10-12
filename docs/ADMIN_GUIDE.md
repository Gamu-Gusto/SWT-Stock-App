# Administrator guide (owner and managers)

## First day checklist

1. **Sign in** with the owner account created during installation.
2. **Settings → Business and branding:** check name, phone, WhatsApp number (`+27764544272` format), address,
   e-mail. The Super Wheels and Tyres logo and its gold colour are built in; upload a file only if the logo
   changes (square PNG/JPG/WebP, max 2 MB). If you change the brand colour, button text automatically
   switches between dark and white so it stays readable.
3. **Settings → Tax and sales:** tick *VAT registered* **only** if the business is registered; enter the VAT
   number, rate (15.00) and whether selling prices include VAT. Set the reservation period (default 24 hours)
   and quotation validity.
4. **Settings → Storage locations:** the defaults are Main Store, Rack A/B/C, Workshop Area, Used Tyre Section,
   Returns & Inspection (quarantine) and Damaged Stock. Rename or add racks to match the building. Keep exactly one
   active *quarantine* and one *damaged* location – returns and damaged stock need them.
5. **Settings → Brands and models:** the common brands are pre-loaded (Falken marked as preferred – it appears
   first in search results). Add any others.
6. **Suppliers:** add the distributors you buy from.
7. **Users:** create an account for each employee with the right role (below). A temporary password appears once –
   give it to the employee privately; they must change it at first sign-in.
8. **Opening stock:** either *Stock import* (download the template, fill it in, preview, import) or add each tyre with
   an opening quantity. Used tyres are added one by one (tread, grade, DOT, price).
9. **First stocktake:** count each rack once to confirm the opening figures.
10. Confirm the **cron job** and **backups** are set up (see the deployment and backup guides).

## Roles

| Role | Can do |
|---|---|
| **Owner / Super Admin** | Everything, always. Only the owner can create other owners or change role permissions and settings. At least one active owner must exist. |
| **Manager** | All operations, approvals (stocktakes, adjustments), voids, returns, reports, user list and audit log. Financial details (cost prices, margins, stock value, valuation report) are **off by default** – the owner can switch them on. |
| **Inventory Clerk** | Search stock, add/edit tyre records (not costs unless granted), receive and post deliveries, transfers, request adjustments, inspect used tyres, count stock (non-blind counts show expected quantities). Cannot approve adjustments. |
| **Sales Employee** | Search stock, see selling prices, reserve tyres, record sales, create quotations, see their own sales. Never sees supplier costs. |
| **Stock Counter** | Only the stocktakes assigned to them: find lines, record counts, submit. Blind counts hide the expected quantity. |

Change what any role (except owner) may do under **Users → Roles and permissions**. Changes apply immediately
and are recorded in the audit log. Examples: give managers *View cost prices, margins and inventory value* and
*Inventory valuation report*; give a senior sales employee *Sell below the minimum approved price*.

## Daily and weekly duties

- **Approvals** (dashboard → *Waiting on someone*): stock adjustments and submitted stocktakes. You cannot approve
  your own adjustment or a count you took part in (the owner can).
- **Returns to inspect** (Sales → Returns to inspect): decide restock / damaged / write-off for returned tyres.
- **Used tyres awaiting inspection:** product page → filter *Awaiting inspection*.
- **Notifications:** low stock, out of stock, reservations about to expire, stocktakes waiting.
- **Reports** weekly: low stock (reorder), slow-moving stock, stock movements, sales by brand/size; monthly:
  inventory valuation and stocktake variances.

## Corrections – never edit history

| Mistake | Fix |
|---|---|
| Delivery captured wrongly (posted) | Receiving → open receipt → **Reverse** (reason required) → capture it again. |
| Sale captured in error, tyres never left | Sale → **Void** (reason required). |
| Customer brings tyres back | Sale → **Customer return** → then inspect under *Returns to inspect*. |
| Stock figure wrong | Stock movements → Adjustments → request a correction with a reason → another manager approves. Better: do a stocktake. |
| Tyre damaged in storage | Adjustments → *Damaged stock* (moves it out of sellable stock), later *Write off* or *Return to supplier*. |

## Account security

- Deactivate leavers immediately (Users → Deactivate) – they are signed out at once. Accounts are never deleted,
  so their history stays traceable.
- *Reset password* creates a new temporary password and signs the employee out everywhere.
- Sign-in is limited to 5 attempts per minute per account and address. Idle sessions end after 120 minutes.
- The audit log records sign-ins, failed sign-ins, product changes, receipts, sales, reservations, adjustments,
  stocktake approvals, permission and settings changes – with the employee, time, IP address and before/after values.
  Passwords and secrets are never logged.

## Stocktake policy

Settings → Stock rules → *While a stocktake is being counted*:
- **Pause stock transactions** (recommended): count when the shop is quiet; sales of the tyres being counted are
  refused until the counter submits. Best for full counts.
- **Allow and track**: for spot checks during trading; differences account for sales made during the count.
