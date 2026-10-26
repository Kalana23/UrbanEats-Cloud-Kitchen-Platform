<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Front-End Order Checkout & Payment Processing
 * 
 * Features:
 * - Access-controlled via require_login()
 * - Cart emptiness guard with automatic redirection
 * - Fulfillment option toggle: Standard Insulated Delivery vs Kitchen Counter Pickup
 * - Dynamic / conditional delivery address inputs based on fulfillment selection
 * - Comprehensive contact phone number validation (Sri Lankan & international formats)
 * - Payment method selection: Cash on Delivery, Credit/Debit Card, Bank Transfer
 * - Optional promotional coupon input box with live validation & discount calculation
 * - Atomic order placement inside PDO transaction utilizing reduce_stock()
 * - Responsive UI styled to match dark-slate culinary amber design system
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/stock.php';
require_once __DIR__ . '/../../includes/coupons.php';

// Ensure session is active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Enforce Authentication Requirement
// Redirect unauthenticated users to login with return redirect parameter
require_login('../login.php?redirect=orders/checkout.php');

// 2. Cart Guard: Redirect if cart is empty
if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    header('Location: cart.php');
    exit();
}

// User context from active session
$userId        = (int)($_SESSION['user_id'] ?? 0);
$userFirstName = (string)($_SESSION['first_name'] ?? '');
$userLastName  = (string)($_SESSION['last_name'] ?? '');
$userEmail     = (string)($_SESSION['email'] ?? '');
$userFullName  = trim("{$userFirstName} {$userLastName}");

// Constant delivery fee (LKR)
const DELIVERY_FEE_LKR = 350.00;

// Feedback & Validation Error Containers
$errors = [];
$feedbackAlert = null;

// Default Form Values
$fulfillmentType = 'delivery'; // 'delivery' or 'pickup'
$deliveryAddress = '';
$deliveryCity    = 'Colombo';
$contactPhone    = '';
$deliveryNotes   = '';
$paymentMethod   = 'cash_on_delivery'; // 'cash_on_delivery', 'card', 'bank_transfer'
$couponCodeInput = '';

// -----------------------------------------------------------------------------
// Live Database Synchronization: Re-verify Cart Items & Prices
// -----------------------------------------------------------------------------
$dishIds = array_keys($_SESSION['cart']);
$placeholders = implode(',', array_fill(0, count($dishIds), '?'));

$syncStmt = $pdo->prepare("
    SELECT d.dish_id, d.name, d.price, d.image_path, d.is_active, 
           COALESCE(i.current_stock, 0) AS current_stock
    FROM dishes d
    LEFT JOIN inventory i ON d.dish_id = i.dish_id
    WHERE d.dish_id IN ({$placeholders})
");
$syncStmt->execute($dishIds);
$liveDishes = [];
while ($row = $syncStmt->fetch(PDO::FETCH_ASSOC)) {
    $liveDishes[(int)$row['dish_id']] = $row;
}

// Check stock and prices
foreach ($_SESSION['cart'] as $dId => $item) {
    $dId = (int)$dId;
    if (!isset($liveDishes[$dId]) || (int)$liveDishes[$dId]['is_active'] !== 1) {
        unset($_SESSION['cart'][$dId]);
        continue;
    }
    $dbDish = $liveDishes[$dId];
    $_SESSION['cart'][$dId]['price'] = (float)$dbDish['price'];
    $_SESSION['cart'][$dId]['name']  = (string)$dbDish['name'];
    $stock = (int)$dbDish['current_stock'];
    if ($stock <= 0) {
        unset($_SESSION['cart'][$dId]);
    } elseif ((int)$item['quantity'] > $stock) {
        $_SESSION['cart'][$dId]['quantity'] = $stock;
    }
}

// Re-check cart emptiness after sync
if (empty($_SESSION['cart'])) {
    header('Location: cart.php');
    exit();
}

// -----------------------------------------------------------------------------
// Helper: Validate Telephone Number
// -----------------------------------------------------------------------------
function is_valid_phone(string $phone): bool {
    $cleaned = preg_replace('/[\s\-\(\)\.]+/', '', $phone);
    if ($cleaned === null || strlen($cleaned) < 9 || strlen($cleaned) > 15) {
        return false;
    }
    // Matches standard Sri Lankan (+947X, 07X, 011X) and general international numbers
    return (bool)preg_match('/^(\+?[0-9]{9,15})$/', $cleaned);
}

// -----------------------------------------------------------------------------
// Calculate Financial Totals
// -----------------------------------------------------------------------------
function calculate_totals(array $cart, ?array $coupon, string $fulfillment): array {
    $subtotal = 0.0;
    foreach ($cart as $item) {
        $subtotal += ((float)$item['price'] * (int)$item['quantity']);
    }

    $deliveryFee = ($fulfillment === 'delivery') ? DELIVERY_FEE_LKR : 0.00;
    $discount = 0.00;

    if ($coupon !== null && $subtotal >= (float)$coupon['min_order_amount']) {
        if ($coupon['code'] === 'FREEDELIVERY') {
            $discount = $deliveryFee;
        } elseif ($coupon['discount_type'] === 'percentage') {
            $calc = ($subtotal * (float)$coupon['discount_value']) / 100.0;
            if ($coupon['max_discount_amount'] !== null && $calc > (float)$coupon['max_discount_amount']) {
                $calc = (float)$coupon['max_discount_amount'];
            }
            $discount = $calc;
        } elseif ($coupon['discount_type'] === 'fixed') {
            $discount = min((float)$coupon['discount_value'], $subtotal);
        }
    }

    $grandTotal = max(0.00, ($subtotal - $discount) + $deliveryFee);

    return [
        'subtotal'     => $subtotal,
        'delivery_fee' => $deliveryFee,
        'discount'     => $discount,
        'grand_total'  => $grandTotal,
    ];
}

// -----------------------------------------------------------------------------
// Handle POST Actions (Coupon Management & Final Order Submission)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    if (!validate_csrf_token()) {
        $errors['csrf'] = 'Security validation failed: Invalid or expired CSRF token. Please refresh.';
    } else {
        $action = trim((string)($_POST['action'] ?? 'place_order'));

        // Handle Quick Coupon Application within Checkout
        if ($action === 'apply_coupon') {
            $rawCode = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
            if (empty($rawCode)) {
                $errors['coupon'] = 'Please enter a coupon code to apply.';
            } else {
                $calc = calculate_totals($_SESSION['cart'], null, $fulfillmentType);
                $validation = validate_coupon($pdo, $rawCode, (float)$calc['subtotal']);

                if (!$validation['valid']) {
                    $errors['coupon'] = $validation['error'];
                } else {
                    $_SESSION['applied_coupon'] = $validation['coupon'];
                    $feedbackAlert = [
                        'type'    => 'success', 
                        'message' => 'Coupon "' . htmlspecialchars($validation['coupon']['code'], ENT_QUOTES, 'UTF-8') . '" applied successfully! Discount: LKR ' . number_format($validation['discount_amount'], 2)
                    ];
                }
            }
        }

        // Handle Coupon Removal
        elseif ($action === 'remove_coupon') {
            unset($_SESSION['applied_coupon']);
            $feedbackAlert = ['type' => 'info', 'message' => 'Coupon code removed.'];
        }

        // Handle Final Order Placement
        elseif ($action === 'place_order') {
            $fulfillmentType = trim((string)($_POST['fulfillment_type'] ?? 'delivery'));
            $contactPhone    = trim((string)($_POST['contact_phone'] ?? ''));
            $paymentMethod   = trim((string)($_POST['payment_method'] ?? 'cash_on_delivery'));
            $deliveryNotes   = trim((string)($_POST['delivery_notes'] ?? ''));

            // Validate Fulfillment
            if (!in_array($fulfillmentType, ['delivery', 'pickup'], true)) {
                $errors['fulfillment_type'] = 'Please choose a valid fulfillment option (Delivery or Pickup).';
            }

            // Validate Delivery Address conditionally
            if ($fulfillmentType === 'delivery') {
                $deliveryAddress = trim((string)($_POST['delivery_address'] ?? ''));
                $deliveryCity    = trim((string)($_POST['delivery_city'] ?? ''));

                if (empty($deliveryAddress) || strlen($deliveryAddress) < 5) {
                    $errors['delivery_address'] = 'Please enter a complete street address for food delivery.';
                }
                if (empty($deliveryCity)) {
                    $errors['delivery_city'] = 'Please specify your delivery city or district.';
                }
            } else {
                $deliveryAddress = 'UrbanEats Cloud Kitchen Hub, Counter Pickup (Station Colombo 03)';
                $deliveryCity    = 'Colombo 03';
            }

            // Validate Contact Phone
            if (empty($contactPhone)) {
                $errors['contact_phone'] = 'A contact phone number is required for kitchen and courier updates.';
            } elseif (!is_valid_phone($contactPhone)) {
                $errors['contact_phone'] = 'Please enter a valid phone number (e.g. +94 77 123 4567 or 0771234567).';
            }

            // Validate Payment Method
            if (!in_array($paymentMethod, ['cash_on_delivery', 'card', 'bank_transfer'], true)) {
                $errors['payment_method'] = 'Please select a valid payment method.';
            }

            // If form validation passed, proceed with atomic database transaction
            if (empty($errors)) {
                $appliedCoupon = $_SESSION['applied_coupon'] ?? null;
                $fin = calculate_totals($_SESSION['cart'], $appliedCoupon, $fulfillmentType);

                // Re-verify coupon minimum if coupon active
                $couponIdToSave = null;
                if ($appliedCoupon !== null) {
                    if ($fin['subtotal'] >= (float)$appliedCoupon['min_order_amount']) {
                        $couponIdToSave = (int)$appliedCoupon['coupon_id'];
                    } else {
                        // Invalidate coupon if subtotal is below minimum
                        $fin['discount'] = 0.00;
                        $fin['grand_total'] = max(0.00, $fin['subtotal'] + $fin['delivery_fee']);
                    }
                }

                // Estimated readiness / delivery timestamp
                $estMinutes = ($fulfillmentType === 'delivery') ? 45 : 25;
                $estDeliveryTime = date('Y-m-d H:i:s', strtotime("+{$estMinutes} minutes"));

                // Generate unique order tracking number: UE-YYYYMMDD-XXXX
                $orderNumber = 'UE-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);

                // ATOMIC TRANSACTION EXECUTION
                $pdo->beginTransaction();

                try {
                    // 1. Stock reduction for all cart items (guaranteed safe decrement via reduce_stock)
                    foreach ($_SESSION['cart'] as $dId => $cItem) {
                        $dId = (int)$dId;
                        $qty = (int)$cItem['quantity'];

                        $reduced = reduce_stock(
                            $pdo,
                            $dId,
                            $qty,
                            $userId,
                            "Online Order Checkout: {$orderNumber}"
                        );

                        if (!$reduced) {
                            throw new RuntimeException("Sorry! Stock for '{$cItem['name']}' became unavailable during checkout. Please adjust your portions.");
                        }
                    }

                    // 2. Insert Master Order record
                    $orderStmt = $pdo->prepare('
                        INSERT INTO orders (
                            order_number, user_id, coupon_id, order_status,
                            subtotal_lkr, discount_lkr, delivery_fee_lkr, total_amount_lkr,
                            delivery_address, delivery_city, contact_phone, delivery_notes,
                            estimated_delivery_time, created_at
                        ) VALUES (
                            :order_number, :user_id, :coupon_id, :order_status,
                            :subtotal_lkr, :discount_lkr, :delivery_fee_lkr, :total_amount_lkr,
                            :delivery_address, :delivery_city, :contact_phone, :delivery_notes,
                            :estimated_delivery_time, NOW()
                        )
                    ');
                    $orderStmt->execute([
                        ':order_number'            => $orderNumber,
                        ':user_id'                 => $userId,
                        ':coupon_id'               => $couponIdToSave,
                        ':order_status'            => 'pending',
                        ':subtotal_lkr'            => $fin['subtotal'],
                        ':discount_lkr'            => $fin['discount'],
                        ':delivery_fee_lkr'        => $fin['delivery_fee'],
                        ':total_amount_lkr'        => $fin['grand_total'],
                        ':delivery_address'        => $deliveryAddress,
                        ':delivery_city'           => $deliveryCity,
                        ':contact_phone'           => $contactPhone,
                        ':delivery_notes'          => !empty($deliveryNotes) ? $deliveryNotes : null,
                        ':estimated_delivery_time' => $estDeliveryTime,
                    ]);

                    $orderId = (int)$pdo->lastInsertId();

                    // 3. Insert Normalized Order Line Items (preserving snapshot unit_price_lkr)
                    $itemStmt = $pdo->prepare('
                        INSERT INTO order_items (
                            order_id, dish_id, quantity, unit_price_lkr, subtotal_lkr, special_instructions, created_at
                        ) VALUES (
                            :order_id, :dish_id, :quantity, :unit_price_lkr, :subtotal_lkr, :special_instructions, NOW()
                        )
                    ');

                    foreach ($_SESSION['cart'] as $dId => $cItem) {
                        $qty = (int)$cItem['quantity'];
                        $unitPrice = (float)$cItem['price'];
                        $lineSubtotal = $unitPrice * $qty;

                        $itemStmt->execute([
                            ':order_id'             => $orderId,
                            ':dish_id'              => (int)$dId,
                            ':quantity'             => $qty,
                            ':unit_price_lkr'       => $unitPrice,
                            ':subtotal_lkr'         => $lineSubtotal,
                            ':special_instructions' => null,
                        ]);
                    }

                    // 4. Insert Payment record
                    $paymentStatus = ($paymentMethod === 'card') ? 'completed' : 'pending';
                    $paidAt = ($paymentStatus === 'completed') ? date('Y-m-d H:i:s') : null;
                    $refPrefix = match ($paymentMethod) {
                        'card'          => 'TXN-CARD-',
                        'bank_transfer' => 'BT-REF-',
                        default         => 'COD-',
                    };
                    $txnReference = $refPrefix . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                    $paymentDetails = match ($paymentMethod) {
                        'card'          => 'Online Card Payment verified. Auth: ' . random_int(100000, 999999),
                        'bank_transfer' => 'Bank of Ceylon A/C: 0012398471. Awaiting receipt confirmation.',
                        default         => 'Cash on Delivery. Courier to collect exact cash upon handover.',
                    };

                    $paymentStmt = $pdo->prepare('
                        INSERT INTO payments (
                            order_id, payment_method, payment_status, amount_lkr,
                            transaction_reference, payment_details, paid_at, created_at
                        ) VALUES (
                            :order_id, :payment_method, :payment_status, :amount_lkr,
                            :transaction_reference, :payment_details, :paid_at, NOW()
                        )
                    ');
                    $paymentStmt->execute([
                        ':order_id'              => $orderId,
                        ':payment_method'        => $paymentMethod,
                        ':payment_status'        => $paymentStatus,
                        ':amount_lkr'            => $fin['grand_total'],
                        ':transaction_reference' => $txnReference,
                        ':payment_details'       => $paymentDetails,
                        ':paid_at'               => $paidAt,
                    ]);

                    // 5. Record initial state in order_status_history
                    $historyStmt = $pdo->prepare('
                        INSERT INTO order_status_history (
                            order_id, changed_by_user_id, previous_status, new_status, comment, created_at
                        ) VALUES (
                            :order_id, :changed_by_user_id, NULL, :new_status, :comment, NOW()
                        )
                    ');
                    $historyStmt->execute([
                        ':order_id'            => $orderId,
                        ':changed_by_user_id'  => $userId,
                        ':new_status'          => 'pending',
                        ':comment'             => sprintf(
                            'Order placed via online checkout (%s, %s).',
                            ucfirst($fulfillmentType),
                            str_replace('_', ' ', ucfirst($paymentMethod))
                        ),
                    ]);

                    // 6. Increment coupon used_count if applied
                    if ($couponIdToSave !== null) {
                        increment_coupon_usage($pdo, $couponIdToSave);
                    }

                    // 7. Record administrative and security audit log
                    log_action(
                        $pdo,
                        $userId,
                        'ORDER_PLACED',
                        sprintf('Customer placed order %s (ID: %d) for LKR %.2f.', $orderNumber, $orderId, $fin['grand_total'])
                    );

                    // Commit all operations atomically
                    $pdo->commit();

                    // Clear cart & applied coupon from session
                    $_SESSION['cart'] = [];
                    unset($_SESSION['applied_coupon']);

                    // Store completed order info in session for receipt display
                    $_SESSION['recent_order'] = [
                        'order_id'         => $orderId,
                        'order_number'     => $orderNumber,
                        'total_amount_lkr' => $fin['grand_total'],
                        'fulfillment_type' => $fulfillmentType,
                        'payment_method'   => $paymentMethod,
                        'est_time'         => $estDeliveryTime,
                    ];

                    // Redirect to order confirmation receipt
                    header("Location: confirmation.php?order_id={$orderId}");
                    exit();

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[Checkout Error] Order placement failed: ' . $e->getMessage());
                    $errors['system'] = $e->getMessage();
                }
            }
        }
    }
}

// Recalculate totals for display based on active coupon and selected fulfillment
$appliedCoupon = $_SESSION['applied_coupon'] ?? null;
$totals = calculate_totals($_SESSION['cart'], $appliedCoupon, $fulfillmentType);

$pageTitle = 'Secure Checkout - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top: 2.25rem; padding-bottom: 5.5rem;">

    <!-- Breadcrumb & Top Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/index.php" style="color: var(--text-secondary); text-decoration: none;">Home</a>
                <span>&rsaquo;</span>
                <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/orders/cart.php" style="color: var(--text-secondary); text-decoration: none;">Order Cart</a>
                <span>&rsaquo;</span>
                <span style="color: var(--primary); font-weight: 600;">Checkout</span>
            </div>
            <h1 style="font-size: 2.25rem; font-weight: 800; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 0.75rem;">
                <span>Delivery &amp; Payment Checkout</span>
            </h1>
        </div>

        <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/orders/cart.php" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 0.4rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
            <span>&larr; Back to Cart</span>
        </a>
    </div>

    <!-- Alert Notifications Feedback -->
    <?php if (!empty($feedbackAlert)): ?>
        <div class="alert alert-<?= htmlspecialchars($feedbackAlert['type'], ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom: 1.75rem; display: flex; align-items: center; gap: 0.75rem;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            <span><?= $feedbackAlert['message']; ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors['system'])): ?>
        <div class="alert alert-danger" style="margin-bottom: 1.75rem;">
            <strong>Order Failed:</strong> <?= htmlspecialchars($errors['system'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors['csrf'])): ?>
        <div class="alert alert-danger" style="margin-bottom: 1.75rem;">
            <?= htmlspecialchars($errors['csrf'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- Main Checkout Form Grid -->
    <div style="display: grid; grid-template-columns: 1.8fr 1.2fr; gap: 2.5rem; align-items: flex-start;">

        <!-- LEFT COLUMN: Checkout Form Inputs -->
        <div>
            <form action="checkout.php" method="POST" id="checkoutForm">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="place_order">

                <!-- Step 1: Customer Details -->
                <div class="card" style="padding: 1.75rem; margin-bottom: 1.75rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-card);">
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.95rem;">
                            1
                        </div>
                        <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin: 0;">Customer Information</h3>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($userFullName, ENT_QUOTES, 'UTF-8'); ?>" readonly style="background: rgba(14, 20, 34, 0.6); color: var(--text-muted); cursor: not-allowed;">
                        </div>
                        <div>
                            <label class="form-label">Account Email</label>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?>" readonly style="background: rgba(14, 20, 34, 0.6); color: var(--text-muted); cursor: not-allowed;">
                        </div>
                    </div>

                    <!-- Contact Phone Input (Crucial with validation) -->
                    <div>
                        <label for="contact_phone" class="form-label">
                            Contact Phone Number <span style="color: var(--danger);">*</span>
                        </label>
                        <input 
                            type="tel" 
                            name="contact_phone" 
                            id="contact_phone" 
                            class="form-control <?= !empty($errors['contact_phone']) ? 'is-invalid' : ''; ?>"
                            value="<?= htmlspecialchars($contactPhone, ENT_QUOTES, 'UTF-8'); ?>" 
                            placeholder="e.g. +94 77 123 4567 or 0771234567"
                            required
                        >
                        <?php if (!empty($errors['contact_phone'])): ?>
                            <div style="color: var(--danger); font-size: 0.8125rem; margin-top: 0.35rem;">
                                <?= htmlspecialchars($errors['contact_phone'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.35rem;">
                                Used exclusively for kitchen dispatch SMS &amp; courier handover calls.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 2: Fulfillment Choice (Delivery vs Pickup) -->
                <div class="card" style="padding: 1.75rem; margin-bottom: 1.75rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-card);">
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.95rem;">
                            2
                        </div>
                        <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin: 0;">Fulfillment Option</h3>
                    </div>

                    <!-- Fulfillment Toggle Cards -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                        
                        <!-- Option A: Insulated Delivery -->
                        <label style="display: block; cursor: pointer;">
                            <input type="radio" 
                                   name="fulfillment_type" 
                                   value="delivery" 
                                   id="radioDelivery"
                                   <?= ($fulfillmentType === 'delivery') ? 'checked' : ''; ?> 
                                   style="display: none;" 
                                   onchange="toggleFulfillment('delivery')">
                            <div id="cardDelivery" style="padding: 1.25rem; border-radius: var(--radius-md); border: 2px solid <?= ($fulfillmentType === 'delivery') ? 'var(--primary)' : 'var(--border-medium)'; ?>; background: <?= ($fulfillmentType === 'delivery') ? 'var(--primary-subtle)' : 'var(--bg-input)'; ?>; transition: all var(--transition-fast);">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="<?= ($fulfillmentType === 'delivery') ? 'var(--primary)' : 'var(--text-secondary)'; ?>" stroke-width="2.5"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                                        <strong style="color: var(--text-primary); font-size: 1.05rem;">Hot Delivery</strong>
                                    </div>
                                    <span style="font-size: 0.85rem; font-weight: 700; color: var(--primary);">LKR 350.00</span>
                                </div>
                                <p style="font-size: 0.8125rem; color: var(--text-secondary); margin: 0; line-height: 1.5;">
                                    Dispatched in thermal insulated packaging directly to your doorstep.
                                </p>
                            </div>
                        </label>

                        <!-- Option B: Kitchen Pickup -->
                        <label style="display: block; cursor: pointer;">
                            <input type="radio" 
                                   name="fulfillment_type" 
                                   value="pickup" 
                                   id="radioPickup"
                                   <?= ($fulfillmentType === 'pickup') ? 'checked' : ''; ?> 
                                   style="display: none;" 
                                   onchange="toggleFulfillment('pickup')">
                            <div id="cardPickup" style="padding: 1.25rem; border-radius: var(--radius-md); border: 2px solid <?= ($fulfillmentType === 'pickup') ? 'var(--primary)' : 'var(--border-medium)'; ?>; background: <?= ($fulfillmentType === 'pickup') ? 'var(--primary-subtle)' : 'var(--bg-input)'; ?>; transition: all var(--transition-fast);">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="<?= ($fulfillmentType === 'pickup') ? 'var(--primary)' : 'var(--text-secondary)'; ?>" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                                        <strong style="color: var(--text-primary); font-size: 1.05rem;">Kitchen Pickup</strong>
                                    </div>
                                    <span style="font-size: 0.85rem; font-weight: 700; color: var(--success);">FREE</span>
                                </div>
                                <p style="font-size: 0.8125rem; color: var(--text-secondary); margin: 0; line-height: 1.5;">
                                    Collect directly from our central cloud kitchen counter in Colombo 03.
                                </p>
                            </div>
                        </label>

                    </div>

                    <!-- Conditional Delivery Address Fields Container -->
                    <div id="deliveryFieldsSection" style="display: <?= ($fulfillmentType === 'delivery') ? 'block' : 'none'; ?>;">
                        
                        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label for="delivery_address" class="form-label">
                                    Delivery Street Address <span style="color: var(--danger);">*</span>
                                </label>
                                <input 
                                    type="text" 
                                    name="delivery_address" 
                                    id="delivery_address" 
                                    class="form-control <?= !empty($errors['delivery_address']) ? 'is-invalid' : ''; ?>"
                                    value="<?= htmlspecialchars($deliveryAddress, ENT_QUOTES, 'UTF-8'); ?>" 
                                    placeholder="e.g. 45/2 Galle Road, Bambalapitiya"
                                >
                                <?php if (!empty($errors['delivery_address'])): ?>
                                    <div style="color: var(--danger); font-size: 0.8125rem; margin-top: 0.35rem;">
                                        <?= htmlspecialchars($errors['delivery_address'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label for="delivery_city" class="form-label">
                                    City / District <span style="color: var(--danger);">*</span>
                                </label>
                                <select name="delivery_city" id="delivery_city" class="form-control">
                                    <?php 
                                        $cities = [
                                            'Colombo 01 - Fort', 'Colombo 02 - Slave Island', 'Colombo 03 - Kollupitiya',
                                            'Colombo 04 - Bambalapitiya', 'Colombo 05 - Havelock / Thimbirigasyaya',
                                            'Colombo 06 - Wellawatte', 'Colombo 07 - Cinnamon Gardens', 'Colombo 08 - Borella',
                                            'Dehiwala', 'Mount Lavinia', 'Nugegoda', 'Kohuwala', 'Maharagama', 'Kotte / Rajagiriya',
                                            'Battaramulla', 'Kelaniya / Kiribathgoda'
                                        ];
                                        foreach ($cities as $c):
                                    ?>
                                        <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?= ($deliveryCity === $c) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="delivery_notes" class="form-label">Special Delivery / Kitchen Instructions (Optional)</label>
                            <input 
                                type="text" 
                                name="delivery_notes" 
                                id="delivery_notes" 
                                class="form-control"
                                value="<?= htmlspecialchars($deliveryNotes, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="e.g. Ring bell, leave package with security guard, less spicy"
                                maxlength="255"
                            >
                        </div>
                    </div>

                    <!-- Pickup Hub Details Container (Shown when Pickup is selected) -->
                    <div id="pickupInfoSection" style="display: <?= ($fulfillmentType === 'pickup') ? 'block' : 'none'; ?>; padding: 1.25rem; background: var(--bg-input); border-radius: var(--radius-sm); border: 1px dashed var(--accent);">
                        <div style="display: flex; gap: 0.85rem; align-items: flex-start;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="flex-shrink: 0; margin-top: 2px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                            <div>
                                <strong style="color: var(--text-primary); font-size: 0.95rem; display: block; margin-bottom: 0.25rem;">
                                    UrbanEats Central Kitchen Dispatch Counter
                                </strong>
                                <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0; line-height: 1.5;">
                                    Location: <strong>45/2 Galle Road, Colombo 03</strong><br>
                                    Operating Hours: <strong>10:00 AM &ndash; 11:00 PM (Daily)</strong><br>
                                    Your order will be prepared and packed fresh in approximately <strong>25 minutes</strong>.
                                </p>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Step 3: Payment Method Selection -->
                <div class="card" style="padding: 1.75rem; margin-bottom: 2rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-card);">
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.95rem;">
                            3
                        </div>
                        <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin: 0;">Payment Method</h3>
                    </div>

                    <?php if (!empty($errors['payment_method'])): ?>
                        <div style="color: var(--danger); font-size: 0.85rem; margin-bottom: 1rem;">
                            <?= htmlspecialchars($errors['payment_method'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                        
                        <!-- Option 1: Cash on Delivery -->
                        <label style="display: block; cursor: pointer;">
                            <input type="radio" 
                                   name="payment_method" 
                                   value="cash_on_delivery" 
                                   id="radioCOD"
                                   <?= ($paymentMethod === 'cash_on_delivery') ? 'checked' : ''; ?> 
                                   style="display: none;" 
                                   onchange="togglePayment('cash_on_delivery')">
                            <div id="cardCOD" style="padding: 1.15rem 1.25rem; border-radius: var(--radius-sm); border: 2px solid <?= ($paymentMethod === 'cash_on_delivery') ? 'var(--primary)' : 'var(--border-subtle)'; ?>; background: <?= ($paymentMethod === 'cash_on_delivery') ? 'var(--primary-subtle)' : 'var(--bg-input)'; ?>; display: flex; align-items: center; justify-content: space-between;">
                                <div style="display: flex; align-items: center; gap: 0.85rem;">
                                    <div style="width: 40px; height: 40px; border-radius: var(--radius-sm); background: rgba(249, 115, 22, 0.15); display: flex; align-items: center; justify-content: center; color: var(--primary);">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
                                    </div>
                                    <div>
                                        <strong style="color: var(--text-primary); font-size: 0.95rem; display: block;">Cash on Delivery / Pickup</strong>
                                        <span style="font-size: 0.8rem; color: var(--text-muted);">Settle in cash directly with the courier or counter upon handover</span>
                                    </div>
                                </div>
                                <span class="badge" style="background: rgba(16, 185, 129, 0.12); color: var(--success); font-size: 0.75rem;">No Surcharge</span>
                            </div>
                        </label>

                        <!-- Option 2: Card -->
                        <label style="display: block; cursor: pointer;">
                            <input type="radio" 
                                   name="payment_method" 
                                   value="card" 
                                   id="radioCard"
                                   <?= ($paymentMethod === 'card') ? 'checked' : ''; ?> 
                                   style="display: none;" 
                                   onchange="togglePayment('card')">
                            <div id="cardCard" style="padding: 1.15rem 1.25rem; border-radius: var(--radius-sm); border: 2px solid <?= ($paymentMethod === 'card') ? 'var(--primary)' : 'var(--border-subtle)'; ?>; background: <?= ($paymentMethod === 'card') ? 'var(--primary-subtle)' : 'var(--bg-input)'; ?>; display: flex; align-items: center; justify-content: space-between;">
                                <div style="display: flex; align-items: center; gap: 0.85rem;">
                                    <div style="width: 40px; height: 40px; border-radius: var(--radius-sm); background: rgba(6, 182, 212, 0.15); display: flex; align-items: center; justify-content: center; color: var(--accent);">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                                    </div>
                                    <div>
                                        <strong style="color: var(--text-primary); font-size: 0.95rem; display: block;">Credit / Debit Card</strong>
                                        <span style="font-size: 0.8rem; color: var(--text-muted);">Instant Visa, MasterCard &amp; Amex card settlement</span>
                                    </div>
                                </div>
                                <span class="badge" style="background: rgba(6, 182, 212, 0.12); color: var(--accent); font-size: 0.75rem;">Instant Dispatch</span>
                            </div>
                        </label>

                        <!-- Option 3: Bank Transfer -->
                        <label style="display: block; cursor: pointer;">
                            <input type="radio" 
                                   name="payment_method" 
                                   value="bank_transfer" 
                                   id="radioBank"
                                   <?= ($paymentMethod === 'bank_transfer') ? 'checked' : ''; ?> 
                                   style="display: none;" 
                                   onchange="togglePayment('bank_transfer')">
                            <div id="cardBank" style="padding: 1.15rem 1.25rem; border-radius: var(--radius-sm); border: 2px solid <?= ($paymentMethod === 'bank_transfer') ? 'var(--primary)' : 'var(--border-subtle)'; ?>; background: <?= ($paymentMethod === 'bank_transfer') ? 'var(--primary-subtle)' : 'var(--bg-input)'; ?>; display: flex; align-items: center; justify-content: space-between;">
                                <div style="display: flex; align-items: center; gap: 0.85rem;">
                                    <div style="width: 40px; height: 40px; border-radius: var(--radius-sm); background: rgba(139, 92, 246, 0.15); display: flex; align-items: center; justify-content: center; color: #a78bfa;">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="21" x2="21" y2="21"></line><line x1="3" y1="10" x2="21" y2="10"></line><polyline points="5 6 12 3 19 6"></polyline><line x1="4" y1="10" x2="4" y2="21"></line><line x1="20" y1="10" x2="20" y2="21"></line></svg>
                                    </div>
                                    <div>
                                        <strong style="color: var(--text-primary); font-size: 0.95rem; display: block;">Direct Bank Transfer</strong>
                                        <span style="font-size: 0.8rem; color: var(--text-muted);">Transfer to UrbanEats Bank of Ceylon / Commercial Bank account</span>
                                    </div>
                                </div>
                                <span class="badge" style="background: rgba(139, 92, 246, 0.12); color: #a78bfa; font-size: 0.75rem;">Online Banking</span>
                            </div>
                        </label>

                    </div>
                </div>

                <!-- Complete Order Submit CTA Button -->
                <button type="submit" 
                        class="btn btn-primary btn-lg" 
                        id="btnPlaceOrder"
                        style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.6rem; padding: 1rem; font-size: 1.15rem; font-weight: 800; box-shadow: var(--shadow-primary);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <span>Confirm &amp; Place Order (LKR <?= number_format($totals['grand_total'], 2); ?>)</span>
                </button>

                <div style="text-align: center; font-size: 0.8125rem; color: var(--text-muted); margin-top: 1rem;">
                    By clicking Confirm &amp; Place Order, your kitchen order ticket will be dispatched to the prep station immediately.
                </div>

            </form>
        </div>

        <!-- RIGHT COLUMN: Order Review, Coupon Form & Live Summary -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            
            <!-- Order Items Review Card -->
            <div class="card" style="padding: 1.5rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-medium);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0;">Items in Order</h3>
                    <a href="cart.php" style="color: var(--primary); font-size: 0.85rem; font-weight: 600; text-decoration: none;">Edit Cart</a>
                </div>

                <div style="display: flex; flex-direction: column; gap: 0.85rem; max-height: 280px; overflow-y: auto; padding-right: 0.35rem;">
                    <?php foreach ($_SESSION['cart'] as $cItem): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem;">
                            <div style="display: flex; align-items: center; gap: 0.65rem; min-width: 0;">
                                <div style="width: 38px; height: 38px; border-radius: var(--radius-sm); overflow: hidden; background: var(--bg-input); flex-shrink: 0; display: flex; align-items: center; justify-content: center;">
                                    <?php if (!empty($cItem['image_path'])): ?>
                                        <img src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($cItem['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <span style="font-size: 0.75rem; color: var(--text-muted);">Dish</span>
                                    <?php endif; ?>
                                </div>
                                <div style="min-width: 0;">
                                    <div style="color: var(--text-primary); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars($cItem['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div style="font-size: 0.775rem; color: var(--text-muted);">
                                        <?= (int)$cItem['quantity']; ?> &times; LKR <?= number_format((float)$cItem['price'], 2); ?>
                                    </div>
                                </div>
                            </div>
                            <strong style="color: var(--text-primary); flex-shrink: 0; margin-left: 0.5rem;">
                                LKR <?= number_format((float)$cItem['price'] * (int)$cItem['quantity'], 2); ?>
                            </strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Optional Coupon Code Input Card -->
            <div class="card" style="padding: 1.5rem; background: var(--bg-surface); border: 1px solid var(--border-card);">
                <h4 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                    <span>Apply Promotional Coupon</span>
                </h4>

                <?php if ($appliedCoupon !== null): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: rgba(16, 185, 129, 0.08); border-radius: var(--radius-sm); border: 1px dashed var(--success);">
                        <div>
                            <span style="font-family: var(--font-mono); font-weight: 800; color: var(--success); font-size: 0.95rem;">
                                <?= htmlspecialchars($appliedCoupon['code'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span style="font-size: 0.8rem; color: var(--text-secondary); margin-left: 0.5rem;">
                                (&minus;LKR <?= number_format($totals['discount'], 2); ?>)
                            </span>
                        </div>
                        <form action="checkout.php" method="POST" style="margin: 0;">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="remove_coupon">
                            <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); font-size: 0.8rem; padding: 0.2rem 0.5rem;">
                                Remove
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <form action="checkout.php" method="POST" style="margin: 0; display: flex; gap: 0.5rem;">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="apply_coupon">
                        <input type="text" 
                               name="coupon_code" 
                               placeholder="e.g. WELCOME10" 
                               class="form-control" 
                               required 
                               style="text-transform: uppercase; font-family: var(--font-mono); font-size: 0.9rem;"
                               maxlength="20">
                        <button type="submit" class="btn btn-secondary btn-sm" style="white-space: nowrap;">
                            Apply
                        </button>
                    </form>
                    <?php if (!empty($errors['coupon'])): ?>
                        <div style="color: var(--danger); font-size: 0.8125rem; margin-top: 0.4rem;">
                            <?= htmlspecialchars($errors['coupon'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                    <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.5rem;">
                        Available promo codes: <code style="color: var(--primary);">WELCOME10</code>, <code style="color: var(--primary);">LKR500OFF</code>, <code style="color: var(--primary);">BIRIHOLIC</code>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Financial Calculation Card -->
            <div class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-medium); box-shadow: var(--shadow-md);">
                <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin-bottom: 1.25rem;">
                    Payment Summary
                </h3>

                <!-- Subtotal -->
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.85rem; font-size: 0.95rem; color: var(--text-secondary);">
                    <span>Portions Subtotal:</span>
                    <strong style="color: var(--text-primary);">LKR <?= number_format($totals['subtotal'], 2); ?></strong>
                </div>

                <!-- Delivery Fee -->
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.85rem; font-size: 0.95rem; color: var(--text-secondary);">
                    <span>Delivery Packaging &amp; Courier:</span>
                    <strong style="color: var(--text-primary);" id="displayDeliveryFee">
                        <?php if ($totals['delivery_fee'] > 0.00): ?>
                            LKR <?= number_format($totals['delivery_fee'], 2); ?>
                        <?php else: ?>
                            <span style="color: var(--success); font-weight: 700;">FREE</span>
                        <?php endif; ?>
                    </strong>
                </div>

                <!-- Coupon Discount -->
                <?php if ($totals['discount'] > 0.00): ?>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.85rem; font-size: 0.95rem; color: var(--success);">
                        <span>Coupon Discount:</span>
                        <strong>&minus; LKR <?= number_format($totals['discount'], 2); ?></strong>
                    </div>
                <?php endif; ?>

                <!-- Grand Total -->
                <div style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--border-medium); display: flex; justify-content: space-between; align-items: flex-end;">
                    <div>
                        <span style="font-size: 1.05rem; font-weight: 800; color: var(--text-primary); display: block;">Grand Total</span>
                        <span style="font-size: 0.775rem; color: var(--text-muted);">Sri Lankan Rupees (LKR)</span>
                    </div>
                    <div style="font-size: 1.65rem; font-weight: 800; color: var(--primary); line-height: 1;" id="displayGrandTotal">
                        LKR <?= number_format($totals['grand_total'], 2); ?>
                    </div>
                </div>
            </div>

            <!-- Kitchen Guarantee -->
            <div class="card" style="padding: 1.25rem; background: var(--bg-surface); border: 1px solid var(--border-subtle); font-size: 0.825rem; color: var(--text-muted); line-height: 1.5;">
                <div style="display: flex; gap: 0.65rem; align-items: center; margin-bottom: 0.5rem; color: var(--text-secondary); font-weight: 700;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                    <span>Cloud Kitchen Hygiene &amp; Speed Guarantee</span>
                </div>
                Your dishes are cooked on high heat upon order verification and sealed in food-grade thermal containers to maintain oven-hot temperature.
            </div>

        </div>

    </div>

</div>

<!-- Client-side Interactivity for Fulfillment Toggle & Live Totals -->
<script>
    const subtotal = <?= (float)$totals['subtotal']; ?>;
    const discount = <?= (float)$totals['discount']; ?>;
    const baseDeliveryFee = <?= DELIVERY_FEE_LKR; ?>;

    function toggleFulfillment(type) {
        const cardDelivery = document.getElementById('cardDelivery');
        const cardPickup = document.getElementById('cardPickup');
        const deliverySection = document.getElementById('deliveryFieldsSection');
        const pickupSection = document.getElementById('pickupInfoSection');
        const deliveryAddressInput = document.getElementById('delivery_address');
        const displayFee = document.getElementById('displayDeliveryFee');
        const displayTotal = document.getElementById('displayGrandTotal');
        const btnPlaceOrder = document.getElementById('btnPlaceOrder');

        if (type === 'delivery') {
            cardDelivery.style.border = '2px solid var(--primary)';
            cardDelivery.style.background = 'var(--primary-subtle)';
            cardPickup.style.border = '2px solid var(--border-medium)';
            cardPickup.style.background = 'var(--bg-input)';

            deliverySection.style.display = 'block';
            pickupSection.style.display = 'none';
            if (deliveryAddressInput) {
                deliveryAddressInput.setAttribute('required', 'required');
            }

            const currentFee = (discount >= baseDeliveryFee && "<?= $appliedCoupon['code'] ?? '' ?>" === 'FREEDELIVERY') ? 0.00 : baseDeliveryFee;
            const newTotal = Math.max(0, (subtotal - discount) + currentFee);

            displayFee.innerHTML = (currentFee > 0) ? 'LKR ' + currentFee.toFixed(2) : '<span style="color: var(--success); font-weight: 700;">FREE</span>';
            displayTotal.innerText = 'LKR ' + newTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            btnPlaceOrder.querySelector('span').innerText = 'Confirm & Place Order (LKR ' + newTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ')';
        } else {
            cardPickup.style.border = '2px solid var(--primary)';
            cardPickup.style.background = 'var(--primary-subtle)';
            cardDelivery.style.border = '2px solid var(--border-medium)';
            cardDelivery.style.background = 'var(--bg-input)';

            deliverySection.style.display = 'none';
            pickupSection.style.display = 'block';
            if (deliveryAddressInput) {
                deliveryAddressInput.removeAttribute('required');
            }

            const newTotal = Math.max(0, subtotal - discount);

            displayFee.innerHTML = '<span style="color: var(--success); font-weight: 700;">FREE</span>';
            displayTotal.innerText = 'LKR ' + newTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            btnPlaceOrder.querySelector('span').innerText = 'Confirm & Place Order (LKR ' + newTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ')';
        }
    }

    function togglePayment(method) {
        const cardCOD = document.getElementById('cardCOD');
        const cardCard = document.getElementById('cardCard');
        const cardBank = document.getElementById('cardBank');

        cardCOD.style.border = (method === 'cash_on_delivery') ? '2px solid var(--primary)' : '2px solid var(--border-subtle)';
        cardCOD.style.background = (method === 'cash_on_delivery') ? 'var(--primary-subtle)' : 'var(--bg-input)';

        cardCard.style.border = (method === 'card') ? '2px solid var(--primary)' : '2px solid var(--border-subtle)';
        cardCard.style.background = (method === 'card') ? 'var(--primary-subtle)' : 'var(--bg-input)';

        cardBank.style.border = (method === 'bank_transfer') ? '2px solid var(--primary)' : '2px solid var(--border-subtle)';
        cardBank.style.background = (method === 'bank_transfer') ? 'var(--primary-subtle)' : 'var(--bg-input)';
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
