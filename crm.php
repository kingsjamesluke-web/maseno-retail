<?php
/**
 * Maseno Retail ERP - CRM & Store Configuration
 *
 * Manages:
 *   - Customer profiles & loyalty tracking
 *   - Store configuration metadata
 *   - Customer search, registration, and analytics
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth.php';

// ──────────────────────────────────────────────────────────────
// 1. CUSTOMER MANAGEMENT
// ──────────────────────────────────────────────────────────────

/**
 * Register a new customer or update existing by phone.
 */
function save_customer(array $data): array
{
    $db = getDB();
    $id = (int) ($data['id'] ?? 0);

    if ($id > 0) {
        $stmt = $db->prepare("
            UPDATE customers SET
                first_name = ?, last_name = ?, phone = ?, email = ?,
                address = ?, id_number = ?, is_wholesale = ?,
                notes = ?, updated_at = NOW()
            WHERE id = ? RETURNING id
        ");
        $stmt->execute([
            $data['first_name'], $data['last_name'], $data['phone'],
            $data['email'] ?? '', $data['address'] ?? '', $data['id_number'] ?? '',
            !empty($data['is_wholesale']) ? 'TRUE' : 'FALSE',
            $data['notes'] ?? '', $id,
        ]);
    } else {
        // Check for duplicate phone
        $check = $db->prepare("SELECT id FROM customers WHERE phone = ?");
        $check->execute([$data['phone']]);
        if ($existing = $check->fetch()) {
            // Update instead
            $data['id'] = (int) $existing['id'];
            return save_customer($data);
        }

        $stmt = $db->prepare("
            INSERT INTO customers
                (first_name, last_name, phone, email, address, id_number, is_wholesale, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $data['first_name'], $data['last_name'], $data['phone'],
            $data['email'] ?? '', $data['address'] ?? '', $data['id_number'] ?? '',
            !empty($data['is_wholesale']) ? 'TRUE' : 'FALSE',
            $data['notes'] ?? '',
        ]);
    }

    $row = $stmt->fetch();
    return ['success' => true, 'customer_id' => (int) $row['id'], 'message' => 'Customer saved.'];
}

/**
 * Find customer by phone or ID.
 */
function find_customer(string $query): ?array
{
    $db = getDB();

    // Try by phone first
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, phone, email, address, id_number,
               loyalty_points, total_spent, visit_count, last_visit, is_wholesale, notes,
               registered_at
        FROM customers
        WHERE phone = ?
        LIMIT 1
    ");
    $stmt->execute([$query]);
    $customer = $stmt->fetch();
    if ($customer) return $customer;

    // Try by ID (if numeric)
    if (is_numeric($query)) {
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([(int)$query]);
        return $stmt->fetch() ?: null;
    }

    // Partial name search
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, phone, email, total_spent, visit_count, last_visit
        FROM customers
        WHERE first_name ILIKE ? OR last_name ILIKE ?
        ORDER BY last_visit DESC NULLS LAST
        LIMIT 20
    ");
    $like = "%{$query}%";
    $stmt->execute([$like, $like]);
    $results = $stmt->fetchAll();

    return $results ?: null;
}

/**
 * Get customer by ID.
 */
function get_customer(int $customerId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    return $stmt->fetch() ?: null;
}

/**
 * List customers with search and pagination.
 */
function list_customers(string $search = '', int $page = 1, int $perPage = 50): array
{
    $db = getDB();
    $offset = ($page - 1) * $perPage;
    $where = "WHERE 1=1";
    $params = [];

    if ($search) {
        $where .= " AND (first_name ILIKE ? OR last_name ILIKE ? OR phone ILIKE ?)";
        $like = "%{$search}%";
        $params = [$like, $like, $like];
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM customers {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT id, first_name, last_name, phone, email, loyalty_points,
               total_spent, visit_count, last_visit, is_wholesale, registered_at
        FROM customers {$where}
        ORDER BY last_visit DESC NULLS LAST, first_name ASC
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
 * Add loyalty points to a customer.
 */
function add_loyalty_points(int $customerId, int $points): array
{
    $db = getDB();
    $db->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?")
       ->execute([$points, $customerId]);
    return ['success' => true, 'message' => "{$points} loyalty points added."];
}

/**
 * Get customer purchase history.
 */
function get_customer_purchases(int $customerId, int $limit = 20): array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT s.id, s.receipt_no, s.total, s.payment_method, s.created_at,
               COUNT(si.id)::int AS item_count
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        WHERE s.customer_id = ? AND s.sale_status = 'complete'
        GROUP BY s.id, s.receipt_no, s.total, s.payment_method, s.created_at
        ORDER BY s.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$customerId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Get top customers by total spent.
 */
function get_top_customers(int $limit = 10): array
{
    $db = getDB();
    return $db->query("
        SELECT id, first_name, last_name, phone, total_spent, visit_count, last_visit
        FROM customers
        WHERE total_spent > 0
        ORDER BY total_spent DESC
        LIMIT {$limit}
    ")->fetchAll();
}

// ──────────────────────────────────────────────────────────────
// 2. STORE CONFIGURATION
// ──────────────────────────────────────────────────────────────

/**
 * Update a store configuration value.
 */
function set_store_config(string $key, string $value): array
{
    $db = getDB();
    $db->prepare("
        INSERT INTO store_config (config_key, config_value, updated_at)
        VALUES (?, ?, NOW())
        ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value, updated_at = NOW()
    ")->execute([$key, $value]);
    return ['success' => true, 'message' => "Config '{$key}' updated."];
}

/**
 * Get a single store config value.
 */
function get_store_config(string $key, string $default = ''): string
{
    return store_config($key, $default);
}

/**
 * Get all store configs.
 */
function get_all_configs(): array
{
    return all_store_config();
}

// ──────────────────────────────────────────────────────────────
// 3. CRM DASHBOARD STATS
// ──────────────────────────────────────────────────────────────

function crm_dashboard_stats(): array
{
    $db = getDB();

    $totalCustomers = $db->query("SELECT COUNT(*)::int FROM customers")->fetchColumn();
    $wholesaleCount = $db->query("SELECT COUNT(*)::int FROM customers WHERE is_wholesale = TRUE")->fetchColumn();
    $totalLoyalty   = $db->query("SELECT COALESCE(SUM(loyalty_points), 0)::int FROM customers")->fetchColumn();
    $avgSpent       = $db->query("SELECT COALESCE(AVG(total_spent), 0) FROM customers WHERE total_spent > 0")->fetchColumn();

    // New customers this month
    $monthStart = date('Y-m-01');
    $newThisMonth = $db->prepare("SELECT COUNT(*)::int FROM customers WHERE registered_at::date >= ?");
    $newThisMonth->execute([$monthStart]);
    $newCount = (int) $newThisMonth->fetchColumn();

    return [
        'total_customers'  => (int) $totalCustomers,
        'wholesale_count'  => (int) $wholesaleCount,
        'total_loyalty'    => (int) $totalLoyalty,
        'avg_spent'        => round((float) $avgSpent, 2),
        'new_this_month'   => $newCount,
        'top_customers'    => get_top_customers(5),
    ];
}