<?php
/**
 * Maseno Retail ERP - Point of Sale Interface
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pos_backend.php';

$user = require_auth();
$shift = defined('BACKEND_MODE') && BACKEND_MODE ? null : current_shift();
$categories = defined('BACKEND_MODE') && BACKEND_MODE ? [] : get_categories();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - <?= htmlspecialchars(STORE_NAME) ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span class="brand-icon">🏪</span>
                <span>Maseno Retail</span>
            </div>
            <nav class="sidebar-nav">
                <a href="index.php"><span class="nav-icon">📊</span> Dashboard</a>
                <a href="pos.php" class="active"><span class="nav-icon">🛒</span> Point of Sale</a>
                <a href="inventory.php"><span class="nav-icon">📦</span> Inventory</a>
                <a href="accounting.php"><span class="nav-icon">💰</span> Accounting</a>
                <a href="expiry.php"><span class="nav-icon">⏰</span> Expiry Tracker</a>
                <a href="crm.php"><span class="nav-icon">👥</span> Customers</a>
                <a href="mpesa.php"><span class="nav-icon">📱</span> M-Pesa</a>
                <a href="shift.php"><span class="nav-icon">🔄</span> Shift Manager</a>
            </nav>
            <div class="sidebar-footer">
                <div><?= htmlspecialchars($user['full_name']) ?> (<?= $user['role'] ?>)</div>
                <a href="logout.php" style="color:rgba(255,255,255,.5); text-decoration:none;">Logout</a>
            </div>
        </aside>

        <main class="main-content" style="padding:10px 20px;">
            <?php if (!$shift): ?>
                <div class="alert alert-warning">
                    No open shift found. <a href="shift/open.php" class="btn btn-primary btn-sm">Open Shift</a>
                </div>
            <?php else: ?>
                <div class="pos-layout">
                    <!-- Left: Product Grid -->
                    <div>
                        <div class="flex-between mb-1">
                            <div class="search-bar" style="flex:1; margin-bottom:0;">
                                <input type="text" id="pos-search" class="form-control"
                                       placeholder="🔍 Search products by name, SKU, or scan barcode... (F1)" autofocus>
                                <select id="pos-category" class="form-control" style="max-width:150px;">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <span class="badge badge-success">Shift #<?= $shift['id'] ?></span>
                            </div>
                        </div>
                        <div id="pos-products" class="pos-products">
                            <!-- Products loaded via JS -->
                        </div>
                    </div>

                    <!-- Right: Cart -->
                    <div class="pos-cart">
                        <div class="pos-cart-header">
                            <span>🛒 Cart</span>
                            <span id="cart-count">0</span>
                        </div>

                        <!-- Customer -->
                        <div style="padding:10px; border-bottom:1px solid var(--light-gray);">
                            <div class="form-inline">
                                <input type="text" id="customer-search" class="form-control"
                                       placeholder="Customer phone..." style="flex:1;">
                                <span id="customer-name" style="font-size:.85rem; color:var(--gray);">Walk-in Customer</span>
                            </div>
                        </div>

                        <!-- Cart Items -->
                        <div id="cart-items" class="pos-cart-items">
                            <!-- Cart items loaded via JS -->
                        </div>

                        <!-- Totals & Checkout -->
                        <div class="pos-cart-totals">
                            <div class="total-row">
                                <span>Subtotal</span>
                                <span id="cart-subtotal">0.00</span>
                            </div>
                            <div class="total-row">
                                <span>Discount</span>
                                <span id="cart-discount">0.00</span>
                            </div>
                            <div class="total-row">
                                <span>Tax (<?= TAX_RATE_PCT ?>%)</span>
                                <span id="cart-tax">0.00</span>
                            </div>
                            <div class="total-row grand-total">
                                <span>Total</span>
                                <span id="cart-grand-total">0.00</span>
                            </div>

                            <div class="payment-row">
                                <select id="payment-method" class="form-control">
                                    <option value="cash">💵 Cash</option>
                                    <option value="mpesa">📱 M-Pesa</option>
                                    <option value="card">💳 Card</option>
                                    <option value="credit">📋 Credit</option>
                                </select>
                                <input type="number" id="amount-tendered" class="form-control"
                                       placeholder="Amount Tendered" step="0.5" min="0">
                            </div>
                            <div class="flex-between" style="margin:5px 0; font-size:.85rem;">
                                <span>Change Due:</span>
                                <span id="change-due" style="font-weight:700; color:var(--secondary);">0.00</span>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px;">
                                <button id="btn-checkout" class="btn btn-success btn-lg" <?= !$shift ? 'disabled' : '' ?>>
                                    💳 Charge (F8)
                                </button>
                                <button id="btn-clear-cart" class="btn btn-outline">🗑️ Clear</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Receipt Modal -->
    <div id="receipt-modal" class="modal-overlay">
        <div class="modal" style="max-width:400px;">
            <div class="modal-header">
                <h2>🧾 Receipt</h2>
                <span class="modal-close">&times;</span>
            </div>
            <div class="modal-body" id="receipt-content">
                <!-- Receipt content loaded via JS -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="window.print()">🖨️ Print</button>
                <button class="btn btn-primary modal-close">Close</button>
            </div>
        </div>
    </div>

    <script>
        const STORE_NAME = <?= json_encode(STORE_NAME) ?>;
        const STORE_PHONE = <?= json_encode(STORE_PHONE) ?>;
        const CURRENCY = <?= json_encode(CURRENCY) ?>;
        const TAX_RATE_PCT = <?= TAX_RATE_PCT ?>;
        const CASHIER_NAME = <?= json_encode($user['full_name']) ?>;
        // Backend URL for Node.js bridge (empty string means same origin)
        const BACKEND_URL = <?= json_encode(getenv('BACKEND_URL') ?: '') ?>;
    </script>
    <script src="js/pos.js"></script>
</body>
</html>