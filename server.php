<?php
/**
 * Maseno Retail ERP - PHP Built-in Server Router
 *
 * This file is used by the PHP development server (started via start-system.sh).
 * It handles URL routing and serves static files.
 *
 * Usage:
 *   php -S 0.0.0.0:8080 -t /path/to/project server.php
 */

// ── Serve static files if they exist ──
$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

// If it's a real file (css, js, images, etc.), serve it directly
if ($uri !== '/' && is_file($file)) {
    // Determine MIME type
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'map'  => 'application/json',
    ];

    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }

    // Cache static assets for 1 hour
    if (in_array($ext, ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico'])) {
        header('Cache-Control: public, max-age=3600');
    }

    readfile($file);
    return true;
}

// ── Route PHP files ──
// Map URI paths to PHP files
$routes = [
    '/'              => '/index.php',
    '/login'         => '/login.php',
    '/logout'        => '/logout.php',
    '/pos'           => '/pos.php',
    '/dashboard'     => '/index.php',
    '/inventory'     => '/inventory.php',
    '/accounting'    => '/accounting.php',
    '/expiry'        => '/expiry.php',
    '/crm'           => '/crm.php',
    '/customers'     => '/crm.php',
    '/mpesa'         => '/mpesa_sandbox.php',
    '/shift'         => '/auth.php',
];

// Check for exact route match
if (isset($routes[$uri])) {
    $script = __DIR__ . $routes[$uri];
    if (is_file($script)) {
        require $script;
        return true;
    }
}

// ── API routing ──
if (preg_match('#^/api/([a-zA-Z_]+)\.php#', $uri, $matches)) {
    $apiFile = __DIR__ . '/api/' . $matches[1] . '.php';
    if (is_file($apiFile)) {
        require $apiFile;
        return true;
    }
}

// ── Fallback: try direct PHP file ──
$phpFile = __DIR__ . $uri;
if (is_file($phpFile) && pathinfo($phpFile, PATHINFO_EXTENSION) === 'php') {
    require $phpFile;
    return true;
}

// ── 404 handler ──
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Maseno Retail ERP</title>
    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            color: #202124;
        }
        .container { text-align: center; padding: 40px; }
        h1 { font-size: 4rem; margin: 0; color: #1a73e8; }
        h2 { font-size: 1.5rem; margin: 10px 0 20px; color: #5f6368; }
        p { color: #5f6368; margin-bottom: 30px; }
        a {
            display: inline-block;
            padding: 12px 24px;
            background: #1a73e8;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
        }
        a:hover { background: #1557b0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>404</h1>
        <h2>Page Not Found</h2>
        <p>The requested resource could not be found on this server.</p>
        <a href="/">← Back to Dashboard</a>
    </div>
</body>
</html>
<?php
return true;