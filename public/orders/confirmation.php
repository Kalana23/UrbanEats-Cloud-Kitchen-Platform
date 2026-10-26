<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Order Confirmation & Receipt Showcase
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

// Enforce login
require_login('../login.php');

$userId = (int)($_SESSION['user_id'] ?? 0);
$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);

if ($orderId === false || $orderId <= 0) {
    header('Location: ../menu/list.php');
    exit();
}

// Fetch order details ensuring user authorization (or Admin/Staff)
$isStaffOrAdmin = has_role(['Administrator', 'Kitchen Staff']);
$sql = '
    SELECT o.*, u.first_name, u.last_name, u.email, c.code AS coupon_code,
           p.payment_method, p.payment_status, p.transaction_reference, p.paid_at
    FROM orders o
    JOIN users u ON o.user_id = u.user_id
    LEFT JOIN coupons c ON o.coupon_id = c.coupon_id
    LEFT JOIN payments p ON o.order_id = p.order_id
    WHERE o.order_id = :order_id
';
if (!$isStaffOrAdmin) {
    $sql .= ' AND o.user_id = :user_id';
}

$stmt = $pdo->prepare($sql);
$params = [':order_id' => $orderId];
if (!$isStaffOrAdmin) {
    $params[':user_id'] = $userId;
}
$stmt->execute($params);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: ../menu/list.php');
    exit();
}

// Fetch order items
$itemsStmt = $pdo->prepare('
    SELECT oi.*, d.name AS dish_name, d.image_path
    FROM order_items oi
    JOIN dishes d ON oi.dish_id = d.dish_id
    WHERE oi.order_id = :order_id
');
$itemsStmt->execute([':order_id' => $orderId]);
$orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch status history timeline
$historyStmt = $pdo->prepare('
    SELECT h.*, u.first_name AS staff_first, u.last_name AS staff_last
    FROM order_status_history h
    LEFT JOIN users u ON h.changed_by_user_id = u.user_id
    WHERE h.order_id = :order_id
    ORDER BY h.created_at ASC
');
$historyStmt->execute([':order_id' => $orderId]);
$statusHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Order Confirmed: ' . htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top: 2.5rem; padding-bottom: 5.5rem;">

    <!-- Success Hero Banner -->
    <div class="card" style="padding: 2.75rem 2rem; text-align: center; background: linear-gradient(180deg, var(--bg-surface-elevated), var(--bg-surface)); border: 1px solid var(--border-medium); margin-bottom: 2rem; box-shadow: var(--shadow-lg);">
        <div style="width: 72px; height: 72px; margin: 0 auto 1.25rem; border-radius: 50%; background: rgba(16, 185, 129, 0.15); border: 2px solid var(--success); display: flex; align-items: center; justify-content: center; color: var(--success);">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>

        <span style="display: inline-block; padding: 0.3rem 1rem; border-radius: var(--radius-pill); font-size: 0.85rem; font-weight: 700; background: var(--primary-subtle); color: var(--primary); margin-bottom: 0.85rem; border: 1px solid rgba(249, 115, 22, 0.3);">
            Order Successfully Placed
        </span>

        <h1 style="font-size: 2.25rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.5rem;">
            <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8'); ?>
        </h1>

        <p style="color: var(--text-secondary); max-width: 520px; margin: 0 auto 1.5rem; font-size: 1.05rem;">
            Thank you, <strong><?= htmlspecialchars($order['first_name'], ENT_QUOTES, 'UTF-8'); ?></strong>! Your order has been dispatched to our kitchen queue. Our chefs are firing up the preparation griddles.
        </p>

        <div style="display: inline-flex; gap: 1rem; flex-wrap: wrap; justify-content: center;">
            <div style="background: var(--bg-input); padding: 0.65rem 1.25rem; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle); font-size: 0.9rem;">
                <span style="color: var(--text-muted);">Estimated Readiness:</span>
                <strong style="color: var(--text-primary); margin-left: 0.35rem;">
                    <?= !empty($order['estimated_delivery_time']) ? date('g:i A', strtotime($order['estimated_delivery_time'])) : '30-45 mins'; ?>
                </strong>
            </div>

            <div style="background: var(--bg-input); padding: 0.65rem 1.25rem; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle); font-size: 0.9rem;">
                <span style="color: var(--text-muted);">Status:</span>
                <strong style="color: var(--primary); margin-left: 0.35rem; text-transform: uppercase;">
                    <?= htmlspecialchars(str_replace('_', ' ', $order['order_status']), ENT_QUOTES, 'UTF-8'); ?>
                </strong>
            </div>
        </div>
    </div>

    <!-- Receipt Details Grid -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; align-items: flex-start;">
        
        <!-- Left: Line Items & Live Timeline -->
        <div>
            
            <!-- Items Card -->
            <div class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); margin-bottom: 2rem;">
                <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path></svg>
                    <span>Ordered Dishes</span>
                </h3>

                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <?php foreach ($orderItems as $item): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid var(--border-subtle);">
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <div style="width: 50px; height: 50px; border-radius: var(--radius-sm); overflow: hidden; background: var(--bg-input); flex-shrink: 0;">
                                    <?php if (!empty($item['image_path'])): ?>
                                        <img src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($item['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <strong style="color: var(--text-primary); font-size: 1rem; display: block;">
                                        <?= htmlspecialchars($item['dish_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </strong>
                                    <span style="font-size: 0.85rem; color: var(--text-muted);">
                                        <?= (int)$item['quantity']; ?> Portion<?= (int)$item['quantity'] > 1 ? 's' : ''; ?> &times; LKR <?= number_format((float)$item['unit_price_lkr'], 2); ?>
                                    </span>
                                </div>
                            </div>
                            <strong style="color: var(--text-primary); font-size: 1.05rem;">
                                LKR <?= number_format((float)$item['subtotal_lkr'], 2); ?>
                            </strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Lifecycle Timeline Card -->
            <div class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated);">
                <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                    <span>Kitchen Progress &amp; Audit Trail</span>
                </h3>

                <div style="position: relative; padding-left: 2rem; display: flex; flex-direction: column; gap: 1.5rem;">
                    <div style="position: absolute; left: 9px; top: 8px; bottom: 8px; width: 2px; background: var(--border-medium);"></div>

                    <?php foreach ($statusHistory as $hist): ?>
                        <div style="position: relative;">
                            <div style="position: absolute; left: -2rem; top: 3px; width: 20px; height: 20px; border-radius: 50%; background: var(--bg-surface-elevated); border: 3px solid var(--primary);"></div>
                            <div style="font-weight: 700; color: var(--text-primary); text-transform: capitalize; font-size: 0.95rem;">
                                Status: <?= htmlspecialchars(str_replace('_', ' ', $hist['new_status']), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div style="font-size: 0.825rem; color: var(--text-secondary); margin-top: 0.2rem;">
                                <?= htmlspecialchars($hist['comment'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">
                                <?= date('M j, Y - g:i A', strtotime($hist['created_at'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <!-- Right: Order Meta & Financial Summary -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            
            <!-- Financial Card -->
            <div class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-medium);">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem;">
                    Payment Summary
                </h3>

                <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem; font-size: 0.9rem; color: var(--text-secondary);">
                    <span>Subtotal:</span>
                    <strong style="color: var(--text-primary);">LKR <?= number_format((float)$order['subtotal_lkr'], 2); ?></strong>
                </div>

                <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem; font-size: 0.9rem; color: var(--text-secondary);">
                    <span>Delivery / Packaging:</span>
                    <strong style="color: var(--text-primary);">
                        <?= (float)$order['delivery_fee_lkr'] > 0 ? 'LKR ' . number_format((float)$order['delivery_fee_lkr'], 2) : 'FREE'; ?>
                    </strong>
                </div>

                <?php if ((float)$order['discount_lkr'] > 0): ?>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem; font-size: 0.9rem; color: var(--success);">
                        <span>Coupon Discount:</span>
                        <strong>&minus; LKR <?= number_format((float)$order['discount_lkr'], 2); ?></strong>
                    </div>
                <?php endif; ?>

                <div style="padding-top: 1rem; border-top: 1px solid var(--border-medium); margin-top: 1rem; display: flex; justify-content: space-between; align-items: flex-end;">
                    <span style="font-weight: 800; color: var(--text-primary); font-size: 1.05rem;">Grand Total:</span>
                    <strong style="font-size: 1.5rem; font-weight: 800; color: var(--primary);">
                        LKR <?= number_format((float)$order['total_amount_lkr'], 2); ?>
                    </strong>
                </div>
            </div>

            <!-- Fulfillment & Delivery Address Card -->
            <div class="card" style="padding: 1.5rem; background: var(--bg-surface); border: 1px solid var(--border-card);">
                <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.75rem;">
                    Delivery Information
                </h4>
                
                <div style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.6;">
                    <div style="margin-bottom: 0.4rem;">
                        <strong style="color: var(--text-primary);">Address:</strong><br>
                        <?= nl2br(htmlspecialchars($order['delivery_address'], ENT_QUOTES, 'UTF-8')); ?>
                    </div>
                    <div style="margin-bottom: 0.4rem;">
                        <strong style="color: var(--text-primary);">City:</strong> <?= htmlspecialchars($order['delivery_city'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div style="margin-bottom: 0.4rem;">
                        <strong style="color: var(--text-primary);">Phone:</strong> <?= htmlspecialchars($order['contact_phone'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php if (!empty($order['delivery_notes'])): ?>
                        <div>
                            <strong style="color: var(--text-primary);">Instructions:</strong><br>
                            <?= htmlspecialchars($order['delivery_notes'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Method Card -->
            <div class="card" style="padding: 1.5rem; background: var(--bg-surface); border: 1px solid var(--border-card);">
                <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.75rem;">
                    Payment Details
                </h4>

                <div style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.6;">
                    <div><strong>Method:</strong> <?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$order['payment_method'])), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div><strong>Status:</strong> <?= htmlspecialchars(strtoupper((string)$order['payment_status']), ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if (!empty($order['transaction_reference'])): ?>
                        <div style="font-family: var(--font-mono); font-size: 0.8rem; margin-top: 0.35rem; color: var(--text-muted);">
                            Ref: <?= htmlspecialchars($order['transaction_reference'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <a href="tracking.php?order_id=<?= (int)$order['order_id']; ?>" class="btn btn-primary" style="text-align: center; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    <span>Track Order Progress &rarr;</span>
                </a>
                <a href="invoice.php?order_id=<?= (int)$order['order_id']; ?>" class="btn btn-secondary" style="text-align: center; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <span>View &amp; Print Tax Invoice</span>
                </a>
                <a href="../menu/list.php" class="btn btn-secondary" style="text-align: center;">
                    Order More Dishes
                </a>
            </div>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
