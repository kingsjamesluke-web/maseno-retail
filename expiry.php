<?php
/**
 * Maseno Retail ERP - Expiry Prompt Engine
 *
 * Tracks perishable goods via stock_batches and provides:
 *   - Expiry alerts (configurable X days before expiry)
 *   - Daily scanning of batches nearing expiration
 *   - Automated flagging of expired batches
 *   - Write-off recommendations
 */

require_once __DIR__ . '/config/app.php';

// ──────────────────────────────────────────────────────────────
// 1. EXPIRY SCANNING & ALERTS
// ──────────────────────────────────────────────────────────────

/**
 * Get all batches that are expiring within the alert threshold.
 * Threshold is configured in EXPIRY_ALERT_DAYS (default 14 days).
 *
 * @param int $withinDays Override the alert window
 * @return array [
 *   'critical' => [...],  // expired or expiring in <= 3 days
 *   'warning'  => [...],  // expiring between 4 and threshold days
 * ]
 */
function get_expiry_alerts(int $withinDays = 0): array
{
    $db = getDB();
    $threshold = $withinDays > 0 ? $withinDays : EXPIRY_ALERT_DAYS;

    // Critical: already expired or expiring within 3 days
    $critical = $db->prepare("
        SELECT
            sb.id AS batch_id,
            sb.batch_number,
            sb.quantity,
            sb.unit_cost,
            sb.manufacture_date,
            sb.expiry_date,
            sb.is_expired,
            sb.alert_sent,
            p.id AS product_id,
            p.sku,
            p.name AS product_name,
            p.category,
            p.sell_unit,
            p.selling_price,
            p.current_stock,
            s.company_name AS supplier
        FROM stock_batches sb
        JOIN products p ON p.id = sb.product_id
        LEFT JOIN suppliers s ON s.id = sb.supplier_id
        WHERE sb.is_expired = FALSE
          AND sb.quantity > 0
          AND sb.expiry_date <= CURRENT_DATE + INTERVAL '3 days'
        ORDER BY sb.expiry_date ASC
    ");
    $critical->execute();
    $criticalAlerts = $critical->fetchAll();

    // Warning: expiring between 4 days and the threshold
    $warning = $db->prepare("
        SELECT
            sb.id AS batch_id,
            sb.batch_number,
            sb.quantity,
            sb.unit_cost,
            sb.manufacture_date,
            sb.expiry_date,
            sb.is_expired,
            sb.alert_sent,
            p.id AS product_id,
            p.sku,
            p.name AS product_name,
            p.category,
            p.sell_unit,
            p.selling_price,
            p.current_stock,
            s.company_name AS supplier
        FROM stock_batches sb
        JOIN products p ON p.id = sb.product_id
        LEFT JOIN suppliers s ON s.id = sb.supplier_id
        WHERE sb.is_expired = FALSE
          AND sb.quantity > 0
          AND sb.expiry_date > CURRENT_DATE + INTERVAL '3 days'
          AND sb.expiry_date <= ?::DATE
        ORDER BY sb.expiry_date ASC
    ");
    $warning->execute([date('Y-m-d', strtotime("+{$threshold} days"))]);
    $warningAlerts = $warning->fetchAll();

    // Mark alerts as sent
    $alertIds = array_merge(
        array_column($criticalAlerts, 'batch_id'),
        array_column($warningAlerts, 'batch_id')
    );
    if ($alertIds) {
        $placeholders = implode(',', array_fill(0, count($alertIds), '?'));
        $db->prepare("UPDATE stock_batches SET alert_sent = TRUE WHERE id IN ({$placeholders})")
           ->execute($alertIds);
    }

    return [
        'critical' => $criticalAlerts,
        'warning'  => $warningAlerts,
        'alert_date' => date('Y-m-d H:i:s'),
    ];
}

/**
 * Auto-flag batches that have passed their expiry date.
 * This does NOT write off stock but marks them as expired for reporting.
 *
 * @return int Number of batches newly flagged as expired
 */
function auto_flag_expired_batches(): int
{
    $db = getDB();

    $stmt = $db->exec("
        UPDATE stock_batches
        SET is_expired = TRUE
        WHERE is_expired = FALSE
          AND expiry_date < CURRENT_DATE
          AND quantity > 0
    ");

    return $stmt; // number of rows updated
}

/**
 * Get all batches that are already expired but still have quantity > 0.
 * These need write-off action.
 */
function get_expired_batches_for_writeoff(): array
{
    $db = getDB();
    return $db->query("
        SELECT
            sb.id AS batch_id,
            sb.batch_number,
            sb.quantity,
            sb.unit_cost,
            sb.expiry_date,
            p.id AS product_id,
            p.sku,
            p.name AS product_name,
            p.category,
            p.sell_unit,
            p.selling_price,
            (sb.quantity * sb.unit_cost) AS total_cost_value
        FROM stock_batches sb
        JOIN products p ON p.id = sb.product_id
        WHERE sb.is_expired = TRUE
          AND sb.quantity > 0
        ORDER BY sb.expiry_date ASC
    ")->fetchAll();
}

// ──────────────────────────────────────────────────────────────
// 2. EXPIRY DASHBOARD DATA
// ──────────────────────────────────────────────────────────────

/**
 * Get consolidated expiry statistics for the dashboard.
 */
function expiry_dashboard_stats(): array
{
    $db = getDB();

    // Total active batches with expiry
    $activeBatches = $db->query("
        SELECT COUNT(*)::int AS count,
               COALESCE(SUM(quantity), 0) AS total_units
        FROM stock_batches
        WHERE is_expired = FALSE AND quantity > 0
    ")->fetch();

    // Already expired but not written off
    $expiredBatches = $db->query("
        SELECT COUNT(*)::int AS count,
               COALESCE(SUM(quantity), 0) AS total_units,
               COALESCE(SUM(quantity * unit_cost), 0) AS total_loss_value
        FROM stock_batches
        WHERE is_expired = TRUE AND quantity > 0
    ")->fetch();

    // Products with expiry tracking
    $expiryProducts = $db->query("
        SELECT COUNT(*)::int
        FROM products
        WHERE has_expiry = TRUE AND is_active = TRUE
    ")->fetchColumn();

    // Nearest expiring batches (top 10)
    $nearest = $db->query("
        SELECT
            sb.id, sb.batch_number, sb.quantity, sb.expiry_date,
            p.name AS product_name, p.sku, p.sell_unit,
            sb.expiry_date - CURRENT_DATE AS days_remaining
        FROM stock_batches sb
        JOIN products p ON p.id = sb.product_id
        WHERE sb.is_expired = FALSE AND sb.quantity > 0
        ORDER BY sb.expiry_date ASC
        LIMIT 10
    ")->fetchAll();

    return [
        'active_batches'       => (int) $activeBatches['count'],
        'active_units'         => (float) $activeBatches['total_units'],
        'expired_batches'      => (int) $expiredBatches['count'],
        'expired_units'        => (float) $expiredBatches['total_units'],
        'expired_loss_value'   => (float) $expiredBatches['total_loss_value'],
        'expiry_products_count'=> (int) $expiryProducts,
        'nearest_expiring'     => $nearest,
    ];
}

/**
 * Get all products that have expiry enabled, with their nearest batch expiry.
 */
function get_expiry_products_with_batches(): array
{
    $db = getDB();
    return $db->query("
        SELECT
            p.id, p.sku, p.name, p.category, p.sell_unit, p.current_stock,
            sb.id AS batch_id, sb.batch_number, sb.quantity AS batch_qty,
            sb.manufacture_date, sb.expiry_date,
            sb.expiry_date - CURRENT_DATE AS days_remaining,
            CASE
                WHEN sb.expiry_date < CURRENT_DATE THEN 'expired'
                WHEN sb.expiry_date <= CURRENT_DATE + INTERVAL '3 days' THEN 'critical'
                WHEN sb.expiry_date <= CURRENT_DATE + INTERVAL '14 days' THEN 'warning'
                ELSE 'ok'
            END AS status
        FROM products p
        LEFT JOIN stock_batches sb ON sb.product_id = p.id AND sb.is_expired = FALSE AND sb.quantity > 0
        WHERE p.has_expiry = TRUE AND p.is_active = TRUE
        ORDER BY sb.expiry_date ASC NULLS LAST, p.name ASC
    ")->fetchAll();
}