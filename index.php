<?php
/**
 * Maseno Retail ERP - Main Dashboard
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pos_backend.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/expiry.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/mpesa_sandbox.php';

$user = require_auth();
$shift = current_shift();

// Dashboard data
$todaySales    = today_sales_summary();
$lowStock      = get_low_stock_products();
$expiryAlerts  = get_expiry_alerts();
$financials    = dashboard_financial_summary();
$crmStats      = crm_dashboard_stats();
$expiryStats   = expiry_dashboard_stats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars(STORE_NAME) ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span class="brand-icon">🏪</span>
                <span>Maseno Retail</span>
            </div>
            <nav class="sidebar-nav">
                <a href="index.php" class="active"><span class="nav-icon">📊</span> Dashboard</a>
                <a href="pos.php"><span class="nav-icon">🛒</span> Point of Sale</a>
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

        <!-- Main Content -->
        <main class="main-content">
            <div class="page-header">
                <h1>Dashboard</h1>
                <div class="header-actions">
                    <?php if ($shift): ?>
                        <span class="badge badge-success">Shift #<?= $shift['id'] ?> Open</span>
                    <?php else: ?>
                        <a href="shift/open.php" class="btn btn-primary btn-sm">Open Shift</a>
                    <?php endif; ?>
                    <a href="pos.php" class="btn btn-success btn-sm">🛒 New Sale</a>
                </div>
            </div>

            <!-- Flash messages -->
            <?php if (!empty($_SESSION['flash'])): ?>
                <div class="alert alert-<?= $_SESSION['flash']['type'] ?>">
                    <?= htmlspecialchars($_SESSION['flash']['msg']) ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value">KES <?= number_format($todaySales['total_sales'], 2) ?></div>
                    <div class="stat-label">Today's Sales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $todaySales['transaction_count'] ?></div>
                    <div class="stat-label">Transactions Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">KES <?= number_format($financials['today']['gross_margin'], 2) ?></div>
                    <div class="stat-label">Today's Gross Margin</div>
                </div>
                <div class="stat-card stat-warning">
                    <div class="stat-value"><?= count($lowStock) ?></div>
                    <div class="stat-label">Low Stock Items</div>
                </div>
                <div class="stat-card stat-danger">
                    <div class="stat-value"><?= count($expiryAlerts['critical']) ?></div>
                    <div class="stat-label">Critical Expiry Alerts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $crmStats['total_customers'] ?></div>
                    <div class="stat-label">Total Customers</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <!-- Low Stock Alerts -->
                <div class="card">
                    <div class="card-header">
                        <h3>📦 Low Stock Alerts</h3>
                        <a href="inventory.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($lowStock) === 0): ?>
                            <p class="text-muted">No low stock items.</p>
                        <?php else: ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Category</th>
                                            <th class="text-right">Stock</th>
                                            <th class="text-right">Min</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($lowStock, 0, 10) as $p): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($p['name']) ?></td>
                                            <td><?= htmlspecialchars($p['category']) ?></td>
                                            <td class="text-right text-danger"><?= (float)$p['current_stock'] ?></td>
                                            <td class="text-right"><?= (float)$p['low_stock_qty'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Expiry Alerts -->
                <div class="card">
                    <div class="card-header">
                        <h3>⏰ Expiry Alerts</h3>
                        <a href="expiry.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($expiryAlerts['critical']) === 0 && count($expiryAlerts['warning']) === 0): ?>
                            <p class="text-muted">No expiry alerts.</p>
                        <?php else: ?>
                            <?php if (count($expiryAlerts['critical']) > 0): ?>
                                <h4 style="color:var(--danger); font-size:.9rem; margin-bottom:8px;">🔴 Critical (≤3 days)</h4>
                                <?php foreach (array_slice($expiryAlerts['critical'], 0, 5) as $b): ?>
                                    <div style="font-size:.85rem; padding:4px 0; border-bottom:1px solid #eee;">
                                        <strong><?= htmlspecialchars($b['product_name']) ?></strong> -
                                        Batch <?= htmlspecialchars($b['batch_number']) ?> -
                                        Expires <?= $b['expiry_date'] ?>
                                        (<?= (float)$b['quantity'] ?> units)
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if (count($expiryAlerts['warning']) > 0): ?>
                                <h4 style="color:var(--warning); font-size:.9rem; margin:8px 0;">🟡 Warning (<?= EXPIRY_ALERT_DAYS ?> days)</h4>
                                <?php foreach (array_slice($expiryAlerts['warning'], 0, 5) as $b): ?>
                                    <div style="font-size:.85rem; padding:4px 0; border-bottom:1px solid #eee;">
                                        <strong><?= htmlspecialchars($b['product_name']) ?></strong> -
                                        Expires <?= $b['expiry_date'] ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Today's Sales Breakdown -->
                <div class="card">
                    <div class="card-header">
                        <h3>💵 Today's Sales Breakdown</h3>
                    </div>
                    <div class="card-body">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                            <div>
                                <div class="text-muted" style="font-size:.85rem;">Cash</div>
                                <div style="font-weight:700; font-size:1.1rem;">KES <?= number_format($todaySales['cash_sales'], 2) ?></div>
                            </div>
                            <div>
                                <div class="text-muted" style="font-size:.85rem;">M-Pesa</div>
                                <div style="font-weight:700; font-size:1.1rem;">KES <?= number_format($todaySales['mpesa_sales'], 2) ?></div>
                            </div>
                            <div>
                                <div class="text-muted" style="font-size:.85rem;">Card</div>
                                <div style="font-weight:700; font-size:1.1rem;">KES <?= number_format($todaySales['card_sales'], 2) ?></div>
                            </div>
                            <div>
                                <div class="text-muted" style="font-size:.85rem;">Avg Transaction</div>
                                <div style="font-weight:700; font-size:1.1rem;">KES <?= number_format($todaySales['avg_transaction'], 2) ?></div>
                            </div>
                        </div>
                        <hr style="margin:15px 0;">
                        <div class="flex-between">
                            <span><strong>COGS</strong></span>
                            <span>KES <?= number_format($financials['today']['cogs'], 2) ?></span>
                        </div>
                        <div class="flex-between">
                            <span><strong>Expenses</strong></span>
                            <span>KES <?= number_format($financials['today']['expenses'], 2) ?></span>
                        </div>
                        <div class="flex-between" style="font-size:1.1rem; font-weight:700; color:var(--primary); margin-top:8px;">
                            <span>Net Margin</span>
                            <span>KES <?= number_format($financials['today']['net_margin'], 2) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Month Overview -->
                <div class="card">
                    <div class="card-header">
                        <h3>📆 Month Overview</h3>
                    </div>
                    <div class="card-body">
                        <div class="flex-between" style="padding:8px 0;">
                            <span>Month Revenue</span>
                            <span style="font-weight:700;">KES <?= number_format($financials['month']['revenue'], 2) ?></span>
                        </div>
                        <div class="flex-between" style="padding:8px 0;">
                            <span>Month Expenses</span>
                            <span style="font-weight:700; color:var(--danger);">KES <?= number_format($financials['month']['expenses'], 2) ?></span>
                        </div>
                        <div class="flex-between" style="padding:8px 0; font-size:1.2rem; border-top:2px solid var(--light-gray);">
                            <span>Net Position</span>
                            <span style="font-weight:700; color:<?= $financials['month']['net'] >= 0 ? 'var(--secondary)' : 'var(--danger)' ?>;">
                                KES <?= number_format($financials['month']['net'], 2) ?>
                            </span>
                        </div>
                        <hr>
                        <div class="flex-between">
                            <span>Total Customers</span>
                            <span><?= $crmStats['total_customers'] ?></span>
                        </div>
                        <div class="flex-between">
                            <span>New This Month</span>
                            <span><?= $crmStats['new_this_month'] ?></span>
                        </div>
                        <div class="flex-between">
                            <span>Expired Stock Loss</span>
                            <span style="color:var(--danger);">KES <?= number_format($expiryStats['expired_loss_value'], 2) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Customers -->
            <div class="card mt-1">
                <div class="card-header">
                    <h3>🏆 Top Customers</h3>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th class="text-right">Total Spent</th>
                                    <th class="text-right">Visits</th>
                                    <th>Last Visit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($crmStats['top_customers'] as $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></td>
                                    <td><?= htmlspecialchars($c['phone']) ?></td>
                                    <td class="text-right">KES <?= number_format($c['total_spent'], 2) ?></td>
                                    <td class="text-right"><?= $c['visit_count'] ?></td>
                                    <td><?= $c['last_visit'] ? date('d/m/Y H:i', strtotime($c['last_visit'])) : '-' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Global JS constants for frontend
        const STORE_NAME = <?= json_encode(STORE_NAME) ?>;
        const STORE_PHONE = <?= json_encode(STORE_PHONE) ?>;
        const CURRENCY = <?= json_encode(CURRENCY) ?>;
        const TAX_RATE_PCT = <?= TAX_RATE_PCT ?>;
        const CASHIER_NAME = <?= json_encode($user['full_name']) ?>;
        // Backend URL for Node.js bridge (empty string means same origin)
        const BACKEND_URL = <?= json_encode(getenv('BACKEND_URL') ?: '') ?>;
    </script>
    <script src="js/app.js"></script>
</body>
</html>