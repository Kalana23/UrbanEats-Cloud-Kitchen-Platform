<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Kitchen Stock & Inventory Dashboard
 * 
 * Accessible only to Kitchen Staff and Administrators.
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/stock.php';

// Access Control: Staff and Admin only
require_role(['Kitchen Staff', 'Administrator', 'Admin']);

$userId   = (int)($_SESSION['user_id'] ?? 0);
$username = (string)($_SESSION['username'] ?? 'Staff');
$feedback = ['type' => '', 'message' => ''];

// -----------------------------------------------------------------------------
// Handle Stock Adjustment POST Request (Restock / Wastage)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token()) {
        $feedback = ['type' => 'danger', 'message' => 'Security token expired. Please refresh the page and try again.'];
    } else {
        $action    = trim((string)($_POST['action'] ?? ''));
        $dishId    = (int)($_POST['dish_id'] ?? 0);
        $qty       = (int)($_POST['quantity'] ?? 0);
        $adjType   = trim((string)($_POST['adjustment_type'] ?? ''));
        $reason    = trim((string)($_POST['reason'] ?? ''));

        if ($action === 'adjust_stock') {
            if ($dishId <= 0) {
                $feedback = ['type' => 'danger', 'message' => 'Please select a valid dish item.'];
            } elseif ($qty <= 0) {
                $feedback = ['type' => 'danger', 'message' => 'Quantity must be a positive integer greater than zero.'];
            } elseif (!in_array($adjType, ['restock', 'wastage'], true)) {
                $feedback = ['type' => 'danger', 'message' => 'Invalid adjustment type selected.'];
            } elseif (empty($reason)) {
                $feedback = ['type' => 'danger', 'message' => 'Please provide a clear justification or reason for this stock adjustment.'];
            } else {
                // Fetch dish name for descriptive audit logs
                $dishStmt = $pdo->prepare('SELECT name FROM dishes WHERE dish_id = :id');
                $dishStmt->execute([':id' => $dishId]);
                $dishName = (string)$dishStmt->fetchColumn() ?: "Dish #{$dishId}";

                if ($adjType === 'restock') {
                    $success = record_stock_adjustment($pdo, $dishId, $qty, 'restock', $reason, $userId);
                    if ($success) {
                        log_action(
                            $pdo,
                            $userId,
                            'STOCK_RESTOCK',
                            sprintf("Restocked +%d units of '%s' (ID #%d). Reason: %s", $qty, $dishName, $dishId, $reason)
                        );
                        $feedback = ['type' => 'success', 'message' => "Successfully restocked +{$qty} units of {$dishName}."];
                    } else {
                        $feedback = ['type' => 'danger', 'message' => 'Failed to process restocking. Please check inventory database status.'];
                    }
                } elseif ($adjType === 'wastage') {
                    $success = record_stock_adjustment($pdo, $dishId, -$qty, 'wastage', $reason, $userId);
                    if ($success) {
                        log_action(
                            $pdo,
                            $userId,
                            'STOCK_WASTAGE',
                            sprintf("Logged wastage of -%d units of '%s' (ID #%d). Reason: %s", $qty, $dishName, $dishId, $reason)
                        );
                        $feedback = ['type' => 'success', 'message' => "Successfully recorded wastage of -{$qty} units of {$dishName}."];
                    } else {
                        $feedback = ['type' => 'danger', 'message' => 'Cannot record wastage: quantity exceeds current on-hand stock or dish not found.'];
                    }
                }
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Fetch Inventory Data
// -----------------------------------------------------------------------------

// 1. Out of Stock items (current_stock = 0)
$outOfStockStmt = $pdo->query('
    SELECT d.dish_id, d.name, d.price, d.image_path, c.name AS category_name,
           i.current_stock, i.reorder_level, i.updated_at
    FROM inventory i
    JOIN dishes d ON i.dish_id = d.dish_id
    JOIN categories c ON d.category_id = c.category_id
    WHERE i.current_stock = 0
    ORDER BY d.name ASC
');
$outOfStockItems = $outOfStockStmt->fetchAll();

// 2. Low Stock items (current_stock > 0 AND current_stock <= reorder_level)
$lowStockStmt = $pdo->query('
    SELECT d.dish_id, d.name, d.price, d.image_path, c.name AS category_name,
           i.current_stock, i.reorder_level, i.updated_at
    FROM inventory i
    JOIN dishes d ON i.dish_id = d.dish_id
    JOIN categories c ON d.category_id = c.category_id
    WHERE i.current_stock > 0 AND i.current_stock <= i.reorder_level
    ORDER BY i.current_stock ASC, d.name ASC
');
$lowStockItems = $lowStockStmt->fetchAll();

// 3. Healthy / Normal Stock items (current_stock > reorder_level)
$healthyStockStmt = $pdo->query('
    SELECT d.dish_id, d.name, d.price, d.image_path, c.name AS category_name,
           i.current_stock, i.reorder_level, i.updated_at
    FROM inventory i
    JOIN dishes d ON i.dish_id = d.dish_id
    JOIN categories c ON d.category_id = c.category_id
    WHERE i.current_stock > i.reorder_level
    ORDER BY c.name ASC, d.name ASC
');
$healthyStockItems = $healthyStockStmt->fetchAll();

// 4. All active dishes for the Adjustment dropdown
$allDishesStmt = $pdo->query('
    SELECT d.dish_id, d.name, c.name AS category_name, i.current_stock
    FROM dishes d
    JOIN categories c ON d.category_id = c.category_id
    LEFT JOIN inventory i ON d.dish_id = i.dish_id
    WHERE d.is_active = 1
    ORDER BY c.name ASC, d.name ASC
');
$activeDishes = $allDishesStmt->fetchAll();

// 5. Recent 15 stock movements for audit log visibility
$movementsStmt = $pdo->query('
    SELECT sm.movement_id, sm.change_qty, sm.movement_type, sm.reason, sm.created_at,
           d.name AS dish_name, u.username, u.first_name, u.last_name
    FROM stock_movements sm
    JOIN dishes d ON sm.dish_id = d.dish_id
    LEFT JOIN users u ON sm.user_id = u.user_id
    ORDER BY sm.created_at DESC
    LIMIT 15
');
$recentMovements = $movementsStmt->fetchAll();

$pageTitle = 'Stock & Inventory Management';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 4rem;">
    
    <!-- Page Header & Summary -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.35rem;">
                <span class="role-badge badge-staff">Component B: Inventory</span>
                <span style="color: var(--text-muted); font-size: 0.85rem;">Cloud Kitchen Stock Control</span>
            </div>
            <h1 style="font-size: 2rem; font-weight: 800; color: var(--text-primary); margin: 0;">Stock & Inventory Dashboard</h1>
            <p style="color: var(--text-secondary); margin-top: 0.35rem; font-size: 0.95rem;">
                Real-time ingredient and prepared portion tracking, reorder thresholds, and staff stock adjustments.
            </p>
        </div>

        <div style="display: flex; gap: 0.75rem;">
            <a href="manage-dishes.php" class="btn btn-secondary btn-sm">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                Manage Dishes
            </a>
            <a href="manage-categories.php" class="btn btn-secondary btn-sm">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                Manage Categories
            </a>
            <a href="list.php" class="btn btn-primary btn-sm">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                Public Menu View
            </a>
        </div>
    </div>

    <!-- Feedback Message -->
    <?php if (!empty($feedback['message'])): ?>
        <div class="alert alert-<?= htmlspecialchars($feedback['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars($feedback['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- Overview Counters -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 2.5rem;">
        <div class="card" style="padding: 1.5rem; border-left: 4px solid var(--danger);">
            <div style="color: var(--text-muted); font-size: 0.85rem; font-weight: 700; text-transform: uppercase;">Out of Stock</div>
            <div style="font-size: 2.25rem; font-weight: 800; color: var(--danger); margin: 0.5rem 0;"><?= count($outOfStockItems); ?></div>
            <div style="font-size: 0.8125rem; color: var(--text-secondary);">Dishes unavailable for customer ordering</div>
        </div>
        <div class="card" style="padding: 1.5rem; border-left: 4px solid var(--warning);">
            <div style="color: var(--text-muted); font-size: 0.85rem; font-weight: 700; text-transform: uppercase;">Low Stock Warning</div>
            <div style="font-size: 2.25rem; font-weight: 800; color: var(--warning); margin: 0.5rem 0;"><?= count($lowStockItems); ?></div>
            <div style="font-size: 0.8125rem; color: var(--text-secondary);">Stock at or below reorder threshold</div>
        </div>
        <div class="card" style="padding: 1.5rem; border-left: 4px solid var(--success);">
            <div style="color: var(--text-muted); font-size: 0.85rem; font-weight: 700; text-transform: uppercase;">Healthy Stock</div>
            <div style="font-size: 2.25rem; font-weight: 800; color: var(--success); margin: 0.5rem 0;"><?= count($healthyStockItems); ?></div>
            <div style="font-size: 0.8125rem; color: var(--text-secondary);">Adequate portions ready in kitchen</div>
        </div>
        <div class="card" style="padding: 1.5rem; border-left: 4px solid var(--accent);">
            <div style="color: var(--text-muted); font-size: 0.85rem; font-weight: 700; text-transform: uppercase;">Active Dishes</div>
            <div style="font-size: 2.25rem; font-weight: 800; color: var(--accent); margin: 0.5rem 0;"><?= count($activeDishes); ?></div>
            <div style="font-size: 0.8125rem; color: var(--text-secondary);">Total tracked catalog dishes</div>
        </div>
    </div>

    <!-- Restock / Wastage Adjustment Form Card -->
    <div class="card" style="margin-bottom: 2.5rem;">
        <div class="card-header" style="background: rgba(255, 255, 255, 0.02);">
            <div>
                <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0;">Record Stock Movement (Restock or Wastage)</h2>
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.25rem;">
                    Staff actions are audited and logged with user attribution and timestamp.
                </p>
            </div>
            <span class="role-badge badge-staff">CSRF Protected</span>
        </div>
        <div class="card-body">
            <form action="stock.php" method="POST" id="stockAdjustmentForm">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="adjust_stock">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem;">
                    <!-- Dish Selector -->
                    <div class="form-group">
                        <label for="dish_id" class="form-label">Select Dish <span class="required">*</span></label>
                        <select name="dish_id" id="dish_id" class="form-control" required>
                            <option value="">-- Choose a dish --</option>
                            <?php foreach ($activeDishes as $d): ?>
                                <option value="<?= (int)$d['dish_id']; ?>">
                                    <?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8'); ?> 
                                    [<?= htmlspecialchars($d['category_name'], ENT_QUOTES, 'UTF-8'); ?>] 
                                    (Current Stock: <?= (int)($d['current_stock'] ?? 0); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Movement Type -->
                    <div class="form-group">
                        <label for="adjustment_type" class="form-label">Movement Type <span class="required">*</span></label>
                        <select name="adjustment_type" id="adjustment_type" class="form-control" required>
                            <option value="restock">Restock (+ Add Portions / Fresh Batch)</option>
                            <option value="wastage">Wastage (- Expired, Spilled, or Spoiled)</option>
                        </select>
                    </div>

                    <!-- Quantity -->
                    <div class="form-group">
                        <label for="quantity" class="form-label">Portion Quantity <span class="required">*</span></label>
                        <input type="number" name="quantity" id="quantity" class="form-control" min="1" max="1000" placeholder="e.g. 10" required>
                    </div>

                    <!-- Reason -->
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="reason" class="form-label">Justification / Reason <span class="required">*</span></label>
                        <input type="text" name="reason" id="reason" class="form-control" placeholder="e.g., Morning batch cook, Supplier replenishment, Expired sauce base" required maxlength="255">
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary" id="btnSubmitStock">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Execute Stock Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 1. Out of Stock Items Section -->
    <div class="card" style="margin-bottom: 2.5rem; border: 1px solid var(--danger-border);">
        <div class="card-header" style="background: var(--danger-bg); border-bottom-color: var(--danger-border);">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                <h2 style="font-size: 1.15rem; font-weight: 700; color: #fecdd3; margin: 0;">Out of Stock Items (Stock = 0)</h2>
            </div>
            <span style="font-size: 0.85rem; font-weight: 700; color: var(--danger);"><?= count($outOfStockItems); ?> Items Depleted</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($outOfStockItems)): ?>
                <div style="padding: 2rem; text-align: center; color: var(--text-muted);">
                    No dishes are currently out of stock. All kitchen catalog items have active portions.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Dish</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Current Stock</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                                <th>Quick Restock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($outOfStockItems as $item): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.85rem;">
                                            <?php if (!empty($item['image_path'])): ?>
                                                <img src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($item['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 44px; height: 44px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
                                            <?php endif; ?>
                                            <div>
                                                <strong style="color: var(--text-primary); font-size: 0.95rem;"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <div style="font-size: 0.785rem; color: var(--text-muted);">Dish ID: #<?= (int)$item['dish_id']; ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($item['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>LKR <?= number_format((float)$item['price'], 2); ?></td>
                                    <td><strong style="color: var(--danger); font-size: 1.1rem;">0</strong></td>
                                    <td><?= (int)$item['reorder_level']; ?></td>
                                    <td>
                                        <span class="role-badge" style="background: var(--danger-bg); color: var(--danger); border-color: var(--danger-border);">Out of Stock</span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="populateQuickRestock(<?= (int)$item['dish_id']; ?>, 'Restocked fresh kitchen batch')">
                                            Restock Now
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 2. Low Stock Items Section -->
    <div class="card" style="margin-bottom: 2.5rem; border: 1px solid var(--warning-border);">
        <div class="card-header" style="background: var(--warning-bg); border-bottom-color: var(--warning-border);">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                <h2 style="font-size: 1.15rem; font-weight: 700; color: #fde68a; margin: 0;">Low Stock Items (Stock &le; Reorder Threshold)</h2>
            </div>
            <span style="font-size: 0.85rem; font-weight: 700; color: var(--warning);"><?= count($lowStockItems); ?> Items Need Replenishment</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($lowStockItems)): ?>
                <div style="padding: 2rem; text-align: center; color: var(--text-muted);">
                    All active items have adequate inventory buffers.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Dish</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Current Stock</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                                <th>Quick Restock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStockItems as $item): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.85rem;">
                                            <?php if (!empty($item['image_path'])): ?>
                                                <img src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($item['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 44px; height: 44px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
                                            <?php endif; ?>
                                            <div>
                                                <strong style="color: var(--text-primary); font-size: 0.95rem;"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <div style="font-size: 0.785rem; color: var(--text-muted);">Dish ID: #<?= (int)$item['dish_id']; ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($item['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>LKR <?= number_format((float)$item['price'], 2); ?></td>
                                    <td><strong style="color: var(--warning); font-size: 1.1rem;"><?= (int)$item['current_stock']; ?></strong></td>
                                    <td><?= (int)$item['reorder_level']; ?></td>
                                    <td>
                                        <span class="role-badge" style="background: var(--warning-bg); color: var(--warning); border-color: var(--warning-border);">Low Stock</span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="populateQuickRestock(<?= (int)$item['dish_id']; ?>, 'Buffer replenishment')">
                                            Restock
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 3. Healthy Inventory Overview -->
    <div class="card" style="margin-bottom: 2.5rem;">
        <div class="card-header">
            <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0;">Adequate Stock Inventory</h2>
            <span style="font-size: 0.85rem; color: var(--text-muted);"><?= count($healthyStockItems); ?> Dishes Healthy</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Dish</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Current Stock</th>
                            <th>Reorder Level</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($healthyStockItems as $item): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.85rem;">
                                        <?php if (!empty($item['image_path'])): ?>
                                            <img src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($item['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 44px; height: 44px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
                                        <?php endif; ?>
                                        <strong style="color: var(--text-primary);"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($item['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>LKR <?= number_format((float)$item['price'], 2); ?></td>
                                <td><strong style="color: var(--success);"><?= (int)$item['current_stock']; ?></strong></td>
                                <td><?= (int)$item['reorder_level']; ?></td>
                                <td>
                                    <span class="role-badge badge-customer">In Stock</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 4. Stock Movement Audit Trail -->
    <div class="card">
        <div class="card-header">
            <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0;">Recent Stock Movement Log</h2>
            <span style="font-size: 0.85rem; color: var(--text-muted);">Last 15 Records</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Dish</th>
                            <th>Type</th>
                            <th>Change Qty</th>
                            <th>Staff / Initiator</th>
                            <th>Reason / Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentMovements as $mv): ?>
                            <tr>
                                <td style="font-size: 0.825rem; font-family: var(--font-mono); color: var(--text-muted);">
                                    <?= htmlspecialchars(date('M d, H:i', strtotime($mv['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td><strong style="color: var(--text-primary);"><?= htmlspecialchars($mv['dish_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td>
                                    <?php if ($mv['movement_type'] === 'restock'): ?>
                                        <span class="role-badge" style="background: var(--success-bg); color: var(--success); border-color: var(--success-border);">Restock</span>
                                    <?php elseif ($mv['movement_type'] === 'wastage'): ?>
                                        <span class="role-badge" style="background: var(--danger-bg); color: var(--danger); border-color: var(--danger-border);">Wastage</span>
                                    <?php elseif ($mv['movement_type'] === 'order'): ?>
                                        <span class="role-badge" style="background: var(--info-bg); color: var(--info); border-color: var(--info-border);">Order</span>
                                    <?php else: ?>
                                        <span class="role-badge badge-guest">Adjustment</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($mv['change_qty'] > 0): ?>
                                        <strong style="color: var(--success);">+<?= (int)$mv['change_qty']; ?></strong>
                                    <?php else: ?>
                                        <strong style="color: var(--danger);"><?= (int)$mv['change_qty']; ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($mv['username'] ?? 'System/Guest', ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="font-size: 0.85rem; color: var(--text-secondary);">
                                    <?= htmlspecialchars($mv['reason'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
function populateQuickRestock(dishId, defaultReason) {
    const select = document.getElementById('dish_id');
    const typeSelect = document.getElementById('adjustment_type');
    const reasonInput = document.getElementById('reason');
    const qtyInput = document.getElementById('quantity');

    if (select) select.value = dishId;
    if (typeSelect) typeSelect.value = 'restock';
    if (reasonInput) reasonInput.value = defaultReason || 'Kitchen replenishment';
    if (qtyInput) {
        qtyInput.value = 10;
        qtyInput.focus();
    }

    window.scrollTo({
        top: document.getElementById('stockAdjustmentForm').offsetTop - 100,
        behavior: 'smooth'
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
