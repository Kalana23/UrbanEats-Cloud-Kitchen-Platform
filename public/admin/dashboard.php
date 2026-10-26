<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component A & C: Administrator Executive Control Center Dashboard
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enforce Administrator role
require_role(['Administrator', 'Admin', 1]);

// 1. Live Platform Summary Counters
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$orderCount = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$activeOrderCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('pending', 'confirmed', 'preparing', 'ready_for_pickup', 'out_for_delivery')")->fetchColumn();
$dishCount = (int)$pdo->query('SELECT COUNT(*) FROM dishes WHERE is_active = 1')->fetchColumn();
$lowStockCount = (int)$pdo->query('SELECT COUNT(*) FROM inventory WHERE current_stock <= reorder_level')->fetchColumn();
$totalRevenueLkr = (float)$pdo->query("SELECT COALESCE(SUM(total_amount_lkr), 0) FROM orders WHERE order_status != 'cancelled'")->fetchColumn();

// 2. Recent Active Orders
$recentOrdersStmt = $pdo->query("
    SELECT o.order_id, o.order_number, o.order_status, o.total_amount_lkr, o.created_at,
           u.first_name, u.last_name
    FROM orders o
    JOIN users u ON o.user_id = u.user_id
    ORDER BY o.created_at DESC
    LIMIT 6
");
$recentOrders = $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Administrator Command Dashboard - UrbanEats';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 5rem;">

    <!-- Dashboard Hero Header -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span class="role-badge badge-admin" style="margin-bottom: 0.5rem;">Platform Operations</span>
            <h1 style="font-size: 2.25rem; font-weight: 800; margin-bottom: 0.25rem;">
                Administrator Control Center
            </h1>
            <p style="color: var(--text-secondary); margin: 0; font-size: 0.95rem;">
                Executive overview of cloud kitchen operations, culinary inventory, and real-time sales performance.
            </p>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="reports.php" class="btn btn-primary btn-sm">Sales Reports &rarr;</a>
            <a href="../orders/kitchen-queue.php" class="btn btn-secondary btn-sm">Kitchen Queue &rarr;</a>
            <a href="../orders/history.php?all=1" class="btn btn-secondary btn-sm">All Orders</a>
        </div>
    </div>

    <!-- Live Metric Cards Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1.25rem; margin-bottom: 2.5rem;">
        
        <!-- Total Revenue -->
        <div class="card" style="padding: 1.5rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-medium);">
            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.05em;">Total Revenue</span>
            <div style="font-size: 1.55rem; font-weight: 800; color: var(--primary); font-family: var(--font-mono); margin: 0.35rem 0;">
                LKR <?= number_format($totalRevenueLkr, 2); ?>
            </div>
            <span style="font-size: 0.775rem; color: var(--success);">&bull; Excluding cancelled orders</span>
        </div>

        <!-- Active Orders -->
        <div class="card" style="padding: 1.5rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-medium);">
            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.05em;">Active Kitchen Orders</span>
            <div style="font-size: 1.55rem; font-weight: 800; color: var(--accent); margin: 0.35rem 0;">
                <?= $activeOrderCount; ?>
            </div>
            <span style="font-size: 0.775rem; color: var(--text-muted);">Total orders: <?= $orderCount; ?></span>
        </div>

        <!-- Low Stock Items -->
        <div class="card" style="padding: 1.5rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-medium);">
            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.05em;">Inventory Attention</span>
            <div style="font-size: 1.55rem; font-weight: 800; color: <?= ($lowStockCount > 0) ? '#fbbf24' : '#34d399'; ?>; margin: 0.35rem 0;">
                <?= $lowStockCount; ?> Items
            </div>
            <span style="font-size: 0.775rem; color: var(--text-muted);">At or below reorder level</span>
        </div>

        <!-- Total Registered Users -->
        <div class="card" style="padding: 1.5rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-medium);">
            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.05em;">Registered Accounts</span>
            <div style="font-size: 1.55rem; font-weight: 800; color: #c084fc; margin: 0.35rem 0;">
                <?= $userCount; ?> Users
            </div>
            <span style="font-size: 0.775rem; color: var(--text-muted);"><?= $dishCount; ?> Active Menu Dishes</span>
        </div>

    </div>

    <!-- Module Quick Navigation Grid -->
    <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin-bottom: 1.25rem;">
        Platform Control Modules
    </h3>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 3rem;">
        
        <!-- Reports -->
        <a href="reports.php" class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); text-decoration: none; border: 1px solid var(--border-medium); transition: var(--transition-base);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                <span class="role-badge badge-admin" style="font-size: 0.75rem;">Component C</span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
            </div>
            <h4 style="font-size: 1.15rem; color: var(--text-primary); font-weight: 800; margin-bottom: 0.4rem;">Sales &amp; Financial Reports</h4>
            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">
                Revenue trends, top-selling dishes, order status aggregations, and date filtering.
            </p>
        </a>

        <!-- Kitchen Queue -->
        <a href="../orders/kitchen-queue.php" class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); text-decoration: none; border: 1px solid var(--border-medium); transition: var(--transition-base);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                <span class="role-badge badge-staff" style="font-size: 0.75rem;">Component C</span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
            </div>
            <h4 style="font-size: 1.15rem; color: var(--text-primary); font-weight: 800; margin-bottom: 0.4rem;">Kitchen Preparation Queue</h4>
            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">
                Active culinary prep line, strict state transitions, chef notes, and courier dispatch.
            </p>
        </a>

        <!-- Master Orders -->
        <a href="../orders/history.php?all=1" class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); text-decoration: none; border: 1px solid var(--border-medium); transition: var(--transition-base);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                <span class="role-badge badge-customer" style="font-size: 0.75rem;">Component C</span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            </div>
            <h4 style="font-size: 1.15rem; color: var(--text-primary); font-weight: 800; margin-bottom: 0.4rem;">Master Orders &amp; Invoices</h4>
            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">
                All customer transactions, printable tax invoices, and real-time delivery tracking.
            </p>
        </a>

        <!-- User Management -->
        <a href="users.php" class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); text-decoration: none; border: 1px solid var(--border-medium); transition: var(--transition-base);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                <span class="role-badge badge-admin" style="font-size: 0.75rem;">Component A</span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#c084fc" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </div>
            <h4 style="font-size: 1.15rem; color: var(--text-primary); font-weight: 800; margin-bottom: 0.4rem;">User &amp; Role Management</h4>
            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">
                Manage administrator, kitchen staff, and customer accounts with role permissions.
            </p>
        </a>

        <!-- Stock Control -->
        <a href="../menu/stock.php" class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); text-decoration: none; border: 1px solid var(--border-medium); transition: var(--transition-base);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                <span class="role-badge badge-staff" style="font-size: 0.75rem;">Component B</span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
            </div>
            <h4 style="font-size: 1.15rem; color: var(--text-primary); font-weight: 800; margin-bottom: 0.4rem;">Stock &amp; Inventory Control</h4>
            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">
                Live inventory tracking, restock logs, wastage deduction, and low-stock alerts.
            </p>
        </a>

        <!-- Audit Log -->
        <a href="audit-log.php" class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); text-decoration: none; border: 1px solid var(--border-medium); transition: var(--transition-base);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                <span class="role-badge badge-admin" style="font-size: 0.75rem;">Component A</span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            </div>
            <h4 style="font-size: 1.15rem; color: var(--text-primary); font-weight: 800; margin-bottom: 0.4rem;">System Security Audit Trail</h4>
            <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0;">
                Comprehensive activity log recording logins, profile changes, and order status transitions.
            </p>
        </a>

    </div>

    <!-- Recent Active Orders Table -->
    <div class="card glass-panel" style="overflow: hidden;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 0.2rem;">Latest Platform Orders</h3>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Real-time dispatch overview</span>
            </div>
            <a href="../orders/history.php?all=1" style="font-size: 0.85rem; color: var(--primary); font-weight: 700;">View All Orders &rarr;</a>
        </div>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-medium); background: var(--bg-surface);">
                        <th style="padding: 0.85rem 1.25rem; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Order #</th>
                        <th style="padding: 0.85rem 1.25rem; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Customer</th>
                        <th style="padding: 0.85rem 1.25rem; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Status</th>
                        <th style="padding: 0.85rem 1.25rem; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; text-align: right;">Total LKR</th>
                        <th style="padding: 0.85rem 1.25rem; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $ro): ?>
                        <tr style="border-bottom: 1px solid var(--border-subtle);">
                            <td style="padding: 1rem 1.25rem; font-family: var(--font-mono); font-weight: 800; color: var(--text-primary);">
                                <?= htmlspecialchars($ro['order_number'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding: 1rem 1.25rem; color: var(--text-secondary);">
                                <?= htmlspecialchars($ro['first_name'] . ' ' . $ro['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding: 1rem 1.25rem;">
                                <span style="font-size: 0.785rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: var(--radius-pill); text-transform: uppercase; background: var(--bg-surface); border: 1px solid var(--border-medium); color: var(--text-primary);">
                                    <?= htmlspecialchars(str_replace('_', ' ', (string)$ro['order_status']), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="padding: 1rem 1.25rem; text-align: right; font-family: var(--font-mono); font-weight: 700; color: var(--primary);">
                                LKR <?= number_format((float)$ro['total_amount_lkr'], 2); ?>
                            </td>
                            <td style="padding: 1rem 1.25rem; text-align: right;">
                                <a href="../orders/tracking.php?order_id=<?= (int)$ro['order_id']; ?>" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.35rem 0.65rem;">Track</a>
                                <a href="../orders/invoice.php?order_id=<?= (int)$ro['order_id']; ?>" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.35rem 0.65rem;">Invoice</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
