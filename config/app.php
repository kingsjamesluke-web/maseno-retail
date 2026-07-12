<?php
/**
 * Maseno Retail ERP - Application Configuration
 * Central config loader for store settings, tax, and constants.
 *
 * On first load, checks database availability. If unavailable,
 * register_db_check_shutdown() renders a beautiful setup page
 * without crashing the server (no 500 errors).
 */

require_once __DIR__ . '/database.php';

// ──────────────────────────────────────────────────────────────
// Bootstrap: Register graceful DB-failure page
// ──────────────────────────────────────────────────────────────

// This ensures that if the database is unreachable, a clean setup
// page is displayed instead of a 500 error. The PHP server stays alive.
register_db_check_shutdown();

// ──────────────────────────────────────────────────────────────
// Store Configuration Helpers
// ──────────────────────────────────────────────────────────────

/**
 * Load a single store config value from the database.
 * Falls back to $default if not found or on error.
 */
function store_config(string $key, string $default = ''): string
{
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT config_value FROM store_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['config_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Load all store configs into an associative array (cached per request).
 */
function all_store_config(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $db    = getDB();
        $rows  = $db->query("SELECT config_key, config_value FROM store_config")->fetchAll();
        $cache = [];
        foreach ($rows as $r) {
            $cache[$r['config_key']] = $r['config_value'];
        }
        return $cache;
    } catch (Exception $e) {
        return [];
    }
}

// ──────────────────────────────────────────────────────────────
// Runtime Constants
// ──────────────────────────────────────────────────────────────
// These are defined at module load time. If the DB is down,
// register_db_check_shutdown() will have already registered a
// shutdown function, so the script will exit with the setup page
// before any code uses these constants. If DB is available, they
// load normally.

define('CURRENCY',         store_config('currency', 'KES'));
define('TAX_RATE_PCT',     (float) store_config('tax_rate_pct', '16'));
define('TAX_RATE',         TAX_RATE_PCT / 100);
define('LOW_STOCK_QTY',    (float) store_config('low_stock_threshold', '10'));
define('EXPIRY_ALERT_DAYS', (int) store_config('expiry_alert_days', '14'));
define('STORE_NAME',       store_config('store_name', 'Maseno Retail Supermarket'));
define('STORE_PHONE',      store_config('store_phone', '+254700000000'));
define('STORE_EMAIL',      store_config('store_email', 'info@masenoretail.co.ke'));
define('STORE_URL',        store_config('store_url', 'http://localhost:8080'));

// Runtime mode detection
define('BACKEND_MODE', (bool) getenv('BACKEND_URL'));

/**
 * JSON response helper.
 */
function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Redirect with optional flash message.
 */
function redirect(string $url, string $flash_msg = '', string $flash_type = 'info'): never
{
    if ($flash_msg) {
        $_SESSION['flash'] = ['msg' => $flash_msg, 'type' => $flash_type];
    }
    header("Location: $url");
    exit;
}