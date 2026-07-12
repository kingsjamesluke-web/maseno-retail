<?php
/**
 * Maseno Retail ERP - Authentication & Cashier Shift Management
 *
 * Handles user login/logout, session validation, and cashier shift
 * lifecycle (open → close → reconcile). Each sale is mapped to
 * an active shift via shift_id.
 */

require_once __DIR__ . '/config/app.php';

// ──────────────────────────────────────────────────────────────
// 1. SESSION MANAGEMENT
// ──────────────────────────────────────────────────────────────

/**
 * Start a secure session if not already started.
 */
function init_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Log in a user by verifying password against bcrypt hash.
 * On success stores user data in session.
 *
 * @return array ['success' => bool, 'message' => string, 'user' => array|null]
 */
function login_user(string $username, string $password): array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, username, password_hash, full_name, role, phone, email, is_active
        FROM users WHERE username = ?
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid username or password.', 'user' => null];
    }

    if (!$user['is_active']) {
        return ['success' => false, 'message' => 'Account is disabled. Contact admin.', 'user' => null];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid username or password.', 'user' => null];
    }

    // Rehash if needed (PHP's built-in does this automatically with PASSWORD_DEFAULT)
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $upd = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([$newHash, $user['id']]);
    }

    // Remove hash from session payload
    unset($user['password_hash']);
    $_SESSION['user'] = $user;

    return ['success' => true, 'message' => 'Login successful.', 'user' => $user];
}

/**
 * Log out the current user and destroy session.
 */
function logout_user(): void
{
    init_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Require authentication. Redirects to login page if not authenticated.
 */
function require_auth(): array
{
    init_session();
    if (empty($_SESSION['user'])) {
        redirect('/login.php', 'Please log in first.', 'warning');
    }
    return $_SESSION['user'];
}

/**
 * Require a specific role (or array of roles). Dies with 403 if unauthorized.
 */
function require_role(string|array $roles): array
{
    $user = require_auth();
    $roles = (array) $roles;
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden: insufficient privileges.']));
    }
    return $user;
}

/**
 * Returns the currently logged-in user from session, or null.
 */
function current_user(): ?array
{
    init_session();
    return $_SESSION['user'] ?? null;
}

// ──────────────────────────────────────────────────────────────
// 2. CASHIER SHIFT OPERATIONS
// ──────────────────────────────────────────────────────────────

/**
 * Open a new cashier shift for the given user.
 * Only one open shift per user is allowed (enforced by DB unique constraint).
 *
 * @param int    $userId
 * @param float  $openingFloat  Cash float at shift start
 * @param string $notes         Optional notes
 * @return array ['success' => bool, 'shift_id' => int|null, 'message' => string]
 */
function open_shift(int $userId, float $openingFloat = 0.00, string $notes = ''): array
{
    $db = getDB();

    // Check for existing open shift
    $check = $db->prepare("
        SELECT id FROM cashier_shifts
        WHERE user_id = ? AND status = 'open'
        LIMIT 1
    ");
    $check->execute([$userId]);
    if ($check->fetch()) {
        return ['success' => false, 'shift_id' => null, 'message' => 'You already have an open shift.'];
    }

    $stmt = $db->prepare("
        INSERT INTO cashier_shifts (user_id, opening_float, notes)
        VALUES (?, ?, ?)
        RETURNING id
    ");
    $stmt->execute([$userId, $openingFloat, $notes]);
    $shiftId = (int) $stmt->fetchColumn();

    $_SESSION['shift_id'] = $shiftId;
    $_SESSION['shift_open_at'] = date('c');

    return ['success' => true, 'shift_id' => $shiftId, 'message' => 'Shift opened successfully.'];
}

/**
 * Get the currently active shift for the logged-in user.
 * Returns null if no open shift exists.
 */
function current_shift(): ?array
{
    $user = current_user();
    if (!$user) return null;

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, user_id, opened_at, opening_float, expected_cash, actual_cash, variance, status, notes
        FROM cashier_shifts
        WHERE user_id = ? AND status = 'open'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $shift = $stmt->fetch();
    return $shift ?: null;
}

/**
 * Get the active shift ID from session or DB.
 */
function require_shift(): int
{
    // Fast path: session
    if (!empty($_SESSION['shift_id'])) {
        // Verify it's still open in DB
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM cashier_shifts WHERE id = ? AND status = 'open'");
        $stmt->execute([(int)$_SESSION['shift_id']]);
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];
    }

    // Slow path: query DB
    $user = current_user();
    if (!$user) {
        redirect('/login.php', 'Please log in.', 'warning');
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id FROM cashier_shifts
        WHERE user_id = ? AND status = 'open'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        redirect('/shift/open.php', 'You must open a shift before making sales.', 'warning');
    }

    $_SESSION['shift_id'] = (int)$row['id'];
    return (int)$row['id'];
}

/**
 * Close the current shift, recording expected vs actual cash.
 *
 * @param float $actualCash Physical cash counted at the till
 * @return array ['success' => bool, 'message' => string, 'data' => array|null]
 */
function close_shift(float $actualCash): array
{
    $user  = require_auth();
    $shift = current_shift();
    if (!$shift) {
        return ['success' => false, 'message' => 'No open shift found.', 'data' => null];
    }

    $db = getDB();

    // Calculate expected cash: opening_float + cash sales - cash expenses during shift
    $calc = $db->prepare("
        SELECT
            COALESCE(SUM(s.total), 0) AS cash_sales_total
        FROM sales s
        WHERE s.shift_id = ?
          AND s.payment_method = 'cash'
          AND s.sale_status = 'complete'
    ");
    $calc->execute([$shift['id']]);
    $cashSales = (float) $calc->fetchColumn();

    $expectedCash = $shift['opening_float'] + $cashSales;
    $variance     = round($actualCash - $expectedCash, 2);

    $stmt = $db->prepare("
        UPDATE cashier_shifts
        SET closed_at       = NOW(),
            expected_cash   = ?,
            actual_cash     = ?,
            variance        = ?,
            status          = 'closed'
        WHERE id = ? AND status = 'open'
        RETURNING id, opened_at, closed_at, opening_float, expected_cash, actual_cash, variance
    ");
    $stmt->execute([$expectedCash, $actualCash, $variance, $shift['id']]);
    $closed = $stmt->fetch();

    // Clear session shift
    unset($_SESSION['shift_id'], $_SESSION['shift_open_at']);

    // Create journal entry for shift close (record cash variance)
    if (abs($variance) > 0.01) {
        $jeStmt = $db->prepare("
            INSERT INTO journal_entries (entry_date, reference_type, reference_id, description, created_by)
            VALUES (CURRENT_DATE, 'shift_close', ?, ?, ?)
            RETURNING id
        ");
        $desc = sprintf('Shift #%d close: variance KES %+.2f', $shift['id'], $variance);
        $jeStmt->execute([$shift['id'], $desc, $user['id']]);
        $journalId = $jeStmt->fetchColumn();

        if ($variance > 0) {
            // Surplus → credit income
            $db->prepare("
                INSERT INTO journal_lines (journal_id, account_id, debit_amount, credit_amount)
                SELECT ?, id, 0, ? FROM gl_accounts WHERE account_code = '1100'
            ")->execute([$journalId, abs($variance)]);
            $db->prepare("
                INSERT INTO journal_lines (journal_id, account_id, debit_amount, credit_amount)
                SELECT ?, id, ?, 0 FROM gl_accounts WHERE account_code = '4100'
            ")->execute([$journalId, abs($variance)]);
        } else {
            // Shortage → expense
            $db->prepare("
                INSERT INTO journal_lines (journal_id, account_id, debit_amount, credit_amount)
                SELECT ?, id, ?, 0 FROM gl_accounts WHERE account_code = '6200'
            ")->execute([$journalId, abs($variance)]);
            $db->prepare("
                INSERT INTO journal_lines (journal_id, account_id, debit_amount, credit_amount)
                SELECT ?, id, 0, ? FROM gl_accounts WHERE account_code = '1100'
            ")->execute([$journalId, abs($variance)]);
        }
    }

    return [
        'success' => true,
        'message' => sprintf('Shift #%d closed. Expected: KES %s | Actual: KES %s | Variance: KES %+.2f',
            $closed['id'],
            number_format($closed['expected_cash'], 2),
            number_format($closed['actual_cash'], 2),
            $variance
        ),
        'data' => $closed,
    ];
}

/**
 * List shifts for a given date range.
 */
function list_shifts(string $fromDate = '', string $toDate = '', ?int $userId = null): array
{
    $db = getDB();
    $sql = "
        SELECT cs.*, u.full_name AS cashier_name, u.username
        FROM cashier_shifts cs
        JOIN users u ON u.id = cs.user_id
        WHERE 1=1
    ";
    $params = [];

    if ($fromDate) {
        $sql .= " AND cs.opened_at >= ?";
        $params[] = $fromDate . ' 00:00:00';
    }
    if ($toDate) {
        $sql .= " AND cs.opened_at <= ?";
        $params[] = $toDate . ' 23:59:59';
    }
    if ($userId) {
        $sql .= " AND cs.user_id = ?";
        $params[] = $userId;
    }

    $sql .= " ORDER BY cs.opened_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}