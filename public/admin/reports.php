<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Administrator Sales & Financial Intelligence Reports
 *
 * Features:
 * - Server-side validated date range filters (start_date & end_date) with quick preset toggles
 * - High-level financial KPIs (Net Revenue LKR, Total Orders, Average Order Value, Completed Orders)
 * - Excludes cancelled orders from revenue and sales performance metrics
 * - Relational SQL Aggregations (SUM, COUNT, AVG, GROUP BY, JOIN):
 *     1. Orders Distribution by Status
 *     2. Top-Selling Dishes & Best Performers
 *     3. Daily Revenue & Order Trend
 *     4. Payment Settlement & Method Breakdown
 * - Strictly restricted to Administrators (require_role)
 * - Print / Export ready with responsive tables
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

// Enforce role-based access control (Administrators only)
require_role(['Administrator', 'Admin', 1]);

// -----------------------------------------------------------------------------
// DATE RANGE FILTER PARSING & SERVER-SIDE VALIDATION
// -----------------------------------------------------------------------------
$preset = filter_input(INPUT_GET, 'preset', FILTER_DEFAULT);
$preset = is_string($preset) ? trim($preset) : 'all_time';

$startDateRaw = filter_input(INPUT_GET, 'start_date', FILTER_DEFAULT);
$endDateRaw = filter_input(INPUT_GET, 'end_date', FILTER_DEFAULT);

// Helper to validate Y-m-d format
function is_valid_date(?string $d): bool
{
    if (!$d) {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

$today = date('Y-m-d');

if ($preset === 'today') {
    $startDate = $today;
    $endDate = $today;
} elseif ($preset === '7days') {
    $startDate = date('Y-m-d', strtotime('-6 days'));
    $endDate = $today;
} elseif ($preset === '30days') {
    $startDate = date('Y-m-d', strtotime('-29 days'));
    $endDate = $today;
} elseif ($preset === 'this_month') {
    $startDate = date('Y-m-01');
    $endDate = $today;
} elseif ($preset === 'custom' && is_valid_date((string)$startDateRaw) && is_valid_date((string)$endDateRaw)) {
    $startDate = (string)$startDateRaw;
    $endDate = (string)$endDateRaw;
    if ($startDate > $endDate) {
        // Swap if in reverse order
        $tmp = $startDate;
        $startDate = $endDate;
        $endDate = $tmp;
    }
} else {
    // Default: All Time
    $preset = 'all_time';
    $startDate = '2025-01-01';
    $endDate = $today;
}

// Append full-day time boundaries for SQL
$startDateTime = "{$startDate} 00:00:00";
$endDateTime = "{$endDate} 23:59:59";
$dateParams = [':start_date' => $startDateTime, ':end_date' => $endDateTime];

// -----------------------------------------------------------------------------
// 1. HIGH-LEVEL EXECUTIVE KPIS & FINANCIAL CARDS
// Exclude cancelled orders from revenue metrics.
// -----------------------------------------------------------------------------
$kpiSql = '
    SELECT 
        COUNT(*) AS total_orders,
        SUM(CASE WHEN order_status != \'cancelled\' THEN total_amount_lkr ELSE 0.00 END) AS net_revenue_lkr,
        SUM(CASE WHEN order_status != \'cancelled\' THEN subtotal_lkr ELSE 0.00 END) AS gross_subtotal_lkr,
        SUM(CASE WHEN order_status != \'cancelled\' THEN discount_lkr ELSE 0.00 END) AS total_discounts_lkr,
        SUM(CASE WHEN order_status != \'cancelled\' THEN delivery_fee_lkr ELSE 0.00 END) AS total_delivery_fees_lkr,
        AVG(CASE WHEN order_status != \'cancelled\' THEN total_amount_lkr ELSE NULL END) AS average_order_value_lkr,
        SUM(CASE WHEN order_status = \'delivered\' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN order_status = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled_orders,
        SUM(CASE WHEN order_status IN (\'pending\', \'confirmed\', \'preparing\', \'ready_for_pickup\', \'out_for_delivery\') THEN 1 ELSE 0 END) AS active_orders
    FROM orders
    WHERE created_at BETWEEN :start_date AND :end_date
';
$kpiStmt = $pdo->prepare($kpiSql);
$kpiStmt->execute($dateParams);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalOrders = (int)($kpi['total_orders'] ?? 0);
$netRevenue = (float)($kpi['net_revenue_lkr'] ?? 0.00);
$completedOrders = (int)($kpi['completed_orders'] ?? 0);
$cancelledOrders = (int)($kpi['cancelled_orders'] ?? 0);
$aov = (float)($kpi['average_order_value_lkr'] ?? 0.00);
$discountsGiven = (float)($kpi['total_discounts_lkr'] ?? 0.00);

// -----------------------------------------------------------------------------
// 2. BREAKDOWN BY ORDER LIFECYCLE STATUS (GROUP BY order_status)
// -----------------------------------------------------------------------------
$statusSql = '
    SELECT 
        order_status,
        COUNT(*) AS status_count,
        SUM(total_amount_lkr) AS status_revenue_lkr,
        AVG(total_amount_lkr) AS avg_ticket_lkr
    FROM orders
    WHERE created_at BETWEEN :start_date AND :end_date
    GROUP BY order_status
    ORDER BY status_count DESC
';
$statusStmt = $pdo->prepare($statusSql);
$statusStmt->execute($dateParams);
$statusBreakdown = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// 3. TOP-SELLING DISHES (JOIN, GROUP BY oi.dish_id, ORDER BY total_qty DESC)
// -----------------------------------------------------------------------------
$topDishesSql = '
    SELECT 
        d.dish_id,
        d.name AS dish_name,
        c.name AS category_name,
        d.price AS current_catalog_price,
        SUM(oi.quantity) AS total_quantity_sold,
        SUM(oi.subtotal_lkr) AS total_revenue_lkr,
        AVG(oi.unit_price_lkr) AS avg_selling_unit_price
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    JOIN dishes d ON oi.dish_id = d.dish_id
    LEFT JOIN categories c ON d.category_id = c.category_id
    WHERE o.order_status != \'cancelled\'
      AND o.created_at BETWEEN :start_date AND :end_date
    GROUP BY d.dish_id, d.name, c.name, d.price
    ORDER BY total_quantity_sold DESC, total_revenue_lkr DESC
    LIMIT 10
';
$topDishesStmt = $pdo->prepare($topDishesSql);
$topDishesStmt->execute($dateParams);
$topDishes = $topDishesStmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// 4. DAILY / PERIODIC SALES TREND (GROUP BY DATE(created_at))
// -----------------------------------------------------------------------------
$trendSql = '
    SELECT 
        DATE(created_at) AS sales_date,
        COUNT(*) AS orders_count,
        SUM(CASE WHEN order_status != \'cancelled\' THEN 1 ELSE 0 END) AS valid_orders,
        SUM(CASE WHEN order_status = \'delivered\' THEN 1 ELSE 0 END) AS delivered_count,
        SUM(CASE WHEN order_status = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled_count,
        SUM(CASE WHEN order_status != \'cancelled\' THEN total_amount_lkr ELSE 0.00 END) AS daily_revenue_lkr,
        AVG(CASE WHEN order_status != \'cancelled\' THEN total_amount_lkr ELSE NULL END) AS daily_aov_lkr
    FROM orders
    WHERE created_at BETWEEN :start_date AND :end_date
    GROUP BY DATE(created_at)
    ORDER BY sales_date DESC
';
$trendStmt = $pdo->prepare($trendSql);
$trendStmt->execute($dateParams);
$salesTrend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// 5. PAYMENT SETTLEMENT BREAKDOWN (GROUP BY p.payment_method)
// -----------------------------------------------------------------------------
$paymentSql = '
    SELECT 
        COALESCE(p.payment_method, \'unspecified\') AS pay_method,
        COUNT(DISTINCT o.order_id) AS orders_count,
        SUM(CASE WHEN o.order_status != \'cancelled\' THEN o.total_amount_lkr ELSE 0 END) AS method_revenue_lkr,
        SUM(CASE WHEN p.payment_status = \'completed\' THEN 1 ELSE 0 END) AS completed_payments,
        SUM(CASE WHEN p.payment_status = \'pending\' THEN 1 ELSE 0 END) AS pending_payments
    FROM orders o
    LEFT JOIN payments p ON o.order_id = p.order_id
    WHERE o.created_at BETWEEN :start_date AND :end_date
    GROUP BY COALESCE(p.payment_method, \'unspecified\')
    ORDER BY method_revenue_lkr DESC
';
$paymentStmt = $pdo->prepare($paymentSql);
$paymentStmt->execute($dateParams);
$paymentBreakdown = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Sales & Financial Reports - UrbanEats Admin';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.reports-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.kpi-card {
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-medium);
    border-radius: var(--radius-lg);
    padding: 1.6rem;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
    transition: var(--transition-base);
}

.kpi-card:hover {
    transform: translateY(-2px);
    border-color: rgba(249, 115, 22, 0.4);
    box-shadow: var(--shadow-md);
}

.kpi-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}

.kpi-rev::before { background: linear-gradient(90deg, #f97316, #ef4444); }
.kpi-ord::before { background: linear-gradient(90deg, #06b6d4, #3b82f6); }
.kpi-aov::before { background: linear-gradient(90deg, #10b981, #059669); }
.kpi-comp::before { background: linear-gradient(90deg, #8b5cf6, #6366f1); }

.kpi-value {
    font-size: 1.85rem;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
    margin: 0.35rem 0;
    font-family: var(--font-mono);
}

.kpi-label {
    font-size: 0.8125rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    font-weight: 700;
}

.kpi-subtext {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

/* Filter Card */
.reports-filter-bar {
    background: var(--bg-surface);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-lg);
    padding: 1.25rem 1.5rem;
    margin-bottom: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.25rem;
}

.filter-preset-pills {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.preset-pill {
    padding: 0.45rem 0.9rem;
    border-radius: var(--radius-pill);
    font-size: 0.8125rem;
    font-weight: 700;
    background: var(--bg-input);
    border: 1px solid var(--border-subtle);
    color: var(--text-secondary);
    text-decoration: none;
    transition: var(--transition-fast);
}

.preset-pill:hover, .preset-pill.active {
    background: var(--primary-subtle);
    border-color: var(--primary);
    color: var(--primary);
}

/* Report Tables */
.report-section {
    margin-bottom: 2.5rem;
}

.table-responsive {
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

.report-table th {
    background: var(--bg-surface);
    padding: 0.9rem 1.15rem;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    font-weight: 700;
    border-bottom: 2px solid var(--border-medium);
}

.report-table td {
    padding: 1rem 1.15rem;
    border-bottom: 1px solid var(--border-subtle);
    font-size: 0.925rem;
    color: var(--text-secondary);
}

.report-table tr:hover td {
    background: rgba(255, 255, 255, 0.02);
}

@media print {
    .site-header, .site-footer, .reports-filter-bar, .no-print, button, a.btn {
        display: none !important;
    }
    body, html, .container {
        background: #ffffff !important;
        color: #0f172a !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .kpi-card, .card {
        background: #ffffff !important;
        border: 1px solid #cbd5e1 !important;
        box-shadow: none !important;
        color: #0f172a !important;
    }
    .report-table th {
        background: #f1f5f9 !important;
        color: #0f172a !important;
    }
    .report-table td {
        color: #0f172a !important;
        border-color: #cbd5e1 !important;
    }
}
</style>

<div class="container" style="padding-top: 2rem; padding-bottom: 5rem;">

    <!-- Top Heading -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span class="role-badge badge-admin" style="margin-bottom: 0.5rem;">Executive Financial Intelligence</span>
            <h1 style="font-size: 2.25rem; font-weight: 800; margin-bottom: 0.25rem;">
                Sales &amp; Revenue Reports
            </h1>
            <p style="color: var(--text-secondary); margin: 0; font-size: 0.95rem;">
                Commercial sales performance, average order values, and product velocity across the platform.
            </p>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;" class="no-print">
            <button type="button" class="btn btn-secondary btn-sm" onclick="window.print();">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                <span>Print Report</span>
            </button>
            <a href="dashboard.php" class="btn btn-secondary btn-sm">&larr; Admin Dashboard</a>
            <a href="../orders/kitchen-queue.php" class="btn btn-primary btn-sm">Kitchen Queue &rarr;</a>
        </div>
    </div>

    <!-- Date Range Filter Toolbar -->
    <div class="reports-filter-bar no-print">
        
        <!-- Preset Quick Toggles -->
        <div class="filter-preset-pills">
            <a href="reports.php?preset=all_time" class="preset-pill <?= ($preset === 'all_time') ? 'active' : ''; ?>">All Time</a>
            <a href="reports.php?preset=today" class="preset-pill <?= ($preset === 'today') ? 'active' : ''; ?>">Today</a>
            <a href="reports.php?preset=7days" class="preset-pill <?= ($preset === '7days') ? 'active' : ''; ?>">Last 7 Days</a>
            <a href="reports.php?preset=30days" class="preset-pill <?= ($preset === '30days') ? 'active' : ''; ?>">Last 30 Days</a>
            <a href="reports.php?preset=this_month" class="preset-pill <?= ($preset === 'this_month') ? 'active' : ''; ?>">This Month</a>
        </div>

        <!-- Custom Date Range Form -->
        <form action="reports.php" method="GET" style="display: flex; gap: 0.65rem; align-items: center; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="preset" value="custom">
            
            <div style="display: flex; align-items: center; gap: 0.4rem;">
                <label for="start_date" style="font-size: 0.775rem; color: var(--text-muted); font-weight: 700;">FROM:</label>
                <input 
                    type="date" 
                    id="start_date" 
                    name="start_date" 
                    class="form-control" 
                    value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>"
                    style="height: 38px; width: 150px; font-size: 0.85rem;"
                    required
                >
            </div>

            <div style="display: flex; align-items: center; gap: 0.4rem;">
                <label for="end_date" style="font-size: 0.775rem; color: var(--text-muted); font-weight: 700;">TO:</label>
                <input 
                    type="date" 
                    id="end_date" 
                    name="end_date" 
                    class="form-control" 
                    value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>"
                    style="height: 38px; width: 150px; font-size: 0.85rem;"
                    required
                >
            </div>

            <button type="submit" class="btn btn-secondary btn-sm" style="height: 38px;">Apply Filter</button>
        </form>

    </div>

    <!-- Active Window Indicator -->
    <div style="margin-bottom: 1.5rem; font-size: 0.875rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        <span>Reporting Period: <strong><?= date('M j, Y', strtotime($startDate)); ?></strong> &mdash; <strong><?= date('M j, Y', strtotime($endDate)); ?></strong></span>
        <span style="color: var(--accent); margin-left: 0.5rem;">(Cancelled orders automatically excluded from net revenue)</span>
    </div>

    <!-- 1. High-Level Executive KPIs Grid -->
    <div class="reports-kpi-grid">
        
        <!-- Net Revenue -->
        <div class="kpi-card kpi-rev">
            <span class="kpi-label">Total Net Revenue</span>
            <div class="kpi-value" style="color: var(--primary);">
                LKR <?= number_format($netRevenue, 2); ?>
            </div>
            <div class="kpi-subtext">
                Discounts granted: LKR <?= number_format($discountsGiven, 2); ?>
            </div>
        </div>

        <!-- Total Orders -->
        <div class="kpi-card kpi-ord">
            <span class="kpi-label">Total Orders Placed</span>
            <div class="kpi-value">
                <?= number_format($totalOrders); ?>
            </div>
            <div class="kpi-subtext">
                Cancelled: <?= $cancelledOrders; ?> &bull; Active: <?= (int)($kpi['active_orders'] ?? 0); ?>
            </div>
        </div>

        <!-- Average Order Value (AOV) -->
        <div class="kpi-card kpi-aov">
            <span class="kpi-label">Average Order Value (AOV)</span>
            <div class="kpi-value" style="color: var(--success);">
                LKR <?= number_format($aov, 2); ?>
            </div>
            <div class="kpi-subtext">
                Per non-cancelled transaction
            </div>
        </div>

        <!-- Completed Orders -->
        <div class="kpi-card kpi-comp">
            <span class="kpi-label">Completed &amp; Delivered</span>
            <div class="kpi-value" style="color: #c084fc;">
                <?= number_format($completedOrders); ?>
            </div>
            <div class="kpi-subtext">
                Fulfillment rate: <?= ($totalOrders > 0) ? round(($completedOrders / $totalOrders) * 100, 1) : 0; ?>%
            </div>
        </div>

    </div>

    <!-- 2-Column Split: Top-Selling Dishes & Status Distribution -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; margin-bottom: 2.5rem; align-items: flex-start;">
        
        <!-- Top-Selling Dishes -->
        <div class="card glass-panel" style="overflow: hidden;">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 0.2rem;">Top-Selling Culinary Dishes</h3>
                    <span style="font-size: 0.8rem; color: var(--text-muted);">Highest volume dishes ranked by portions sold</span>
                </div>
                <span class="role-badge badge-customer">Velocity</span>
            </div>

            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Dish Name</th>
                            <th>Category</th>
                            <th style="text-align: center;">Portions Sold</th>
                            <th style="text-align: right;">Unit Price</th>
                            <th style="text-align: right;">Net Sales (LKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topDishes)) : ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                                    No dish sales recorded during this selected date range.
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($topDishes as $dish) : ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--text-primary); font-size: 0.95rem;">
                                            <?= htmlspecialchars($dish['dish_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.8rem; color: var(--accent);">
                                            <?= htmlspecialchars($dish['category_name'] ?? 'General', ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center; font-weight: 700; color: var(--text-primary);">
                                        <?= (int)$dish['total_quantity_sold']; ?>
                                    </td>
                                    <td style="text-align: right; font-family: var(--font-mono); color: var(--text-muted); font-size: 0.85rem;">
                                        LKR <?= number_format((float)$dish['avg_selling_unit_price'], 2); ?>
                                    </td>
                                    <td style="text-align: right; font-family: var(--font-mono); font-weight: 700; color: var(--primary);">
                                        LKR <?= number_format((float)$dish['total_revenue_lkr'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Order Lifecycle Status Distribution -->
        <div class="card glass-panel" style="overflow: hidden;">
            <div class="card-header">
                <div>
                    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 0.2rem;">Orders by Status</h3>
                    <span style="font-size: 0.8rem; color: var(--text-muted);">Breakdown across lifecycle</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th style="text-align: center;">Orders</th>
                            <th style="text-align: right;">Volume (LKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($statusBreakdown)) : ?>
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                    No status data.
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($statusBreakdown as $sb) : ?>
                                <tr>
                                    <td>
                                        <span style="font-size: 0.8rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: var(--radius-pill); text-transform: uppercase; background: var(--bg-surface); border: 1px solid var(--border-medium); color: var(--text-primary);">
                                            <?= htmlspecialchars(str_replace('_', ' ', (string)$sb['order_status']), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center; font-weight: 700; color: var(--text-primary);">
                                        <?= (int)$sb['status_count']; ?>
                                    </td>
                                    <td style="text-align: right; font-family: var(--font-mono); color: var(--text-secondary); font-size: 0.85rem;">
                                        LKR <?= number_format((float)$sb['status_revenue_lkr'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- 3. Daily Sales & Order Trend -->
    <div class="card glass-panel" style="margin-bottom: 2.5rem; overflow: hidden;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 0.2rem;">Daily Sales Velocity &amp; Revenue Trend</h3>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Day-by-day financial performance breakdown</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th style="text-align: center;">Total Orders</th>
                        <th style="text-align: center;">Delivered</th>
                        <th style="text-align: center;">Cancelled</th>
                        <th style="text-align: right;">Average Basket (LKR)</th>
                        <th style="text-align: right;">Net Revenue (LKR)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salesTrend)) : ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                                No transaction history found for the specified date interval.
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($salesTrend as $day) : ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--text-primary); font-family: var(--font-mono);">
                                        <?= date('M j, Y (D)', strtotime((string)$day['sales_date'])); ?>
                                    </strong>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: var(--text-primary);">
                                    <?= (int)$day['orders_count']; ?>
                                </td>
                                <td style="text-align: center; color: var(--success); font-weight: 600;">
                                    <?= (int)$day['delivered_count']; ?>
                                </td>
                                <td style="text-align: center; color: var(--danger); font-weight: 600;">
                                    <?= (int)$day['cancelled_count']; ?>
                                </td>
                                <td style="text-align: right; font-family: var(--font-mono); color: var(--text-secondary);">
                                    LKR <?= number_format((float)$day['daily_aov_lkr'], 2); ?>
                                </td>
                                <td style="text-align: right; font-family: var(--font-mono); font-weight: 800; color: var(--primary); font-size: 0.95rem;">
                                    LKR <?= number_format((float)$day['daily_revenue_lkr'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 4. Payment Methods Settlement -->
    <div class="card glass-panel" style="overflow: hidden;">
        <div class="card-header">
            <div>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 0.2rem;">Payment Channels &amp; Settlements</h3>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Collection volume categorized by payment method</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Payment Channel</th>
                        <th style="text-align: center;">Transactions</th>
                        <th style="text-align: center;">Completed Settlements</th>
                        <th style="text-align: center;">Pending Collection</th>
                        <th style="text-align: right;">Total Amount Processed (LKR)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paymentBreakdown)) : ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                No payment records found.
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($paymentBreakdown as $pay) : ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--text-primary); text-transform: uppercase;">
                                        <?= htmlspecialchars(str_replace('_', ' ', (string)$pay['pay_method']), ENT_QUOTES, 'UTF-8'); ?>
                                    </strong>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: var(--text-primary);">
                                    <?= (int)$pay['orders_count']; ?>
                                </td>
                                <td style="text-align: center; color: var(--success); font-weight: 700;">
                                    <?= (int)$pay['completed_payments']; ?>
                                </td>
                                <td style="text-align: center; color: var(--warning); font-weight: 700;">
                                    <?= (int)$pay['pending_payments']; ?>
                                </td>
                                <td style="text-align: right; font-family: var(--font-mono); font-weight: 800; color: var(--primary);">
                                    LKR <?= number_format((float)$pay['method_revenue_lkr'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
