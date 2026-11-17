# Textile POS — offline-first billing & inventory for textile / garment shops

Core PHP (no framework) + SQLite on the shop PC, with an optional separate Core PHP + MySQL API for **manual** cloud sync.
Keyboard-first POS for USB barcode scanners, piece- and metre-wise goods, size/colour variants, GST-ready invoices
(80 mm and A4), returns/exchanges, purchasing, stock with weighted-average costing, reports and backups.

> Billing never needs the internet. Every bill is committed to SQLite in one transaction (sale, items, payments,
> stock movements and a sync outbox event) **before** printing. Nothing is uploaded until an authorised user clicks **Sync Now**.

## Quick start (Windows, no XAMPP needed)

Requirements: PHP 8.1+ (8.2/8.3 recommended) with `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, and `curl` (curl is only used for Sync).

```powershell
cd C:\path\to\textile
powershell -ExecutionPolicy Bypass -File scripts\setup-windows.ps1   # checks PHP extensions, creates .env, runs migrations
php scripts\create-admin.php                                          # creates the shop + first admin (asks name, username, password)
powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1       # DEV server on http://127.0.0.1:8000
```

Open http://127.0.0.1:8000 → **login page** → sign in with the username/password you just created.
There is no setup web page and **no default password**. Add `--demo` to `create-admin.php` to load fictional training data (every screen then shows a DEMO ribbon).

The PHP built-in server (`php -S 127.0.0.1:8000 -t public public/router.php`) is for development and trials only.
For daily shop use run Apache or Nginx + PHP as a Windows service: see [docs/DEPLOY_WINDOWS.md](docs/DEPLOY_WINDOWS.md).

## Everyday use

| Task | Where |
|---|---|
| Billing | **POS** (`F2` from anywhere). Scan → scan → `F6` → type cash → `F10` → `Enter` for next bill |
| Shortcuts | `F1` on the POS, or [docs/KEYBOARD_SHORTCUTS.md](docs/KEYBOARD_SHORTCUTS.md) |
| Reprint | Invoices → open bill → `Ctrl+P` (logged; never creates a new bill) |
| Return / exchange | `F9` or Returns → scan the receipt barcode |
| Stock in | Purchases → New purchase → scan items → *Save & post* |
| Day end | Cash counter → Close (expected vs counted) |
| Upload to cloud | Sync Center → **Sync Now** (manual only) |
| Backup | Backup & restore → *Create backup now*; schedule `php scripts\backup.php` (see deploy doc) |

## Commands

```powershell
php scripts\run-migrations.php          # apply DB migrations (takes a verified backup first)
php scripts\create-admin.php [--demo]   # first-run: shop + admin; later: add/reset an admin
php scripts\seed-demo.php               # load fictional demo data into an EMPTY shop
php scripts\seed-catalog.php            # add the 50-product starter catalog with illustrations + opening stock (re-run safe)
php scripts\backup.php [tag]            # online backup (safe while the app runs)
php scripts\restore.php <file>          # restore (stop the web server first)
php scripts\sync-now.php <username>     # manual sync from the command line (asks to confirm)
php scripts\lint.php                    # php -l on every PHP file
php tests\run.php [filter]              # automated tests (no dependencies)
```

## Repository layout

```
app/            Core (router, DB, auth, CSRF, money/qty), Controllers, Services, Views, Lang
public/         web root: index.php, router.php (dev), assets (CSS/JS/fonts/icons/Chart.js — all local)
database/       migrations/sqlite, seeders/DemoSeeder.php
storage/        sqlite DB, backups, logs, sessions (NOT web-accessible)
server-api/     separate online API: app/, public/index.php, database/migrations/mysql, scripts/server-cli.php
scripts/        setup/start PowerShell scripts, migrations, backup/restore, admin, demo, sync
tests/          tests/run.php + Unit, Integration (incl. sync against the real server kernel), E2E (HTTP + browser)
docs/           architecture, database, API, sync, deployment, testing, shortcuts
```

## Documentation

- [ARCHITECTURE](docs/ARCHITECTURE.md) — modules, request flow, transactions, costing, security
- [DATABASE](docs/DATABASE.md) — schema, money/quantity conventions, SQLite vs MySQL differences
- [OFFLINE_SYNC](docs/OFFLINE_SYNC.md) — outbox, manual sync protocol, idempotency, conflicts, multi-counter limits
- [API](docs/API.md) — server endpoints
- [DEPLOY_WINDOWS](docs/DEPLOY_WINDOWS.md) — shop PC production setup, printer, scanner, backups, troubleshooting
- [DEPLOY_SERVER](docs/DEPLOY_SERVER.md) — MySQL API hosting, device registration, HTTPS
- [TESTING](docs/TESTING.md) — what is tested and how to run it
- [KEYBOARD_SHORTCUTS](docs/KEYBOARD_SHORTCUTS.md)

## Important limitations (read before going live)

- **Tax rules are examples.** GST rates, slab thresholds and HSN codes must be verified by your accountant (Masters → Tax rules). The software makes no legal-compliance claim.
- **One billing counter per shop** is the supported configuration. Several counters billing offline against one shared stock figure is **not** supported yet (see OFFLINE_SYNC.md for the migration path).
- Browsers always show a print dialog; truly silent printing needs a kiosk-mode browser or a separate print bridge (not included).
- There is no packaged `.exe` installer; the app is PHP files plus PowerShell helper scripts.
- Tamil translations cover navigation and the POS; they should be reviewed by a native speaker.
