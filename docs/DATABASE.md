# Database

- Local: `database/migrations/sqlite/*.sql` (applied by `app/Core/Migrator.php`, tracked in `schema_migrations`).
- Server: `server-api/database/migrations/mysql/*.sql` (applied by `server-cli.php migrate`).

## Conventions

| Convention | Rule |
|---|---|
| IDs | UUIDv7 strings (time-ordered, generated on the device). Never auto-increment for cross-device identity. Server `master_changes.id` is an AUTO_INCREMENT used only as a pull cursor. |
| Money | `*_paise` INTEGER/BIGINT (₹1 = 100). No REAL/FLOAT columns for money. |
| Quantity | `*_milli` INTEGER (1 piece = 1000, 2.75 m = 2750). |
| Rates | `*_bp` basis points (5% = 500). |
| Time | `YYYY-MM-DD HH:MM:SS` in the shop time zone (`APP_TIMEZONE`, default Asia/Kolkata). |
| Enums | TEXT validated in application code (status, movement_type, method…). |

## Local tables (SQLite)

- Organisation & access: `stores`, `devices`, `users`, `roles`, `permissions`, `role_permissions`, `login_attempts`
- Catalog: `categories` (with parent), `brands`, `sizes` (size systems), `colors`, `tax_rules`, `products` (family), `product_variants` (SKU: size/colour/pattern/design/batch, barcode, MRP, price, cost), `product_barcodes` (alternate/supplier codes), `price_history`
- Inventory: `locations` (MAIN, DAMAGED), `stock_balances` (qty + moving average per variant/location), `stock_movements` (append-only), `stock_adjustments`, `stock_adjustment_items`
- Purchasing: `suppliers`, `purchases` (draft/posted/cancelled), `purchase_items` (incl. landed cost), `supplier_returns`, `supplier_return_items`
- Sales: `doc_sequences` (per device/series/FY numbering), `sales`, `sale_items` (cost_at_sale snapshot, returned qty), `payments`, `held_sales`, `print_logs`
- Returns: `returns` (credit notes; type return/exchange/void), `return_items` (linked to `sale_items`), `refunds`, `exchanges`
- Customers & money: `customers`, `customer_receipts`, `cash_sessions`, `cash_movements`, `expense_categories`, `expenses`
- System: `settings`, `audit_logs` (append-only), `sync_outbox` (immutable payloads), `sync_receipts`, `sync_runs`, `sync_conflicts`

Key constraints: unique SKU and barcode; unique (product, size, colour, pattern, design, batch) expression index; unique `(store_id, invoice_no)`;
unique `sales.client_ref`; one open cash session per device (partial unique index); unique non-null customer phone;
unique supplier invoice per supplier (partial index excluding cancelled); CHECKs for positive quantities and price ≤ MRP.

## Server tables (MySQL)

`stores`, `devices` (sha-256 token hash), `sync_receipts` (event_id PK = idempotency; stores payload + hash), `customers`, `products`, `product_variants`,
`tax_rules`, `suppliers`, `sales`, `sale_items`, `payments`, `returns`, `return_items`, `refunds`, `exchanges`, `purchases`, `purchase_items`,
`stock_movements`, `stock_balances` (mirror), `documents` (expenses, cash sessions, adjustments, supplier returns, receipts — full JSON),
`conflicts`, `master_changes`, `rate_limits`, `schema_migrations`. InnoDB, utf8mb4.

## SQLite vs MySQL differences

| Topic | SQLite (local) | MySQL (server) |
|---|---|---|
| Upsert | `ON CONFLICT … DO UPDATE` | `ON DUPLICATE KEY UPDATE` (handled in `Server\Db::upsert`) |
| Append-only | Triggers on `stock_movements`, `audit_logs`, `sync_outbox` | Enforced by the API (no update paths) |
| Expression/partial indexes | Used | Not needed (server receives already-validated data) |
| Transactions | `BEGIN IMMEDIATE`, WAL, `synchronous=FULL` | InnoDB, strict SQL mode |
| Test mode | — | The same migration runs on SQLite for automated tests (MySQL table options stripped) |

The server schema was verified against MariaDB 10.4 (all sync tests pass with `TEST_SERVER_DB=mysql`).
