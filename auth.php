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
 * Call the Node.js backend authentication endpoint.
 */
function backend_auth(string $endpoint, array $payload): array
{
    // Use relative path; Apache reverse proxy forwards /api/* to Node.js backend
    $url = $endpoint;

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 10,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['success' => false, 'message' => 'Could not reach authentication server at ' . $url];
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : ['success' => false, 'message' => 'Invalid response from authentication server.'];
}

/**
 * Log in a user by delegating to the Node.js backend.
 */
function login_user(string $username, string $password): array
{
    if (defined('BACKEND_MODE') && BACKEND_MODE) {
        $result = backend_auth('/api/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        if (!empty($result['success']) && !empty($result['user'])) {
            $_SESSION['user'] = $result['user'];
        }
        return $result;
    }

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

    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $upd = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([$newHash, $user['id']]);
    }

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
 * Open a new cashier shift for the given user via backend.
 */
function open_shift(int $userId, float $openingFloat = 0.00, string $notes = ''): array
{
    if (defined('BACKEND_MODE') && BACKEND_MODE) {
        $result = backend_auth('/api/shifts/open', [
            'user_id' => $userId,
            'opening_float' => $openingFloat,
            'notes' => $notes,
        ]);
        if (!empty($result['success']) && !empty($result['shift_id'])) {
            $_SESSION['shift_id'] = (int)$result['shift_id'];
            $_SESSION['shift_open_at'] = date('c');
        }
        return $result;
    }

    $db = getDB();
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
 * Get the currently active shift for the logged-in user via backend.
 */
function current_shift(): ?array
{
    if (defined('BACKEND_MODE') && BACKEND_MODE) {
        $result = backend_auth('/api/shifts/current', []);
        return !empty($result['success']) && !empty($result['shift']) ? $result['shift'] : null;
    }

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
 * Get the active shift ID from session or backend.
 */
function require_shift(): int
{
    if (defined('BACKEND_MODE') && BACKEND_MODE) {
        $result = backend_auth('/api/shifts/current', []);
        if (!empty($result['success']) && !empty($result['shift']['id'])) {
            $_SESSION['shift_id'] = (int)$result['shift']['id'];
            return (int)$result['shift']['id'];
        }
        redirect('/shift/open.php', 'You must open a shift before making sales.', 'warning');
    }

    if (!empty($_SESSION['shift_id'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM cashier_shifts WHERE id = ? AND status = 'open'");
        $stmt->execute([(int)$_SESSION['shift_id']]);
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];
    }

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
 * Close the current shift via backend.
 */
function close_shift(float $actualCash): array
{
    if (defined('BACKEND_MODE') && BACKEND_MODE) {
        $shiftId = $_SESSION['shift_id'] ?? 0;
        return backend_auth('/api/shifts/close', [
            'shift_id' => $shiftId,
            'actual_cash' => $actualCash,
        ]);
    }

    $user  = require_auth();
    $shift = current_shift();
    if (!$shift) {
        return ['success' => false, 'message' => 'No open shift found.', 'data' => null];
    }

    $db = getDB();
    $calc = $db->prepare("
        SELECT COALESCE(SUM(s.total), 0) AS cash_sales_total
        FROM sales s
        WHERE s.shift_id = ? AND s.payment_method = 'cash' AND s.sale_status = 'complete'
    ");
    $calc->execute([$shift['id']]);
    $cashSales = (float) $calc->fetchColumn();

    $expectedCash = $shift['opening_float'] + $cashSales;
    $variance     = round($actualCash - $expectedCash, 2);

    $stmt = $db->prepare("
        UPDATE cashier_shifts
        SET closed_at = NOW(), expected_cash = ?, actual_cash = ?, variance = ?, status = 'closed'
        WHERE id = ? AND status = 'open'
        RETURNING id, opened_at, closed_at, opening_float, expected_cash, actual_cash, variance
    ");
    $stmt->execute([$expectedCash, $actualCash, $variance, $shift['id']]);
    $closed = $stmt->fetch();

    unset($_SESSION['shift_id'], $_SESSION['shift_open_at']);

    return [
        'success' => true,
        'message' => sprintf('Shift #%d closed. Expected: KES %s | Actual: KES %s | Variance: KES %+.2f',
            $closed['id'], number_format($closed['expected_cash'], 2), number_format($closed['actual_cash'], 2), $variance),
        'data' => $closed,
    ];
}

/**
 * List shifts for a given date range via backend.
 */
function list_shifts(string $fromDate = '', string $toDate = '', ?int $userId = null): array
{
    if (defined('BACKEND_MODE') && BACKEND_MODE) {
        $result = backend_auth('/api/shifts/list', [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'user_id' => $userId,
        ]);
        return $result['shifts'] ?? [];
    }

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