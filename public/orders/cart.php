<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Interactive Order Cart & Portions Management
 * 
 * Features:
 * - Session-based cart persistence ($_SESSION['cart'])
 * - Strict server-side validation for dish IDs and portion quantities
 * - Database stock and price re-verification against dishes and inventory tables
 * - CSRF verification across all modification actions (add, update, remove, clear, coupon)
 * - Coupon application and dynamic discount calculation from Component C coupons table
 * - Premium, responsive dark-slate and culinary amber UI with LKR currency formatting
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';

// Ensure session is started safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize session cart array if not already present
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Flat-rate insulated delivery fee (LKR)
const STANDARD_DELIVERY_FEE_LKR = 350.00;

// Feedback notifications storage
$alerts = [];

// -----------------------------------------------------------------------------
// Helper: Append a feedback notification
// -----------------------------------------------------------------------------
function add_cart_alert(string $type, string $message): void {
    global $alerts;
    $alerts[] = ['type' => $type, 'message' => $message];
}

// -----------------------------------------------------------------------------
// POST Request Processing: Cart Modification Actions with CSRF Protection
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Enforce strict CSRF verification on all state modifications
    if (!validate_csrf_token()) {
        add_cart_alert('danger', 'Security validation failed: Invalid or expired CSRF token. Please refresh and try again.');
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        // ---------------------------------------------------------------------
        // Action: ADD TO CART
        // ---------------------------------------------------------------------
        if ($action === 'add') {
            $dishId = filter_input(INPUT_POST, 'dish_id', FILTER_VALIDATE_INT);
            $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);

            if ($dishId === false || $dishId <= 0) {
                add_cart_alert('danger', 'Invalid dish identifier provided.');
            } elseif ($quantity === false || $quantity <= 0) {
                add_cart_alert('danger', 'Portion quantity must be at least 1.');
            } else {
                // Fetch dish and live inventory directly from database (Never trust client payload)
                $stmt = $pdo->prepare('
                    SELECT d.dish_id, d.name, d.price, d.image_path, d.is_active, 
                           COALESCE(i.current_stock, 0) AS current_stock
                    FROM dishes d
                    LEFT JOIN inventory i ON d.dish_id = i.dish_id
                    WHERE d.dish_id = :dish_id
                ');
                $stmt->execute([':dish_id' => $dishId]);
                $dish = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$dish) {
                    add_cart_alert('danger', 'The requested culinary dish could not be found.');
                } elseif ((int)$dish['is_active'] !== 1) {
                    add_cart_alert('danger', htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8') . ' is currently disabled on our kitchen menu.');
                } else {
                    $availableStock = (int)$dish['current_stock'];
                    $currentCartQty = (int)($_SESSION['cart'][$dishId]['quantity'] ?? 0);
                    $targetQty = $currentCartQty + $quantity;

                    if ($availableStock <= 0) {
                        add_cart_alert('danger', 'Sorry, "' . htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8') . '" is currently sold out.');
                    } elseif ($targetQty > $availableStock) {
                        $allowedAdd = max(0, $availableStock - $currentCartQty);
                        if ($allowedAdd > 0) {
                            $_SESSION['cart'][$dishId] = [
                                'dish_id'    => (int)$dish['dish_id'],
                                'name'       => (string)$dish['name'],
                                'price'      => (float)$dish['price'],
                                'image_path' => (string)($dish['image_path'] ?? ''),
                                'quantity'   => $availableStock,
                            ];
                            add_cart_alert('warning', 'Only ' . $availableStock . ' portions available for "' . htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8') . '". Quantity capped to maximum in stock.');
                        } else {
                            add_cart_alert('warning', 'You already have all available portions (' . $availableStock . ') of "' . htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8') . '" in your cart.');
                        }
                    } else {
                        $_SESSION['cart'][$dishId] = [
                            'dish_id'    => (int)$dish['dish_id'],
                            'name'       => (string)$dish['name'],
                            'price'      => (float)$dish['price'],
                            'image_path' => (string)($dish['image_path'] ?? ''),
                            'quantity'   => $targetQty,
                        ];
                        add_cart_alert('success', 'Added ' . $quantity . 'x "' . htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8') . '" to your order cart.');
                    }
                }
            }
        }

        // ---------------------------------------------------------------------
        // Action: UPDATE QUANTITY
        // ---------------------------------------------------------------------
        elseif ($action === 'update') {
            $dishId = filter_input(INPUT_POST, 'dish_id', FILTER_VALIDATE_INT);
            $newQuantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);

            if ($dishId === false || $dishId <= 0 || !isset($_SESSION['cart'][$dishId])) {
                add_cart_alert('danger', 'Invalid or missing dish in cart.');
            } elseif ($newQuantity === false || $newQuantity < 0) {
                add_cart_alert('danger', 'Quantity must be a non-negative number.');
            } elseif ($newQuantity === 0) {
                // If updated to 0, cleanly remove item
                $dishName = $_SESSION['cart'][$dishId]['name'];
                unset($_SESSION['cart'][$dishId]);
                add_cart_alert('info', 'Removed "' . htmlspecialchars($dishName, ENT_QUOTES, 'UTF-8') . '" from your cart.');
            } else {
                // Check current live kitchen stock
                $stmt = $pdo->prepare('
                    SELECT d.name, d.price, COALESCE(i.current_stock, 0) AS current_stock, d.is_active
                    FROM dishes d
                    LEFT JOIN inventory i ON d.dish_id = i.dish_id
                    WHERE d.dish_id = :dish_id
                ');
                $stmt->execute([':dish_id' => $dishId]);
                $dish = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$dish || (int)$dish['is_active'] !== 1) {
                    unset($_SESSION['cart'][$dishId]);
                    add_cart_alert('warning', 'This dish is no longer available and was removed from your cart.');
                } else {
                    $availableStock = (int)$dish['current_stock'];
                    if ($availableStock <= 0) {
                        unset($_SESSION['cart'][$dishId]);
                        add_cart_alert('danger', '"' . htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8') . '" is now out of stock and was removed from your cart.');
                    } elseif ($newQuantity > $availableStock) {
                        $_SESSION['cart'][$dishId]['quantity'] = $availableStock;
                        $_SESSION['cart'][$dishId]['price'] = (float)$dish['price'];
                        add_cart_alert('warning', 'Kitchen stock limited to ' . $availableStock . ' portions for "' . htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8') . '". Quantity adjusted.');
                    } else {
                        $_SESSION['cart'][$dishId]['quantity'] = $newQuantity;
                        $_SESSION['cart'][$dishId]['price'] = (float)$dish['price'];
                        add_cart_alert('success', 'Updated "' . htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8') . '" to ' . $newQuantity . ' portion' . ($newQuantity > 1 ? 's' : '') . '.');
                    }
                }
            }
        }

        // ---------------------------------------------------------------------
        // Action: REMOVE ITEM
        // ---------------------------------------------------------------------
        elseif ($action === 'remove') {
            $dishId = filter_input(INPUT_POST, 'dish_id', FILTER_VALIDATE_INT);
            if ($dishId !== false && isset($_SESSION['cart'][$dishId])) {
                $removedName = $_SESSION['cart'][$dishId]['name'];
                unset($_SESSION['cart'][$dishId]);
                add_cart_alert('info', 'Removed "' . htmlspecialchars($removedName, ENT_QUOTES, 'UTF-8') . '" from your cart.');
            } else {
                add_cart_alert('warning', 'Item not found in order cart.');
            }
        }

        // ---------------------------------------------------------------------
        // Action: CLEAR CART
        // ---------------------------------------------------------------------
        elseif ($action === 'clear') {
            $_SESSION['cart'] = [];
            unset($_SESSION['applied_coupon']);
            add_cart_alert('info', 'Your order cart has been cleared.');
        }

        // ---------------------------------------------------------------------
        // Action: APPLY PROMOTIONAL COUPON
        // ---------------------------------------------------------------------
        elseif ($action === 'apply_coupon') {
            $couponCode = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));

            if (empty($couponCode)) {
                add_cart_alert('danger', 'Please enter a promotional coupon code.');
            } else {
                // Calculate current subtotal first
                $currentSubtotal = 0.0;
                foreach ($_SESSION['cart'] as $item) {
                    $currentSubtotal += ((float)$item['price'] * (int)$item['quantity']);
                }

                if ($currentSubtotal <= 0.0) {
                    add_cart_alert('danger', 'Cannot apply a coupon to an empty cart.');
                } else {
                    $couponStmt = $pdo->prepare('
                        SELECT coupon_id, code, discount_type, discount_value, min_order_amount, 
                               max_discount_amount, usage_limit, used_count, valid_from, valid_until, is_active
                        FROM coupons
                        WHERE code = :code
                    ');
                    $couponStmt->execute([':code' => $couponCode]);
                    $coupon = $couponStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$coupon) {
                        add_cart_alert('danger', 'Coupon code "' . htmlspecialchars($couponCode, ENT_QUOTES, 'UTF-8') . '" does not exist.');
                    } elseif ((int)$coupon['is_active'] !== 1) {
                        add_cart_alert('danger', 'This promotional coupon has been deactivated.');
                    } else {
                        $now = date('Y-m-d H:i:s');
                        if ($now < $coupon['valid_from']) {
                            add_cart_alert('danger', 'This coupon is not valid yet.');
                        } elseif ($now > $coupon['valid_until']) {
                            add_cart_alert('danger', 'This coupon has expired.');
                        } elseif ($coupon['usage_limit'] !== null && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) {
                            add_cart_alert('danger', 'This promotional coupon has reached its maximum platform redemption limit.');
                        } elseif ($currentSubtotal < (float)$coupon['min_order_amount']) {
                            add_cart_alert('warning', 'Coupon requires a minimum order subtotal of LKR ' . number_format((float)$coupon['min_order_amount'], 2) . '. Add more items to qualify.');
                        } else {
                            $_SESSION['applied_coupon'] = [
                                'coupon_id'           => (int)$coupon['coupon_id'],
                                'code'                => (string)$coupon['code'],
                                'discount_type'       => (string)$coupon['discount_type'],
                                'discount_value'      => (float)$coupon['discount_value'],
                                'min_order_amount'    => (float)$coupon['min_order_amount'],
                                'max_discount_amount' => $coupon['max_discount_amount'] !== null ? (float)$coupon['max_discount_amount'] : null,
                            ];
                            add_cart_alert('success', 'Coupon "' . htmlspecialchars($coupon['code'], ENT_QUOTES, 'UTF-8') . '" successfully applied!');
                        }
                    }
                }
            }
        }

        // ---------------------------------------------------------------------
        // Action: REMOVE APPLIED COUPON
        // ---------------------------------------------------------------------
        elseif ($action === 'remove_coupon') {
            unset($_SESSION['applied_coupon']);
            add_cart_alert('info', 'Promotional coupon has been removed.');
        }
    }
}

// -----------------------------------------------------------------------------
// Live Database Synchronization: Refresh Prices & Stock on every View
// -----------------------------------------------------------------------------
if (!empty($_SESSION['cart'])) {
    $dishIds = array_keys($_SESSION['cart']);
    $inPlaceholders = implode(',', array_fill(0, count($dishIds), '?'));

    $syncStmt = $pdo->prepare("
        SELECT d.dish_id, d.name, d.price, d.image_path, d.is_active, 
               COALESCE(i.current_stock, 0) AS current_stock
        FROM dishes d
        LEFT JOIN inventory i ON d.dish_id = i.dish_id
        WHERE d.dish_id IN ({$inPlaceholders})
    ");
    $syncStmt->execute($dishIds);
    $activeDishes = [];
    while ($row = $syncStmt->fetch(PDO::FETCH_ASSOC)) {
        $activeDishes[(int)$row['dish_id']] = $row;
    }

    foreach ($_SESSION['cart'] as $id => $cartItem) {
        $id = (int)$id;
        if (!isset($activeDishes[$id]) || (int)$activeDishes[$id]['is_active'] !== 1) {
            unset($_SESSION['cart'][$id]);
            add_cart_alert('warning', 'Item "' . htmlspecialchars($cartItem['name'], ENT_QUOTES, 'UTF-8') . '" is no longer available and was removed from your cart.');
            continue;
        }

        $dbDish = $activeDishes[$id];
        $currentStock = (int)$dbDish['current_stock'];

        // Synchronize price directly from DB
        $_SESSION['cart'][$id]['price'] = (float)$dbDish['price'];
        $_SESSION['cart'][$id]['name'] = (string)$dbDish['name'];
        if (!empty($dbDish['image_path'])) {
            $_SESSION['cart'][$id]['image_path'] = (string)$dbDish['image_path'];
        }

        // Validate stock quantity
        if ($currentStock <= 0) {
            unset($_SESSION['cart'][$id]);
            add_cart_alert('danger', 'Dish "' . htmlspecialchars($dbDish['name'], ENT_QUOTES, 'UTF-8') . '" is now out of stock and was removed from your cart.');
        } elseif ((int)$cartItem['quantity'] > $currentStock) {
            $_SESSION['cart'][$id]['quantity'] = $currentStock;
            add_cart_alert('warning', 'Portions for "' . htmlspecialchars($dbDish['name'], ENT_QUOTES, 'UTF-8') . '" adjusted to available kitchen stock (' . $currentStock . ').');
        }
    }
}

// -----------------------------------------------------------------------------
// Calculate Financial Totals
// -----------------------------------------------------------------------------
$subtotalLkr = 0.0;
$totalItemCount = 0;

foreach ($_SESSION['cart'] as $item) {
    $qty = (int)$item['quantity'];
    $price = (float)$item['price'];
    $subtotalLkr += ($price * $qty);
    $totalItemCount += $qty;
}

// Delivery fee calculation
$deliveryFeeLkr = (!empty($_SESSION['cart'])) ? STANDARD_DELIVERY_FEE_LKR : 0.00;

// Coupon discount calculation
$discountLkr = 0.00;
$appliedCoupon = $_SESSION['applied_coupon'] ?? null;

if ($appliedCoupon !== null && !empty($_SESSION['cart'])) {
    if ($subtotalLkr < (float)$appliedCoupon['min_order_amount']) {
        add_cart_alert('warning', 'Applied coupon "' . htmlspecialchars($appliedCoupon['code'], ENT_QUOTES, 'UTF-8') . '" removed: Subtotal fell below required minimum LKR ' . number_format((float)$appliedCoupon['min_order_amount'], 2) . '.');
        unset($_SESSION['applied_coupon']);
        $appliedCoupon = null;
    } else {
        if ($appliedCoupon['code'] === 'FREEDELIVERY') {
            $discountLkr = $deliveryFeeLkr;
        } elseif ($appliedCoupon['discount_type'] === 'percentage') {
            $calc = ($subtotalLkr * (float)$appliedCoupon['discount_value']) / 100.0;
            if ($appliedCoupon['max_discount_amount'] !== null && $calc > (float)$appliedCoupon['max_discount_amount']) {
                $calc = (float)$appliedCoupon['max_discount_amount'];
            }
            $discountLkr = $calc;
        } elseif ($appliedCoupon['discount_type'] === 'fixed') {
            $discountLkr = min((float)$appliedCoupon['discount_value'], $subtotalLkr);
        }
    }
}

// Final grand total
$grandTotalLkr = max(0.00, ($subtotalLkr - $discountLkr) + $deliveryFeeLkr);

// -----------------------------------------------------------------------------
// Page Presentation & Header
// -----------------------------------------------------------------------------
$pageTitle = 'Order Cart - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top: 2.25rem; padding-bottom: 5.5rem;">

    <!-- Breadcrumb & Top Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/index.php" style="color: var(--text-secondary); text-decoration: none;">Home</a>
                <span>&rsaquo;</span>
                <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/menu/list.php" style="color: var(--text-secondary); text-decoration: none;">Menu Catalog</a>
                <span>&rsaquo;</span>
                <span style="color: var(--primary); font-weight: 600;">Order Cart</span>
            </div>
            <h1 style="font-size: 2.25rem; font-weight: 800; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 0.75rem;">
                <span>Your Order Cart</span>
                <?php if ($totalItemCount > 0): ?>
                    <span style="font-size: 0.95rem; font-weight: 700; background: var(--primary-subtle); color: var(--primary); padding: 0.25rem 0.85rem; border-radius: var(--radius-pill); border: 1px solid rgba(249, 115, 22, 0.3);">
                        <?= $totalItemCount; ?> Portion<?= $totalItemCount > 1 ? 's' : ''; ?>
                    </span>
                <?php endif; ?>
            </h1>
        </div>
        
        <div style="display: flex; gap: 0.75rem;">
            <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/menu/list.php" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 0.4rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                <span>Continue Ordering</span>
            </a>
            <?php if (!empty($_SESSION['cart'])): ?>
                <form action="cart.php" method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to empty your entire cart?');">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); border-color: var(--danger-border); display: inline-flex; align-items: center; gap: 0.4rem;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        <span>Clear Cart</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Notifications Feedback -->
    <?php if (!empty($alerts)): ?>
        <div style="margin-bottom: 2rem; display: flex; flex-direction: column; gap: 0.75rem;">
            <?php foreach ($alerts as $alert): ?>
                <div class="alert alert-<?= htmlspecialchars($alert['type'], ENT_QUOTES, 'UTF-8'); ?>" style="display: flex; align-items: center; gap: 0.75rem;">
                    <?php if ($alert['type'] === 'success'): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <?php elseif ($alert['type'] === 'warning'): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    <?php elseif ($alert['type'] === 'danger'): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                    <?php else: ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--info)" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                    <?php endif; ?>
                    <span style="font-size: 0.95rem; font-weight: 500;"><?= $alert['message']; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Main Cart Content Grid -->
    <?php if (empty($_SESSION['cart'])): ?>
        
        <!-- Empty Cart State -->
        <div class="card" style="text-align: center; padding: 4.5rem 2rem; border: 1px dashed var(--border-medium); background: var(--bg-surface-elevated);">
            <div style="width: 80px; height: 80px; margin: 0 auto 1.5rem; border-radius: 50%; background: var(--primary-subtle); display: flex; align-items: center; justify-content: center; color: var(--primary);">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="9" cy="21" r="1"></circle>
                    <circle cx="20" cy="21" r="1"></circle>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                </svg>
            </div>
            <h2 style="font-size: 1.65rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.75rem;">Your Cart is Empty</h2>
            <p style="color: var(--text-secondary); max-width: 480px; margin: 0 auto 2.25rem; font-size: 1rem; line-height: 1.6;">
                You haven't added any Sri Lankan delicacies to your order yet. Explore our fresh dum biriyanis, sizzling hot kottu, and authentic village curries!
            </p>
            <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/menu/list.php" class="btn btn-primary btn-lg" style="display: inline-flex; align-items: center; gap: 0.6rem; padding: 0.9rem 2.25rem;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path></svg>
                <span>Browse Cloud Kitchen Menu</span>
            </a>
        </div>

    <?php else: ?>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2.25rem; align-items: flex-start;">
            
            <!-- Left Column: Line Items Table -->
            <div class="card" style="padding: 0; overflow: hidden; border: 1px solid var(--border-card);">
                
                <div style="padding: 1.25rem 1.5rem; background: var(--bg-surface-elevated); border-bottom: 1px solid var(--border-medium); display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                        <span>Selected Dishes &amp; Portions</span>
                    </h3>
                    <span style="font-size: 0.85rem; color: var(--text-muted);">
                        Prices verified with live kitchen stock
                    </span>
                </div>

                <div class="table-wrap">
                    <table class="table" style="margin: 0;">
                        <thead>
                            <tr style="background: rgba(14, 20, 34, 0.5);">
                                <th style="padding-left: 1.5rem;">Culinary Dish</th>
                                <th style="text-align: right; width: 140px;">Unit Price</th>
                                <th style="text-align: center; width: 170px;">Portion Quantity</th>
                                <th style="text-align: right; width: 150px;">Subtotal (LKR)</th>
                                <th style="text-align: center; width: 70px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($_SESSION['cart'] as $dishId => $item): ?>
                                <?php 
                                    $itemPrice = (float)$item['price'];
                                    $itemQty = (int)$item['quantity'];
                                    $itemRowTotal = $itemPrice * $itemQty;
                                    $dishImg = !empty($item['image_path']) ? htmlspecialchars($item['image_path'], ENT_QUOTES, 'UTF-8') : '';
                                ?>
                                <tr>
                                    <!-- Dish details with thumbnail -->
                                    <td style="padding-left: 1.5rem;">
                                        <div style="display: flex; align-items: center; gap: 1rem;">
                                            <div style="width: 54px; height: 54px; flex-shrink: 0; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--border-subtle); background: var(--bg-surface-elevated); display: flex; align-items: center; justify-content: center;">
                                                <?php if (!empty($dishImg)): ?>
                                                    <img src="/UrbanEats-Cloud-Kitchen-Platform/<?= $dishImg; ?>" 
                                                         alt="<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                         style="width: 100%; height: 100%; object-fit: cover;"
                                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; color: var(--text-muted);">
                                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                                                    </div>
                                                <?php else: ?>
                                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path></svg>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/menu/detail.php?id=<?= (int)$dishId; ?>" 
                                                   style="color: var(--text-primary); font-weight: 700; text-decoration: none; font-size: 1rem; display: block; margin-bottom: 0.2rem; transition: color var(--transition-fast);"
                                                   onmouseover="this.style.color='var(--primary)';" 
                                                   onmouseout="this.style.color='var(--text-primary)';">
                                                    <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </a>
                                                <div style="font-size: 0.8125rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.4rem;">
                                                    <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: var(--success);"></span>
                                                    <span>Live Kitchen Station Item</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Unit Price -->
                                    <td style="text-align: right; font-weight: 600; color: var(--text-secondary); font-size: 0.95rem;">
                                        LKR <?= number_format($itemPrice, 2); ?>
                                    </td>

                                    <!-- Stepper Quantity Form -->
                                    <td style="text-align: center;">
                                        <form action="cart.php" method="POST" style="margin: 0; display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem;">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="dish_id" value="<?= (int)$dishId; ?>">
                                            
                                            <!-- Decrement button -->
                                            <button type="submit" 
                                                    name="quantity" 
                                                    value="<?= max(0, $itemQty - 1); ?>"
                                                    class="btn btn-secondary btn-sm" 
                                                    style="width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; border-radius: var(--radius-sm);"
                                                    title="Decrease portion">
                                                &minus;
                                            </button>

                                            <input type="number" 
                                                   name="quantity" 
                                                   value="<?= $itemQty; ?>" 
                                                   min="0" 
                                                   max="50" 
                                                   class="form-control" 
                                                   style="width: 58px; text-align: center; padding: 0.3rem 0.25rem; font-weight: 700; font-size: 0.95rem; background: var(--bg-input);"
                                                   onchange="this.form.submit();">

                                            <!-- Increment button -->
                                            <button type="submit" 
                                                    name="quantity" 
                                                    value="<?= $itemQty + 1; ?>"
                                                    class="btn btn-secondary btn-sm" 
                                                    style="width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; border-radius: var(--radius-sm);"
                                                    title="Increase portion">
                                                &#43;
                                            </button>
                                        </form>
                                    </td>

                                    <!-- Subtotal per item -->
                                    <td style="text-align: right; font-weight: 700; color: var(--text-primary); font-size: 1rem;">
                                        LKR <?= number_format($itemRowTotal, 2); ?>
                                    </td>

                                    <!-- Remove Action Button -->
                                    <td style="text-align: center;">
                                        <form action="cart.php" method="POST" style="margin: 0;" onsubmit="return confirm('Remove this dish from your cart?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="dish_id" value="<?= (int)$dishId; ?>">
                                            <button type="submit" 
                                                    class="btn btn-outline btn-sm" 
                                                    style="width: 32px; height: 32px; padding: 0; color: var(--danger); border-color: transparent; display: inline-flex; align-items: center; justify-content: center;"
                                                    title="Remove portion">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Table Bottom Bar -->
                <div style="padding: 1.25rem 1.5rem; background: var(--bg-surface-elevated); border-top: 1px solid var(--border-medium); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div style="font-size: 0.875rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        <span>Prepared fresh by specialized culinary chefs per order</span>
                    </div>

                    <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/menu/list.php" class="btn btn-outline btn-sm">
                        &larr; Add More Dishes
                    </a>
                </div>

            </div>

            <!-- Right Column: Order Financial Summary Sidebar -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                
                <!-- Financial Calculation Card -->
                <div class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-medium); box-shadow: var(--shadow-md);">
                    <h3 style="font-size: 1.3rem; font-weight: 800; color: var(--text-primary); margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between;">
                        <span>Order Summary</span>
                        <span style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">LKR Breakdown</span>
                    </h3>

                    <!-- Subtotal row -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; font-size: 0.95rem; color: var(--text-secondary);">
                        <span>Portions Subtotal:</span>
                        <strong style="color: var(--text-primary); font-size: 1.05rem;">LKR <?= number_format($subtotalLkr, 2); ?></strong>
                    </div>

                    <!-- Insulated Delivery row -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; font-size: 0.95rem; color: var(--text-secondary);">
                        <div style="display: flex; align-items: center; gap: 0.35rem;">
                            <span>Insulated Delivery:</span>
                            <span title="Flat fee for temperature-controlled hot delivery packaging" style="cursor: help; color: var(--text-muted);">&#9432;</span>
                        </div>
                        <strong style="color: var(--text-primary); font-size: 1.05rem;">
                            <?php if ($deliveryFeeLkr > 0.00): ?>
                                LKR <?= number_format($deliveryFeeLkr, 2); ?>
                            <?php else: ?>
                                <span style="color: var(--success); font-weight: 700;">FREE</span>
                            <?php endif; ?>
                        </strong>
                    </div>

                    <!-- Promotional Coupon Discount Row (if applied) -->
                    <?php if ($discountLkr > 0.00 && $appliedCoupon !== null): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; padding: 0.5rem 0.75rem; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: var(--radius-sm);">
                            <div>
                                <span style="color: var(--success); font-weight: 700; font-size: 0.9rem;">
                                    Coupon: <?= htmlspecialchars($appliedCoupon['code'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php if ($appliedCoupon['discount_type'] === 'percentage'): ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                                        <?= (int)$appliedCoupon['discount_value']; ?>% discount applied
                                    </div>
                                <?php endif; ?>
                            </div>
                            <strong style="color: var(--success); font-size: 1.05rem;">
                                - LKR <?= number_format($discountLkr, 2); ?>
                            </strong>
                        </div>
                    <?php endif; ?>

                    <!-- Total divider -->
                    <div style="margin: 1.25rem 0 1.5rem; border-top: 1px solid var(--border-medium); padding-top: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                            <div>
                                <span style="font-size: 1.1rem; font-weight: 800; color: var(--text-primary); display: block;">
                                    Total Amount:
                                </span>
                                <span style="font-size: 0.8rem; color: var(--text-muted);">
                                    Inclusive of all kitchen &amp; delivery taxes
                                </span>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 1.65rem; font-weight: 800; color: var(--primary); line-height: 1;">
                                    LKR <?= number_format($grandTotalLkr, 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Checkout CTA Button -->
                    <?php if (is_logged_in()): ?>
                        <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/orders/checkout.php" 
                           class="btn btn-primary btn-lg" 
                           style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.5rem; text-align: center; text-decoration: none; font-size: 1.05rem; box-shadow: var(--shadow-primary);">
                            <span>Proceed to Checkout</span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </a>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/login.php?redirect=orders/cart.php" 
                           class="btn btn-primary btn-lg" 
                           style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.5rem; text-align: center; text-decoration: none; font-size: 1.05rem;">
                            <span>Sign In to Checkout</span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </a>
                    <?php endif; ?>

                    <div style="font-size: 0.8rem; color: var(--text-muted); text-align: center; margin-top: 1rem; display: flex; align-items: center; justify-content: center; gap: 0.4rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                        <span>Estimated delivery time: 35 &ndash; 45 minutes</span>
                    </div>

                </div>

                <!-- Promotional Coupon Form Card -->
                <div class="card" style="padding: 1.5rem; background: var(--bg-surface); border: 1px solid var(--border-card);">
                    <h4 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                        <span>Have a Promo Voucher?</span>
                    </h4>

                    <?php if ($appliedCoupon !== null): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: var(--bg-surface-elevated); border-radius: var(--radius-sm); border: 1px dashed var(--success);">
                            <div>
                                <span style="font-family: var(--font-mono); font-weight: 800; color: var(--success); font-size: 0.95rem;">
                                    <?= htmlspecialchars($appliedCoupon['code'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span style="font-size: 0.8rem; color: var(--text-secondary); margin-left: 0.5rem;">
                                    (&minus;LKR <?= number_format($discountLkr, 2); ?>)
                                </span>
                            </div>
                            <form action="cart.php" method="POST" style="margin: 0;">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="remove_coupon">
                                <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); font-size: 0.8rem; padding: 0.2rem 0.5rem;">
                                    Remove
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form action="cart.php" method="POST" style="margin: 0; display: flex; gap: 0.5rem;">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="apply_coupon">
                            <input type="text" 
                                   name="coupon_code" 
                                   placeholder="e.g. WELCOME10, LKR500OFF" 
                                   class="form-control" 
                                   required 
                                   style="text-transform: uppercase; font-family: var(--font-mono); font-size: 0.9rem;"
                                   maxlength="20">
                            <button type="submit" class="btn btn-secondary btn-sm" style="white-space: nowrap;">
                                Apply
                            </button>
                        </form>
                        <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.6rem;">
                            Try test coupons: <code style="color: var(--primary);">WELCOME10</code> or <code style="color: var(--primary);">LKR500OFF</code>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Cloud Kitchen Standards Card -->
                <div class="card" style="padding: 1.25rem; background: var(--bg-surface); border: 1px solid var(--border-subtle); font-size: 0.85rem; color: var(--text-muted);">
                    <div style="display: flex; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.75rem;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" style="flex-shrink: 0; margin-top: 2px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        <div>
                            <strong style="color: var(--text-secondary); display: block;">100% Sealed &amp; Insulated</strong>
                            Delivered in heat-retentive containers ensuring piping hot food.
                        </div>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="flex-shrink: 0; margin-top: 2px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                        <div>
                            <strong style="color: var(--text-secondary); display: block;">Real-Time Kitchen Dispatch</strong>
                            Live order status tracked from preparation griddle to delivery door.
                        </div>
                    </div>
                </div>

            </div>

        </div>

    <?php endif; ?>

</div>

<!-- Auto-dismiss alerts on interaction or timeout -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(function() { alert.remove(); }, 500);
            }, 6000);
        });
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
