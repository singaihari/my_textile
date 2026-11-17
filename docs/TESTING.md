# Testing

## Commands

```powershell
php scripts\lint.php                         # php -l on every PHP file
php tests\run.php                            # unit + integration tests (fresh SQLite DB per test; no dependencies)
php tests\run.php Sync                       # filter by name

# Sync tests against a real MySQL/MariaDB (database is WIPED per test):
$env:TEST_SERVER_DB='mysql'; $env:TEST_MYSQL_PORT='3307'; $env:TEST_MYSQL_DB='tpos_test'; php tests\run.php Sync

# HTTP smoke test (needs a FRESH database):
$env:DB_PATH='storage/tmp/smoke.sqlite'; php -S 127.0.0.1:8099 -t public public/router.php   # in one window
php tests\E2E\http_smoke.php http://127.0.0.1:8099                                           # in another

# Browser keyboard test (installed Chrome/Edge; FRESH database):
$env:DB_PATH='storage/tmp/e2e.sqlite'; php -S 127.0.0.1:8098 -t public public/router.php
cd tests\E2E; npm install; $env:BASE='http://127.0.0.1:8098'; node pos_keyboard.test.mjs
```

## Results at hand-off (executed on Windows 11, PHP 8.2.12, SQLite via pdo_sqlite, Chrome headless)

| Suite | Result |
|---|---|
| Lint | all PHP files: 0 errors |
| `tests/run.php` | 45 tests, all passing |
| Sync tests on MariaDB 10.4.32 | 7/7 passing |
| HTTP smoke (fresh DB, no setup page, CLI admin + demo seed, every page + all 20 reports + CSV, POS APIs, permissions, CSRF, lockout) | all checks passed |
| Browser keyboard test (Scenario J, 1366×768 and 1920×1080) | 25/25 checks passed, no JS errors |
| Real HTTP sync (curl) to local mock API | 316 events uploaded; 249 bills on server, each exactly once; second click uploaded 0 |

## Coverage map

| Requirement | Test |
|---|---|
| Money/qty without floats, rounding, allocation, UUIDv7 | `Unit/MoneyTest` |
| Inclusive/exclusive GST, CGST/SGST/IGST, slabs, discounts, metre maths | `Unit/PricingTest` |
| Scenario A atomic sale, outbox event, cost snapshot | `CheckoutTest::testScenarioA…` |
| Double submit, negative stock + rollback, payment guards (short, card number, split), credit needs customer | `CheckoutTest` |
| Discount ceiling + one-time manager approval; cashier price override/permissions (Scenario H) | `CheckoutTest`, `AdminTest::testCashier…` |
| Scenario E metre sale; G reprint without new sale; void | `CheckoutTest` |
| Append-only movements/audit, immutable outbox | `CheckoutTest::testAppendOnlyTables` |
| Scenario D exchange, over-return guard, damaged stock, refund approval | `ReturnsTest` |
| Scenario F purchase/landed/weighted average; Scenario I reports reconcile with raw data | `ReturnsTest::testScenarioFAndI…` |
| Scenarios B & C offline → manual sync, repeat click, replay, mid-batch drop, partial rejection, dependencies, pull + conflicts, auth | `SyncTest` |
| Backup/restore round trip with safety backup; invalid file rejection | `AdminTest` |
| CSV import preview/commit/duplicates; variant uniqueness; Code 128; password hashing + lockout; XSS/SQLi; perf (10k SKUs) | `AdminTest` |
| Keyboard-only checkout, focus, Esc safety, hold/resume, reload draft, unknown barcode, responsive layout | `E2E/pos_keyboard.test.mjs` |

## Not verified automatically

- Physical thermal printer output and real USB scanner hardware (manual checklist in DEPLOY_WINDOWS.md).
- Firefox and Safari keyboard behaviour (only Chromium was automated).
- Production Apache/Nginx service configuration and a real HTTPS host (the API was exercised via the PHP built-in server and in-process).
- Tamil translation quality; legal correctness of tax configuration.
