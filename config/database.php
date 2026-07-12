<?php
/**
 * Maseno Retail ERP - Database Configuration
 * PostgreSQL PDO connection with graceful degradation.
 *
 * If the database is unavailable, getDB() throws a RuntimeException.
 * Use is_db_available() or db_status() to check connectivity without crashing.
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'maseno_retail');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: '');

// If BACKEND_URL is set, skip direct DB connections entirely
if (getenv('BACKEND_URL')) {
    function is_db_available(): bool { return true; }
    function db_status(): array {
        return [
            'available' => true,
            'error'     => null,
            'host'      => DB_HOST,
            'port'      => DB_PORT,
            'database'  => DB_NAME,
            'user'      => DB_USER,
        ];
    }
    function getDB(): PDO {
        throw new RuntimeException('Database connections are managed by the Node.js backend in production.');
    }
    function register_db_check_shutdown(): void {
        // Do nothing — backend handles data
    }
    // Stub auth/shift functions to avoid DB queries in backend mode
    function current_shift(): ?array { return null; }
    function require_shift(): int { return 0; }
}

/** @var string|null Stores the last connection error message */
$_db_error = null;

/**
 * Check whether the PostgreSQL database is reachable.
 * Returns true if a connection can be established, false otherwise.
 * Does NOT throw or die.
 */
function is_db_available(): bool
{
    try {
        $pdo = _create_pdo_connection();
        return $pdo !== null;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get the current database connection status as a human-readable array.
 * Safe to call at any time — never throws or dies.
 *
 * @return array ['available' => bool, 'error' => string|null, 'host' => string, 'port' => string, 'database' => string]
 */
function db_status(): array
{
    global $_db_error;
    $available = false;
    try {
        $pdo = _create_pdo_connection();
        if ($pdo) {
            $pdo->query('SELECT 1');
            $available = true;
        }
    } catch (Exception $e) {
        $_db_error = $e->getMessage();
    }

    return [
        'available' => $available,
        'error'     => $_db_error,
        'host'      => DB_HOST,
        'port'      => DB_PORT,
        'database'  => DB_NAME,
        'user'      => DB_USER,
    ];
}

/**
 * Returns a singleton PDO connection to PostgreSQL.
 *
 * @return PDO
 * @throws RuntimeException if the database is unreachable
 */
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = _create_pdo_connection();
        if ($pdo === null) {
            global $_db_error;
            throw new RuntimeException('Database connection failed: ' . ($_db_error ?? 'Unknown error'));
        }
    }
    return $pdo;
}

/**
 * Internal: create a raw PDO connection (no singleton caching).
 *
 * @return PDO|null
 */
function _create_pdo_connection(): ?PDO
{
    global $_db_error;
    try {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;options=\'--client_encoding=UTF8\'',
            DB_HOST, DB_PORT, DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $_db_error = null;
        return $pdo;
    } catch (PDOException $e) {
        $_db_error = $e->getMessage();
        return null;
    }
}

/**
 * Register a shutdown function that renders a beautiful setup page
 * if the database is unavailable. Call this early in bootstrap.
 */
function register_db_check_shutdown(): void
{
    register_shutdown_function(function () {
        // Only intercept if we're in an HTTP context and DB is down
        if (php_sapi_name() === 'cli') return;
        if (is_db_available()) return;

        // Clean any output already sent
        while (ob_get_level()) ob_end_clean();

        $status = db_status();
        http_response_code(200); // Keep server alive, don't send 500
        render_setup_page($status);
        exit;
    });
}

/**
 * Render a beautiful HTML setup/diagnostics page when the database is unavailable.
 */
function render_setup_page(array $status): void
{
    $host     = htmlspecialchars($status['host']);
    $port     = htmlspecialchars($status['port']);
    $database = htmlspecialchars($status['database']);
    $user     = htmlspecialchars($status['user']);
    $error    = htmlspecialchars($status['error'] ?? 'Unknown error');

    $hint = '';
    if (strpos($error, 'could not connect') !== false || strpos($error, 'Connection refused') !== false) {
        $hint = 'Make sure PostgreSQL is installed and running. Try: <code>sudo systemctl start postgresql</code>';
    } elseif (strpos($error, 'database "' . $database . '" does not exist') !== false) {
        $hint = 'Run the setup script to create the database: <code>bash start-system.sh</code>';
    } elseif (strpos($error, 'password') !== false || strpos($error, 'authentication') !== false) {
        $hint = 'Check your database credentials in <code>config/database.php</code> or set env vars <code>DB_USER</code>/<code>DB_PASS</code>.';
    } else {
        $hint = 'Ensure PostgreSQL is running and the credentials in <code>config/database.php</code> are correct. Then run <code>bash start-system.sh</code>.';
    }

    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Required - Maseno Retail ERP</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 640px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #1a73e8 0%, #1557b0 100%);
            color: #fff;
            padding: 40px 40px 30px;
            text-align: center;
        }
        .header .icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .header p {
            opacity: 0.85;
            font-size: 0.95rem;
        }
        .body {
            padding: 30px 40px 40px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 100px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .status-badge.error {
            background: #fce8e6;
            color: #c5221f;
        }
        .status-badge .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #c5221f;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 20px 0;
        }
        .detail-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px 16px;
        }
        .detail-item .label {
            font-size: 0.75rem;
            color: #5f6368;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .detail-item .value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #202124;
            font-family: 'Courier New', monospace;
        }
        .error-box {
            background: #fce8e6;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 14px 16px;
            margin: 16px 0;
            font-size: 0.85rem;
            color: #c5221f;
            word-break: break-word;
        }
        .hint-box {
            background: #e8f0fe;
            border: 1px solid #c2d9fc;
            border-radius: 8px;
            padding: 16px;
            margin: 16px 0 24px;
            font-size: 0.9rem;
            color: #1967d2;
            line-height: 1.6;
        }
        .hint-box code {
            background: rgba(0,0,0,0.06);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        .terminal-box {
            background: #202124;
            color: #e8eaed;
            border-radius: 8px;
            padding: 16px 20px;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            line-height: 1.7;
            margin: 16px 0 24px;
            overflow-x: auto;
        }
        .terminal-box .prompt { color: #34a853; }
        .terminal-box .cmd { color: #e8eaed; }
        .terminal-box .comment { color: #9aa0a6; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #1a73e8;
            color: #fff;
        }
        .btn-primary:hover { background: #1557b0; }
        .btn-outline {
            background: transparent;
            border: 1px solid #dadce0;
            color: #5f6368;
        }
        .btn-outline:hover { background: #f8f9fa; }
        .footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e8eaed;
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        @media (max-width: 480px) {
            .header { padding: 30px 20px 20px; }
            .body { padding: 20px; }
            .detail-grid { grid-template-columns: 1fr; }
            .footer { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon">🏪</div>
            <h1>Maseno Retail ERP</h1>
            <p>Database connection required</p>
        </div>
        <div class="body">
            <div class="status-badge error">
                <span class="dot"></span>
                Database Unavailable
            </div>

            <p style="color:#5f6368; margin-bottom:8px;">
                The system could not connect to the PostgreSQL database. Please check your configuration or run the automatic setup script.
            </p>

            <div class="error-box">
                <strong>Error:</strong> <?= $error ?>
            </div>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="label">Host</div>
                    <div class="value"><?= $host ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Port</div>
                    <div class="value"><?= $port ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Database</div>
                    <div class="value"><?= $database ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">User</div>
                    <div class="value"><?= $user ?></div>
                </div>
            </div>

            <div class="hint-box">
                <strong>💡 Quick Fix:</strong><br>
                <?= $hint ?>
            </div>

            <div class="terminal-box">
                <div><span class="comment"># Run from the project directory:</span></div>
                <div><span class="prompt">$</span> <span class="cmd">cd <?= htmlspecialchars(getcwd()) ?></span></div>
                <div><span class="prompt">$</span> <span class="cmd">bash start-system.sh</span></div>
                <div style="margin-top:8px;"><span class="comment"># This will create the database, import the schema,</span></div>
                <div><span class="comment"># and start the server on http://localhost:8080</span></div>
            </div>

            <div class="footer">
                <a href="?refresh=<?= time() ?>" class="btn btn-primary">🔄 Retry Connection</a>
                <a href="https://www.postgresql.org/download/" target="_blank" class="btn btn-outline">📥 Install PostgreSQL</a>
            </div>
        </div>
    </div>
</body>
</html><?php
    exit;
}