# API documentation

There are two APIs:

1. **Private application API** – `/api/v1/*`, used only by the stock system itself (same origin). It is not
   intended for third parties and requires a signed-in session.
2. **Public availability API** – `/api/public/tyres/availability`, an optional, read-only endpoint for the
   public website. **Disabled by default.**

## Conventions (private API)

- Authentication: Laravel session cookie (Sanctum, `web` guard). Browser clients send the `XSRF-TOKEN`
  cookie value as the `X-XSRF-TOKEN` header on every state-changing request (CSRF protection).
- Requests and responses are JSON (`Accept: application/json`). Money is a decimal string (`"1350.00"`),
  dates are ISO 8601 in Africa/Johannesburg time.
- Authorisation is checked on every endpoint (`can:<permission>` route middleware plus policies).
- Errors:

| Status | `code` | Meaning |
|---|---|---|
| 401 | `unauthenticated` / `account_inactive` | Not signed in / session expired / deactivated. |
| 403 | `forbidden`, `self_approval`, `below_minimum_price`, `password_change_required` | Not allowed. |
| 404 | `not_found` | Record does not exist. |
| 409 | `insufficient_stock`, `already_posted`, `already_completed`, `duplicate_reference`, `duplicate_count`, `duplicate_product`, `reserved_exceeds_stock`, `invalid_state` | Business rule prevented the change; nothing was saved. |
| 422 | (validation) `errors: { field: [message] }`, or a business `code` such as `uncounted_lines` | Fix the input. |
| 423 | `stocktake_locked` | The stock is being counted (block mode). `context.stocktake_no` names the stocktake. |
| 429 | – | Rate limited (login: 5/min per e-mail+IP; API: 240/min per user). |

Example business error:

```json
{ "message": "Insufficient stock for Falken Ziex ZE310 205/55 R16: requested 8, only 7 available.",
  "code": "insufficient_stock", "context": { "requested": 8, "available": 7 } }
```

- **Idempotency:** `POST /sales`, `/receipts`, `/reservations`, `/quotations`, `/transfers`, `/adjustments`
  and `/sales/{id}/returns` accept `idempotency_key` (≤ 64 chars). Re-sending the same key returns the original
  record instead of creating a second one.

## Endpoint overview (private API, prefix `/api/v1`)

| Area | Endpoints |
|---|---|
| Auth | `POST auth/login`, `POST auth/logout`, `GET auth/me`, `PUT auth/password`, `PUT auth/profile`, `POST auth/forgot-password`, `POST auth/reset-password` |
| Reference data | `GET lookups` (settings, brands+models, locations, suppliers, employees, service codes) |
| Dashboard | `GET dashboard` |
| Inventory | `GET products` (search: `q, width, profile, rim, brand_id, condition, location_id, min_available, in_stock, stock_status, price_min, price_max, sort, per_page`), `GET/POST/PUT products/{id}`, `POST products/{id}/archive|restore|images`, `GET products/{id}/movements|units` |
| Used tyres | `POST products/{id}/units`, `GET units/lookup?code=`, `GET/PUT units/{id}`, `POST units/{id}/inspect|images` |
| Catalogue | `GET/POST brands`, `PUT brands/{id}`, `POST brands/{id}/models`, `PUT models/{id}`, `GET/POST locations`, `PUT locations/{id}` |
| Suppliers | `GET/POST suppliers`, `GET/PUT suppliers/{id}` |
| Receiving | `GET/POST receipts` (`post: true` posts immediately), `GET/PUT/DELETE receipts/{id}`, `POST receipts/{id}/post|reverse|attachments` |
| Sales | `GET/POST sales` (`status: draft|completed`), `GET/PUT/DELETE sales/{id}`, `POST sales/{id}/complete|void|returns`, `GET returns/pending`, `POST return-items/{id}/inspect` |
| Reservations | `GET/POST reservations`, `GET reservations/{id}`, `POST reservations/{id}/cancel|extend` |
| Quotations | `GET/POST quotations`, `GET quotations/{id}` (includes `whatsapp_url`), `POST quotations/{id}/cancel|reservation|sale` |
| Stock | `GET movements`, `GET/POST transfers`, `GET/POST adjustments`, `POST adjustments/{id}/approve|reject` |
| Stocktakes | `GET/POST stocktakes`, `GET stocktakes/{id}`, `GET stocktakes/{id}/items|products`, `POST stocktakes/{id}/start|count|submit|review|recount|approve|close|cancel`, `PUT stocktakes/{id}/assignees` |
| Reports | `GET reports`, `GET reports/{key}`, `GET reports/{key}/export?format=csv|xlsx` – keys: `current_stock, stock_by_size, stock_by_brand, low_stock, out_of_stock, stock_movements, sales, stocktake_variances, inventory_valuation, slow_moving` |
| Import | `GET imports/template`, `GET/POST imports`, `GET imports/{id}`, `POST imports/{id}/preview|commit` |
| Notifications | `GET notifications`, `GET notifications/summary`, `POST notifications/{id}/read`, `POST notifications/read-all` |
| Administration | `GET/POST users`, `GET/PUT users/{id}`, `POST users/{id}/activate|deactivate|reset-password`, `GET roles`, `PUT roles/{id}`, `GET audit-logs`, `GET/PUT settings`, `POST/DELETE settings/logo` |
| Files | `GET media/{id}` (authorised download), `DELETE media/{id}` |

Supporting non-API routes: `GET /branding` (name, colour, logo URL for the sign-in page), `GET /branding/logo`,
`GET|POST /setup` (one-time installer), `GET /up` (health check).

---

# Public tyre availability API

```
GET https://stock.superwheeltyres.co.za/api/public/tyres/availability
```

Enable it in **Settings → Public website API**. Until then every request returns `404`.

### Query parameters

| Parameter | Type | Notes |
|---|---|---|
| `width` | integer 100–455 | e.g. `205` – at least `width` or `rim` is required |
| `profile` | integer 20–95 | e.g. `55` |
| `rim` | number 8–30 | e.g. `16` or `17.5` |
| `brand` | string | exact brand name, e.g. `Falken` |
| `condition` | `new` / `used` | |
| `quantity` | integer 1–20 | how many the customer needs (default 1) |
| `type` | string | tyre type key, e.g. `suv_4x4` |

These match the search fields on the current website (width, profile, rim size, quantity, tyre type).

### Response

```json
{
  "data": [
    {
      "brand": "Falken", "model": "Ziex ZE310", "size": "205/55 R16",
      "width": 205, "profile": 55, "rim": "16", "load_index": "91", "speed_rating": "V",
      "condition": "new",
      "availability": "in_stock",
      "available_quantity": 12,
      "price": "1350.00", "currency": "ZAR"
    }
  ],
  "meta": { "count": 1, "generated_at": "2026-10-11T10:15:00+02:00", "note": "Availability is indicative; confirm with the store before visiting." }
}
```

- `availability`: `in_stock`, `limited` (at or below the minimum level) or `insufficient` (fewer than `quantity`).
- `available_quantity` appears **only** when visibility is set to *Exact quantities*. Default: status only (recommended).
- `price`/`currency` appear only when *Include selling prices* is enabled.
- Only active, public, in-stock tyres of active brands are listed. Costs, margins, suppliers, employees,
  movements and audit data are **never** included.
- Rate limit: 60 requests per minute per IP. Responses may be cached for 60 seconds.
- CORS: only origins listed in *Allowed website origins* (default `https://superwheeltyres.co.za`) may call it from a browser.

### Integrating with the existing website (when you are ready)

The live website is not modified by this project. When the owner decides to show availability:

1. In the stock system: Settings → Public website API → enable, choose *status only*, decide on prices,
   confirm the allowed origin `https://superwheeltyres.co.za` (add `https://www.superwheeltyres.co.za` if used).
2. Test the endpoint in a browser, e.g.
   `https://stock.superwheeltyres.co.za/api/public/tyres/availability?width=205&profile=55&rim=16`.
3. On a **staging copy** of the website, add a small script to the tyre search form. Example:

```html
<div id="swt-availability"></div>
<script>
async function swtCheck(width, profile, rim, quantity) {
  const qs = new URLSearchParams({ width, profile, rim, quantity: quantity || 1 });
  const res = await fetch('https://stock.superwheeltyres.co.za/api/public/tyres/availability?' + qs);
  const box = document.getElementById('swt-availability');
  if (!res.ok) { box.textContent = 'Please WhatsApp us on +27 76 454 4272 to check stock.'; return; }
  const { data } = await res.json();
  box.innerHTML = data.length
    ? data.map(t => `<p>${t.brand} ${t.model} ${t.size} (${t.condition}) – ${t.availability === 'in_stock' ? 'In stock' : t.availability === 'limited' ? 'Limited stock' : 'Not enough in stock'}${t.price ? ' – R ' + t.price : ''}</p>`).join('')
    : 'Not in stock right now – WhatsApp us on +27 76 454 4272 and we will source it.';
}
</script>
```

   If the site is WordPress, place the script in a *Custom HTML* block or a code-snippets plugin on the tyre search page.
   (Server-side fetching from PHP also works and needs no CORS.)
4. Test on staging with real searches, then publish. To switch it off at any time, disable the API in Settings –
   the website then falls back to its WhatsApp message.
