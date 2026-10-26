<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Live Customer Order Tracking & Visual Progress Timeline
 *
 * Features:
 * - Real-time visual lifecycle timeline derived from order_status_history audit records
 * - Visual multi-step progress bar highlighting current preparation / delivery stage
 * - Milestone timestamps, responsible culinary / courier staff details, and operational notes
 * - Delivery fulfillment card, courier contact info, and special instructions
 * - Ordered dishes summary with unit prices and total LKR
 * - Quick direct actions: Tax Invoice (invoice.php) and Order History (history.php)
 * - Strict customer authorization: customers can only view their own orders; staff/admin have oversight view
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

// Retrieve order identifier
$rawOrderId = $_GET['order_id'] ?? filter_input(INPUT_GET, 'order_id', FILTER_DEFAULT);
$orderId = ($rawOrderId !== null && filter_var($rawOrderId, FILTER_VALIDATE_INT) !== false && (int)$rawOrderId > 0)
    ? (int)$rawOrderId
    : null;

$rawOrderNum = $_GET['order_number'] ?? filter_input(INPUT_GET, 'order_number', FILTER_DEFAULT);
$orderNumber = (is_string($rawOrderNum) && trim($rawOrderNum) !== '')
    ? trim($rawOrderNum)
    : null;

if ($orderId === null && empty($orderNumber)) {
    header('Location: history.php');
    exit();
}

// Fetch order record
$orderSql = '
    SELECT o.*,
           u.first_name, u.last_name, u.email, u.username,
           c.code AS coupon_code,
           p.payment_method, p.payment_status, p.transaction_reference, p.paid_at
    FROM orders o
    JOIN users u ON o.user_id = u.user_id
    LEFT JOIN coupons c ON o.coupon_id = c.coupon_id
    LEFT JOIN payments p ON o.order_id = p.order_id
    WHERE (:order_id_param IS NOT NULL AND o.order_id = :order_id)
       OR (:order_num_param IS NOT NULL AND o.order_number = :order_number)
    LIMIT 1
';

$orderStmt = $pdo->prepare($orderSql);
$orderStmt->bindValue(':order_id_param', $orderId ?: null, $orderId ? PDO::PARAM_INT : PDO::PARAM_NULL);
$orderStmt->bindValue(':order_id', $orderId ?: 0, PDO::PARAM_INT);
$orderStmt->bindValue(':order_num_param', $orderNumber ?: null, $orderNumber ? PDO::PARAM_STR : PDO::PARAM_NULL);
$orderStmt->bindValue(':order_number', $orderNumber ?: '', PDO::PARAM_STR);
$orderStmt->execute();
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    $pageTitle = 'Order Not Found - UrbanEats Cloud Kitchen';
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="container" style="padding-top: 4rem; padding-bottom: 5rem; text-align: center;">
        <div class="card" style="max-width: 580px; margin: 0 auto; padding: 3rem 2rem;">
            <h2 style="color: var(--text-primary); margin-bottom: 0.75rem;">Order Not Found</h2>
            <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
                The requested order could not be located. Please verify the order number or view your order history.
            </p>
            <a href="history.php" class="btn btn-primary">Go to Order History</a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit();
}

// -----------------------------------------------------------------------------
// STRICT AUTHORIZATION CHECK:
// Customers can view ONLY their own orders. Staff and Administrators can view any.
// -----------------------------------------------------------------------------
$orderOwnerId = (int)$order['user_id'];
$isOwner = ($orderOwnerId === $currentUserId);

if (!$isOwner && !$isStaffOrAdmin) {
    log_action(
        $pdo,
        $currentUserId,
        'UNAUTHORIZED_ORDER_TRACKING_DENIED',
        sprintf("User #%d attempted to track order '%s' (Owner: User #%d).", $currentUserId, $order['order_number'], $orderOwnerId)
    );
    http_response_code(403);
    header('Location: ../access-denied.php');
    exit();
}

$effectiveOrderId = (int)$order['order_id'];

// Fetch order items
$itemsStmt = $pdo->prepare('
    SELECT oi.*, d.name AS dish_name, d.image_path, c.name AS category_name
    FROM order_items oi
    JOIN dishes d ON oi.dish_id = d.dish_id
    LEFT JOIN categories c ON d.category_id = c.category_id
    WHERE oi.order_id = :order_id
    ORDER BY oi.order_item_id ASC
');
$itemsStmt->execute([':order_id' => $effectiveOrderId]);
$orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch chronological audit status history with staff details
$historyStmt = $pdo->prepare('
    SELECT h.*,
           u.first_name AS staff_first,
           u.last_name AS staff_last,
           r.role_name AS staff_role
    FROM order_status_history h
    LEFT JOIN users u ON h.changed_by_user_id = u.user_id
    LEFT JOIN roles r ON u.role_id = r.role_id
    WHERE h.order_id = :order_id
    ORDER BY h.created_at ASC, h.history_id ASC
');
$historyStmt->execute([':order_id' => $effectiveOrderId]);
$statusHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

// Define standard chronological pipeline stages
$pipelineStages = [
    'pending'          => ['label' => 'Order Placed',      'icon' => 'shopping-bag'],
    'confirmed'        => ['label' => 'Confirmed',         'icon' => 'clipboard-check'],
    'preparing'        => ['label' => 'Kitchen Prep',      'icon' => 'flame'],
    'ready_for_pickup' => ['label' => 'Ready for Pickup',  'icon' => 'package'],
    'out_for_delivery' => ['label' => 'Out for Delivery',  'icon' => 'truck'],
    'delivered'        => ['label' => 'Delivered',         'icon' => 'check-circle'],
];

$currentStatus = (string)$order['order_status'];
$isCancelled = ($currentStatus === 'cancelled');

// Determine step index for progress bar
$stageKeys = array_keys($pipelineStages);
$currentStageIndex = array_search($currentStatus, $stageKeys, true);
if ($currentStageIndex === false) {
    $currentStageIndex = 0;
}

$pageTitle = 'Live Tracking: #' . htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.tracking-header-card {
    background: linear-gradient(180deg, var(--bg-surface-elevated), var(--bg-surface));
    border: 1px solid var(--border-medium);
    border-radius: var(--radius-lg);
    padding: 2.25rem;
    box-shadow: var(--shadow-lg);
    margin-bottom: 2rem;
}

/* Stepper Progress Bar */
.stepper-wrap {
    display: flex;
    justify-content: space-between;
    position: relative;
    margin: 2.5rem 0 1rem;
}

.stepper-line-bg {
    position: absolute;
    top: 22px;
    left: 20px;
    right: 20px;
    height: 4px;
    background: var(--border-medium);
    z-index: 1;
}

.stepper-line-active {
    position: absolute;
    top: 22px;
    left: 20px;
    height: 4px;
    background: linear-gradient(90deg, var(--primary), var(--accent));
    z-index: 2;
    transition: width 0.5s ease;
}

.step-node {
    position: relative;
    z-index: 3;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 110px;
    text-align: center;
}

.step-icon-circle {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: var(--bg-surface);
    border: 3px solid var(--border-medium);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-weight: 700;
    transition: var(--transition-base);
    box-shadow: var(--shadow-sm);
}

.step-node.completed .step-icon-circle {
    background: var(--primary);
    border-color: var(--primary);
    color: #ffffff;
    box-shadow: 0 0 16px rgba(249, 115, 22, 0.4);
}

.step-node.active .step-icon-circle {
    background: var(--bg-surface-elevated);
    border-color: var(--accent);
    color: var(--accent);
    box-shadow: 0 0 20px rgba(6, 182, 212, 0.5);
    animation: pulseGlow 2s infinite;
}

@keyframes pulseGlow {
    0%, 100% { box-shadow: 0 0 15px rgba(6, 182, 212, 0.4); }
    50% { box-shadow: 0 0 25px rgba(6, 182, 212, 0.8); }
}

.step-label {
    margin-top: 0.65rem;
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--text-muted);
}

.step-node.completed .step-label {
    color: var(--text-primary);
}

.step-node.active .step-label {
    color: var(--accent);
}

/* Timeline Activity Stream */
.timeline-stream {
    position: relative;
    padding-left: 2rem;
    display: flex;
    flex-direction: column;
    gap: 1.75rem;
    margin-top: 1.5rem;
}

.timeline-line-track {
    position: absolute;
    left: 8px;
    top: 6px;
    bottom: 6px;
    width: 3px;
    background: var(--border-medium);
}

.timeline-event-item {
    position: relative;
}

.timeline-bullet {
    position: absolute;
    left: -2rem;
    top: 3px;
    width: 19px;
    height: 19px;
    border-radius: 50%;
    background: var(--bg-surface-elevated);
    border: 3px solid var(--primary);
}

.timeline-bullet.latest {
    border-color: var(--accent);
    background: var(--accent);
    box-shadow: 0 0 10px rgba(6, 182, 212, 0.6);
}

.timeline-bubble {
    background: var(--bg-surface);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    box-shadow: var(--shadow-sm);
}

@media (max-width: 768px) {
    .stepper-wrap {
        display: none; /* Collapses to cleaner mobile timeline */
    }
}
</style>

<div class="container" style="padding-top: 2rem; padding-bottom: 5rem;">

    <!-- Top Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <a href="history.php" class="btn btn-secondary btn-sm" title="Return to orders list">
                &larr; Order History
            </a>
            <span style="color: var(--text-muted); font-size: 0.85rem;">/ Live Dispatch Tracker</span>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="invoice.php?order_id=<?= (int)$order['order_id']; ?>" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 0.4rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                <span>Tax Invoice</span>
            </a>
            <a href="../menu/list.php" class="btn btn-secondary btn-sm">Menu Catalog</a>
        </div>
    </div>

    <!-- Live Tracking Banner Card -->
    <div class="tracking-header-card">
        
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 1.5rem;">
            <div>
                <span class="role-badge badge-customer" style="margin-bottom: 0.5rem; font-size: 0.8rem;">Live Dispatch Status</span>
                <h1 style="font-size: 2rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.35rem;">
                    <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8'); ?>
                </h1>
                <p style="color: var(--text-secondary); margin: 0; font-size: 0.95rem;">
                    Recipient: <strong><?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name'], ENT_QUOTES, 'UTF-8'); ?></strong> &bull; Placed: <?= date('F j, Y - g:i A', strtotime($order['created_at'])); ?>
                </p>
            </div>

            <div style="text-align: right;">
                <span style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; display: block; margin-bottom: 0.25rem;">
                    Current Milestone
                </span>
                <?php if ($isCancelled) : ?>
                    <span style="display: inline-block; padding: 0.45rem 1.15rem; border-radius: var(--radius-pill); font-size: 0.95rem; font-weight: 800; background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-border);">
                        &times; CANCELLED
                    </span>
                <?php else : ?>
                    <span style="display: inline-block; padding: 0.45rem 1.15rem; border-radius: var(--radius-pill); font-size: 0.95rem; font-weight: 800; background: var(--primary-subtle); color: var(--primary); border: 1px solid rgba(249, 115, 22, 0.35); text-transform: uppercase;">
                        <?= htmlspecialchars(str_replace('_', ' ', $currentStatus), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
                
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.5rem;">
                    Target Delivery: <strong><?= !empty($order['estimated_delivery_time']) ? date('g:i A', strtotime($order['estimated_delivery_time'])) : '30-45 mins'; ?></strong>
                </div>
            </div>
        </div>

        <!-- Visual Stepper Progress Bar (If not cancelled) -->
        <?php if (!$isCancelled) : ?>
            <?php
                $totalSteps = count($pipelineStages);
                $progressPercent = ($currentStageIndex / ($totalSteps - 1)) * 100;
            ?>
            <div class="stepper-wrap">
                <div class="stepper-line-bg"></div>
                <div class="stepper-line-active" style="width: calc(<?= $progressPercent; ?>% - 30px);"></div>

                <?php $idx = 0; foreach ($pipelineStages as $stgKey => $stgInfo) : ?>
                    <?php
                        $isCompleted = ($idx < $currentStageIndex);
                        $isActive = ($idx === $currentStageIndex);
                        $nodeClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
                    ?>
                    <div class="step-node <?= $nodeClass; ?>">
                        <div class="step-icon-circle">
                            <?php if ($isCompleted) : ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <?php else : ?>
                                <span><?= $idx + 1; ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="step-label"><?= htmlspecialchars($stgInfo['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php $idx++;
                endforeach; ?>
            </div>
        <?php else : ?>
            <div style="padding: 1rem 1.25rem; background: var(--danger-bg); border: 1px solid var(--danger-border); border-radius: var(--radius-md); color: #fecdd3; font-size: 0.95rem; margin-top: 1rem;">
                <strong>Order Cancelled:</strong> This order has been cancelled and will not progress through kitchen preparation. If payment was collected, a refund process has been initiated.
            </div>
        <?php endif; ?>

    </div>

    <!-- 2-Column Grid: Timeline Stream (Left) & Fulfillment Metadata (Right) -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; align-items: flex-start;">
        
        <!-- Left: Activity Timeline Stream -->
        <div class="card" style="padding: 2rem; background: var(--bg-surface-elevated);">
            <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                <span>Milestone History &amp; Audit Trail</span>
            </h3>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Detailed chronological log of kitchen confirmation, chef preparation, packaging, and delivery transitions.
            </p>

            <div class="timeline-stream">
                <div class="timeline-line-track"></div>

                <?php
                    $historyCount = count($statusHistory);
                    $hIndex = 0;
                foreach ($statusHistory as $hist) :
                    $hIndex++;
                    $isLatest = ($hIndex === $historyCount);
                    ?>
                    <div class="timeline-event-item">
                        <div class="timeline-bullet <?= $isLatest ? 'latest' : ''; ?>"></div>
                        
                        <div class="timeline-bubble">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.4rem;">
                                <strong style="color: var(--text-primary); font-size: 1rem; text-transform: capitalize;">
                                    Status Transition: <?= htmlspecialchars(str_replace('_', ' ', (string)$hist['new_status']), ENT_QUOTES, 'UTF-8'); ?>
                                </strong>
                                <span style="font-size: 0.775rem; color: var(--text-muted); font-family: var(--font-mono);">
                                <?= date('M j, Y - g:i A', strtotime($hist['created_at'])); ?>
                                </span>
                            </div>

                        <?php if (!empty($hist['comment'])) : ?>
                                <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 0.5rem; line-height: 1.5;">
                                    <?= htmlspecialchars($hist['comment'], ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                        <?php endif; ?>

                            <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.4rem;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                <span>Updated by: </span>
                                <strong style="color: var(--text-secondary);">
                                <?= !empty($hist['staff_first']) ? htmlspecialchars($hist['staff_first'] . ' ' . $hist['staff_last'] . ' (' . ($hist['staff_role'] ?? 'Staff') . ')', ENT_QUOTES, 'UTF-8') : 'Platform System Engine'; ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>

        <!-- Right Column: Delivery Details & Items Breakdown -->
        <div style="display: flex; flex-direction: column; gap: 1.75rem;">
            
            <!-- Delivery Info Card -->
            <div class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-medium);">
                <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem;">
                    Delivery Information
                </h4>
                
                <div style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.6;">
                    <div style="margin-bottom: 0.6rem;">
                        <span style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">Destination Address</span>
                        <strong style="color: var(--text-primary);"><?= htmlspecialchars($order['delivery_address'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div style="margin-bottom: 0.6rem;">
                        <span style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">City / Region</span>
                        <strong style="color: var(--text-primary);"><?= htmlspecialchars($order['delivery_city'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div style="margin-bottom: 0.6rem;">
                        <span style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">Contact Phone</span>
                        <strong style="color: var(--text-primary);"><?= htmlspecialchars($order['contact_phone'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <?php if (!empty($order['delivery_notes'])) : ?>
                        <div style="padding: 0.65rem 0.85rem; background: var(--bg-input); border-radius: var(--radius-sm); border-left: 3px solid var(--accent); margin-top: 0.5rem; font-size: 0.825rem;">
                            <strong>Delivery Note:</strong><br>
                            <?= htmlspecialchars($order['delivery_notes'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ordered Dishes Summary -->
            <div class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated);">
                <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem;">
                    Ordered Items Summary
                </h4>

                <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                    <?php foreach ($orderItems as $item) : ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-subtle);">
                            <div>
                                <strong style="color: var(--text-primary); font-size: 0.925rem; display: block;">
                                    <?= htmlspecialchars($item['dish_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </strong>
                                <span style="font-size: 0.8rem; color: var(--text-muted);">
                                    <?= (int)$item['quantity']; ?> Portion<?= (int)$item['quantity'] > 1 ? 's' : ''; ?> &times; LKR <?= number_format((float)$item['unit_price_lkr'], 2); ?>
                                </span>
                            </div>
                            <strong style="color: var(--text-primary); font-size: 0.925rem; font-family: var(--font-mono);">
                                LKR <?= number_format((float)$item['subtotal_lkr'], 2); ?>
                            </strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 1.25rem; padding-top: 1rem; border-top: 2px solid var(--border-medium); display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: 800; color: var(--text-primary);">Grand Total:</span>
                    <strong style="font-size: 1.35rem; color: var(--primary); font-family: var(--font-mono); font-weight: 800;">
                        LKR <?= number_format((float)$order['total_amount_lkr'], 2); ?>
                    </strong>
                </div>
            </div>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
