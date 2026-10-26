<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Dedicated Backend Order Checkout Processing Script
 * 
 * Architecture:
 * - Single ACID-compliant PDO Transaction ($pdo->beginTransaction())
 * - Live database re-verification for latest dish pricing and stock availability (FOR UPDATE)
 * - Server-side promotional coupon validation (coupons table)
 * - Atomic stock deduction utilizing Component B's reduce_stock()
 * - Inserts records into:
 *     1. orders (master transaction header)
 *     2. order_items (line items with immutable unit_price_lkr snapshot)
 *     3. payments (payment settlement details)
 *     4. order_status_history (initial pending state transition)
 * - Atomic coupon usage increment (coupons.used_count)
 * - Complete rollback ($pdo->rollBack()) on any error, exception, or validation failure
 * - Audit logging via log_action()
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/stock.php';
require_once __DIR__ . '/../../includes/coupons.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Flat-rate delivery fee constant (LKR)
const STANDARD_DELIVERY_FEE_LKR = 350.00;

// Helper: Redirect back to checkout with error message stored in session
function abort_to_checkout(string $errorMessage): void {
    $_SESSION['checkout_error'] = $errorMessage;
    header('Location: checkout.php');
    exit();
}

// Helper: Check if client requested JSON response
function is_json_request(): bool {
    return (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
}

// -----------------------------------------------------------------------------
// 1. Pre-Flight Verification & Access Control
// -----------------------------------------------------------------------------

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: checkout.php');
    exit();
}

// Enforce authentication
if (!is_logged_in()) {
    if (is_json_request()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required to complete checkout.']);
        exit();
    }
    header('Location: ../login.php?redirect=orders/checkout.php');
    exit();
}

// Enforce CSRF protection
if (!validate_csrf_token()) {
    if (is_json_request()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Security token expired or invalid. Please refresh and try again.']);
        exit();
    }
    abort_to_checkout('Security token expired or invalid. Please refresh the page and try again.');
}

// Verify that cart exists and is not empty
if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    if (is_json_request()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Your order cart is empty.']);
        exit();
    }
    header('Location: cart.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];

// -----------------------------------------------------------------------------
// 2. Extract & Sanitize Form Input
// -----------------------------------------------------------------------------
$fulfillmentType = trim((string)($_POST['fulfillment_type'] ?? 'delivery'));
$contactPhone    = trim((string)($_POST['contact_phone'] ?? ''));
$paymentMethod   = trim((string)($_POST['payment_method'] ?? 'cash_on_delivery'));
$deliveryNotes   = trim((string)($_POST['delivery_notes'] ?? ''));
$couponCodeInput = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));

// If no coupon was passed via POST, check if one was applied in the session
if (empty($couponCodeInput) && isset($_SESSION['applied_coupon']['code'])) {
    $couponCodeInput = strtoupper((string)$_SESSION['applied_coupon']['code']);
}

// Validate Fulfillment Type
if (!in_array($fulfillmentType, ['delivery', 'pickup'], true)) {
    abort_to_checkout('Invalid fulfillment type selected. Please choose Hot Delivery or Kitchen Pickup.');
}

// Validate Delivery Address
if ($fulfillmentType === 'delivery') {
    $deliveryAddress = trim((string)($_POST['delivery_address'] ?? ''));
    $deliveryCity    = trim((string)($_POST['delivery_city'] ?? ''));

    if (empty($deliveryAddress) || strlen($deliveryAddress) < 5) {
        abort_to_checkout('Please provide a complete delivery street address (minimum 5 characters).');
    }
    if (empty($deliveryCity)) {
        abort_to_checkout('Please select or specify a delivery city/district.');
    }
} else {
    $deliveryAddress = 'UrbanEats Central Cloud Kitchen Counter Pickup, 45/2 Galle Road, Colombo 03';
    $deliveryCity    = 'Colombo 03';
}

// Validate Contact Phone Number
$cleanedPhone = preg_replace('/[\s\-\(\)\.]+/', '', $contactPhone);
if ($cleanedPhone === null || strlen($cleanedPhone) < 9 || strlen($cleanedPhone) > 15 || !preg_match('/^(\+?[0-9]{9,15})$/', $cleanedPhone)) {
    abort_to_checkout('Please provide a valid contact phone number (e.g. +94 77 123 4567 or 0771234567).');
}

// Validate Payment Method
$validPaymentMethods = ['cash_on_delivery', 'card', 'bank_transfer'];
if (!in_array($paymentMethod, $validPaymentMethods, true)) {
    abort_to_checkout('Invalid payment method selected.');
}

// -----------------------------------------------------------------------------
// 3. ATOMIC BACKEND CHECKOUT TRANSACTION EXECUTION
// -----------------------------------------------------------------------------

// Start the master single transaction
$pdo->beginTransaction();

try {
    // -------------------------------------------------------------------------
    // A. Re-Check Latest Prices and Stock Availability from Database (FOR UPDATE)
    // -------------------------------------------------------------------------
    $cartItems = $_SESSION['cart'];
    $verifiedOrderItems = [];
    $recalculatedSubtotal = 0.0;

    foreach ($cartItems as $dishId => $sessionItem) {
        $dishId = (int)$dishId;
        $requestedQty = (int)($sessionItem['quantity'] ?? 0);

        if ($dishId <= 0 || $requestedQty <= 0) {
            throw new RuntimeException('Invalid dish portion or quantity in order cart.');
        }

        // Lock dish and inventory rows to prevent race conditions during concurrent checkouts
        $stmtDish = $pdo->prepare('
            SELECT d.dish_id, d.name, d.price, d.is_active, 
                   COALESCE(i.current_stock, 0) AS current_stock
            FROM dishes d
            LEFT JOIN inventory i ON d.dish_id = i.dish_id
            WHERE d.dish_id = :dish_id
            FOR UPDATE
        ');
        $stmtDish->execute([':dish_id' => $dishId]);
        $dbDish = $stmtDish->fetch(PDO::FETCH_ASSOC);

        // Verify active status
        if (!$dbDish || (int)$dbDish['is_active'] !== 1) {
            $dishName = $sessionItem['name'] ?? "Dish #{$dishId}";
            throw new RuntimeException("Sorry, '{$dishName}' is no longer available on our menu.");
        }

        // Verify stock availability
        $availableStock = (int)$dbDish['current_stock'];
        if ($availableStock <= 0) {
            throw new RuntimeException("Sorry, '{$dbDish['name']}' has just sold out. Please remove it from your cart.");
        }
        if ($requestedQty > $availableStock) {
            throw new RuntimeException("Insufficient stock for '{$dbDish['name']}'. Only {$availableStock} portions remaining.");
        }

        // Use true database price snapshot (Never trust client session price)
        $unitPriceLkr = (float)$dbDish['price'];
        $lineSubtotal = $unitPriceLkr * $requestedQty;
        $recalculatedSubtotal += $lineSubtotal;

        $verifiedOrderItems[] = [
            'dish_id'              => $dishId,
            'dish_name'            => (string)$dbDish['name'],
            'quantity'             => $requestedQty,
            'unit_price_lkr'       => $unitPriceLkr,
            'subtotal_lkr'         => $lineSubtotal,
            'special_instructions' => null,
        ];
    }

    if (empty($verifiedOrderItems) || $recalculatedSubtotal <= 0.0) {
        throw new RuntimeException('Your order contains no valid items.');
    }

    // -------------------------------------------------------------------------
    // B. Delivery Fee & Promotional Coupon Validation
    // -------------------------------------------------------------------------
    $deliveryFeeLkr = ($fulfillmentType === 'delivery') ? STANDARD_DELIVERY_FEE_LKR : 0.00;
    $discountLkr = 0.00;
    $couponIdToRecord = null;

    if (!empty($couponCodeInput)) {
        // Run coupon validation using PDO prepared statements
        $couponValidation = validate_coupon($pdo, $couponCodeInput, $recalculatedSubtotal);

        if (!$couponValidation['valid']) {
            throw new RuntimeException('Coupon Validation Error: ' . $couponValidation['error']);
        }

        $validatedCoupon = $couponValidation['coupon'];
        $couponIdToRecord = (int)$validatedCoupon['coupon_id'];

        // Compute discount using server calculation
        if ($validatedCoupon['code'] === 'FREEDELIVERY') {
            $discountLkr = $deliveryFeeLkr;
        } else {
            $discountLkr = (float)$couponValidation['discount_amount'];
        }
    }

    // Calculate final grand total
    $totalAmountLkr = max(0.00, ($recalculatedSubtotal - $discountLkr) + $deliveryFeeLkr);

    // -------------------------------------------------------------------------
    // C. Atomic Stock Deduction (Component B reduce_stock())
    // -------------------------------------------------------------------------
    $estMinutes = ($fulfillmentType === 'delivery') ? 45 : 25;
    $estDeliveryTime = date('Y-m-d H:i:s', strtotime("+{$estMinutes} minutes"));
    $orderNumber = 'UE-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);

    foreach ($verifiedOrderItems as $item) {
        $reduced = reduce_stock(
            $pdo,
            $item['dish_id'],
            $item['quantity'],
            $userId,
            "Order Checkout: {$orderNumber}"
        );

        if (!$reduced) {
            throw new RuntimeException("Stock deduction failed for '{$item['dish_name']}'. Inventory condition could not be satisfied.");
        }
    }

    // -------------------------------------------------------------------------
    // D. Insert Master Order Record into orders Table
    // -------------------------------------------------------------------------
    $stmtOrder = $pdo->prepare('
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
    $stmtOrder->execute([
        ':order_number'            => $orderNumber,
        ':user_id'                 => $userId,
        ':coupon_id'               => $couponIdToRecord,
        ':order_status'            => 'pending',
        ':subtotal_lkr'            => $recalculatedSubtotal,
        ':discount_lkr'            => $discountLkr,
        ':delivery_fee_lkr'        => $deliveryFeeLkr,
        ':total_amount_lkr'        => $totalAmountLkr,
        ':delivery_address'        => $deliveryAddress,
        ':delivery_city'           => $deliveryCity,
        ':contact_phone'           => $contactPhone,
        ':delivery_notes'          => !empty($deliveryNotes) ? $deliveryNotes : null,
        ':estimated_delivery_time' => $estDeliveryTime,
    ]);

    $orderId = (int)$pdo->lastInsertId();

    // -------------------------------------------------------------------------
    // E. Insert Line Items into order_items Table (Storing Snapshot Purchase Prices)
    // -------------------------------------------------------------------------
    $stmtItems = $pdo->prepare('
        INSERT INTO order_items (
            order_id, dish_id, quantity, unit_price_lkr, subtotal_lkr, special_instructions, created_at
        ) VALUES (
            :order_id, :dish_id, :quantity, :unit_price_lkr, :subtotal_lkr, :special_instructions, NOW()
        )
    ');

    foreach ($verifiedOrderItems as $line) {
        $stmtItems->execute([
            ':order_id'             => $orderId,
            ':dish_id'              => $line['dish_id'],
            ':quantity'             => $line['quantity'],
            ':unit_price_lkr'       => $line['unit_price_lkr'], // Immutable purchase price
            ':subtotal_lkr'         => $line['subtotal_lkr'],
            ':special_instructions' => $line['special_instructions'],
        ]);
    }

    // -------------------------------------------------------------------------
    // F. Insert Payment Record into payments Table
    // -------------------------------------------------------------------------
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
        'bank_transfer' => 'Bank of Ceylon A/C: 0012398471. Awaiting bank transfer confirmation.',
        default         => 'Cash on Delivery. Pending payment collection on handover.',
    };

    $stmtPayment = $pdo->prepare('
        INSERT INTO payments (
            order_id, payment_method, payment_status, amount_lkr,
            transaction_reference, payment_details, paid_at, created_at
        ) VALUES (
            :order_id, :payment_method, :payment_status, :amount_lkr,
            :transaction_reference, :payment_details, :paid_at, NOW()
        )
    ');
    $stmtPayment->execute([
        ':order_id'              => $orderId,
        ':payment_method'        => $paymentMethod,
        ':payment_status'        => $paymentStatus,
        ':amount_lkr'            => $totalAmountLkr,
        ':transaction_reference' => $txnReference,
        ':payment_details'       => $paymentDetails,
        ':paid_at'               => $paidAt,
    ]);

    // -------------------------------------------------------------------------
    // G. Insert State Transition into order_status_history Table
    // -------------------------------------------------------------------------
    $stmtHistory = $pdo->prepare('
        INSERT INTO order_status_history (
            order_id, changed_by_user_id, previous_status, new_status, comment, created_at
        ) VALUES (
            :order_id, :changed_by_user_id, NULL, :new_status, :comment, NOW()
        )
    ');
    $stmtHistory->execute([
        ':order_id'            => $orderId,
        ':changed_by_user_id'  => $userId,
        ':new_status'          => 'pending',
        ':comment'             => sprintf(
            'Order submitted via checkout backend (%s, %s). Kitchen prep pending.',
            ucfirst($fulfillmentType),
            str_replace('_', ' ', ucfirst($paymentMethod))
        ),
    ]);

    // -------------------------------------------------------------------------
    // H. Atomically Increment Coupon Usage Count (coupons Table)
    // -------------------------------------------------------------------------
    if ($couponIdToRecord !== null) {
        $stmtUpdCoupon = $pdo->prepare('
            UPDATE coupons
            SET used_count = used_count + 1
            WHERE coupon_id = :coupon_id
        ');
        $stmtUpdCoupon->execute([':coupon_id' => $couponIdToRecord]);
    }

    // -------------------------------------------------------------------------
    // I. Record Security and Administrative Event in audit_log
    // -------------------------------------------------------------------------
    log_action(
        $pdo,
        $userId,
        'ORDER_CHECKOUT_COMPLETED',
        sprintf(
            'Order %s (ID: %d) successfully processed for LKR %.2f (%s items, %s).',
            $orderNumber,
            $orderId,
            $totalAmountLkr,
            count($verifiedOrderItems),
            ucfirst($paymentMethod)
        )
    );

    // -------------------------------------------------------------------------
    // J. COMMIT THE TRANSACTION ATOMICALLY
    // -------------------------------------------------------------------------
    $pdo->commit();

    // Clear session cart and temporary applied coupon data
    $_SESSION['cart'] = [];
    unset($_SESSION['applied_coupon']);
    unset($_SESSION['checkout_error']);

    // Store completed order info in session
    $_SESSION['recent_order'] = [
        'order_id'         => $orderId,
        'order_number'     => $orderNumber,
        'total_amount_lkr' => $totalAmountLkr,
        'fulfillment_type' => $fulfillmentType,
        'payment_method'   => $paymentMethod,
        'est_time'         => $estDeliveryTime,
    ];

    // Return success response
    if (is_json_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'      => true,
            'order_id'     => $orderId,
            'order_number' => $orderNumber,
            'total_lkr'    => $totalAmountLkr,
            'redirect'     => "confirmation.php?order_id={$orderId}",
        ]);
        exit();
    }

    // Redirect to Order Confirmation Receipt
    header("Location: confirmation.php?order_id={$orderId}");
    exit();

} catch (Throwable $e) {
    // -------------------------------------------------------------------------
    // K. COMPLETE TRANSACTION ROLLBACK ON ANY FAILURE
    // -------------------------------------------------------------------------
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errorMessage = $e->getMessage();
    error_log("[UrbanEats Checkout Transaction Aborted] Error: {$errorMessage}");

    if (is_json_request()) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => $errorMessage,
        ]);
        exit();
    }

    // Redirect back to checkout with descriptive error alert
    abort_to_checkout($errorMessage);
}
