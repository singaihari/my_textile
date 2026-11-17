-- Textile POS — local (offline) SQLite schema, migration 001
-- Conventions:
--   * Primary keys are UUIDv7 strings (sync-safe, globally unique, time-ordered).
--   * Money columns end in _paise and are INTEGER minor units (1 INR = 100 paise). Never REAL.
--   * Quantity columns end in _milli and are INTEGER thousandths of the unit
--     (1 piece = 1000; 2.750 metre = 2750). Piece/set units must be multiples of 1000.
--   * Rates end in _bp and are basis points (5% = 500).
--   * Timestamps are 'YYYY-MM-DD HH:MM:SS' in the shop's configured timezone.
--   * Status enums are validated in application code (see app/Core/Enums.php).

CREATE TABLE stores (
    id            TEXT PRIMARY KEY,
    code          TEXT NOT NULL UNIQUE,
    name          TEXT NOT NULL,
    address       TEXT,
    phone         TEXT,
    email         TEXT,
    gstin         TEXT,
    state_code    TEXT,
    state_name    TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);

CREATE TABLE devices (
    id             TEXT PRIMARY KEY,
    store_id       TEXT NOT NULL REFERENCES stores(id),
    code           TEXT NOT NULL,
    name           TEXT NOT NULL,
    is_this_device INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT NOT NULL,
    UNIQUE (store_id, code)
);

CREATE TABLE roles (
    id               TEXT PRIMARY KEY,
    code             TEXT NOT NULL UNIQUE,
    name             TEXT NOT NULL,
    max_discount_bp  INTEGER NOT NULL DEFAULT 0,
    is_system        INTEGER NOT NULL DEFAULT 0,
    created_at       TEXT NOT NULL
);

CREATE TABLE permissions (
    code        TEXT PRIMARY KEY,
    grp         TEXT NOT NULL,
    description TEXT NOT NULL
);

CREATE TABLE role_permissions (
    role_id         TEXT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_code TEXT NOT NULL REFERENCES permissions(code) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_code)
);

CREATE TABLE users (
    id                  TEXT PRIMARY KEY,
    store_id            TEXT NOT NULL REFERENCES stores(id),
    username            TEXT NOT NULL UNIQUE COLLATE NOCASE,
    full_name           TEXT NOT NULL,
    password_hash       TEXT NOT NULL,
    role_id             TEXT NOT NULL REFERENCES roles(id),
    is_active           INTEGER NOT NULL DEFAULT 1,
    counter_device_id   TEXT REFERENCES devices(id),
    language            TEXT,
    last_login_at       TEXT,
    password_changed_at TEXT,
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);
CREATE INDEX ix_users_role ON users(role_id);

CREATE TABLE login_attempts (
    id           TEXT PRIMARY KEY,
    username     TEXT NOT NULL COLLATE NOCASE,
    ip           TEXT NOT NULL,
    success      INTEGER NOT NULL,
    attempted_at TEXT NOT NULL
);
CREATE INDEX ix_login_attempts_user ON login_attempts(username, attempted_at);
CREATE INDEX ix_login_attempts_ip ON login_attempts(ip, attempted_at);

-- ---------------------------------------------------------------- catalog
CREATE TABLE categories (
    id         TEXT PRIMARY KEY,
    parent_id  TEXT REFERENCES categories(id),
    name       TEXT NOT NULL COLLATE NOCASE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX ux_categories_name ON categories(IFNULL(parent_id, ''), name);

CREATE TABLE brands (
    id         TEXT PRIMARY KEY,
    name       TEXT NOT NULL UNIQUE COLLATE NOCASE,
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE sizes (
    id          TEXT PRIMARY KEY,
    size_system TEXT NOT NULL,           -- apparel | waist | age | free | other
    label       TEXT NOT NULL COLLATE NOCASE,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    is_active   INTEGER NOT NULL DEFAULT 1,
    UNIQUE (size_system, label)
);

CREATE TABLE colors (
    id        TEXT PRIMARY KEY,
    name      TEXT NOT NULL UNIQUE COLLATE NOCASE,
    hex       TEXT,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE tax_rules (
    id              TEXT PRIMARY KEY,
    code            TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name            TEXT NOT NULL,
    rate_bp         INTEGER NOT NULL,          -- base rate
    threshold_paise INTEGER,                   -- optional per-unit value threshold
    above_rate_bp   INTEGER,                   -- rate applied when per-unit value > threshold
    is_active       INTEGER NOT NULL DEFAULT 1,
    notes           TEXT,
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);

CREATE TABLE products (
    id                  TEXT PRIMARY KEY,
    store_id            TEXT NOT NULL REFERENCES stores(id),
    code                TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name                TEXT NOT NULL,
    category_id         TEXT REFERENCES categories(id),
    brand_id            TEXT REFERENCES brands(id),
    unit                TEXT NOT NULL DEFAULT 'pcs',  -- pcs | set | m
    gender              TEXT,                         -- men | women | kids | unisex
    age_group           TEXT,
    fabric              TEXT,
    collection          TEXT,
    style               TEXT,
    description         TEXT,
    hsn_code            TEXT,
    tax_rule_id         TEXT REFERENCES tax_rules(id),
    reorder_level_milli INTEGER NOT NULL DEFAULT 0,
    is_unique_piece     INTEGER NOT NULL DEFAULT 0,
    discount_allowed    INTEGER NOT NULL DEFAULT 1,
    status              TEXT NOT NULL DEFAULT 'active', -- active | archived
    is_demo             INTEGER NOT NULL DEFAULT 0,
    created_by          TEXT REFERENCES users(id),
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);
CREATE INDEX ix_products_category ON products(category_id);
CREATE INDEX ix_products_brand ON products(brand_id);
CREATE INDEX ix_products_name ON products(name COLLATE NOCASE);
CREATE INDEX ix_products_status ON products(status);

CREATE TABLE product_variants (
    id                  TEXT PRIMARY KEY,
    product_id          TEXT NOT NULL REFERENCES products(id),
    sku                 TEXT NOT NULL UNIQUE COLLATE NOCASE,
    barcode             TEXT NOT NULL UNIQUE,
    size_id             TEXT REFERENCES sizes(id),
    color_id            TEXT REFERENCES colors(id),
    pattern             TEXT,
    design_no           TEXT,
    batch_no            TEXT,
    mrp_paise           INTEGER NOT NULL,
    price_paise         INTEGER NOT NULL,
    cost_paise          INTEGER NOT NULL DEFAULT 0,  -- latest/default purchase cost (ex-tax)
    reorder_level_milli INTEGER,                     -- overrides product level when set
    status              TEXT NOT NULL DEFAULT 'active',
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL,
    CHECK (mrp_paise >= 0 AND price_paise >= 0 AND cost_paise >= 0),
    CHECK (price_paise <= mrp_paise)
);
CREATE UNIQUE INDEX ux_variant_combo ON product_variants(
    product_id, IFNULL(size_id, ''), IFNULL(color_id, ''), IFNULL(pattern, ''),
    IFNULL(design_no, ''), IFNULL(batch_no, ''));
CREATE INDEX ix_variants_product ON product_variants(product_id);
CREATE INDEX ix_variants_design ON product_variants(design_no);

CREATE TABLE product_barcodes (
    id         TEXT PRIMARY KEY,
    variant_id TEXT NOT NULL REFERENCES product_variants(id),
    barcode    TEXT NOT NULL UNIQUE,
    kind       TEXT NOT NULL DEFAULT 'supplier',
    created_at TEXT NOT NULL
);
CREATE INDEX ix_product_barcodes_variant ON product_barcodes(variant_id);

CREATE TABLE price_history (
    id          TEXT PRIMARY KEY,
    variant_id  TEXT NOT NULL REFERENCES product_variants(id),
    old_mrp_paise   INTEGER, new_mrp_paise   INTEGER,
    old_price_paise INTEGER, new_price_paise INTEGER,
    old_cost_paise  INTEGER, new_cost_paise  INTEGER,
    source      TEXT NOT NULL DEFAULT 'manual',   -- manual | import | purchase | sync
    changed_by  TEXT REFERENCES users(id),
    changed_at  TEXT NOT NULL
);
CREATE INDEX ix_price_history_variant ON price_history(variant_id, changed_at);

-- ---------------------------------------------------------------- inventory
CREATE TABLE locations (
    id          TEXT PRIMARY KEY,
    store_id    TEXT NOT NULL REFERENCES stores(id),
    code        TEXT NOT NULL,     -- MAIN | DAMAGED
    name        TEXT NOT NULL,
    is_sellable INTEGER NOT NULL DEFAULT 1,
    UNIQUE (store_id, code)
);

CREATE TABLE stock_balances (
    variant_id     TEXT NOT NULL REFERENCES product_variants(id),
    location_id    TEXT NOT NULL REFERENCES locations(id),
    qty_milli      INTEGER NOT NULL DEFAULT 0,
    avg_cost_paise INTEGER NOT NULL DEFAULT 0,   -- moving weighted average, per unit
    last_in_at     TEXT,
    last_out_at    TEXT,
    updated_at     TEXT NOT NULL,
    PRIMARY KEY (variant_id, location_id)
);
CREATE INDEX ix_stock_balances_location ON stock_balances(location_id, qty_milli);

CREATE TABLE stock_movements (
    id                  TEXT PRIMARY KEY,
    store_id            TEXT NOT NULL,
    device_id           TEXT NOT NULL,
    variant_id          TEXT NOT NULL REFERENCES product_variants(id),
    location_id         TEXT NOT NULL REFERENCES locations(id),
    movement_type       TEXT NOT NULL,  -- opening|purchase|sale|sale_return|damaged_return|adjustment|supplier_return|void|exchange_in|exchange_out
    qty_milli           INTEGER NOT NULL,           -- signed
    unit_cost_paise     INTEGER NOT NULL,
    value_paise         INTEGER NOT NULL,           -- signed, qty x cost
    balance_after_milli INTEGER NOT NULL,
    avg_cost_after_paise INTEGER NOT NULL,
    ref_type            TEXT,
    ref_id              TEXT,
    reason              TEXT,
    user_id             TEXT,
    created_at          TEXT NOT NULL
);
CREATE INDEX ix_movements_variant ON stock_movements(variant_id, created_at);
CREATE INDEX ix_movements_ref ON stock_movements(ref_type, ref_id);
CREATE INDEX ix_movements_type_date ON stock_movements(movement_type, created_at);

CREATE TRIGGER trg_stock_movements_no_update BEFORE UPDATE ON stock_movements
BEGIN SELECT RAISE(ABORT, 'stock_movements is append-only'); END;
CREATE TRIGGER trg_stock_movements_no_delete BEFORE DELETE ON stock_movements
BEGIN SELECT RAISE(ABORT, 'stock_movements is append-only'); END;

CREATE TABLE stock_adjustments (
    id          TEXT PRIMARY KEY,
    store_id    TEXT NOT NULL,
    device_id   TEXT NOT NULL,
    adj_no      TEXT NOT NULL UNIQUE,
    reason_code TEXT NOT NULL,       -- opening | count_correction | damaged | lost | found | other
    notes       TEXT,
    created_by  TEXT NOT NULL REFERENCES users(id),
    approved_by TEXT REFERENCES users(id),
    created_at  TEXT NOT NULL
);

CREATE TABLE stock_adjustment_items (
    id               TEXT PRIMARY KEY,
    adjustment_id    TEXT NOT NULL REFERENCES stock_adjustments(id),
    variant_id       TEXT NOT NULL REFERENCES product_variants(id),
    location_id      TEXT NOT NULL REFERENCES locations(id),
    qty_change_milli INTEGER NOT NULL,
    unit_cost_paise  INTEGER NOT NULL
);
CREATE INDEX ix_adj_items_adj ON stock_adjustment_items(adjustment_id);

-- ---------------------------------------------------------------- purchasing
CREATE TABLE suppliers (
    id                    TEXT PRIMARY KEY,
    store_id              TEXT NOT NULL,
    name                  TEXT NOT NULL COLLATE NOCASE,
    contact_person        TEXT,
    phone                 TEXT,
    email                 TEXT,
    gstin                 TEXT,
    address               TEXT,
    state_code            TEXT,
    opening_balance_paise INTEGER NOT NULL DEFAULT 0,
    notes                 TEXT,
    is_active             INTEGER NOT NULL DEFAULT 1,
    created_at            TEXT NOT NULL,
    updated_at            TEXT NOT NULL
);
CREATE INDEX ix_suppliers_name ON suppliers(name);

CREATE TABLE purchases (
    id                    TEXT PRIMARY KEY,
    store_id              TEXT NOT NULL,
    device_id             TEXT NOT NULL,
    purchase_no           TEXT NOT NULL UNIQUE,
    supplier_id           TEXT NOT NULL REFERENCES suppliers(id),
    supplier_invoice_no   TEXT,
    supplier_invoice_date TEXT,
    status                TEXT NOT NULL DEFAULT 'draft',   -- draft | posted | cancelled
    tax_claimable         INTEGER NOT NULL DEFAULT 1,
    subtotal_paise        INTEGER NOT NULL DEFAULT 0,
    tax_paise             INTEGER NOT NULL DEFAULT 0,
    freight_paise         INTEGER NOT NULL DEFAULT 0,
    total_paise           INTEGER NOT NULL DEFAULT 0,
    amount_paid_paise     INTEGER NOT NULL DEFAULT 0,
    notes                 TEXT,
    created_by            TEXT REFERENCES users(id),
    posted_by             TEXT REFERENCES users(id),
    posted_at             TEXT,
    created_at            TEXT NOT NULL,
    updated_at            TEXT NOT NULL
);
CREATE INDEX ix_purchases_supplier ON purchases(supplier_id, created_at);
CREATE UNIQUE INDEX ux_purchases_supplier_invoice ON purchases(supplier_id, supplier_invoice_no)
    WHERE supplier_invoice_no IS NOT NULL AND status <> 'cancelled';

CREATE TABLE purchase_items (
    id                     TEXT PRIMARY KEY,
    purchase_id            TEXT NOT NULL REFERENCES purchases(id) ON DELETE CASCADE,
    variant_id             TEXT NOT NULL REFERENCES product_variants(id),
    qty_milli              INTEGER NOT NULL CHECK (qty_milli > 0),
    unit_cost_paise        INTEGER NOT NULL CHECK (unit_cost_paise >= 0),
    tax_bp                 INTEGER NOT NULL DEFAULT 0,
    line_subtotal_paise    INTEGER NOT NULL,
    tax_paise              INTEGER NOT NULL DEFAULT 0,
    landed_unit_cost_paise INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX ix_purchase_items_purchase ON purchase_items(purchase_id);
CREATE INDEX ix_purchase_items_variant ON purchase_items(variant_id);

CREATE TABLE supplier_returns (
    id          TEXT PRIMARY KEY,
    store_id    TEXT NOT NULL,
    device_id   TEXT NOT NULL,
    return_no   TEXT NOT NULL UNIQUE,
    supplier_id TEXT NOT NULL REFERENCES suppliers(id),
    purchase_id TEXT REFERENCES purchases(id),
    reason      TEXT,
    total_paise INTEGER NOT NULL DEFAULT 0,
    created_by  TEXT REFERENCES users(id),
    created_at  TEXT NOT NULL
);

CREATE TABLE supplier_return_items (
    id                 TEXT PRIMARY KEY,
    supplier_return_id TEXT NOT NULL REFERENCES supplier_returns(id),
    variant_id         TEXT NOT NULL REFERENCES product_variants(id),
    location_id        TEXT NOT NULL REFERENCES locations(id),
    qty_milli          INTEGER NOT NULL CHECK (qty_milli > 0),
    unit_cost_paise    INTEGER NOT NULL,
    line_total_paise   INTEGER NOT NULL
);

-- ---------------------------------------------------------------- customers
CREATE TABLE customers (
    id                 TEXT PRIMARY KEY,
    store_id           TEXT NOT NULL,
    name               TEXT NOT NULL,
    phone              TEXT,
    email              TEXT,
    address            TEXT,
    gstin              TEXT,
    state_code         TEXT,
    notes              TEXT,
    credit_limit_paise INTEGER NOT NULL DEFAULT 0,
    consent_marketing  INTEGER NOT NULL DEFAULT 0,
    is_active          INTEGER NOT NULL DEFAULT 1,
    anonymized_at      TEXT,
    created_at         TEXT NOT NULL,
    updated_at         TEXT NOT NULL
);
CREATE UNIQUE INDEX ux_customers_phone ON customers(phone) WHERE phone IS NOT NULL;
CREATE INDEX ix_customers_name ON customers(name COLLATE NOCASE);

CREATE TABLE customer_receipts (
    id           TEXT PRIMARY KEY,
    store_id     TEXT NOT NULL,
    device_id    TEXT NOT NULL,
    customer_id  TEXT NOT NULL REFERENCES customers(id),
    amount_paise INTEGER NOT NULL CHECK (amount_paise > 0),
    method       TEXT NOT NULL,
    reference    TEXT,
    notes        TEXT,
    cash_session_id TEXT,
    received_by  TEXT REFERENCES users(id),
    created_at   TEXT NOT NULL
);
CREATE INDEX ix_customer_receipts_customer ON customer_receipts(customer_id);

-- ---------------------------------------------------------------- sales
CREATE TABLE doc_sequences (
    id        TEXT PRIMARY KEY,
    store_id  TEXT NOT NULL,
    device_id TEXT NOT NULL,
    series    TEXT NOT NULL,      -- e.g. INV, CN, PO, ADJ, SR
    fy        TEXT NOT NULL,      -- e.g. 2627
    next_no   INTEGER NOT NULL DEFAULT 1,
    UNIQUE (store_id, device_id, series, fy)
);

CREATE TABLE cash_sessions (
    id                  TEXT PRIMARY KEY,
    store_id            TEXT NOT NULL,
    device_id           TEXT NOT NULL,
    opened_by           TEXT NOT NULL REFERENCES users(id),
    opened_at           TEXT NOT NULL,
    opening_float_paise INTEGER NOT NULL DEFAULT 0,
    status              TEXT NOT NULL DEFAULT 'open',  -- open | closed
    closed_by           TEXT REFERENCES users(id),
    closed_at           TEXT,
    expected_cash_paise INTEGER,
    counted_cash_paise  INTEGER,
    difference_paise    INTEGER,
    notes               TEXT
);
CREATE UNIQUE INDEX ux_cash_sessions_open ON cash_sessions(device_id) WHERE status = 'open';

CREATE TABLE cash_movements (
    id              TEXT PRIMARY KEY,
    cash_session_id TEXT NOT NULL REFERENCES cash_sessions(id),
    store_id        TEXT NOT NULL,
    type            TEXT NOT NULL,     -- pay_in | pay_out
    amount_paise    INTEGER NOT NULL CHECK (amount_paise > 0),
    reason          TEXT NOT NULL,
    user_id         TEXT NOT NULL REFERENCES users(id),
    created_at      TEXT NOT NULL
);

CREATE TABLE sales (
    id                    TEXT PRIMARY KEY,
    store_id              TEXT NOT NULL,
    device_id             TEXT NOT NULL,
    invoice_no            TEXT NOT NULL,
    fy                    TEXT NOT NULL,
    status                TEXT NOT NULL DEFAULT 'completed',  -- completed | voided
    sale_type             TEXT NOT NULL DEFAULT 'sale',       -- sale | exchange
    customer_id           TEXT REFERENCES customers(id),
    customer_name         TEXT,
    customer_phone        TEXT,
    customer_gstin        TEXT,
    is_interstate         INTEGER NOT NULL DEFAULT 0,
    prices_include_tax    INTEGER NOT NULL DEFAULT 1,
    cashier_id            TEXT NOT NULL REFERENCES users(id),
    cash_session_id       TEXT REFERENCES cash_sessions(id),
    items_count           INTEGER NOT NULL,
    qty_total_milli       INTEGER NOT NULL,
    gross_paise           INTEGER NOT NULL,   -- sum(unit price x qty), before discounts
    line_discount_paise   INTEGER NOT NULL DEFAULT 0,
    bill_discount_type    TEXT,               -- amount | percent
    bill_discount_value   INTEGER,            -- paise or bp
    bill_discount_paise   INTEGER NOT NULL DEFAULT 0,
    taxable_paise         INTEGER NOT NULL,
    cgst_paise            INTEGER NOT NULL DEFAULT 0,
    sgst_paise            INTEGER NOT NULL DEFAULT 0,
    igst_paise            INTEGER NOT NULL DEFAULT 0,
    tax_paise             INTEGER NOT NULL DEFAULT 0,
    round_off_paise       INTEGER NOT NULL DEFAULT 0,
    total_paise           INTEGER NOT NULL,
    paid_paise            INTEGER NOT NULL,
    change_paise          INTEGER NOT NULL DEFAULT 0,
    credit_paise          INTEGER NOT NULL DEFAULT 0,
    cogs_paise            INTEGER NOT NULL DEFAULT 0,
    approved_by           TEXT REFERENCES users(id),
    notes                 TEXT,
    client_ref            TEXT UNIQUE,        -- idempotency key from the POS screen (double-submit guard)
    print_count           INTEGER NOT NULL DEFAULT 0,
    last_printed_at       TEXT,
    voided_at             TEXT,
    voided_by             TEXT REFERENCES users(id),
    void_reason           TEXT,
    created_at            TEXT NOT NULL,
    updated_at            TEXT NOT NULL,
    UNIQUE (store_id, invoice_no)
);
CREATE INDEX ix_sales_created ON sales(created_at);
CREATE INDEX ix_sales_customer ON sales(customer_id, created_at);
CREATE INDEX ix_sales_cashier ON sales(cashier_id, created_at);
CREATE INDEX ix_sales_status_created ON sales(status, created_at);
CREATE INDEX ix_sales_phone ON sales(customer_phone);

CREATE TABLE sale_items (
    id                       TEXT PRIMARY KEY,
    sale_id                  TEXT NOT NULL REFERENCES sales(id),
    line_no                  INTEGER NOT NULL,
    variant_id               TEXT NOT NULL REFERENCES product_variants(id),
    product_id               TEXT NOT NULL REFERENCES products(id),
    sku                      TEXT NOT NULL,
    barcode                  TEXT,
    product_name             TEXT NOT NULL,
    variant_label            TEXT,
    hsn_code                 TEXT,
    unit                     TEXT NOT NULL,
    qty_milli                INTEGER NOT NULL CHECK (qty_milli > 0),
    mrp_paise                INTEGER NOT NULL,
    unit_price_paise         INTEGER NOT NULL,
    gross_paise              INTEGER NOT NULL,
    line_discount_type       TEXT,
    line_discount_value      INTEGER,
    line_discount_paise      INTEGER NOT NULL DEFAULT 0,
    bill_discount_alloc_paise INTEGER NOT NULL DEFAULT 0,
    net_paise                INTEGER NOT NULL,     -- after all discounts (tax-inclusive if prices include tax)
    taxable_paise            INTEGER NOT NULL,
    tax_rate_bp              INTEGER NOT NULL DEFAULT 0,
    cgst_paise               INTEGER NOT NULL DEFAULT 0,
    sgst_paise               INTEGER NOT NULL DEFAULT 0,
    igst_paise               INTEGER NOT NULL DEFAULT 0,
    tax_paise                INTEGER NOT NULL DEFAULT 0,
    line_total_paise         INTEGER NOT NULL,     -- taxable + tax
    cost_at_sale_paise       INTEGER NOT NULL,     -- immutable per-unit cost snapshot
    cogs_paise               INTEGER NOT NULL,
    returned_qty_milli       INTEGER NOT NULL DEFAULT 0,
    UNIQUE (sale_id, line_no)
);
CREATE INDEX ix_sale_items_sale ON sale_items(sale_id);
CREATE INDEX ix_sale_items_variant ON sale_items(variant_id);
CREATE INDEX ix_sale_items_product ON sale_items(product_id);

CREATE TABLE payments (
    id              TEXT PRIMARY KEY,
    store_id        TEXT NOT NULL,
    sale_id         TEXT NOT NULL REFERENCES sales(id),
    method          TEXT NOT NULL,     -- cash | upi | card | credit | exchange_credit | other
    amount_paise    INTEGER NOT NULL CHECK (amount_paise > 0),  -- amount applied to the bill
    tendered_paise  INTEGER,           -- cash handed over (cash only)
    change_paise    INTEGER NOT NULL DEFAULT 0,
    reference       TEXT,              -- UPI ref / card approval code (never card numbers)
    cash_session_id TEXT,
    user_id         TEXT NOT NULL,
    created_at      TEXT NOT NULL
);
CREATE INDEX ix_payments_sale ON payments(sale_id);
CREATE INDEX ix_payments_method_date ON payments(method, created_at);
CREATE INDEX ix_payments_session ON payments(cash_session_id);

CREATE TABLE held_sales (
    id                   TEXT PRIMARY KEY,
    store_id             TEXT NOT NULL,
    device_id            TEXT NOT NULL,
    label                TEXT,
    customer_id          TEXT,
    cart_json            TEXT NOT NULL,
    items_count          INTEGER NOT NULL,
    total_estimate_paise INTEGER NOT NULL,
    held_by              TEXT NOT NULL REFERENCES users(id),
    created_at           TEXT NOT NULL
);

CREATE TABLE print_logs (
    id         TEXT PRIMARY KEY,
    sale_id    TEXT REFERENCES sales(id),
    kind       TEXT NOT NULL,     -- initial | reprint | test
    format     TEXT NOT NULL,
    user_id    TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX ix_print_logs_sale ON print_logs(sale_id);

-- ---------------------------------------------------------------- returns
CREATE TABLE returns (
    id                    TEXT PRIMARY KEY,
    store_id              TEXT NOT NULL,
    device_id             TEXT NOT NULL,
    return_no             TEXT NOT NULL UNIQUE,  -- credit note number
    original_sale_id      TEXT NOT NULL REFERENCES sales(id),
    customer_id           TEXT REFERENCES customers(id),
    return_type           TEXT NOT NULL,         -- return | exchange | void
    reason                TEXT NOT NULL,
    taxable_paise         INTEGER NOT NULL,
    cgst_paise            INTEGER NOT NULL DEFAULT 0,
    sgst_paise            INTEGER NOT NULL DEFAULT 0,
    igst_paise            INTEGER NOT NULL DEFAULT 0,
    tax_paise             INTEGER NOT NULL DEFAULT 0,
    total_paise           INTEGER NOT NULL,      -- credit value of returned goods (after round-off)
    round_off_paise       INTEGER NOT NULL DEFAULT 0,
    refund_paise         INTEGER NOT NULL DEFAULT 0,  -- money paid back
    exchange_credit_paise INTEGER NOT NULL DEFAULT 0,  -- applied to replacement sale
    cogs_reversed_paise   INTEGER NOT NULL DEFAULT 0,
    damaged_cost_paise    INTEGER NOT NULL DEFAULT 0,
    approved_by           TEXT REFERENCES users(id),
    created_by            TEXT NOT NULL REFERENCES users(id),
    cash_session_id       TEXT,
    created_at            TEXT NOT NULL
);
CREATE INDEX ix_returns_sale ON returns(original_sale_id);
CREATE INDEX ix_returns_created ON returns(created_at);

CREATE TABLE return_items (
    id                  TEXT PRIMARY KEY,
    return_id           TEXT NOT NULL REFERENCES returns(id),
    sale_item_id        TEXT NOT NULL REFERENCES sale_items(id),
    variant_id          TEXT NOT NULL REFERENCES product_variants(id),
    qty_milli           INTEGER NOT NULL CHECK (qty_milli > 0),
    item_condition      TEXT NOT NULL,   -- sellable | damaged
    amount_paise        INTEGER NOT NULL,
    taxable_paise       INTEGER NOT NULL,
    tax_paise           INTEGER NOT NULL,
    cgst_paise          INTEGER NOT NULL DEFAULT 0,
    sgst_paise          INTEGER NOT NULL DEFAULT 0,
    igst_paise          INTEGER NOT NULL DEFAULT 0,
    unit_cost_paise     INTEGER NOT NULL,
    cogs_paise          INTEGER NOT NULL,
    location_id         TEXT NOT NULL REFERENCES locations(id)
);
CREATE INDEX ix_return_items_return ON return_items(return_id);
CREATE INDEX ix_return_items_sale_item ON return_items(sale_item_id);

CREATE TABLE refunds (
    id              TEXT PRIMARY KEY,
    store_id        TEXT NOT NULL,
    return_id       TEXT NOT NULL REFERENCES returns(id),
    method          TEXT NOT NULL,
    amount_paise    INTEGER NOT NULL CHECK (amount_paise > 0),
    reference       TEXT,
    cash_session_id TEXT,
    approved_by     TEXT REFERENCES users(id),
    created_by      TEXT NOT NULL,
    created_at      TEXT NOT NULL
);
CREATE INDEX ix_refunds_return ON refunds(return_id);
CREATE INDEX ix_refunds_method_date ON refunds(method, created_at);

CREATE TABLE exchanges (
    id                    TEXT PRIMARY KEY,
    store_id              TEXT NOT NULL,
    return_id             TEXT NOT NULL UNIQUE REFERENCES returns(id),
    new_sale_id           TEXT NOT NULL UNIQUE REFERENCES sales(id),
    original_sale_id      TEXT NOT NULL REFERENCES sales(id),
    return_credit_paise   INTEGER NOT NULL,
    new_sale_total_paise  INTEGER NOT NULL,
    difference_paise      INTEGER NOT NULL,   -- >0 customer pays, <0 shop refunds
    created_by            TEXT NOT NULL,
    created_at            TEXT NOT NULL
);

-- ---------------------------------------------------------------- expenses
CREATE TABLE expense_categories (
    id        TEXT PRIMARY KEY,
    name      TEXT NOT NULL UNIQUE COLLATE NOCASE,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE expenses (
    id              TEXT PRIMARY KEY,
    store_id        TEXT NOT NULL,
    device_id       TEXT NOT NULL,
    expense_date    TEXT NOT NULL,
    category_id     TEXT NOT NULL REFERENCES expense_categories(id),
    description     TEXT NOT NULL,
    amount_paise    INTEGER NOT NULL CHECK (amount_paise > 0),
    payment_method  TEXT NOT NULL,
    reference       TEXT,
    attachment_path TEXT,
    status          TEXT NOT NULL DEFAULT 'approved',  -- pending | approved | rejected
    cash_session_id TEXT,
    created_by      TEXT NOT NULL,
    approved_by     TEXT,
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);
CREATE INDEX ix_expenses_date ON expenses(expense_date);

-- ---------------------------------------------------------------- system
CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      TEXT,
    updated_at TEXT NOT NULL,
    updated_by TEXT
);

CREATE TABLE audit_logs (
    id          TEXT PRIMARY KEY,
    store_id    TEXT,
    user_id     TEXT,
    action      TEXT NOT NULL,
    entity_type TEXT,
    entity_id   TEXT,
    details     TEXT,
    ip          TEXT,
    created_at  TEXT NOT NULL
);
CREATE INDEX ix_audit_created ON audit_logs(created_at);
CREATE INDEX ix_audit_entity ON audit_logs(entity_type, entity_id);
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs
BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs
BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;

-- ---------------------------------------------------------------- sync
CREATE TABLE sync_outbox (
    id               TEXT PRIMARY KEY,        -- event UUID (idempotency key on the server)
    seq              INTEGER NOT NULL UNIQUE, -- local causal order
    store_id         TEXT NOT NULL,
    device_id        TEXT NOT NULL,
    entity_type      TEXT NOT NULL,
    entity_id        TEXT NOT NULL,
    op               TEXT NOT NULL,
    schema_version   INTEGER NOT NULL DEFAULT 1,
    payload          TEXT NOT NULL,
    depends_on       TEXT,                    -- event id that must be acknowledged first
    status           TEXT NOT NULL DEFAULT 'pending',  -- pending | acked | failed | conflict
    attempts         INTEGER NOT NULL DEFAULT 0,
    last_error       TEXT,
    last_attempt_at  TEXT,
    acked_at         TEXT,
    server_status    TEXT,
    created_at       TEXT NOT NULL
);
CREATE INDEX ix_outbox_status_seq ON sync_outbox(status, seq);
CREATE INDEX ix_outbox_entity ON sync_outbox(entity_type, entity_id);
CREATE TRIGGER trg_outbox_payload_immutable BEFORE UPDATE OF id, seq, payload, entity_type, entity_id, op, created_at ON sync_outbox
BEGIN SELECT RAISE(ABORT, 'sync_outbox events are immutable'); END;
CREATE TRIGGER trg_outbox_no_delete_unacked BEFORE DELETE ON sync_outbox
WHEN OLD.status <> 'acked'
BEGIN SELECT RAISE(ABORT, 'cannot delete an unacknowledged sync event'); END;

CREATE TABLE sync_runs (
    id          TEXT PRIMARY KEY,
    user_id     TEXT,
    endpoint    TEXT,
    status      TEXT NOT NULL DEFAULT 'running',  -- running | success | partial | failed
    uploaded    INTEGER NOT NULL DEFAULT 0,
    acked       INTEGER NOT NULL DEFAULT 0,
    duplicates  INTEGER NOT NULL DEFAULT 0,
    failed      INTEGER NOT NULL DEFAULT 0,
    conflicts   INTEGER NOT NULL DEFAULT 0,
    pulled      INTEGER NOT NULL DEFAULT 0,
    message     TEXT,
    started_at  TEXT NOT NULL,
    finished_at TEXT
);

CREATE TABLE sync_receipts (
    event_id      TEXT PRIMARY KEY REFERENCES sync_outbox(id),
    sync_run_id   TEXT,
    server_status TEXT NOT NULL,   -- applied | duplicate | conflict
    message       TEXT,
    received_at   TEXT NOT NULL
);

CREATE TABLE sync_conflicts (
    id           TEXT PRIMARY KEY,
    sync_run_id  TEXT,
    event_id     TEXT,
    entity_type  TEXT NOT NULL,
    entity_id    TEXT NOT NULL,
    kind         TEXT NOT NULL,
    local_value  TEXT,
    server_value TEXT,
    status       TEXT NOT NULL DEFAULT 'open',  -- open | resolved | ignored
    resolution   TEXT,
    resolved_by  TEXT,
    resolved_at  TEXT,
    created_at   TEXT NOT NULL
);
CREATE INDEX ix_conflicts_status ON sync_conflicts(status);
