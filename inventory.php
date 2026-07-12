<?php
/**
 * Maseno Retail ERP - Inventory & Stock Control
 *
 * Manages:
 *   - Stock receiving (purchase orders → stock batches)
 *   - Bulk-to-retail conversion (e.g., Case of 24 → Pieces)
 *   - Stock adjustments, transfers, and write-offs
 *   - Stock variance tracking and low-stock alerts
 *   - Batch/expiry tracking
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth.php';

// ──────────────────────────────────────────────────────────────
// 1. PRODUCT MANAGEMENT
// ──────────────────────────────────────────────────────────────

/**
 * Create or update a product.
 */
function save_product(array $data): array
{
    $db = getDB();
    $id = (int) ($data['id'] ?? 0);

    $fields = [
        'sku'               => $data['sku'] ?? '',
        'barcode'           => $data['barcode'] ?? '',
        'name'              => $data['name'] ?? '',
        'category'          => $data['category'] ?? 'General',
        'description'       => $data['description'] ?? '',
        'sell_unit'         => $data['sell_unit'] ?? 'piece',
        'purchase_unit'     => $data['purchase_unit'] ?? 'case',
        'conversion_factor' => (float) ($data['conversion_factor'] ?? 1),
        'supplier_price'    => (float) ($data['supplier_price'] ?? 0),
        'selling_price'     => (float) ($data['selling_price'] ?? 0),
        'low_stock_qty'     => (float) ($data['low_stock_qty'] ?? LOW_STOCK_QTY),
        'has_expiry'        => !empty($data['has_expiry']) ? 'TRUE' : 'FALSE',
    ];

    if ($id > 0) {
        $sql = "UPDATE products SET
                    sku = ?, barcode = ?, name = ?, category = ?, description = ?,
                    sell_unit = ?, purchase_unit = ?, conversion_factor = ?,
                    supplier_price = ?, selling_price = ?, low_stock_qty = ?,
                    has_expiry = {$fields['has_expiry']}, updated_at = NOW()
                WHERE id = ? RETURNING id";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $fields['sku'], $fields['barcode'], $fields['name'], $fields['category'],
            $fields['description'], $fields['sell_unit'], $fields['purchase_unit'],
            $fields['conversion_factor'], $fields['supplier_price'], $fields['selling_price'],
            $fields['low_stock_qty'], $id,
        ]);
    } else {
        $sql = "INSERT INTO products
                    (sku, barcode, name, category, description, sell_unit, purchase_unit,
                     conversion_factor, supplier_price, selling_price, low_stock_qty, has_expiry)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, {$fields['has_expiry']})
                RETURNING id";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $fields['sku'], $fields['barcode'], $fields['name'], $fields['category'],
            $fields['description'], $fields['sell_unit'], $fields['purchase_unit'],
            $fields['conversion_factor'], $fields['supplier_price'], $fields['selling_price'],
            $fields['low_stock_qty'],
        ]);
    }

    $row = $stmt->fetch();
    return ['success' => true, 'product_id' => (int) $row['id'], 'message' => 'Product saved.'];
}

/**
 * Toggle product active status.
 */
function toggle_product_active(int $productId, bool $active): array
{
    $db = getDB();
    $db->prepare("UPDATE products SET is_active = ?, updated_at = NOW() WHERE id = ?")
       ->execute([$active ? 'TRUE' : 'FALSE', $productId]);
    return ['success' => true, 'message' => $active ? 'Product activated.' : 'Product deactivated.'];
}

// ──────────────────────────────────────────────────────────────
// 2. STOCK RECEIVING (Purchase Order → Inventory)
// ──────────────────────────────────────────────────────────────

/**
 * Receive stock from a purchase order.
 * Converts purchase units to sell units using conversion_factor.
 * Creates stock_batches for expiry-tracked products.
 *
 * Example: Case of 24 with conversion_factor=24 means 1 case = 24 pieces.
 * Receiving 2 cases adds 48 pieces to current_stock.
 */
function receive_purchase_order(int $poId, int $userId): array
{
    $db = getDB();
    $db->beginTransaction();
    try {
        $po = $db->prepare("
            SELECT * FROM purchase_orders WHERE id = ? AND status = 'approved'
        ");
        $po->execute([$poId]);
        $order = $po->fetch();
        if (!$order) {
            return ['success' => false, 'message' => 'Purchase order not found or not approved.'];
        }

        $items = $db->prepare("
            SELECT poi.*, p.name, p.sku, p.conversion_factor, p.sell_unit, p.purchase_unit, p.has_expiry
            FROM purchase_order_items poi
            JOIN products p ON p.id = poi.product_id
            WHERE poi.po_id = ?
        ");
        $items->execute([$poId]);

        $movementStmt = $db->prepare("
            INSERT INTO stock_movements
                (product_id, movement_type, quantity, unit_cost, batch_number, expiry_date, reference_id, notes, created_by)
            VALUES (?, 'purchase_receive', ?, ?, ?, ?, ?, ?, ?)
        ");
        $batchStmt = $db->prepare("
            INSERT INTO stock_batches
                (product_id, batch_number, quantity, unit_cost, manufacture_date, expiry_date, supplier_id, purchase_order_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($items as $item) {
            $qtyReceived = (float) $item['quantity_received'];
            if ($qtyReceived <= 0) continue;

            // Convert purchase units to sell units
            $sellQty = round($qtyReceived * (float) $item['conversion_factor'], 4);

            // Update product stock (stored in sell units)
            $db->prepare("UPDATE products SET current_stock = current_stock + ?, updated_at = NOW() WHERE id = ?")
               ->execute([$sellQty, $item['product_id']]);

            // Record stock movement
            $batchNo = 'PO-' . $poId . '-B' . time();
            $expiryDate = null;
            $movementStmt->execute([
                $item['product_id'], $sellQty, $item['unit_cost'],
                $batchNo, $expiryDate, $poId,
                "Received from PO #{$order['po_number']}", $userId,
            ]);

            // Create stock batch for expiry tracking
            if (!empty($item['has_expiry'])) {
                $batchStmt->execute([
                    $item['product_id'], $batchNo, $sellQty, $item['unit_cost'],
                    null, '2099-12-31',
                    $order['supplier_id'], $poId,
                ]);
            }
        }

        // Mark PO as received
        $db->prepare("
            UPDATE purchase_orders
            SET status = 'received', received_by = ?, received_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ")->execute([$userId, $poId]);

        $db->commit();
        return ['success' => true, 'message' => "Purchase order #{$order['po_number']} received."];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'message' => 'Receive failed: ' . $e->getMessage()];
    }
}

/**
 * Direct stock adjustment (add or remove).
 */
function adjust_stock(int $productId, float $quantity, string $reason, int $userId, ?float $unitCost = null): array
{
    $db = getDB();
    $movementType = $quantity > 0 ? 'adjustment_add' : 'adjustment_remove';

    $db->beginTransaction();
    try {
        $product = get_product($productId);
        if (!$product) {
            return ['success' => false, 'message' => 'Product not found.'];
        }

        $newStock = $product['current_stock'] + $quantity;
        if ($newStock < 0) {
            return ['success' => false, 'message' => sprintf(
                'Cannot remove %.4f %s. Only %.4f available.',
                abs($quantity), $product['sell_unit'], $product['current_stock']
            )];
        }

        $db->prepare("UPDATE products SET current_stock = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$newStock, $productId]);

        $db->prepare("
            INSERT INTO stock_movements
                (product_id, movement_type, quantity, unit_cost, reference_id, notes, created_by)
            VALUES (?, ?, ?, ?, NULL, ?, ?)
        ")->execute([$productId, $movementType, $quantity, $unitCost ?? $product['supplier_price'], $reason, $userId]);

        $db->commit();
        return ['success' => true, 'message' => sprintf(
            'Stock adjusted by %+.4f %s. New balance: %.4f',
            $quantity, $product['sell_unit'], $newStock
        )];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'message' => 'Adjustment failed: ' . $e->getMessage()];
    }
}

/**
 * Write off expired stock from a specific batch.
 */
function write_off_expired_batch(int $batchId, int $userId): array
{
    $db = getDB();
    $db->beginTransaction();
    try {
        $batch = $db->prepare("
            SELECT sb.*, p.name, p.sell_unit
            FROM stock_batches sb
            JOIN products p ON p.id = sb.product_id
            WHERE sb.id = ? AND sb.is_expired = FALSE
        ");
        $batch->execute([$batchId]);
        $data = $batch->fetch();
        if (!$data) {
            return ['success' => false, 'message' => 'Batch not found or already written off.'];
        }

        $qty = (float) $data['quantity'];
        if ($qty <= 0) {
            return ['success' => false, 'message' => 'Batch quantity is zero.'];
        }

        // Deduct from product stock
        $db->prepare("UPDATE products SET current_stock = current_stock - ?, updated_at = NOW() WHERE id = ?")
           ->execute([$qty, $data['product_id']]);

        // Record movement
        $db->prepare("
            INSERT INTO stock_movements
                (product_id, movement_type, quantity, unit_cost, batch_number, reference_id, notes, created_by)
            VALUES (?, 'expiry_writeoff', ?, ?, ?, NULL, ?, ?)
        ")->execute([
            $data['product_id'], -$qty, $data['unit_cost'], $data['batch_number'],
            "Expired batch #{$data['batch_number']} - {$data['name']}", $userId,
        ]);

        // Mark batch as expired
        $db->prepare("UPDATE stock_batches SET is_expired = TRUE, quantity = 0 WHERE id = ?")
           ->execute([$batchId]);

        $db->commit();
        return ['success' => true, 'message' => "Expired batch #{$data['batch_number']} written off. {$qty} {$data['sell_unit']} removed."];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'message' => 'Write-off failed: ' . $e->getMessage()];
    }
}

// ──────────────────────────────────────────────────────────────
// 3. STOCK QUERIES & REPORTS
// ──────────────────────────────────────────────────────────────

/**
 * Get low-stock products (current_stock <= low_stock_qty).
 */
function get_low_stock_products(): array
{
    $db = getDB();
    return $db->query("
        SELECT id, sku, name, category, sell_unit, current_stock, low_stock_qty, supplier_price, selling_price
        FROM products
        WHERE is_active = TRUE AND current_stock <= low_stock_qty
        ORDER BY (current_stock::float / NULLIF(low_stock_qty, 0)) ASC
    ")->fetchAll();
}

/**
 * Get stock movement history for a product.
 */
function get_stock_movements(int $productId, int $limit = 100): array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT sm.*, u.full_name AS created_by_name
        FROM stock_movements sm
        LEFT JOIN users u ON u.id = sm.created_by
        WHERE sm.product_id = ?
        ORDER BY sm.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$productId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Get stock valuation report (current stock × supplier_price).
 */
function stock_valuation_report(): array
{
    $db = getDB();
    $data = $db->query("
        SELECT
            category,
            COUNT(*)::int                          AS product_count,
            COALESCE(SUM(current_stock), 0)        AS total_units,
            COALESCE(SUM(current_stock * supplier_price), 0) AS cost_value,
            COALESCE(SUM(current_stock * selling_price), 0)  AS retail_value
        FROM products
        WHERE is_active = TRUE
        GROUP BY category
        ORDER BY category
    ")->fetchAll();

    $total = [
        'product_count' => 0,
        'total_units'   => 0,
        'cost_value'    => 0,
        'retail_value'  => 0,
    ];
    foreach ($data as &$row) {
        $total['product_count'] += (int) $row['product_count'];
        $total['total_units']   += (float) $row['total_units'];
        $total['cost_value']    += (float) $row['cost_value'];
        $total['retail_value']  += (float) $row['retail_value'];
    }
    unset($row);

    return ['categories' => $data, 'total' => $total];
}

/**
 * Get stock variance report: compares expected stock (based on movements) vs actual.
 */
function stock_variance_report(): array
{
    $db = getDB();
    // Compare actual stock vs calculated stock from movements
    $rows = $db->query("
        SELECT
            p.id, p.sku, p.name, p.category, p.current_stock AS actual_stock,
            COALESCE((
                SELECT SUM(CASE
                    WHEN movement_type IN ('purchase_receive', 'adjustment_add', 'transfer_in') THEN quantity
                    WHEN movement_type IN ('sale_deduction', 'adjustment_remove', 'transfer_out', 'expiry_writeoff') THEN -quantity
                    ELSE 0
                END)
                FROM stock_movements sm
                WHERE sm.product_id = p.id
            ), 0) AS calculated_stock
        FROM products p
        WHERE p.is_active = TRUE
        ORDER BY p.name
    ")->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $variance = round((float)$row['actual_stock'] - (float)$row['calculated_stock'], 4);
        if (abs($variance) > 0.01) {
            $row['variance'] = $variance;
            $result[] = $row;
        }
    }

    usort($result, fn($a, $b) => abs($b['variance']) <=> abs($a['variance']));
    return $result;
}

// ──────────────────────────────────────────────────────────────
// 4. SUPPLIER & PURCHASE ORDER MANAGEMENT
// ──────────────────────────────────────────────────────────────

function save_supplier(array $data): array
{
    $db = getDB();
    $id = (int) ($data['id'] ?? 0);

    if ($id > 0) {
        $stmt = $db->prepare("
            UPDATE suppliers SET company_name=?, contact_person=?, phone=?, email=?,
                address=?, tax_pin=?, payment_terms=?, updated_at=NOW()
            WHERE id=? RETURNING id
        ");
        $stmt->execute([
            $data['company_name'], $data['contact_person'] ?? '', $data['phone'],
            $data['email'] ?? '', $data['address'] ?? '', $data['tax_pin'] ?? '',
            $data['payment_terms'] ?? '30 days', $id,
        ]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO suppliers (company_name, contact_person, phone, email, address, tax_pin, payment_terms)
            VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id
        ");
        $stmt->execute([
            $data['company_name'], $data['contact_person'] ?? '', $data['phone'],
            $data['email'] ?? '', $data['address'] ?? '', $data['tax_pin'] ?? '',
            $data['payment_terms'] ?? '30 days',
        ]);
    }
    $row = $stmt->fetch();
    return ['success' => true, 'supplier_id' => (int) $row['id']];
}

function list_suppliers(bool $activeOnly = true): array
{
    $db = getDB();
    $sql = "SELECT * FROM suppliers";
    if ($activeOnly) $sql .= " WHERE is_active = TRUE";
    $sql .= " ORDER BY company_name";
    return $db->query($sql)->fetchAll();
}

function create_purchase_order(int $supplierId, array $items, int $userId): array
{
    $db = getDB();
    $db->beginTransaction();
    try {
        $poStmt = $db->prepare("
            INSERT INTO purchase_orders (po_number, supplier_id, ordered_by, status)
            VALUES (?, ?, ?, 'pending') RETURNING id
        ");
        $poNumber = 'PO-' . date('Ymd') . '-' . time();
        $poStmt->execute([$poNumber, $supplierId, $userId]);
        $poId = (int) $poStmt->fetchColumn();

        $itemStmt = $db->prepare("
            INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered, unit_cost, line_total)
            VALUES (?, ?, ?, ?, ?)
        ");
        $total = 0;
        foreach ($items as $item) {
            $lineTotal = round($item['quantity'] * $item['unit_cost'], 2);
            $itemStmt->execute([$poId, $item['product_id'], $item['quantity'], $item['unit_cost'], $lineTotal]);
            $total += $lineTotal;
        }

        $db->prepare("UPDATE purchase_orders SET total_amount = ? WHERE id = ?")
           ->execute([$total, $poId]);

        $db->commit();
        return ['success' => true, 'po_id' => $poId, 'po_number' => $poNumber, 'message' => "PO {$poNumber} created."];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'message' => 'PO creation failed: ' . $e->getMessage()];
    }
}

function approve_purchase_order(int $poId): array
{
    $db = getDB();
    $db->prepare("UPDATE purchase_orders SET status = 'approved', updated_at = NOW() WHERE id = ? AND status = 'pending'")
       ->execute([$poId]);
    return ['success' => true, 'message' => 'Purchase order approved.'];
}

function list_purchase_orders(string $status = ''): array
{
    $db = getDB();
    $sql = "
        SELECT po.*, s.company_name AS supplier, u.full_name AS ordered_by_name
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        JOIN users u ON u.id = po.ordered_by
    ";
    $params = [];
    if ($status) {
        $sql .= " WHERE po.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY po.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}