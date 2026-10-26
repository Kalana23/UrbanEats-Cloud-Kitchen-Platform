<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Kitchen Order Queue & Culinary Prep Management
 *
 * Features:
 * - Real-time active culinary queue for kitchen chefs and administrators
 * - Strict server-side state machine validation preventing illegal workflow jumps
 * - Single ACID-compliant PDO Transaction ($pdo->beginTransaction()):
 *     1. Locks order row FOR UPDATE and verifies current state
 *     2. Updates orders.order_status
 *     3. Inserts state change record into order_status_history
 *     4. Records action in audit_log via log_action()
 *     5. Complete rollback on any failure
 * - CSRF token verification for all status transitions
 * - Visual cooking tickets highlighting customer special instructions, elapsed time, and next valid steps
 *
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

// Ensure session is active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enforce role-based access control (Kitchen Staff & Administrators only)
require_role(['Kitchen Staff', 'Administrator', 'Staff', 'Admin', 1, 2]);

$currentStaffId = (int)($_SESSION['user_id'] ?? 0);
$currentStaffName = (string)($_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Kitchen Staff');

// -----------------------------------------------------------------------------
// STRICT LIFECYCLE STATE TRANSITION RULES (State Machine Definition)
// -----------------------------------------------------------------------------
$allowedTransitions = [
    'pending' => [
        'confirmed' => ['label' => 'Accept & Confirm Order', 'class' => 'btn-primary'],
        'cancelled' => ['label' => 'Reject / Cancel',       'class' => 'btn-danger'],
    ],
    'confirmed' => [
        'preparing' => ['label' => 'Commence Food Prep',    'class' => 'btn-primary'],
        'cancelled' => ['label' => 'Cancel Order',          'class' => 'btn-danger'],
    ],
    'preparing' => [
        'ready_for_pickup' => ['label' => 'Mark Cooked & Packaged', 'class' => 'btn-primary'],
        'cancelled'        => ['label' => 'Cancel Order',           'class' => 'btn-danger'],
    ],
    'ready_for_pickup' => [
        'out_for_delivery' => ['label' => 'Hand to Courier Rider', 'class' => 'btn-primary'],
        'delivered'        => ['label' => 'Direct Customer Pickup', 'class' => 'btn-secondary'],
        'cancelled'        => ['label' => 'Cancel Order',           'class' => 'btn-danger'],
    ],
    'out_for_delivery' => [
        'delivered' => ['label' => 'Mark Delivered by Courier', 'class' => 'btn-primary'],
        'cancelled' => ['label' => 'Mark Delivery Failed / Cancel', 'class' => 'btn-danger'],
    ],
    'delivered' => [], // Terminal state
    'cancelled' => [], // Terminal state
];

$feedbackMessage = null;
$feedbackType = 'info';

// -----------------------------------------------------------------------------
// PROCESS POST STATUS TRANSITIONS
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transition_status') {
    // 1. CSRF Token Validation
    if (!validate_csrf_token()) {
        $feedbackMessage = 'Invalid security token or session expired. Please refresh and try again.';
        $feedbackType = 'danger';
    } else {
        $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
        $newStatus = filter_input(INPUT_POST, 'new_status', FILTER_DEFAULT);
        $newStatus = is_string($newStatus) ? trim($newStatus) : '';
        $comment = filter_input(INPUT_POST, 'comment', FILTER_DEFAULT);
        $comment = is_string($comment) ? trim($comment) : '';

        if (!$orderId || empty($newStatus)) {
            $feedbackMessage = 'Order ID and target status are required.';
            $feedbackType = 'danger';
        } else {
            try {
                // START ACID PDO TRANSACTION
                $pdo->beginTransaction();

                // Select current order row FOR UPDATE to prevent race conditions
                $lockStmt = $pdo->prepare('
                    SELECT order_id, order_number, order_status
                    FROM orders
                    WHERE order_id = :order_id
                    FOR UPDATE
                ');
                $lockStmt->execute([':order_id' => $orderId]);
                $orderToUpdate = $lockStmt->fetch(PDO::FETCH_ASSOC);

                if (!$orderToUpdate) {
                    throw new RuntimeException("Order #{$orderId} was not found.");
                }

                $previousStatus = (string)$orderToUpdate['order_status'];

                // 2. Strict State Transition Verification
                $validNextStates = array_keys($allowedTransitions[$previousStatus] ?? []);

                if (!in_array($newStatus, $validNextStates, true)) {
                    $humanPrev = ucwords(str_replace('_', ' ', $previousStatus));
                    $humanTarget = ucwords(str_replace('_', ' ', $newStatus));
                    throw new RuntimeException("Illegal state jump! Order is currently '{$humanPrev}' and cannot transition directly to '{$humanTarget}'.");
                }

                // If comment is empty, generate standard descriptive audit note
                if ($comment === '') {
                    $comment = match ($newStatus) {
                        'confirmed'        => "Order confirmed by {$currentStaffName} into culinary queue.",
                        'preparing'        => "Chefs commenced food cooking and griddle preparation.",
                        'ready_for_pickup' => "Dishes cooked, packaged in thermal bags, and sealed for dispatch.",
                        'out_for_delivery' => "Handed over to delivery courier rider for transit.",
                        'delivered'        => "Order successfully handed over and completed.",
                        'cancelled'        => "Order cancelled by kitchen supervisor {$currentStaffName}.",
                        default            => "Status updated to " . str_replace('_', ' ', $newStatus),
                    };
                }

                // 3. Update orders table
                $updateOrderStmt = $pdo->prepare('
                    UPDATE orders
                    SET order_status = :new_status,
                        updated_at = NOW()
                    WHERE order_id = :order_id
                ');
                $updateOrderStmt->execute([
                    ':new_status' => $newStatus,
                    ':order_id'   => $orderId,
                ]);

                // 4. Insert state audit history record
                $historyInsertStmt = $pdo->prepare('
                    INSERT INTO order_status_history (order_id, changed_by_user_id, previous_status, new_status, comment, created_at)
                    VALUES (:order_id, :user_id, :prev_status, :new_status, :comment, NOW())
                ');
                $historyInsertStmt->execute([
                    ':order_id'     => $orderId,
                    ':user_id'      => $currentStaffId,
                    ':prev_status'  => $previousStatus,
                    ':new_status'   => $newStatus,
                    ':comment'      => $comment,
                ]);

                // 5. System Audit Log Record
                log_action(
                    $pdo,
                    $currentStaffId,
                    'ORDER_STATUS_TRANSITION',
                    sprintf("Staff '%s' transitioned Order '%s' from %s to %s. Note: %s", $currentStaffName, $orderToUpdate['order_number'], $previousStatus, $newStatus, $comment)
                );

                // COMMIT TRANSACTION
                $pdo->commit();

                // Rotate CSRF token
                regenerate_csrf_token();

                $feedbackMessage = "Order {$orderToUpdate['order_number']} successfully advanced to " . strtoupper(str_replace('_', ' ', $newStatus)) . "!";
                $feedbackType = 'success';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $feedbackMessage = $e->getMessage();
                $feedbackType = 'danger';
            }
        }
    }
}

// -----------------------------------------------------------------------------
// FILTER PARAMETERS & DATA RETRIEVAL
// -----------------------------------------------------------------------------
$statusTab = filter_input(INPUT_GET, 'tab', FILTER_DEFAULT);
$statusTab = is_string($statusTab) ? trim($statusTab) : 'active';

$searchKeyword = filter_input(INPUT_GET, 'search', FILTER_DEFAULT);
$searchKeyword = is_string($searchKeyword) ? trim($searchKeyword) : '';

// 1. Queue Statistics
$statStmt = $pdo->query('
    SELECT 
        SUM(CASE WHEN order_status = \'pending\' THEN 1 ELSE 0 END) AS count_pending,
        SUM(CASE WHEN order_status IN (\'confirmed\', \'preparing\') THEN 1 ELSE 0 END) AS count_cooking,
        SUM(CASE WHEN order_status = \'ready_for_pickup\' THEN 1 ELSE 0 END) AS count_ready,
        SUM(CASE WHEN order_status = \'out_for_delivery\' THEN 1 ELSE 0 END) AS count_transit,
        SUM(CASE WHEN order_status = \'delivered\' AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS count_delivered_today
    FROM orders
');
$queueCounts = $statStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'count_pending'         => 0,
    'count_cooking'         => 0,
    'count_ready'           => 0,
    'count_transit'         => 0,
    'count_delivered_today' => 0,
];

// 2. Build Where Filter
$where = [];
$params = [];

if ($statusTab === 'active') {
    $where[] = "o.order_status IN ('pending', 'confirmed', 'preparing', 'ready_for_pickup', 'out_for_delivery')";
} elseif ($statusTab === 'cooking') {
    $where[] = "o.order_status IN ('confirmed', 'preparing')";
} elseif ($statusTab === 'history') {
    $where[] = "o.order_status IN ('delivered', 'cancelled')";
} elseif (in_array($statusTab, ['pending', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'cancelled'], true)) {
    $where[] = 'o.order_status = :tab_status';
    $params[':tab_status'] = $statusTab;
}

if (!empty($searchKeyword)) {
    $where[] = '(o.order_number LIKE :kw OR u.first_name LIKE :kw OR u.last_name LIKE :kw OR o.contact_phone LIKE :kw)';
    $params[':kw'] = '%' . $searchKeyword . '%';
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$ordersSql = "
    SELECT o.*,
           u.first_name, u.last_name, u.email,
           p.payment_method, p.payment_status
    FROM orders o
    JOIN users u ON o.user_id = u.user_id
    LEFT JOIN payments p ON o.order_id = p.order_id
    {$whereSql}
    ORDER BY 
        CASE o.order_status
            WHEN 'pending' THEN 1
            WHEN 'confirmed' THEN 2
            WHEN 'preparing' THEN 3
            WHEN 'ready_for_pickup' THEN 4
            WHEN 'out_for_delivery' THEN 5
            ELSE 6
        END ASC,
        o.created_at ASC
";

$queueStmt = $pdo->prepare($ordersSql);
$queueStmt->execute($params);
$queueOrders = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch all line items for displayed orders
$orderItemsGrouped = [];
if (!empty($queueOrders)) {
    $orderIds = array_column($queueOrders, 'order_id');
    $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));

    $itemsStmt = $pdo->prepare("
        SELECT oi.*, d.name AS dish_name, c.name AS category_name
        FROM order_items oi
        JOIN dishes d ON oi.dish_id = d.dish_id
        LEFT JOIN categories c ON d.category_id = c.category_id
        WHERE oi.order_id IN ({$inPlaceholders})
        ORDER BY oi.order_id ASC, oi.order_item_id ASC
    ");
    $itemsStmt->execute($orderIds);
    $allLineItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allLineItems as $it) {
        $orderItemsGrouped[(int)$it['order_id']][] = $it;
    }
}

$pageTitle = 'Kitchen Order Queue - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.kitchen-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2rem;
}

.k-stat-card {
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-medium);
    border-radius: var(--radius-lg);
    padding: 1.25rem 1.5rem;
    display: flex;
    align-items: center;
    gap: 1.15rem;
    box-shadow: var(--shadow-sm);
}

.k-stat-val {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
}

.k-stat-lbl {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 700;
}

/* Tabs Toolbar */
.kitchen-toolbar {
    background: var(--bg-surface);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-md);
    padding: 1rem 1.25rem;
    margin-bottom: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.k-tab-pill {
    padding: 0.45rem 0.95rem;
    border-radius: var(--radius-pill);
    font-size: 0.8125rem;
    font-weight: 700;
    background: var(--bg-input);
    border: 1px solid var(--border-subtle);
    color: var(--text-secondary);
    text-decoration: none;
    transition: var(--transition-fast);
}

.k-tab-pill:hover, .k-tab-pill.active {
    background: var(--primary-subtle);
    border-color: var(--primary);
    color: var(--primary);
}

/* Kitchen Ticket Cards */
.ticket-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 1.5rem;
}

.k-ticket {
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-medium);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    transition: var(--transition-base);
}

.k-ticket:hover {
    border-color: rgba(249, 115, 22, 0.4);
    box-shadow: var(--shadow-lg);
}

.k-ticket-header {
    padding: 1rem 1.25rem;
    background: rgba(0, 0, 0, 0.25);
    border-bottom: 1px solid var(--border-subtle);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.k-ticket-body {
    padding: 1.25rem;
    flex-grow: 1;
}

.k-ticket-footer {
    padding: 1.25rem;
    background: rgba(0, 0, 0, 0.15);
    border-top: 1px solid var(--border-subtle);
}

.special-instruction-box {
    background: rgba(245, 158, 11, 0.12);
    border-left: 3px solid var(--warning);
    border-radius: var(--radius-sm);
    padding: 0.5rem 0.75rem;
    margin-top: 0.35rem;
    font-size: 0.8rem;
    color: #fde68a;
}
</style>

<div class="container" style="padding-top: 2rem; padding-bottom: 5rem;">

    <!-- Top Hero -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span class="role-badge badge-staff" style="margin-bottom: 0.5rem;">Kitchen Operations &bull; Dispatch Hub</span>
            <h1 style="font-size: 2.25rem; font-weight: 800; margin-bottom: 0.25rem;">
                Active Kitchen Order Queue
            </h1>
            <p style="color: var(--text-secondary); margin: 0; font-size: 0.95rem;">
                Live culinary prep pipeline. Advance orders through cooking stages, track customer notes, and maintain dispatch flow.
            </p>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="history.php?all=1" class="btn btn-secondary btn-sm">Master Order History</a>
            <a href="../menu/manage-dishes.php" class="btn btn-secondary btn-sm">Dishes Catalog</a>
            <a href="../admin/reports.php" class="btn btn-secondary btn-sm">Sales Reports</a>
        </div>
    </div>

    <!-- Feedback Flash Alert -->
    <?php if ($feedbackMessage !== null) : ?>
        <div style="padding: 1rem 1.25rem; margin-bottom: 1.75rem; border-radius: var(--radius-md); font-size: 0.925rem; display: flex; align-items: center; gap: 0.75rem; <?= ($feedbackType === 'success') ? 'background: var(--success-bg); color: #34d399; border: 1px solid var(--success-border);' : 'background: var(--danger-bg); color: #fecdd3; border: 1px solid var(--danger-border);'; ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <span><?= htmlspecialchars($feedbackMessage, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <!-- Live Queue Statistics -->
    <div class="kitchen-stats-grid">
        <!-- Pending -->
        <div class="k-stat-card">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: rgba(245, 158, 11, 0.15); color: #fbbf24; display: flex; align-items: center; justify-content: center;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
            </div>
            <div>
                <div class="k-stat-val"><?= (int)$queueCounts['count_pending']; ?></div>
                <div class="k-stat-lbl">Pending Approval</div>
            </div>
        </div>

        <!-- Cooking Now -->
        <div class="k-stat-card">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: rgba(249, 115, 22, 0.15); color: var(--primary); display: flex; align-items: center; justify-content: center;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2c1 3 4 5 4 9a6 6 0 0 1-12 0c0-4 3-6 4-9 1 2 3 3 4 0z"></path></svg>
            </div>
            <div>
                <div class="k-stat-val"><?= (int)$queueCounts['count_cooking']; ?></div>
                <div class="k-stat-lbl">On Griddles / Cooking</div>
            </div>
        </div>

        <!-- Ready for Pickup -->
        <div class="k-stat-card">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: rgba(6, 182, 212, 0.15); color: var(--accent); display: flex; align-items: center; justify-content: center;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
            </div>
            <div>
                <div class="k-stat-val"><?= (int)$queueCounts['count_ready']; ?></div>
                <div class="k-stat-lbl">Ready for Dispatch</div>
            </div>
        </div>

        <!-- Out for Delivery -->
        <div class="k-stat-card">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: rgba(139, 92, 246, 0.15); color: #c084fc; display: flex; align-items: center; justify-content: center;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
            </div>
            <div>
                <div class="k-stat-val"><?= (int)$queueCounts['count_transit']; ?></div>
                <div class="k-stat-lbl">Courier In Transit</div>
            </div>
        </div>

        <!-- Delivered Today -->
        <div class="k-stat-card">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: rgba(16, 185, 129, 0.15); color: var(--success); display: flex; align-items: center; justify-content: center;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <div>
                <div class="k-stat-val"><?= (int)$queueCounts['count_delivered_today']; ?></div>
                <div class="k-stat-lbl">Delivered Today</div>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="kitchen-toolbar">
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="kitchen-queue.php?tab=active" class="k-tab-pill <?= ($statusTab === 'active') ? 'active' : ''; ?>">All Active (<?= (int)$queueCounts['count_pending'] + (int)$queueCounts['count_cooking'] + (int)$queueCounts['count_ready'] + (int)$queueCounts['count_transit']; ?>)</a>
            <a href="kitchen-queue.php?tab=pending" class="k-tab-pill <?= ($statusTab === 'pending') ? 'active' : ''; ?>">Pending (<?= (int)$queueCounts['count_pending']; ?>)</a>
            <a href="kitchen-queue.php?tab=cooking" class="k-tab-pill <?= ($statusTab === 'cooking') ? 'active' : ''; ?>">Cooking / Prep (<?= (int)$queueCounts['count_cooking']; ?>)</a>
            <a href="kitchen-queue.php?tab=ready_for_pickup" class="k-tab-pill <?= ($statusTab === 'ready_for_pickup') ? 'active' : ''; ?>">Ready (<?= (int)$queueCounts['count_ready']; ?>)</a>
            <a href="kitchen-queue.php?tab=out_for_delivery" class="k-tab-pill <?= ($statusTab === 'out_for_delivery') ? 'active' : ''; ?>">In Transit (<?= (int)$queueCounts['count_transit']; ?>)</a>
            <a href="kitchen-queue.php?tab=history" class="k-tab-pill <?= ($statusTab === 'history') ? 'active' : ''; ?>">History / Completed</a>
        </div>

        <form action="kitchen-queue.php" method="GET" style="display: flex; gap: 0.5rem; align-items: center; margin: 0;">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($statusTab, ENT_QUOTES, 'UTF-8'); ?>">
            <input 
                type="text" 
                name="search" 
                class="form-control" 
                placeholder="Search ticket / customer..." 
                value="<?= htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>"
                style="height: 38px; width: 220px; font-size: 0.85rem;"
            >
            <button type="submit" class="btn btn-secondary btn-sm" style="height: 38px;">Filter</button>
            <?php if (!empty($searchKeyword)) : ?>
                <a href="kitchen-queue.php?tab=<?= urlencode($statusTab); ?>" class="btn btn-secondary btn-sm" style="height: 38px;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Active Tickets Grid -->
    <?php if (empty($queueOrders)) : ?>
        <div class="card" style="padding: 4rem 2rem; text-align: center; background: var(--bg-surface-elevated);">
            <div style="width: 64px; height: 64px; margin: 0 auto 1.25rem; border-radius: 50%; background: var(--bg-input); display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <h3 style="font-size: 1.35rem; color: var(--text-primary); margin-bottom: 0.5rem;">Kitchen Queue Clear!</h3>
            <p style="color: var(--text-secondary); max-width: 440px; margin: 0 auto;">
                There are currently no active orders in this kitchen queue tab. New online checkout orders will appear automatically.
            </p>
        </div>
    <?php else : ?>
        <div class="ticket-grid">
            <?php foreach ($queueOrders as $order) : ?>
                <?php
                    $ordId = (int)$order['order_id'];
                    $currentSt = (string)$order['order_status'];
                    $items = $orderItemsGrouped[$ordId] ?? [];
                    $nextSteps = $allowedTransitions[$currentSt] ?? [];
                    $elapsedMinutes = (int)floor((time() - strtotime($order['created_at'])) / 60);
                ?>
                <div class="k-ticket">
                    
                    <!-- Ticket Header -->
                    <div class="k-ticket-header">
                        <div>
                            <span style="font-family: var(--font-mono); font-size: 1.15rem; font-weight: 800; color: var(--text-primary); display: block;">
                                <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span style="font-size: 0.775rem; color: var(--text-muted);">
                                Placed <?= $elapsedMinutes; ?> min<?= $elapsedMinutes === 1 ? '' : 's'; ?> ago
                            </span>
                        </div>

                        <div>
                            <span style="font-size: 0.8rem; font-weight: 700; padding: 0.3rem 0.75rem; border-radius: var(--radius-pill); text-transform: uppercase; background: var(--bg-input); border: 1px solid var(--border-medium); color: var(--primary);">
                                <?= htmlspecialchars(str_replace('_', ' ', $currentSt), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Ticket Body -->
                    <div class="k-ticket-body">
                        
                        <!-- Customer & Destination -->
                        <div style="margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px dashed var(--border-subtle); font-size: 0.875rem;">
                            <div style="display: flex; justify-content: space-between; align-items: baseline;">
                                <strong style="color: var(--text-primary);"><?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span style="font-size: 0.8rem; color: var(--text-muted); font-family: var(--font-mono);"><?= htmlspecialchars($order['contact_phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.2rem;">
                                <?= htmlspecialchars($order['delivery_address'] . ', ' . $order['delivery_city'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php if (!empty($order['delivery_notes'])) : ?>
                                <div style="margin-top: 0.35rem; font-size: 0.785rem; color: var(--accent); background: rgba(6, 182, 212, 0.08); padding: 0.3rem 0.5rem; border-radius: var(--radius-sm);">
                                    <strong>Fulfillment Note:</strong> <?= htmlspecialchars($order['delivery_notes'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Dishes Checklist -->
                        <div>
                            <span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); font-weight: 700; display: block; margin-bottom: 0.5rem;">
                                Ordered Dishes (<?= count($items); ?> items)
                            </span>

                            <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                                <?php foreach ($items as $it) : ?>
                                    <div style="background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); padding: 0.6rem 0.75rem;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <strong style="color: var(--text-primary); font-size: 0.9rem;">
                                                    <span style="color: var(--primary); font-weight: 800;"><?= (int)$it['quantity']; ?>&times;</span>
                                                    <?= htmlspecialchars($it['dish_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </strong>
                                                <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">
                                                    <?= htmlspecialchars($it['category_name'] ?? 'Kitchen Special', ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </div>
                                            <span style="font-size: 0.85rem; font-family: var(--font-mono); color: var(--text-secondary);">
                                                LKR <?= number_format((float)$it['subtotal_lkr'], 2); ?>
                                            </span>
                                        </div>

                                        <?php if (!empty($it['special_instructions'])) : ?>
                                            <div class="special-instruction-box">
                                                <strong>Chef Note:</strong> <?= htmlspecialchars($it['special_instructions'], ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>

                    <!-- Ticket Footer (Actions & State Transitions) -->
                    <div class="k-ticket-footer">
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; font-size: 0.85rem;">
                            <span style="color: var(--text-muted);">Total Order Value:</span>
                            <strong style="font-family: var(--font-mono); color: var(--text-primary); font-size: 1.05rem;">
                                LKR <?= number_format((float)$order['total_amount_lkr'], 2); ?>
                            </strong>
                        </div>

                        <!-- Transition Control Buttons -->
                        <?php if (!empty($nextSteps)) : ?>
                            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <?php foreach ($nextSteps as $targetKey => $stepMeta) : ?>
                                    <form action="kitchen-queue.php?tab=<?= urlencode($statusTab); ?>" method="POST" style="margin: 0;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="transition_status">
                                        <input type="hidden" name="order_id" value="<?= $ordId; ?>">
                                        <input type="hidden" name="new_status" value="<?= htmlspecialchars($targetKey, ENT_QUOTES, 'UTF-8'); ?>">
                                        
                                        <button 
                                            type="submit" 
                                            class="btn <?= $stepMeta['class']; ?> btn-sm" 
                                            style="width: 100%; justify-content: center;"
                                            onclick="return confirm('Advance order <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8'); ?> to <?= strtoupper(str_replace('_', ' ', $targetKey)); ?>?');"
                                        >
                                            <?= htmlspecialchars($stepMeta['label'], ENT_QUOTES, 'UTF-8'); ?> &rarr;
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <div style="text-align: center; font-size: 0.825rem; color: var(--text-muted); padding: 0.35rem 0;">
                                Order is in completed terminal state.
                            </div>
                        <?php endif; ?>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.75rem; padding-top: 0.65rem; border-top: 1px solid var(--border-subtle); font-size: 0.8rem;">
                            <a href="tracking.php?order_id=<?= $ordId; ?>" style="color: var(--accent);">Visual Timeline &rarr;</a>
                            <a href="invoice.php?order_id=<?= $ordId; ?>" style="color: var(--text-muted);">View Invoice</a>
                        </div>

                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
