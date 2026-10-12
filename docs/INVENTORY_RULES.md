# How stock is calculated – inventory rules and design decisions

This document explains exactly how the system decides how many tyres there are, what can be sold,
and what the stock is worth. All of it is enforced on the server; the browser never calculates
authoritative quantities.

## 1. Catalogue versus stock

- **Products** (`products` + `tyre_specs`) are catalogue records: brand, model, size, load/speed,
  condition, prices and stock settings. One record per unique combination of brand + model + size +
  load index + speed rating + run-flat + **condition**. A unique `variant_key` makes it impossible to
  merge new and used tyres, or two different specifications, into one record.
- **Stock** is held separately:
  - `stock_balances` – physical quantity per product **per storage location**;
  - `tyre_units` – one row per individually identified used tyre;
  - `stock_lots` – FIFO cost layers;
  - `stock_movements` – the permanent ledger.
- `products.qty_on_hand / qty_reserved / qty_unsellable` are **cached totals** for fast search. They are
  recalculated from the balances, units and reservations inside every stock transaction and never
  edited directly (they are not mass-assignable).

### Future product categories

`product_categories` already contains *Tyres* (active) and *Mag wheels, Batteries, Accessories*
(inactive). Everything in the stock engine (balances, lots, movements, sales, reservations,
stocktakes) works on `products` and does not depend on `tyre_specs`. A new category needs only its own
specification table (like `tyre_specs`) and its form/search fields; tyre search is unaffected.

## 2. The quantities

| Quantity | Meaning |
|---|---|
| **Physical** | Tyres on the premises, in any location (`qty_on_hand + qty_unsellable`). |
| **On hand (sellable)** | Physical tyres in *sellable* locations; for used tyres only units with status *available* or *reserved*. |
| **Unsellable** | Physical but not for sale: quarantine (returns awaiting inspection), damaged-stock area, used tyres awaiting inspection or rejected. |
| **Reserved** | Outstanding quantities on *active* reservations. |
| **Available** | `On hand − Reserved` – what may be sold or reserved right now. |

Stock status uses **available** quantity: *Out of stock* at 0; *Low stock* when `0 < available ≤ minimum
stock level` (only when a minimum is set); otherwise *In stock*.

## 3. Invariants (enforced in code and, on MySQL/MariaDB, in the database)

1. Physical quantity can never be negative – balances are `UNSIGNED` and every decrease is checked under a row lock.
2. Available quantity can never be negative – `reserved ≤ on hand` is checked in `StockLedger::refreshProductTotals`
   after every change and by a `CHECK (qty_reserved <= qty_on_hand)` constraint.
3. A completed transaction is never applied twice – receipts, sales, transfers, reservations, quotations,
   returns and adjustments carry an **idempotency key** (unique index) generated per form, and every
   posting checks the document status while holding a row lock (`already_posted`, `already_completed`).
4. Every manual adjustment has a reason, a requester, a different approver (the owner may self-approve),
   an immutable movement and an audit-log entry.

## 4. The ledger (`stock_movements`)

`App\Services\Inventory\StockLedger` is the **only** code that changes stock. For every change it:

1. requires an open database transaction;
2. locks the affected **product rows first** (`SELECT … FOR UPDATE`, in ascending id order to avoid deadlocks),
   then the location balance row;
3. applies the stocktake locking policy (section 7);
4. rejects any result below zero;
5. writes an immutable movement: type, product/unit, location, previous qty, change, resulting qty,
   from/to location, reference (document number), reason, employee, approver, unit and total cost;
6. creates or consumes FIFO cost layers;
7. recalculates the cached product totals and enforces reserved ≤ on hand;
8. raises or resolves low-stock / out-of-stock notifications.

Movement types: Opening Balance, Stock Received, Receipt Reversal, Sale, Sale Void, Customer Return,
Supplier Return, Manual Adjustment, Stocktake Adjustment, Stock Transfer, Damaged Stock, Write Off.

Movements and audit-log entries cannot be updated or deleted through the application (model guards
throw an exception, and there are no edit/delete endpoints). Corrections are always new movements.

Because every concurrent operation on the same tyre waits for the product row lock, two employees
selling or reserving the last tyre at the same moment cannot both succeed – the second receives a clear
"insufficient stock" message. This is covered by a multi-process test (`tests/Feature/ConcurrencyTest.php`).

## 5. Business rules per transaction

| Transaction | Effect |
|---|---|
| Receipt (posted) | + physical at the chosen location; new FIFO lot at the purchase cost; product cost price = latest cost. Drafts change nothing. Posted receipts cannot be edited – **reverse** them. |
| Sale (completed) | − physical. Location: chosen, else the usual location first, then where most stock is. Cost of sale = FIFO layers consumed (used tyres: the unit's own cost). Drafts change nothing. |
| Reservation | Reduces available only. Expires automatically (default 24 h, configurable). Selling a reservation consumes it first, so stock is deducted once; any unsold remainder is released. |
| Sale void | Same-day error correction: tyres go back to the locations they came from with their original cost layers. |
| Customer return | Tyres go to the **quarantine** location (physical, not sellable). Inspection decides: restock (transfer to a sellable location), damaged (to damaged-stock location) or write off. |
| Transfer | − at source and + at destination in one transaction; cost layers untouched. |
| Damaged stock | Moved to the damaged-stock location (no longer sellable). Disposition later: write off or supplier return. |
| Write off / supplier return | − physical; cost layers consumed (FIFO). Requires approval. |
| Stocktake adjustment | Only after a manager approves the difference (section 7). |
| Import | Creates products and **Opening Balance** movements. Existing stock is never overwritten: duplicates are skipped or added. |

## 6. Valuation (FIFO)

- **New tyres:** first in, first out. Each receipt/opening balance creates a cost layer
  (`stock_lots`). Outgoing movements consume the oldest layers first and record exactly which layers were
  used (`stock_lot_allocations`), so each sale's cost and gross margin use historical costs.
  Stock value = Σ remaining quantity × layer cost.
- **Used tyres:** specific identification – each tyre unit carries its own cost price.
- Stock gains from stocktakes are costed at the weighted average of remaining layers (or the product's
  standard cost if none remain). Returns re-create the cost of the sale they came from.
- Transfers never change value.

Money is stored as `DECIMAL` and calculated in **integer cents** (`App\Support\Money`) – never floating point.
VAT (when the business is VAT-registered) is calculated on the discounted total: extracted from
VAT-inclusive prices (`total × rate / (100 + rate)`) or added to exclusive prices, rounded half-up to the cent.

## 7. Stocktakes

Lifecycle: **Draft → In progress → Submitted → Under review → Approved → Closed** (or Cancelled).

1. *Start* takes a snapshot: one line per product/location with stock, and one line per physical used tyre, within the scope
   (full, brands, size, location, used tyres; optionally limited to one location).
2. *Count* records the physical quantity. Each count also stores the **system quantity at the moment of
   counting**; `variance = counted − system quantity at count` (negative = shortage, positive = excess).
   Counts never change stock. Counting the same line again requires explicit confirmation, and every count
   is kept in `stocktake_counts`.
3. *Blind counting* (default) hides expected quantities – and even the number of differing lines – from
   counters. Only users with approval rights, or non-blind counts with *view expected* permission, see them.
4. *Submit* requires every line to be counted, or explicit confirmation that uncounted lines were not found (zero).
5. *Review*: the manager sees system vs counted, difference, cost impact, movements that happened during the
   count, who counted and when; can request recounts of selected lines (the stocktake re-opens for counters).
6. *Approve*: each difference is approved (reason required) or rejected. Approved differences are posted as
   **Stocktake Adjustment** movements against the current balance; the stocktake number is the adjustment
   reference. A manager who counted in the session cannot approve it (the owner may).

### Locking policy (Settings → Stock rules)

- **Pause stock transactions (default, "block")** – while a stocktake is *in progress*, sales, receipts,
  transfers and adjustments touching a product/location inside its scope are refused with a message naming
  the stocktake. Reservations are still allowed (they do not move stock). The lock lifts when the count is
  submitted (and re-applies if a recount is requested). The product page and stocktake page display the lock.
- **Allow and track ("track")** – trading continues. Because each count stores the live system quantity at
  that moment, a sale made before a tyre was counted is not mistaken for a shortage, and the review lists the
  net movements made during the count. Sales or receipts are never silently ignored.

## 8. Used tyres

Each used tyre is a `tyre_unit` with its own code (`UT-YYYY-NNNNNN`), tread depth, grade, DOT code, defects,
photos, cost and price. Statuses: awaiting inspection, available, reserved, sold, rejected, written off,
returned to supplier, missing. Only *available* units in sellable locations can be reserved or sold. Grades
(A/B/C by default, configurable) are internal stock classifications – the interface states that they do not
replace a safety inspection. Users without inspection permission can only register tyres as *awaiting inspection*.
