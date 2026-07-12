-- Maseno Retail ERP - Complete Database Schema
-- PostgreSQL 15+ Compatible
-- Run: psql -U postgres -d maseno_retail -f sql/schema.sql

-- ============================================================
-- 1. CORE CONFIGURATION & SETTINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS store_config (
    id              SERIAL PRIMARY KEY,
    config_key      VARCHAR(128) UNIQUE NOT NULL,
    config_value    TEXT NOT NULL,
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

INSERT INTO store_config (config_key, config_value) VALUES
    ('store_name', 'Maseno Retail Supermarket'),
    ('store_phone', '+254700000000'),
    ('store_email', 'info@masenoretail.co.ke'),
    ('store_address', 'Maseno Town, Kisumu County'),
    ('currency', 'KES'),
    ('tax_rate_pct', '16'),
    ('low_stock_threshold', '10'),
    ('expiry_alert_days', '14'),
    ('mpesa_consumer_key', ''),
    ('mpesa_consumer_secret', ''),
    ('mpesa_passkey', ''),
    ('mpesa_shortcode', '174379'),
    ('mpesa_env', 'sandbox')
ON CONFLICT (config_key) DO NOTHING;

-- ============================================================
-- 2. USERS & AUTHENTICATION
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              SERIAL PRIMARY KEY,
    username        VARCHAR(64) UNIQUE NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(128) NOT NULL,
    role            VARCHAR(32) NOT NULL DEFAULT 'cashier'
                    CHECK (role IN ('admin','manager','cashier','auditor')),
    phone           VARCHAR(20),
    email           VARCHAR(128),
    is_active       BOOLEAN DEFAULT TRUE,
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

-- default admin password: admin123 (change immediately)
INSERT INTO users (username, password_hash, full_name, role, phone) VALUES
    ('admin', '$2y$12$LJ3m4ys3Lk0TSwHnbfOMiOXPm1Qlq5Gz0Yq0e8Vn7s5KpR9fW1xS', 'System Admin', 'admin', '+254700000001')
ON CONFLICT (username) DO NOTHING;

-- ============================================================
-- 3. CASHIER SHIFT SYSTEM
-- ============================================================
CREATE TABLE IF NOT EXISTS cashier_shifts (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER NOT NULL REFERENCES users(id),
    opened_at       TIMESTAMPTZ DEFAULT NOW(),
    closed_at       TIMESTAMPTZ,
    opening_float   NUMERIC(12,2) DEFAULT 0.00,
    expected_cash   NUMERIC(12,2) DEFAULT 0.00,
    actual_cash     NUMERIC(12,2) DEFAULT 0.00,
    variance        NUMERIC(12,2) DEFAULT 0.00,
    status          VARCHAR(16) DEFAULT 'open'
                    CHECK (status IN ('open','closed','reconciled')),
    notes           TEXT,
    -- Note: unique open-shift-per-user is enforced in application logic
);

-- ============================================================
-- 4. PRODUCT / ITEM MASTER
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
    id              SERIAL PRIMARY KEY,
    sku             VARCHAR(64) UNIQUE NOT NULL,
    barcode         VARCHAR(128),
    name            VARCHAR(256) NOT NULL,
    category        VARCHAR(128) NOT NULL DEFAULT 'General',
    description     TEXT,
    -- Selling unit (the unit sold to customers)
    sell_unit       VARCHAR(32) NOT NULL DEFAULT 'piece',
    -- Purchase unit (the unit bought from supplier)
    purchase_unit   VARCHAR(32) NOT NULL DEFAULT 'case',
    -- Conversion: 1 purchase_unit = conversion_factor sell_units
    conversion_factor NUMERIC(12,4) NOT NULL DEFAULT 1.0000,
    supplier_price  NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    selling_price   NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    current_stock   NUMERIC(12,4) NOT NULL DEFAULT 0.0000,
    -- stock is always stored in sell_unit scale
    low_stock_qty   NUMERIC(12,4) NOT NULL DEFAULT 10.0000,
    has_expiry      BOOLEAN DEFAULT FALSE,
    is_active       BOOLEAN DEFAULT TRUE,
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_products_sku ON products(sku);
CREATE INDEX idx_products_category ON products(category);
CREATE INDEX idx_products_active ON products(is_active);

-- ============================================================
-- 5. INVENTORY TRANSACTIONS (STOCK MOVEMENTS)
-- ============================================================
CREATE TABLE IF NOT EXISTS stock_movements (
    id              SERIAL PRIMARY KEY,
    product_id      INTEGER NOT NULL REFERENCES products(id),
    movement_type   VARCHAR(32) NOT NULL
                    CHECK (movement_type IN (
                        'purchase_receive',  -- goods received from supplier
                        'sale_deduction',    -- items sold
                        'adjustment_add',    -- stock increase (count correction)
                        'adjustment_remove', -- stock decrease (damage/loss)
                        'transfer_out',      -- transfer to another branch
                        'transfer_in',       -- transfer from another branch
                        'expiry_writeoff'    -- expired items removed
                    )),
    quantity        NUMERIC(12,4) NOT NULL, -- positive for in, negative for out
    unit_cost       NUMERIC(12,2),          -- cost per sell_unit at time of movement
    batch_number    VARCHAR(64),
    expiry_date     DATE,
    reference_id    INTEGER,                -- links to sale_id, purchase_order_id etc.
    notes           TEXT,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_stock_product ON stock_movements(product_id);
CREATE INDEX idx_stock_type ON stock_movements(movement_type);
CREATE INDEX idx_stock_date ON stock_movements(created_at);

-- ============================================================
-- 6. POS TRANSACTIONS (SALES)
-- ============================================================
CREATE TABLE IF NOT EXISTS sales (
    id              SERIAL PRIMARY KEY,
    receipt_no      VARCHAR(32) UNIQUE NOT NULL,
    shift_id        INTEGER REFERENCES cashier_shifts(id),
    user_id         INTEGER REFERENCES users(id),
    customer_id     INTEGER,  -- 0 = walk-in
    subtotal        NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    tax_amount      NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    discount_amount NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    total           NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    payment_method  VARCHAR(32) NOT NULL DEFAULT 'cash'
                    CHECK (payment_method IN ('cash','mpesa','card','credit')),
    amount_tendered NUMERIC(12,2) DEFAULT 0.00,
    change_due      NUMERIC(12,2) DEFAULT 0.00,
    sale_status     VARCHAR(16) DEFAULT 'complete'
                    CHECK (sale_status IN ('complete','void','refunded')),
    mpesa_receipt   VARCHAR(64),            -- M-Pesa transaction ID
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_sales_shift ON sales(shift_id);
CREATE INDEX idx_sales_date ON sales(created_at);
CREATE INDEX idx_sales_customer ON sales(customer_id);
CREATE INDEX idx_sales_receipt ON sales(receipt_no);

-- ============================================================
-- 7. SALE LINE ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS sale_items (
    id              SERIAL PRIMARY KEY,
    sale_id         INTEGER NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
    product_id      INTEGER NOT NULL REFERENCES products(id),
    quantity        NUMERIC(12,4) NOT NULL,
    unit_price      NUMERIC(12,2) NOT NULL,
    line_total      NUMERIC(12,2) NOT NULL,
    discount_pct    NUMERIC(5,2) DEFAULT 0.00,
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_sale_items_sale ON sale_items(sale_id);

-- ============================================================
-- 8. ACCOUNTING: GENERAL LEDGER
-- ============================================================
CREATE TABLE IF NOT EXISTS gl_accounts (
    id              SERIAL PRIMARY KEY,
    account_code    VARCHAR(20) UNIQUE NOT NULL,
    account_name    VARCHAR(128) NOT NULL,
    account_type    VARCHAR(32) NOT NULL
                    CHECK (account_type IN (
                        'asset','liability','equity',
                        'income','expense','cost_of_sales'
                    )),
    is_active       BOOLEAN DEFAULT TRUE,
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

INSERT INTO gl_accounts (account_code, account_name, account_type) VALUES
    ('1000', 'Cash at Bank',          'asset'),
    ('1100', 'Cash on Hand (Till)',   'asset'),
    ('1200', 'Accounts Receivable',   'asset'),
    ('1300', 'Inventory Stock',       'asset'),
    ('2000', 'Accounts Payable',      'liability'),
    ('2100', 'VAT Payable',           'liability'),
    ('3000', 'Retained Earnings',     'equity'),
    ('4000', 'Sales Revenue',         'income'),
    ('4100', 'Other Income',          'income'),
    ('5000', 'Cost of Goods Sold',    'cost_of_sales'),
    ('6000', 'Salaries & Wages',      'expense'),
    ('6100', 'Rent & Utilities',      'expense'),
    ('6200', 'Operating Expenses',    'expense')
ON CONFLICT (account_code) DO NOTHING;

-- ============================================================
-- 9. ACCOUNTING: JOURNAL ENTRIES
-- ============================================================
CREATE TABLE IF NOT EXISTS journal_entries (
    id              SERIAL PRIMARY KEY,
    entry_date      DATE NOT NULL DEFAULT CURRENT_DATE,
    reference_type  VARCHAR(32) NOT NULL
                    CHECK (reference_type IN (
                        'sale','expense','purchase',
                        'stock_adjustment','shift_close','transfer'
                    )),
    reference_id    INTEGER,  -- links to sale_id, expense_id, etc.
    description     TEXT,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS journal_lines (
    id              SERIAL PRIMARY KEY,
    journal_id      INTEGER NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
    account_id      INTEGER NOT NULL REFERENCES gl_accounts(id),
    debit_amount    NUMERIC(12,2) DEFAULT 0.00,
    credit_amount   NUMERIC(12,2) DEFAULT 0.00,
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT chk_non_negative CHECK (debit_amount >= 0 AND credit_amount >= 0)
);

CREATE INDEX idx_journal_date ON journal_entries(entry_date);
CREATE INDEX idx_journal_ref ON journal_entries(reference_type, reference_id);

-- ============================================================
-- 10. EXPENSES
-- ============================================================
CREATE TABLE IF NOT EXISTS expenses (
    id              SERIAL PRIMARY KEY,
    expense_category VARCHAR(64) NOT NULL
                     CHECK (expense_category IN (
                        'rent','utilities','salaries','restocking',
                        'transport','marketing','maintenance',
                        'supplies','tax','other'
                     )),
    description     TEXT NOT NULL,
    amount          NUMERIC(12,2) NOT NULL,
    payment_method  VARCHAR(32) DEFAULT 'cash',
    receipt_ref     VARCHAR(128),
    gl_account_id   INTEGER REFERENCES gl_accounts(id),
    recorded_by     INTEGER REFERENCES users(id),
    entry_date      DATE NOT NULL DEFAULT CURRENT_DATE,
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_expenses_date ON expenses(entry_date);
CREATE INDEX idx_expenses_cat ON expenses(expense_category);

-- ============================================================
-- 11. CUSTOMER / CRM
-- ============================================================
CREATE TABLE IF NOT EXISTS customers (
    id              SERIAL PRIMARY KEY,
    first_name      VARCHAR(64) NOT NULL,
    last_name       VARCHAR(64) NOT NULL,
    phone           VARCHAR(20) UNIQUE NOT NULL,
    email           VARCHAR(128),
    address         TEXT,
    id_number       VARCHAR(32),           -- National ID
    loyalty_points  INTEGER DEFAULT 0,
    total_spent     NUMERIC(12,2) DEFAULT 0.00,
    visit_count     INTEGER DEFAULT 0,
    last_visit      TIMESTAMPTZ,
    is_wholesale    BOOLEAN DEFAULT FALSE,
    notes           TEXT,
    registered_at   TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_customers_phone ON customers(phone);
CREATE INDEX idx_customers_name ON customers(last_name, first_name);

-- ============================================================
-- 12. SUPPLIERS
-- ============================================================
CREATE TABLE IF NOT EXISTS suppliers (
    id              SERIAL PRIMARY KEY,
    company_name    VARCHAR(256) NOT NULL,
    contact_person  VARCHAR(128),
    phone           VARCHAR(20) NOT NULL,
    email           VARCHAR(128),
    address         TEXT,
    tax_pin         VARCHAR(32),
    payment_terms   VARCHAR(64) DEFAULT '30 days',
    is_active       BOOLEAN DEFAULT TRUE,
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================
-- 13. PURCHASE ORDERS
-- ============================================================
CREATE TABLE IF NOT EXISTS purchase_orders (
    id              SERIAL PRIMARY KEY,
    po_number       VARCHAR(32) UNIQUE NOT NULL,
    supplier_id     INTEGER NOT NULL REFERENCES suppliers(id),
    status          VARCHAR(32) DEFAULT 'pending'
                    CHECK (status IN (
                        'pending','approved','received','cancelled'
                    )),
    ordered_by      INTEGER REFERENCES users(id),
    received_by     INTEGER REFERENCES users(id),
    received_at     TIMESTAMPTZ,
    total_amount    NUMERIC(12,2) DEFAULT 0.00,
    notes           TEXT,
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id              SERIAL PRIMARY KEY,
    po_id           INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    product_id      INTEGER NOT NULL REFERENCES products(id),
    quantity_ordered NUMERIC(12,4) NOT NULL,
    quantity_received NUMERIC(12,4) DEFAULT 0.0000,
    unit_cost       NUMERIC(12,2) NOT NULL,
    line_total      NUMERIC(12,2) NOT NULL
);

-- ============================================================
-- 14. EXPIRY TRACKING (batch-level)
-- ============================================================
CREATE TABLE IF NOT EXISTS stock_batches (
    id              SERIAL PRIMARY KEY,
    product_id      INTEGER NOT NULL REFERENCES products(id),
    batch_number    VARCHAR(64) NOT NULL,
    quantity        NUMERIC(12,4) NOT NULL DEFAULT 0.0000,
    unit_cost       NUMERIC(12,2),
    manufacture_date DATE,
    expiry_date     DATE NOT NULL,
    supplier_id     INTEGER REFERENCES suppliers(id),
    purchase_order_id INTEGER REFERENCES purchase_orders(id),
    is_expired      BOOLEAN DEFAULT FALSE,
    alert_sent      BOOLEAN DEFAULT FALSE,
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_batches_expiry ON stock_batches(expiry_date)
    WHERE is_expired = FALSE;
CREATE INDEX idx_batches_product ON stock_batches(product_id);

-- ============================================================
-- 15. M-PESA TRANSACTION LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS mpesa_transactions (
    id                  SERIAL PRIMARY KEY,
    transaction_type    VARCHAR(64),  -- CustomerPayBillOnline, etc.
    trans_id            VARCHAR(64) UNIQUE,  -- M-PESA receipt number
    trans_time          TIMESTAMPTZ,
    trans_amount        NUMERIC(12,2),
    business_shortcode  VARCHAR(16),
    bill_ref_number     VARCHAR(64),  -- receipt_no or order ref
    invoice_number      VARCHAR(64),
    msisdn              VARCHAR(20),   -- customer phone
    first_name          VARCHAR(64),
    middle_name         VARCHAR(64),
    last_name           VARCHAR(64),
    org_account_balance NUMERIC(12,2),
    sale_id             INTEGER REFERENCES sales(id),
    raw_callback        JSONB,        -- full callback payload for audit
    result_code         INTEGER,
    result_desc         VARCHAR(256),
    created_at          TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_mpesa_transid ON mpesa_transactions(trans_id);
CREATE INDEX idx_mpesa_ref ON mpesa_transactions(bill_ref_number);

-- ============================================================
-- AUTO-GENERATE RECEIPT NUMBERS
-- ============================================================
CREATE SEQUENCE IF NOT EXISTS receipt_seq START 1000 INCREMENT 1;

-- ============================================================
-- FUNCTION: generate_receipt_no()
-- ============================================================
CREATE OR REPLACE FUNCTION generate_receipt_no()
RETURNS VARCHAR(32) LANGUAGE SQL IMMUTABLE AS $$
    SELECT 'RCP-' || TO_CHAR(NOW(), 'YYYYMMDD') || '-' ||
           LPAD(NEXTVAL('receipt_seq')::TEXT, 6, '0');
$$;

-- ============================================================
-- FUNCTION: update_stock_from_sale() - trigger
-- ============================================================
CREATE OR REPLACE FUNCTION deduct_stock_on_sale()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE
    v_product RECORD;
    v_new_stock NUMERIC(12,4);
BEGIN
    SELECT * INTO v_product FROM products WHERE id = NEW.product_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Product % not found', NEW.product_id;
    END IF;

    v_new_stock := v_product.current_stock - NEW.quantity;
    IF v_new_stock < 0 THEN
        RAISE EXCEPTION 'Insufficient stock for product % (SKU: %). Available: %, requested: %',
            v_product.name, v_product.sku, v_product.current_stock, NEW.quantity;
    END IF;

    UPDATE products
    SET current_stock = v_new_stock, updated_at = NOW()
    WHERE id = NEW.product_id;

    INSERT INTO stock_movements
        (product_id, movement_type, quantity, unit_cost, reference_id, notes)
    VALUES
        (NEW.product_id, 'sale_deduction', -NEW.quantity,
         NEW.unit_price, NEW.sale_id,
         'Sale #' || (SELECT receipt_no FROM sales WHERE id = NEW.sale_id));

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_deduct_stock_on_sale
    AFTER INSERT ON sale_items
    FOR EACH ROW EXECUTE FUNCTION deduct_stock_on_sale();

-- ============================================================
-- FUNCTION: create_journal_for_sale()
-- ============================================================
CREATE OR REPLACE FUNCTION journalize_sale()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE
    v_journal_id INTEGER;
    v_cogs NUMERIC(12,2);
BEGIN
    -- Create journal entry
    INSERT INTO journal_entries (entry_date, reference_type, reference_id, description)
    VALUES (CURRENT_DATE, 'sale', NEW.id, 'Sale ' || NEW.receipt_no)
    RETURNING id INTO v_journal_id;

    -- Debit: Cash/Bank (total received)
    INSERT INTO journal_lines (journal_id, account_id, debit_amount, credit_amount)
    SELECT v_journal_id, id,
           CASE WHEN NEW.payment_method = 'mpesa' THEN 1000 ELSE 1100 END,
           0.00
    FROM gl_accounts WHERE account_code = CASE
        WHEN NEW.payment_method = 'mpesa' THEN '1000' ELSE '1100' END;

    -- Credit: Sales Revenue (subtotal)
    INSERT INTO journal_lines (journal_id, account_id, debit_amount, credit_amount)
    VALUES (v_journal_id,
        (SELECT id FROM gl_accounts WHERE account_code = '4000'),
        0.00, NEW.subtotal);

    -- Credit: VAT Payable (tax)
    IF NEW.tax_amount > 0 THEN
        INSERT INTO journal_lines (journal_id, account_id, debit_amount, credit_amount)
        VALUES (v_journal_id,
            (SELECT id FROM gl_accounts WHERE account_code = '2100'),
            0.00, NEW.tax_amount);
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_journalize_sale
    AFTER INSERT ON sales
    FOR EACH ROW EXECUTE FUNCTION journalize_sale();
