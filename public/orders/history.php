<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Customer Order History & Dispatch Status Overview
 *
 * Features:
 * - Customer order history with filtering by lifecycle status and keyword search
 * - Summary statistics cards (Total Orders, Active In Progress, Completed, Total LKR Spent)
 * - Order line items summary with item counts and total amount in LKR
 * - Quick direct actions: Live Order Tracking (tracking.php) and Official Tax Invoice (invoice.php)
 * - Secure access control: Customers view only their own orders; staff/admin have oversight view
 *
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Ensure session is active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enforce authentication
require_login('../login.php');

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$isStaffOrAdmin = has_role(['Admin', 'Administrator', 'Kitchen Staff', 1, 2]);

// Filter parameters
$statusFilter = filter_input(INPUT_GET, 'status', FILTER_DEFAULT);
$statusFilter = is_string($statusFilter) ? trim($statusFilter) : '';

$searchKeyword = filter_input(INPUT_GET, 'search', FILTER_DEFAULT);
$searchKeyword = is_string($searchKeyword) ? trim($searchKeyword) : '';

// Admin/Staff can optionally view all orders or specific user
$viewAllStaff = $isStaffOrAdmin && (isset($_GET['all']) && $_GET['all'] === '1');

// Base query for user's orders
$whereConditions = [];
$params = [];

if (!$viewAllStaff) {
    $whereConditions[] = 'o.user_id = :user_id';
    $params[':user_id'] = $currentUserId;
}

if (!empty($statusFilter)) {
    if ($statusFilter === 'active') {
        $whereConditions[] = "o.order_status IN ('pending', 'confirmed', 'preparing', 'ready_for_pickup', 'out_for_delivery')";
    } elseif (in_array($statusFilter, ['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'cancelled'], true)) {
        $whereConditions[] = 'o.order_status = :status_filter';
        $params[':status_filter'] = $statusFilter;
    }
}

if (!empty($searchKeyword)) {
    $whereConditions[] = '(o.order_number LIKE :kw OR o.delivery_city LIKE :kw OR o.delivery_address LIKE :kw)';
    $params[':kw'] = '%' . $searchKeyword . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// 1. Fetch Summary Statistics for Current Customer
$statsSql = '
    SELECT 
        COUNT(*) AS total_orders,
        SUM(CASE WHEN order_status IN (\'pending\', \'confirmed\', \'preparing\', \'ready_for_pickup\', \'out_for_delivery\') THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN order_status = \'delivered\' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN order_status != \'cancelled\' THEN total_amount_lkr ELSE 0 END) AS total_spent_lkr
    FROM orders
';
if (!$viewAllStaff) {
    $statsSql .= ' WHERE user_id = :uid';
    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute([':uid' => $currentUserId]);
} else {
    $statsStmt = $pdo->query($statsSql);
}
$userStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_orders'     => 0,
    'active_orders'    => 0,
    'completed_orders' => 0,
    'total_spent_lkr'  => 0.00,
];

// 2. Fetch Orders matching filter criteria
$sql = "
    SELECT o.*,
           u.first_name, u.last_name, u.email,
           p.payment_method, p.payment_status, p.transaction_reference,
           c.code AS coupon_code,
           (SELECT COUNT(*) FROM order_items WHERE order_id = o.order_id) AS items_count,
           (SELECT SUM(quantity) FROM order_items WHERE order_id = o.order_id) AS total_portions
    FROM orders o
    JOIN users u ON o.user_id = u.user_id
    LEFT JOIN payments p ON o.order_id = p.order_id
    LEFT JOIN coupons c ON o.coupon_id = c.coupon_id
    {$whereClause}
    ORDER BY o.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for status badge styling
function get_order_status_badge(string $status): array
{
    return match ($status) {
        'delivered'        => ['class' => 'badge-delivered', 'text' => 'Delivered'],
        'out_for_delivery' => ['class' => 'badge-out',       'text' => 'Out For Delivery'],
        'ready_for_pickup' => ['class' => 'badge-ready',     'text' => 'Ready For Pickup'],
        'preparing'        => ['class' => 'badge-preparing', 'text' => 'In Kitchen'],
        'confirmed'        => ['class' => 'badge-confirmed', 'text' => 'Confirmed'],
        'cancelled'        => ['class' => 'badge-cancelled', 'text' => 'Cancelled'],
        default            => ['class' => 'badge-pending',   'text' => 'Pending Approval'],
    };
}

$pageTitle = 'My Order History - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.history-hero {
    margin-bottom: 2rem;
}
.history-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2rem;
}
.stat-card {
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-medium);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1.25rem;
    box-shadow: var(--shadow-sm);
    transition: var(--transition-base);
}
.stat-card:hover {
    transform: translateY(-2px);
    border-color: rgba(249, 115, 22, 0.4);
}
.stat-icon {
    width: 52px;
    height: 52px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.stat-content {
    display: flex;
    flex-direction: column;
}
.stat-val {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
}
.stat-lbl {
    font-size: 0.8125rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 600;
}

/* Filter Controls Card */
.history-filters {
    background: var(--bg-surface);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-md);
    padding: 1.25rem 1.5rem;
    margin-bottom: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.status-pill-group {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.status-pill-btn {
    padding: 0.45rem 1rem;
    border-radius: var(--radius-pill);
    font-size: 0.825rem;
    font-weight: 600;
    background: var(--bg-input);
    border: 1px solid var(--border-subtle);
    color: var(--text-secondary);
    text-decoration: none;
    transition: var(--transition-fast);
}
.status-pill-btn:hover, .status-pill-btn.active {
    background: var(--primary-subtle);
    border-color: var(--primary);
    color: var(--primary);
}

/* Orders Cards / Table */
.order-card-item {
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-lg);
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: var(--transition-base);
}
.order-card-item:hover {
    border-color: var(--border-medium);
    box-shadow: var(--shadow-md);
}
.order-card-header {
    padding: 1.25rem 1.75rem;
    background: rgba(0, 0, 0, 0.2);
    border-bottom: 1px solid var(--border-subtle);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.order-card-body {
    padding: 1.5rem 1.75rem;
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 1.5rem;
    align-items: center;
}
.order-id-tag {
    font-family: var(--font-mono);
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--text-primary);
}
.order-date-tag {
    font-size: 0.825rem;
    color: var(--text-muted);
}

/* Status Badges */
.badge-delivered { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); }
.badge-out       { background: rgba(249, 115, 22, 0.15); color: #fb923c; border: 1px solid rgba(249, 115, 22, 0.35); }
.badge-ready     { background: rgba(6, 182, 212, 0.15); color: #38bdf8; border: 1px solid rgba(6, 182, 212, 0.35); }
.badge-preparing { background: rgba(139, 92, 246, 0.15); color: #c084fc; border: 1px solid rgba(139, 92, 246, 0.35); }
.badge-confirmed { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.35); }
.badge-pending   { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.35); }
.badge-cancelled { background: rgba(244, 63, 94, 0.15); color: #fb7185; border: 1px solid rgba(244, 63, 94, 0.35); }

.order-actions-wrap {
    display: flex;
    gap: 0.65rem;
    flex-wrap: wrap;
}

@media (max-width: 900px) {
    .order-card-body {
        grid-template-columns: 1fr;
        gap: 1.25rem;
    }
    .order-actions-wrap {
        width: 100%;
        justify-content: flex-start;
    }
}
</style>

<div class="container" style="padding-top: 2rem; padding-bottom: 5rem;">

    <!-- Hero Header -->
    <div class="history-hero" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span class="role-badge badge-customer" style="margin-bottom: 0.5rem;">Customer Portal</span>
            <h1 style="font-size: 2.25rem; font-weight: 800; margin-bottom: 0.35rem;">
                <?= $viewAllStaff ? 'Platform Master Order History' : 'My Orders &amp; Receipts'; ?>
            </h1>
            <p style="color: var(--text-secondary); margin: 0; font-size: 1rem;">
                <?= $viewAllStaff ? 'Staff supervisory view of customer orders.' : 'Track live kitchen dispatches, view timeline milestones, and print tax invoices.'; ?>
            </p>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <?php if ($isStaffOrAdmin) : ?>
                <?php if ($viewAllStaff) : ?>
                    <a href="history.php" class="btn btn-secondary btn-sm">View Only My Orders</a>
                <?php else : ?>
                    <a href="history.php?all=1" class="btn btn-secondary btn-sm">Supervise All Orders</a>
                <?php endif; ?>
                <a href="kitchen-queue.php" class="btn btn-primary btn-sm">Kitchen Queue &rarr;</a>
            <?php else : ?>
                <a href="../menu/list.php" class="btn btn-primary btn-sm">Explore Menu &rarr;</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary Metrics Grid -->
    <div class="history-stats-grid">
        <!-- Total Orders -->
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(249, 115, 22, 0.15); color: var(--primary);">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            </div>
            <div class="stat-content">
                <span class="stat-val"><?= (int)$userStats['total_orders']; ?></span>
                <span class="stat-lbl">Total Orders</span>
            </div>
        </div>

        <!-- Active Orders -->
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(6, 182, 212, 0.15); color: var(--accent);">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
            </div>
            <div class="stat-content">
                <span class="stat-val"><?= (int)$userStats['active_orders']; ?></span>
                <span class="stat-lbl">Active In Progress</span>
            </div>
        </div>

        <!-- Completed Orders -->
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.15); color: var(--success);">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <div class="stat-content">
                <span class="stat-val"><?= (int)$userStats['completed_orders']; ?></span>
                <span class="stat-lbl">Completed</span>
            </div>
        </div>

        <!-- Total Spent -->
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(139, 92, 246, 0.15); color: #c084fc;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
            <div class="stat-content">
                <span class="stat-val" style="font-size: 1.35rem;">LKR <?= number_format((float)$userStats['total_spent_lkr'], 2); ?></span>
                <span class="stat-lbl">Total Spent</span>
            </div>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="history-filters">
        <div class="status-pill-group">
            <a href="history.php<?= $viewAllStaff ? '?all=1' : ''; ?>" class="status-pill-btn <?= ($statusFilter === '') ? 'active' : ''; ?>">All Orders</a>
            <a href="history.php?status=active<?= $viewAllStaff ? '&all=1' : ''; ?>" class="status-pill-btn <?= ($statusFilter === 'active') ? 'active' : ''; ?>">Active</a>
            <a href="history.php?status=preparing<?= $viewAllStaff ? '&all=1' : ''; ?>" class="status-pill-btn <?= ($statusFilter === 'preparing') ? 'active' : ''; ?>">In Kitchen</a>
            <a href="history.php?status=out_for_delivery<?= $viewAllStaff ? '&all=1' : ''; ?>" class="status-pill-btn <?= ($statusFilter === 'out_for_delivery') ? 'active' : ''; ?>">On Route</a>
            <a href="history.php?status=delivered<?= $viewAllStaff ? '&all=1' : ''; ?>" class="status-pill-btn <?= ($statusFilter === 'delivered') ? 'active' : ''; ?>">Delivered</a>
            <a href="history.php?status=cancelled<?= $viewAllStaff ? '&all=1' : ''; ?>" class="status-pill-btn <?= ($statusFilter === 'cancelled') ? 'active' : ''; ?>">Cancelled</a>
        </div>

        <form action="history.php" method="GET" style="display: flex; gap: 0.5rem; align-items: center; margin: 0;">
            <?php if ($viewAllStaff) : ?>
                <input type="hidden" name="all" value="1">
            <?php endif; ?>
            <?php if (!empty($statusFilter)) : ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <input 
                type="text" 
                name="search" 
                class="form-control" 
                placeholder="Search order number or city..." 
                value="<?= htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>"
                style="height: 38px; width: 230px; font-size: 0.85rem;"
            >
            <button type="submit" class="btn btn-secondary btn-sm" style="height: 38px;">Search</button>
            <?php if (!empty($searchKeyword)) : ?>
                <a href="history.php<?= $viewAllStaff ? '?all=1' : ''; ?>" class="btn btn-secondary btn-sm" style="height: 38px;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Orders List -->
    <?php if (empty($orders)) : ?>
        <div class="card" style="padding: 4rem 2rem; text-align: center; background: var(--bg-surface-elevated);">
            <div style="width: 64px; height: 64px; margin: 0 auto 1.25rem; border-radius: 50%; background: var(--bg-input); display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
            </div>
            <h3 style="font-size: 1.35rem; color: var(--text-primary); margin-bottom: 0.5rem;">No Orders Found</h3>
            <p style="color: var(--text-secondary); max-width: 420px; margin: 0 auto 1.5rem;">
                <?= !empty($searchKeyword) || !empty($statusFilter) ? 'No orders match your selected filter criteria. Try clearing search filters.' : 'You have not placed any cloud kitchen orders yet. Explore our culinary specials!'; ?>
            </p>
            <a href="../menu/list.php" class="btn btn-primary">Browse Food Menu</a>
        </div>
    <?php else : ?>
        <div style="display: flex; flex-direction: column;">
            <?php foreach ($orders as $ord) : ?>
                <?php
                    $badge = get_order_status_badge((string)$ord['order_status']);
                    $payStatus = strtoupper((string)($ord['payment_status'] ?? 'PENDING'));
                ?>
                <div class="order-card-item">
                    
                    <!-- Card Header -->
                    <div class="order-card-header">
                        <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                            <span class="order-id-tag">
                                <?= htmlspecialchars($ord['order_number'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="order-date-tag">
                                Placed on <?= date('M j, Y - g:i A', strtotime($ord['created_at'])); ?>
                            </span>
                            <?php if ($viewAllStaff) : ?>
                                <span style="font-size: 0.825rem; color: var(--accent); background: var(--bg-input); padding: 0.2rem 0.6rem; border-radius: var(--radius-sm);">
                                    Customer: <?= htmlspecialchars($ord['first_name'] . ' ' . $ord['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <span class="status-badge <?= $badge['class']; ?>" style="font-size: 0.825rem; padding: 0.35rem 0.85rem; border-radius: var(--radius-pill); font-weight: 700;">
                                <?= htmlspecialchars($badge['text'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="order-card-body">
                        
                        <!-- Delivery Address & Items Summary -->
                        <div>
                            <div style="font-size: 0.875rem; color: var(--text-primary); font-weight: 700; margin-bottom: 0.3rem;">
                                <?= (int)$ord['items_count']; ?> Dish<?= (int)$ord['items_count'] > 1 ? 'es' : ''; ?> &bull; <?= (int)$ord['total_portions']; ?> Total Portion<?= (int)$ord['total_portions'] > 1 ? 's' : ''; ?>
                            </div>
                            <div style="font-size: 0.825rem; color: var(--text-muted); line-height: 1.5;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <?= htmlspecialchars($ord['delivery_address'] . ', ' . $ord['delivery_city'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>

                        <!-- Financial Total -->
                        <div>
                            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); display: block; font-weight: 700;">Total LKR</span>
                            <strong style="font-size: 1.25rem; color: var(--primary); font-family: var(--font-mono);">
                                LKR <?= number_format((float)$ord['total_amount_lkr'], 2); ?>
                            </strong>
                            <?php if ((float)$ord['discount_lkr'] > 0) : ?>
                                <span style="font-size: 0.75rem; color: var(--success); display: block;">Saved LKR <?= number_format((float)$ord['discount_lkr'], 2); ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Payment Summary -->
                        <div>
                            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); display: block; font-weight: 700;">Payment</span>
                            <div style="font-size: 0.85rem; color: var(--text-secondary); text-transform: capitalize;">
                                <?= htmlspecialchars(str_replace('_', ' ', (string)($ord['payment_method'] ?? 'COD')), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <span style="font-size: 0.75rem; font-weight: 700; color: <?= ($payStatus === 'COMPLETED') ? '#34d399' : '#fbbf24'; ?>;">
                                <?= $payStatus; ?>
                            </span>
                        </div>

                        <!-- Action Buttons -->
                        <div class="order-actions-wrap">
                            <a href="tracking.php?order_id=<?= (int)$ord['order_id']; ?>" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 0.4rem;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                                <span>Track Order</span>
                            </a>
                            <a href="invoice.php?order_id=<?= (int)$ord['order_id']; ?>" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 0.4rem;" title="View printable tax invoice">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                                <span>Invoice</span>
                            </a>
                        </div>

                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
