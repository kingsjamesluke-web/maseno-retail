<?php
/**
 * Maseno Retail ERP - Open Shift
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../auth.php';

$user = require_auth();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $openingFloat = isset($_POST['opening_float']) ? (float) $_POST['opening_float'] : 0.00;
    $notes = trim($_POST['notes'] ?? '');

    if (defined('BACKEND_MODE') && BACKEND_MODE) {
        // In backend mode, shift management is handled by Node.js backend
        $message = 'Shift management is handled by the backend system.';
    } else {
        $result = open_shift($user['id'], $openingFloat, $notes);
        if ($result['success']) {
            $_SESSION['flash'] = ['msg' => $result['message'], 'type' => 'success'];
            redirect('/pos.php');
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
    <title>Open Shift - <?= htmlspecialchars(STORE_NAME) ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span class="brand-icon">🏪</span>
                <span>Maseno Retail</span>
            </div>
            <nav class="sidebar-nav">
                <a href="../index.php"><span class="nav-icon">📊</span> Dashboard</a>
                <a href="../pos.php"><span class="nav-icon">🛒</span> Point of Sale</a>
                <a href="../inventory.php"><span class="nav-icon">📦</span> Inventory</a>
                <a href="../accounting.php"><span class="nav-icon">💰</span> Accounting</a>
                <a href="../expiry.php"><span class="nav-icon">⏰</span> Expiry Tracker</a>
                <a href="../crm.php"><span class="nav-icon">👥</span> Customers</a>
                <a href="../mpesa.php"><span class="nav-icon">📱</span> M-Pesa</a>
                <a href="../shift.php"><span class="nav-icon">🔄</span> Shift Manager</a>
            </nav>
        </aside>

        <main class="main-content">
            <div class="page-header">
                <h1>Open Shift</h1>
            </div>

            <?php if (!empty($_SESSION['flash'])): ?>
                <div class="alert alert-<?= $_SESSION['flash']['type'] ?>">
                    <?= htmlspecialchars($_SESSION['flash']['msg']) ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card" style="max-width: 600px;">
                <div class="card-header">
                    <h3>Start New Shift</h3>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="form-group">
                            <label>Opening Float (KES)</label>
                            <input type="number" name="opening_float" class="form-control" value="0" step="0.5" min="0">
                        </div>
                        <div class="form-group">
                            <label>Notes (optional)</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Open Shift</button>
                        <a href="../pos.php" class="btn btn-outline">Cancel</a>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script src="../js/app.js"></script>
</body>
</html>