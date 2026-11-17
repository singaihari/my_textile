# Architecture

## Two applications

| | Shop PC (this folder) | Online server (`server-api/`) |
|---|---|---|
| Purpose | All billing, stock, reports — fully offline | Receives uploaded events; publishes approved price/tax changes |
| Stack | Core PHP 8.1+, SQLite (WAL) via PDO | Core PHP 8.1+, MySQL/MariaDB via PDO |
| Web root | `public/` | `server-api/public/` |
| Talks to the other | Only when a user clicks **Sync Now** | Never initiates contact |

## Request flow (shop app)

`public/index.php` → `app/bootstrap.php` (autoload, `.env`, timezone, error handler) → apply a staged restore if present →
open/auto-create SQLite (pending migrations on an existing DB show a maintenance page) → redirect to `/setup` if there are no users →
`Router` (CSRF check for every non-GET, `Auth::requireLogin`, per-route permission) → controller → `Response`.
Errors: `ValidationException`/`InvalidArgumentException` → 422 JSON or flash + redirect; `HttpException` 401/403/404/419/423; anything else → logged with a reference id, generic message.

## Modules

| Module | Code |
|---|---|
| Money & quantity maths | `app/Core/Money.php`, `app/Core/Qty.php` |
| Auth, roles, approvals, lock, rate limiting | `app/Core/Auth.php`, `app/Core/Seeder.php` (permissions/roles) |
| Catalog, variants, barcodes, price history | `app/Services/CatalogService.php`, `Barcode.php`, `CsvImportService.php` |
| Pricing, discounts, GST | `app/Services/PricingService.php` |
| Checkout, void, reprint log | `app/Services/SaleService.php` |
| Stock movements & costing | `app/Services/InventoryService.php` |
| Returns / exchanges / credit notes | `app/Services/ReturnService.php` |
| Purchasing, supplier returns | `app/Services/PurchaseService.php` |
| Customers, credit dues, privacy | `app/Services/CustomerService.php` |
| Cash counter, expenses | `CashService.php`, `ExpenseService.php` |
| Reports & profit | `app/Services/ReportService.php` |
| Outbox + manual sync client | `OutboxService.php`, `Services/Sync/*` |
| Backup / restore | `BackupService.php` |
| POS UI | `app/Views/pages/pos.php`, `public/assets/js/pos.js`, `css/pos.css` |

## Transactions

Every business operation is one `Database::transaction()` (`BEGIN IMMEDIATE`, so the write lock is taken up front).
A completed bill writes, atomically: `sales`, `sale_items` (with `cost_at_sale_paise`), `payments`, `stock_movements` + `stock_balances`,
`doc_sequences` (invoice number), `sync_outbox` event, `audit_logs`. If any step fails (e.g. insufficient stock) nothing is written and no invoice number is consumed.
The POS sends a `client_ref` per bill attempt; re-submitting returns the already-saved bill (double-click / retry safety, also enforced by a UNIQUE column).

## Money, quantities, tax

- Money: integer paise everywhere (PHP and the POS JavaScript). Rounding is half-up via integer division.
- Quantities: integer milli-units. Pieces/sets must be whole; metres allow up to 3 decimals (configurable).
- Line maths order: gross → item discount → bill discount allocated by largest remainder → net → tax (inclusive: `taxable = net×10000/(10000+rate)`; exclusive: `tax = net×rate/10000`) → CGST = ⌈tax/2⌉, SGST = rest, or IGST → optional rupee round-off on the bill.
- Tax rules: base rate, plus optional per-unit threshold and higher rate. The threshold is compared with the per-unit taxable value at the base rate (documented edge case: inclusive prices just above a threshold). Verify configuration with an accountant.
- The POS asks the server for quotes (`/api/pos/quote`) so the screen and the saved bill always use the same code; the save also checks `expected_total_paise`.

## Inventory costing

Moving weighted-average cost per variant and location. Purchases post at landed cost (unit cost + freight allocated by value, + input GST only when not claimable).
Sales snapshot the current average into `sale_items.cost_at_sale_paise`; returns re-enter stock at that snapshot cost, so editing a cost later never changes historical profit.
`stock_movements` and `audit_logs` are append-only (SQLite triggers abort UPDATE/DELETE). Negative stock is blocked unless the user has `pos.oversell` **and** confirms per bill (audited).

## Security

`password_hash`/`password_verify` (bcrypt, auto-rehash); session cookie HttpOnly + SameSite=Strict (+Secure on HTTPS), id regenerated on login;
CSRF token on every form and JSON POST; login lockout (5 failures per user / 15 min); inactivity lock enforced server-side (423) with an in-place unlock on the POS;
manager approval tokens (one-time, 5 min) for discounts above the role ceiling, refunds, voids, credit sales; server-side permission checks on every route and inside services;
all SQL parameterised; output escaped with `e()`; strict CSP (no inline scripts, no external origins); `.htaccess` deny for project root and `storage/`;
secrets never in code (sync token is entered by an admin and shown masked).

## UI

Server-rendered PHP views + small vanilla JS files; design tokens in `public/assets/css/app.css` (Inter + Noto Sans Tamil bundled locally, Chart.js 4.4.1 bundled).
Respects `prefers-reduced-motion`; visible focus rings; modals trap Tab but Esc always closes; the POS never clears a bill on Esc or refresh (draft kept in localStorage as a convenience).
