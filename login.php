<?php
/**
 * Maseno Retail ERP - Login Page
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth.php';

init_session();

// Redirect if already logged in
if (!empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } elseif (defined('BACKEND_MODE') && BACKEND_MODE) {
        // Delegate authentication to the Node.js backend
        $rawBackendUrl = getenv('BACKEND_URL');
        $backendUrl = rtrim($rawBackendUrl, '/');

        if (empty($backendUrl)) {
            $error = 'BACKEND_URL is not configured. Please set the BACKEND_URL environment variable in your Render dashboard to point to your Node.js backend (e.g., https://your-backend.onrender.com).';
        } else {
            $endpoint = $backendUrl . '/api/auth/login';

            $payload = json_encode([
                'username' => $username,
                'password' => $password,
            ]);

            $context = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 10,
                ]
            ]);

            $response = @file_get_contents($endpoint, false, $context);
            if ($response === false) {
                $error = 'Could not reach authentication server at: ' . htmlspecialchars($endpoint) . '. Please ensure the Node.js backend is running and BACKEND_URL is correct in Render.';
            } else {
                $data = json_decode($response, true);
                if (!empty($data['success']) && !empty($data['user'])) {
                    $_SESSION['user'] = $data['user'];
                    header('Location: index.php');
                    exit;
                } else {
                    $error = $data['message'] ?? 'Invalid username or password.';
                }
            }
        }
    } else {
        $result = login_user($username, $password);
        if ($result['success']) {
            header('Location: index.php');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= STORE_NAME ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="login-page">
        <div class="login-card">
            <h1>🏪 <?= htmlspecialchars(STORE_NAME) ?></h1>
            <p class="subtitle">ERP Supermarket Management System</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control"
                           placeholder="Enter username" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control"
                           placeholder="Enter password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg">Sign In</button>
            </form>

            <p class="text-center text-muted mt-2" style="font-size:0.8rem;">
                Default: admin / admin123
            </p>
        </div>
    </div>
</body>
</html>