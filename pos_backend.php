<?php
/**
 * Maseno Retail ERP - Point of Sale & Billing Engine (Backend)
 *
 * Handles the complete POS lifecycle:
 *   - Product lookup (barcode/SKU)
 *   - Multi-item cart management (add, update qty, remove)
 *   - Dynamic totals (subtotal, tax, discount, grand total)
 *   - Sale finalization with stock deduction (via DB trigger)
 *   - Receipt generation
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth.php';

// ──────────────────────────────────────────────────────────────
// 1. PRODUCT LOOKUP
// ──────────────────────────────────────────────────────────────

/**
 * Find a product by barcode, SKU, or partial name match.
 * Returns the first match or null.
 */
function find_product(string $query): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, sku, barcode, name, category, sell_unit, purchase_unit,
               conversion_factor, selling_price, current_stock, low_stock_qty,
               has_expiry, is_active
        FROM products
        WHERE (barcode = ? OR sku = ?)
          AND is_active = TRUE
        LIMIT 1
    ");
    $stmt->execute([$query, $query]);
    $product = $stmt->fetch();

    if ($product) return $product;

    // Fallback: partial name search
    $stmt = $db->prepare("
        SELECT id, sku, barcode, name, category, sell_unit, purchase_unit,
               conversion_factor, selling_price, current_stock, low_stock_qty,
               has_expiry, is_active
        FROM products
        WHERE name ILIKE ? AND is_active = TRUE
        ORDER BY name ASC
        LIMIT 20
    ");
    $stmt->execute(["%{$query}%"]);
    return $stmt->fetchAll(); // return array of matches for selection
}

/**
 * Get a single product by ID.
 */
function get_product(int $productId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, sku, barcode, name, category, sell_unit, purchase_unit,
               conversion_factor, selling_price, current_stock, low_stock_qty,
               has_expiry, is_active
        FROM products WHERE id = ? AND is_active = TRUE
    ");
    $stmt->execute([$productId]);
    return $stmt->fetch() ?: null;
}

/**
 * Search products by name/category with pagination.
 */
function search_products(string $term = '', string $category = '', int $page = 1, int $perPage = 50): array
{
    $db = getDB();
    $offset = ($page - 1) * $perPage;
    $where  = "WHERE p.is_active = TRUE";
    $params = [];

    if ($term) {
        $where .= " AND (p.name ILIKE ? OR p.sku ILIKE ? OR p.barcode ILIKE ?)";
        $like = "%{$term}%";
        $params = [$like, $like, $like];
    }
    if ($category) {
        $where .= " AND p.category = ?";
        $params[] = $category;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM products p {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT p.*,
               COALESCE(
                   (SELECT MIN(sb.expiry_date) FROM stock_batches sb
                    WHERE sb.product_id = p.id AND sb.is_expired = FALSE
                      AND sb.expiry_date >= CURRENT_DATE
                   ), NULL
               ) AS nearest_expiry
        FROM products p
        {$where}
        ORDER BY p.name ASC
        LIMIT ? OFFSET ?
    ");
    $params[] = $perPage;
    $params[] = $offset;
    $stmt->execute($params);

    return [
        'data'       => $stmt->fetchAll(),
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'total_pages'=> (int) ceil($total / $perPage),
    ];
}

/**
 * Get distinct product categories.
 */
function get_categories(): array
{
    $db = getDB();
    return $db->query("SELECT DISTINCT category FROM products WHERE is_active = TRUE ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
}

// ──────────────────────────────────────────────────────────────
// 2. SHOPPING CART (SESSION-BASED)
// ──────────────────────────────────────────────────────────────

/**
 * Initialize an empty cart in session.
 */
function init_cart(): void
{
    init_session();
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
}

/**
 * Get the current cart contents.
 *
 * @return array [ 'items' => [...], 'summary' => [...] ]
 */
function get_cart(): array
{
    init_cart();
    $items = $_SESSION['cart'];
    $subtotal = 0;
    $totalDiscount = 0;

    foreach ($items as &$item) {
        $item['line_total'] = round($item['quantity'] * $item['unit_price'], 2);
        $item['discount_amount'] = round($item['line_total'] * ($item['discount_pct'] / 100), 2);
        $item['net_total'] = round($item['line_total'] - $item['discount_amount'], 2);
        $subtotal += $item['net_total'];
        $totalDiscount += $item['discount_amount'];
    }
    unset($item);

    $taxAmount = round($subtotal * TAX_RATE, 2);
    $grandTotal = round($subtotal + $taxAmount, 2);

    return [
        'items'           => $items,
        'item_count'      => count($items),
        'subtotal'        => $subtotal,
        'total_discount'  => $totalDiscount,
        'tax_rate_pct'    => TAX_RATE_PCT,
        'tax_amount'      => $taxAmount,
        'grand_total'     => $grandTotal,
    ];
}

/**
 * Add a product to the cart.
 * If the product already exists, increments quantity.
 */
function cart_add(int $productId, float $quantity = 1.0, float $discountPct = 0.0): array
{
    init_cart();
    $product = get_product($productId);
    if (!$product) {
        return ['success' => false, 'message' => 'Product not found or inactive.'];
    }

    // Check stock availability
    if ($product['current_stock'] < $quantity) {
        return [
            'success' => false,
            'message' => sprintf('Insufficient stock for "%s". Available: %s %s',
                $product['name'], $product['current_stock'], $product['sell_unit']
            ),
        ];
    }

    // Check if product already in cart
    foreach ($_SESSION['cart'] as &$item) {
        if ($item['product_id'] === $productId) {
            $newQty = $item['quantity'] + $quantity;
            if ($product['current_stock'] < $newQty) {
                return ['success' => false, 'message' => sprintf(
                    'Cannot add more. Available stock: %s %s', $product['current_stock'], $product['sell_unit']
                )];
            }
            $item['quantity'] = $newQty;
            $item['discount_pct'] = max($item['discount_pct'], $discountPct);
            return ['success' => true, 'message' => 'Quantity updated in cart.'];
        }
    }
    unset($item);

    // New item
    $_SESSION['cart'][] = [
        'product_id'   => $productId,
        'sku'          => $product['sku'],
        'name'         => $product['name'],
        'unit_price'   => (float) $product['selling_price'],
        'quantity'     => $quantity,
        'discount_pct' => $discountPct,
        'sell_unit'    => $product['sell_unit'],
    ];

    return ['success' => true, 'message' => sprintf('Added %s x %s to cart.', $quantity, $product['name'])];
}

/**
 * Update the quantity of a cart item.
 */
function cart_update_qty(int $productId, float $quantity): array
{
    init_cart();
    if ($quantity <= 0) {
        return cart_remove($productId);
    }

    $product = get_product($productId);
    if ($product && $product['current_stock'] < $quantity) {
        return ['success' => false, 'message' => sprintf(
            'Insufficient stock. Available: %s %s', $product['current_stock'], $product['sell_unit']
        )];
    }

    foreach ($_SESSION['cart'] as &$item) {
        if ($item['product_id'] === $productId) {
            $item['quantity'] = $quantity;
            return ['success' => true, 'message' => 'Quantity updated.'];
        }
    }
    unset($item);

    return ['success' => false, 'message' => 'Item not in cart.'];
}

/**
 * Remove an item from the cart.
 */
function cart_remove(int $productId): array
{
    init_cart();
    foreach ($_SESSION['cart'] as $idx => $item) {
        if ($item['product_id'] === $productId) {
            array_splice($_SESSION['cart'], $idx, 1);
            return ['success' => true, 'message' => 'Item removed from cart.'];
        }
    }
    return ['success' => false, 'message' => 'Item not in cart.'];
}

/**
 * Clear the entire cart.
 */
function cart_clear(): void
{
    init_cart();
    $_SESSION['cart'] = [];
}

/**
 * Apply a discount percentage to a specific cart item.
 */
function cart_item_discount(int $productId, float $discountPct): array
{
    init_cart();
    foreach ($_SESSION['cart'] as &$item) {
        if ($item['product_id'] === $productId) {
            $item['discount_pct'] = max(0, min(100, $discountPct));
            return ['success' => true, 'message' => sprintf('Discount set to %.1f%%', $discountPct)];
        }
    }
    unset($item);
    return ['success' => false, 'message' => 'Item not in cart.'];
}

// ──────────────────────────────────────────────────────────────
// 3. SALE COMPLETION
// ──────────────────────────────────────────────────────────────

/**
 * Finalize the current cart into a completed sale.
 *
 * Steps:
 *   1. Validate cart is not empty
 *   2. Validate stock for all items
 *   3. Create sale record with shift_id
 *   4. Insert sale_items (DB trigger deducts stock & creates journal)
 *   5. Clear cart
 *
 * @param int    $shiftId        Active cashier shift ID
 * @param int    $userId         Logged-in user ID
 * @param string $paymentMethod  cash|mpesa|card|credit
 * @param float  $amountTendered Amount paid by customer
 * @param int    $customerId     0 for walk-in
 * @return array ['success' => bool, 'sale_id' => int|null, 'receipt_no' => string|null, 'message' => string]
 */
function complete_sale(
    int    $shiftId,
    int    $userId,
    string $paymentMethod = 'cash',
    float  $amountTendered = 0.00,
    int    $customerId = 0
): array {
    $db = getDB();
    $cart = get_cart();

    if ($cart['item_count'] === 0) {
        return ['success' => false, 'sale_id' => null, 'receipt_no' => null, 'message' => 'Cart is empty.'];
    }

    // Validate stock for all items before proceeding
    foreach ($cart['items'] as $item) {
        $product = get_product($item['product_id']);
        if (!$product) {
            return ['success' => false, 'sale_id' => null, 'receipt_no' => null,
                    'message' => "Product '{$item['name']}' no longer available."];
        }
        if ($product['current_stock'] < $item['quantity']) {
            return ['success' => false, 'sale_id' => null, 'receipt_no' => null,
                    'message' => "Insufficient stock for '{$product['name']}'. Available: {$product['current_stock']} {$product['sell_unit']}"];
        }
    }

    $db->beginTransaction();
    try {
        // Generate receipt number
        $receiptStmt = $db->query("SELECT generate_receipt_no()");
        $receiptNo = $receiptStmt->fetchColumn();

        // Insert sale
        $saleStmt = $db->prepare("
            INSERT INTO sales
                (receipt_no, shift_id, user_id, customer_id,
                 subtotal, tax_amount, discount_amount, total,
                 payment_method, amount_tendered, change_due, sale_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'complete')
            RETURNING id
        ");
        $changeDue = max(0, $amountTendered - $cart['grand_total']);
        $saleStmt->execute([
            $receiptNo, $shiftId, $userId, $customerId,
            $cart['subtotal'], $cart['tax_amount'], $cart['total_discount'], $cart['grand_total'],
            $paymentMethod, $amountTendered, $changeDue,
        ]);
        $saleId = (int) $saleStmt->fetchColumn();

        // Insert sale items (DB trigger deducts stock)
        $itemStmt = $db->prepare("
            INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, line_total, discount_pct)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($cart['items'] as $item) {
            $itemStmt->execute([
                $saleId,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['net_total'],
                $item['discount_pct'],
            ]);
        }

        // Update customer visit stats if not walk-in
        if ($customerId > 0) {
            $db->prepare("
                UPDATE customers
                SET total_spent = total_spent + ?,
                    visit_count = visit_count + 1,
                    last_visit  = NOW()
                WHERE id = ?
            ")->execute([$cart['grand_total'], $customerId]);
        }

        $db->commit();

        // Save cart totals before clearing (for receipt display)
        $_SESSION['last_cart_subtotal']  = $cart['subtotal'];
        $_SESSION['last_cart_tax']       = $cart['tax_amount'];
        $_SESSION['last_cart_discount']  = $cart['total_discount'];
        $_SESSION['last_cart_total']     = $cart['grand_total'];

        // Clear the cart
        cart_clear();

        return [
            'success'    => true,
            'sale_id'    => $saleId,
            'receipt_no' => $receiptNo,
            'message'    => "Sale {$receiptNo} completed successfully.",
            'change_due' => $changeDue,
        ];
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success'    => false,
            'sale_id'    => null,
            'receipt_no' => null,
            'message'    => 'Sale failed: ' . $e->getMessage(),
        ];
    }
}

/**
 * Void a sale (admin/manager only). Reverses stock and creates a void journal entry.
 */
function void_sale(int $saleId, int $userId): array
{
    $db = getDB();
    $db->beginTransaction();
    try {
        $sale = $db->prepare("SELECT * FROM sales WHERE id = ? AND sale_status = 'complete'");
        $sale->execute([$saleId]);
        $saleData = $sale->fetch();
        if (!$saleData) {
            return ['success' => false, 'message' => 'Sale not found or already voided.'];
        }

        // Mark sale as void
        $db->prepare("UPDATE sales SET sale_status = 'void' WHERE id = ?")->execute([$saleId]);

        // Reverse stock movements
        $items = $db->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $items->execute([$saleId]);
        foreach ($items as $item) {
            // Add stock back
            $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?")
               ->execute([$item['quantity'], $item['product_id']]);

            // Record reversal movement
            $db->prepare("
                INSERT INTO stock_movements (product_id, movement_type, quantity, unit_cost, reference_id, notes, created_by)
                VALUES (?, 'adjustment_add', ?, ?, ?, 'Void sale #' || ?, ?)
            ")->execute([$item['product_id'], $item['quantity'], $item['unit_price'], $saleId, $saleId, $userId]);
        }

        // Create void journal entry
        $jeStmt = $db->prepare("
            INSERT INTO journal_entries (entry_date, reference_type, reference_id, description, created_by)
            VALUES (CURRENT_DATE, 'sale', ?, 'VOID: ' || (SELECT receipt_no FROM sales WHERE id = ?), ?)
            RETURNING id
        ");
        $jeStmt->execute([$saleId, $saleId, $userId]);
        $journalId = $jeStmt->fetchColumn();

        // Reverse the original journal entries
        $db->prepare("
            INSERT INTO journal_lines (journal_id, account_id, debit_amount, credit_amount)
            SELECT ?, account_id, credit_amount, debit_amount
            FROM journal_lines WHERE journal_id = (
                SELECT id FROM journal_entries
                WHERE reference_type = 'sale' AND reference_id = ?
                ORDER BY id DESC LIMIT 1
            )
        ")->execute([$journalId, $saleId]);

        $db->commit();
        return ['success' => true, 'message' => "Sale #{$saleId} voided successfully."];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'message' => 'Void failed: ' . $e->getMessage()];
    }
}

// ──────────────────────────────────────────────────────────────
// 4. RECEIPT & SALE QUERIES
// ──────────────────────────────────────────────────────────────

/**
 * Get full sale details with line items.
 */
function get_sale(int $saleId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT s.*, u.full_name AS cashier_name, u.username
        FROM sales s
        JOIN users u ON u.id = s.user_id
        WHERE s.id = ?
    ");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    if (!$sale) return null;

    $itemsStmt = $db->prepare("
        SELECT si.*, p.name, p.sku, p.sell_unit
        FROM sale_items si
        JOIN products p ON p.id = si.product_id
        WHERE si.sale_id = ?
        ORDER BY si.id
    ");
    $itemsStmt->execute([$saleId]);
    $sale['items'] = $itemsStmt->fetchAll();

    return $sale;
}

/**
 * Get sale by receipt number.
 */
function get_sale_by_receipt(string $receiptNo): ?array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM sales WHERE receipt_no = ?");
    $stmt->execute([$receiptNo]);
    $row = $stmt->fetch();
    return $row ? get_sale((int)$row['id']) : null;
}

/**
 * List sales for a date range with optional filters.
 */
function list_sales(string $fromDate = '', string $toDate = '', ?int $shiftId = null, ?string $paymentMethod = null, int $page = 1, int $perPage = 50): array
{
    $db = getDB();
    $offset = ($page - 1) * $perPage;
    $where = "WHERE 1=1";
    $params = [];

    if ($fromDate) {
        $where .= " AND s.created_at >= ?";
        $params[] = $fromDate . ' 00:00:00';
    }
    if ($toDate) {
        $where .= " AND s.created_at <= ?";
        $params[] = $toDate . ' 23:59:59';
    }
    if ($shiftId) {
        $where .= " AND s.shift_id = ?";
        $params[] = $shiftId;
    }
    if ($paymentMethod) {
        $where .= " AND s.payment_method = ?";
        $params[] = $paymentMethod;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM sales s {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT s.*, u.full_name AS cashier_name
        FROM sales s
        JOIN users u ON u.id = s.user_id
        {$where}
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $perPage;
    $params[] = $offset;
    $stmt->execute($params);

    return [
        'data'       => $stmt->fetchAll(),
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'total_pages'=> (int) ceil($total / $perPage),
    ];
}

/**
 * Get today's sales summary for the dashboard.
 */
function today_sales_summary(): array
{
    $db = getDB();
    $today = date('Y-m-d');

    $stmt = $db->prepare("
        SELECT
            COUNT(*)::int                        AS transaction_count,
            COALESCE(SUM(total), 0)              AS total_sales,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total ELSE 0 END), 0) AS cash_sales,
            COALESCE(SUM(CASE WHEN payment_method = 'mpesa' THEN total ELSE 0 END), 0) AS mpesa_sales,
            COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total ELSE 0 END), 0)  AS card_sales,
            COALESCE(AVG(total), 0)              AS avg_transaction
        FROM sales
        WHERE created_at::date = ?
          AND sale_status = 'complete'
    ");
    $stmt->execute([$today]);
    return $stmt->fetch();
}