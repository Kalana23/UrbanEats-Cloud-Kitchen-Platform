<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Official Customer Tax Invoice & Electronic Receipt
 * 
 * Features:
 * - Order details (order number, timestamp, current status, estimated delivery)
 * - Customer info & delivery address (full name, email, contact phone, notes)
 * - Itemized dishes with immutable purchase unit prices, quantities, and line totals
 * - Financial breakdown (subtotal, coupon discount, delivery fee, final LKR grand total)
 * - Payment settlement details (payment method, transaction reference, status, paid timestamp)
 * - Interactive 'Print Invoice' button with professional browser print CSS media queries (@media print)
 * - Strict authorization: customers can only view their own invoices; staff/admin have authorized view
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

// Enforce customer / user authentication
require_login('../login.php');

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$isStaffOrAdmin = has_role(['Admin', 'Administrator', 'Kitchen Staff', 1, 2]);

// Retrieve order identifier from GET parameters (supporting both web server and CLI testing)
$rawOrderId = $_GET['order_id'] ?? filter_input(INPUT_GET, 'order_id', FILTER_DEFAULT);
$orderId = ($rawOrderId !== null && filter_var($rawOrderId, FILTER_VALIDATE_INT) !== false && (int)$rawOrderId > 0)
    ? (int)$rawOrderId
    : null;

$rawOrderNum = $_GET['order_number'] ?? filter_input(INPUT_GET, 'order_number', FILTER_DEFAULT);
$orderNumber = (is_string($rawOrderNum) && trim($rawOrderNum) !== '')
    ? trim($rawOrderNum)
    : null;

// Validate that at least one identifier is provided
if ($orderId === null && empty($orderNumber)) {
    header('Location: ../menu/list.php');
    exit();
}

// Fetch order record along with customer and coupon details
$orderSql = '
    SELECT o.*,
           u.first_name, u.last_name, u.email, u.username,
           c.code AS coupon_code, c.discount_type, c.discount_value
    FROM orders o
    JOIN users u ON o.user_id = u.user_id
    LEFT JOIN coupons c ON o.coupon_id = c.coupon_id
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

// If order is not found, render a friendly error state
if (!$order) {
    http_response_code(404);
    $pageTitle = 'Invoice Not Found - UrbanEats Cloud Kitchen';
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="container" style="padding-top: 3.5rem; padding-bottom: 5rem;">
        <div class="card" style="max-width: 640px; margin: 0 auto; text-align: center; padding: 3rem 2rem;">
            <div style="width: 72px; height: 72px; margin: 0 auto 1.5rem; border-radius: 50%; background: var(--danger-bg); border: 2px solid var(--danger-border); display: flex; align-items: center; justify-content: center; color: var(--danger);">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
            </div>
            <h1 style="font-size: 1.75rem; font-weight: 800; margin-bottom: 0.75rem; color: var(--text-primary);">Order Invoice Not Found</h1>
            <p style="color: var(--text-secondary); margin-bottom: 1.75rem;">
                We could not locate an invoice matching the requested order reference. The order may have been removed or the link is invalid.
            </p>
            <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                <a href="../menu/list.php" class="btn btn-primary">Browse Food Menu</a>
                <a href="../profile.php" class="btn btn-secondary">My Account Profile</a>
            </div>
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
    // Log unauthorized access attempt for security audit compliance
    log_action(
        $pdo,
        $currentUserId,
        'UNAUTHORIZED_INVOICE_ACCESS_DENIED',
        sprintf("User #%d attempted to access customer invoice for order '%s' (Owner: User #%d).", $currentUserId, $order['order_number'], $orderOwnerId)
    );

    // Redirect to 403 Access Denied page
    http_response_code(403);
    header('Location: ../access-denied.php');
    exit();
}

$effectiveOrderId = (int)$order['order_id'];

// Fetch latest payment record for this order
$paymentStmt = $pdo->prepare('
    SELECT *
    FROM payments
    WHERE order_id = :order_id
    ORDER BY payment_id DESC
    LIMIT 1
');
$paymentStmt->execute([':order_id' => $effectiveOrderId]);
$payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

// Fetch itemized dishes with purchase unit prices and quantities
$itemsStmt = $pdo->prepare('
    SELECT oi.order_item_id, oi.dish_id, oi.quantity, oi.unit_price_lkr, oi.subtotal_lkr, oi.special_instructions,
           d.name AS dish_name, d.image_path,
           c.name AS category_name
    FROM order_items oi
    JOIN dishes d ON oi.dish_id = d.dish_id
    LEFT JOIN categories c ON d.category_id = c.category_id
    WHERE oi.order_id = :order_id
    ORDER BY oi.order_item_id ASC
');
$itemsStmt->execute([':order_id' => $effectiveOrderId]);
$orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Human-friendly Status Formatter Helpers
$rawOrderStatus = (string)$order['order_status'];
$orderStatusFormatted = ucwords(str_replace('_', ' ', $rawOrderStatus));

$orderStatusBadge = match ($rawOrderStatus) {
    'delivered'        => ['class' => 'status-badge-delivered', 'text' => 'Delivered', 'icon' => 'check-circle'],
    'out_for_delivery' => ['class' => 'status-badge-out',       'text' => 'Out For Delivery', 'icon' => 'truck'],
    'ready_for_pickup' => ['class' => 'status-badge-ready',     'text' => 'Ready For Pickup', 'icon' => 'package'],
    'preparing'        => ['class' => 'status-badge-preparing', 'text' => 'Preparing in Kitchen', 'icon' => 'flame'],
    'confirmed'        => ['class' => 'status-badge-confirmed', 'text' => 'Confirmed', 'icon' => 'clipboard-check'],
    'cancelled'        => ['class' => 'status-badge-cancelled', 'text' => 'Cancelled', 'icon' => 'x-circle'],
    default            => ['class' => 'status-badge-pending',   'text' => 'Pending Approval', 'icon' => 'clock'],
};

// Payment formatting
$rawPaymentMethod = (string)($payment['payment_method'] ?? 'cash_on_delivery');
$paymentMethodFormatted = match ($rawPaymentMethod) {
    'card'             => 'Credit / Debit Card (Visa / Mastercard)',
    'online'           => 'Online Payment Gateway (IPG)',
    'bank_transfer'    => 'Direct Bank Transfer',
    'cash_on_delivery' => 'Cash on Delivery (COD)',
    default            => ucwords(str_replace('_', ' ', $rawPaymentMethod)),
};

$rawPaymentStatus = (string)($payment['payment_status'] ?? 'pending');
$paymentStatusFormatted = strtoupper($rawPaymentStatus);

$paymentStatusBadge = match ($rawPaymentStatus) {
    'completed' => ['class' => 'pay-badge-completed', 'text' => 'PAID / COMPLETED'],
    'refunded'  => ['class' => 'pay-badge-refunded',  'text' => 'REFUNDED'],
    'failed'    => ['class' => 'pay-badge-failed',    'text' => 'PAYMENT FAILED'],
    default     => ['class' => 'pay-badge-pending',   'text' => 'PAYMENT PENDING'],
};

$pageTitle = 'Tax Invoice #' . htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ==========================================================================
   Screen Styles for Invoice Presentation
   ========================================================================== */
.invoice-wrapper {
    max-width: 960px;
    margin: 1.5rem auto 4rem;
}

/* Actions Bar (Screen only) */
.invoice-actions-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.invoice-actions-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.btn-print {
    background: linear-gradient(135deg, var(--primary), #e11d48);
    color: #ffffff !important;
    box-shadow: 0 4px 15px rgba(249, 115, 22, 0.35);
    transition: var(--transition-base);
    font-weight: 700;
}

.btn-print:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(249, 115, 22, 0.55);
}

/* Main Invoice Document Card */
.invoice-sheet {
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-medium);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    overflow: hidden;
    position: relative;
    padding: 3rem 3.5rem;
    color: var(--text-primary);
}

/* Header Section */
.invoice-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 2rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid var(--border-subtle);
    margin-bottom: 2rem;
}

.invoice-brand-block {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.invoice-brand-title {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--text-primary);
    text-decoration: none;
    letter-spacing: -0.02em;
}

.invoice-brand-title .brand-icon-box {
    width: 38px;
    height: 38px;
    border-radius: var(--radius-sm);
    background: linear-gradient(135deg, var(--primary), #f43f5e);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
}

.invoice-meta-block {
    text-align: right;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.invoice-type-pill {
    display: inline-block;
    align-self: flex-end;
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    padding: 0.35rem 0.85rem;
    border-radius: var(--radius-pill);
    background: rgba(249, 115, 22, 0.15);
    color: var(--primary);
    border: 1px solid rgba(249, 115, 22, 0.3);
    margin-bottom: 0.25rem;
}

.invoice-doc-id {
    font-size: 1.6rem;
    font-weight: 800;
    font-family: var(--font-mono);
    color: var(--text-primary);
    letter-spacing: -0.01em;
}

/* Parties Information Grid */
.invoice-parties-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid var(--border-subtle);
    margin-bottom: 2rem;
}

.invoice-party-box h4 {
    font-size: 0.8125rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    margin-bottom: 0.75rem;
    font-weight: 700;
}

.invoice-party-name {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.35rem;
}

.invoice-party-lines {
    font-size: 0.925rem;
    color: var(--text-secondary);
    line-height: 1.6;
}

/* Status & Payment Highlights Bar */
.invoice-highlights-bar {
    background: var(--bg-surface);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem 1.5rem;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2rem;
}

.highlight-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.highlight-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    font-weight: 700;
}

.highlight-value {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text-primary);
}

/* Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.8125rem;
    font-weight: 700;
    padding: 0.3rem 0.75rem;
    border-radius: var(--radius-pill);
    width: fit-content;
}

.status-badge-delivered { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); }
.status-badge-out { background: rgba(249, 115, 22, 0.15); color: #fb923c; border: 1px solid rgba(249, 115, 22, 0.35); }
.status-badge-ready { background: rgba(6, 182, 212, 0.15); color: #38bdf8; border: 1px solid rgba(6, 182, 212, 0.35); }
.status-badge-preparing { background: rgba(139, 92, 246, 0.15); color: #c084fc; border: 1px solid rgba(139, 92, 246, 0.35); }
.status-badge-confirmed { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.35); }
.status-badge-pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.35); }
.status-badge-cancelled { background: rgba(244, 63, 94, 0.15); color: #fb7185; border: 1px solid rgba(244, 63, 94, 0.35); }

.pay-badge-completed { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); }
.pay-badge-pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.35); }
.pay-badge-refunded { background: rgba(139, 92, 246, 0.15); color: #c084fc; border: 1px solid rgba(139, 92, 246, 0.35); }
.pay-badge-failed { background: rgba(244, 63, 94, 0.15); color: #fb7185; border: 1px solid rgba(244, 63, 94, 0.35); }

/* Line Items Table */
.invoice-items-table-wrap {
    margin-bottom: 2rem;
    overflow-x: auto;
}

.invoice-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

.invoice-table th {
    padding: 0.85rem 1rem;
    background: var(--bg-surface);
    color: var(--text-muted);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 700;
    border-bottom: 2px solid var(--border-medium);
}

.invoice-table th.col-num { width: 50px; text-align: center; }
.invoice-table th.col-qty { width: 90px; text-align: center; }
.invoice-table th.col-price { width: 150px; text-align: right; }
.invoice-table th.col-total { width: 160px; text-align: right; }

.invoice-table td {
    padding: 1.15rem 1rem;
    border-bottom: 1px solid var(--border-subtle);
    font-size: 0.925rem;
    vertical-align: top;
}

.invoice-table td.col-num { text-align: center; color: var(--text-muted); font-family: var(--font-mono); font-size: 0.85rem; }
.invoice-table td.col-qty { text-align: center; font-weight: 700; color: var(--text-primary); }
.invoice-table td.col-price { text-align: right; font-family: var(--font-mono); color: var(--text-secondary); }
.invoice-table td.col-total { text-align: right; font-family: var(--font-mono); font-weight: 700; color: var(--text-primary); font-size: 0.95rem; }

.item-dish-name {
    font-weight: 700;
    color: var(--text-primary);
    font-size: 1rem;
    margin-bottom: 0.2rem;
}

.item-dish-cat {
    font-size: 0.8rem;
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 600;
}

.item-instruction-pill {
    display: inline-block;
    margin-top: 0.35rem;
    font-size: 0.8rem;
    color: var(--text-secondary);
    background: var(--bg-input);
    padding: 0.2rem 0.55rem;
    border-radius: var(--radius-sm);
    border: 1px dashed var(--border-medium);
}

/* Financial Totals Area */
.invoice-totals-container {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 2.5rem;
}

.invoice-totals-box {
    width: 360px;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.totals-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.925rem;
    color: var(--text-secondary);
}

.totals-row.discount-row {
    color: var(--success);
}

.totals-row strong {
    font-family: var(--font-mono);
    color: var(--text-primary);
}

.totals-row.grand-total {
    margin-top: 0.5rem;
    padding-top: 1rem;
    border-top: 2px solid var(--border-medium);
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--text-primary);
}

.totals-row.grand-total strong {
    font-size: 1.55rem;
    color: var(--primary);
    letter-spacing: -0.02em;
}

/* Invoice Legal / Compliance Footer */
.invoice-bottom-notes {
    border-top: 1px solid var(--border-subtle);
    padding-top: 1.75rem;
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2rem;
    font-size: 0.8125rem;
    color: var(--text-muted);
    line-height: 1.6;
}

.invoice-bottom-notes h5 {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 0.4rem;
}

.invoice-stamp {
    text-align: right;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: center;
}

.invoice-stamp-badge {
    display: inline-block;
    padding: 0.5rem 1rem;
    border: 2px dashed rgba(16, 185, 129, 0.4);
    border-radius: var(--radius-sm);
    color: var(--success);
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-size: 0.75rem;
    text-align: center;
}

/* Admin Inspection Banner */
.admin-audit-notice {
    background: rgba(139, 92, 246, 0.12);
    border: 1px solid rgba(139, 92, 246, 0.35);
    border-radius: var(--radius-md);
    padding: 0.85rem 1.25rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #c084fc;
    font-size: 0.875rem;
}

/* ==========================================================================
   CSS PRINT MEDIA QUERIES (@media print)
   Engineered for pristine A4 paper and PDF printing.
   ========================================================================== */
@media print {
    /* Hide all platform chrome and non-printable elements */
    .site-header,
    .site-footer,
    .invoice-actions-bar,
    .admin-audit-notice,
    .mobile-toggle,
    nav,
    header,
    footer,
    .no-print {
        display: none !important;
    }

    /* Reset global page for crisp paper output */
    @page {
        size: A4 portrait;
        margin: 12mm 15mm 15mm 15mm;
    }

    html, body {
        background: #ffffff !important;
        background-image: none !important;
        color: #0f172a !important;
        font-size: 10pt !important;
        line-height: 1.5 !important;
        padding: 0 !important;
        margin: 0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .main-content {
        padding: 0 !important;
        margin: 0 !important;
    }

    .container, .invoice-wrapper {
        max-width: 100% !important;
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    /* Print Paper Card */
    .invoice-sheet {
        background: #ffffff !important;
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        color: #0f172a !important;
    }

    .invoice-header {
        border-bottom: 2px solid #0f172a !important;
        padding-bottom: 15px !important;
        margin-bottom: 20px !important;
    }

    .invoice-brand-title {
        color: #0f172a !important;
        font-size: 18pt !important;
    }

    .invoice-brand-title .brand-icon-box {
        background: #f97316 !important;
        color: #ffffff !important;
        width: 32px !important;
        height: 32px !important;
    }

    .invoice-brand-title .highlight {
        color: #ea580c !important;
    }

    .invoice-type-pill {
        background: #f1f5f9 !important;
        color: #0f172a !important;
        border: 1px solid #cbd5e1 !important;
    }

    .invoice-doc-id {
        color: #0f172a !important;
        font-size: 16pt !important;
    }

    .invoice-parties-grid {
        border-bottom: 1px solid #cbd5e1 !important;
        padding-bottom: 15px !important;
        margin-bottom: 20px !important;
    }

    .invoice-party-box h4 {
        color: #475569 !important;
    }

    .invoice-party-name {
        color: #0f172a !important;
    }

    .invoice-party-lines {
        color: #334155 !important;
    }

    .invoice-highlights-bar {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 6px !important;
        padding: 10px 15px !important;
        margin-bottom: 20px !important;
    }

    .highlight-label {
        color: #64748b !important;
    }

    .highlight-value {
        color: #0f172a !important;
    }

    .status-badge, .status-badge-delivered, .status-badge-out,
    .status-badge-ready, .status-badge-preparing, .status-badge-confirmed,
    .status-badge-pending, .status-badge-cancelled,
    .pay-badge-completed, .pay-badge-pending, .pay-badge-refunded, .pay-badge-failed {
        background: #f1f5f9 !important;
        color: #0f172a !important;
        border: 1px solid #94a3b8 !important;
    }

    /* Print Table */
    .invoice-table th {
        background: #f1f5f9 !important;
        color: #1e293b !important;
        border-bottom: 1.5pt solid #334155 !important;
        border-top: 1pt solid #cbd5e1 !important;
    }

    .invoice-table td {
        border-bottom: 1pt solid #e2e8f0 !important;
        color: #0f172a !important;
        padding: 8px 10px !important;
    }

    .item-dish-name {
        color: #0f172a !important;
    }

    .item-dish-cat {
        color: #0369a1 !important;
    }

    .item-instruction-pill {
        background: #f8fafc !important;
        border: 1px solid #cbd5e1 !important;
        color: #334155 !important;
    }

    .invoice-totals-box {
        width: 320px !important;
    }

    .totals-row {
        color: #334155 !important;
    }

    .totals-row strong {
        color: #0f172a !important;
    }

    .totals-row.grand-total {
        border-top: 2pt solid #0f172a !important;
        color: #0f172a !important;
    }

    .totals-row.grand-total strong {
        color: #ea580c !important;
        font-size: 14pt !important;
    }

    .invoice-bottom-notes {
        border-top: 1pt solid #cbd5e1 !important;
        color: #64748b !important;
    }

    .invoice-bottom-notes h5 {
        color: #334155 !important;
    }

    .invoice-stamp-badge {
        border: 1.5pt dashed #10b981 !important;
        color: #059669 !important;
    }

    /* Avoid page-breaks inside tables, totals, and stamps */
    .invoice-sheet,
    .invoice-table tr,
    .invoice-totals-container,
    .invoice-bottom-notes,
    .invoice-parties-grid {
        break-inside: avoid;
        page-break-inside: avoid;
    }
}

/* Responsive adjustments for mobile screens */
@media screen and (max-width: 768px) {
    .invoice-sheet {
        padding: 1.75rem 1.25rem;
    }
    .invoice-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1.25rem;
    }
    .invoice-meta-block {
        text-align: left;
    }
    .invoice-type-pill {
        align-self: flex-start;
    }
    .invoice-parties-grid {
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }
    .invoice-totals-box {
        width: 100%;
    }
    .invoice-bottom-notes {
        grid-template-columns: 1fr;
    }
    .invoice-stamp {
        align-items: flex-start;
        text-align: left;
    }
}
</style>

<div class="container">
    <div class="invoice-wrapper">

        <!-- Staff / Admin Audit Notice Banner (Screen Only) -->
        <?php if ($isStaffOrAdmin && !$isOwner): ?>
            <div class="admin-audit-notice no-print">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                <div>
                    <strong>Administrative Oversight Mode:</strong> You are viewing this invoice with staff privileges (Logged in as <?= htmlspecialchars($_SESSION['username'] ?? 'Staff', ENT_QUOTES, 'UTF-8'); ?> - Role: <?= htmlspecialchars((string)($_SESSION['role_name'] ?? 'Staff'), ENT_QUOTES, 'UTF-8'); ?>).
                </div>
            </div>
        <?php endif; ?>

        <!-- Screen Actions & Navigation Bar (Screen Only) -->
        <div class="invoice-actions-bar no-print">
            <div class="invoice-actions-group">
                <a href="confirmation.php?order_id=<?= (int)$order['order_id']; ?>" class="btn btn-secondary btn-sm" title="Return to order confirmation">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                    <span>Order Confirmation</span>
                </a>
                <a href="../menu/list.php" class="btn btn-secondary btn-sm" title="Browse dishes">
                    <span>Menu Catalog</span>
                </a>
            </div>

            <div class="invoice-actions-group">
                <button type="button" class="btn btn-primary btn-print" onclick="window.print();" id="btnPrintInvoice">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 6 2 18 2 18 9"></polyline>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                        <rect x="6" y="14" width="12" height="8"></rect>
                    </svg>
                    <span>Print Invoice</span>
                </button>
            </div>
        </div>

        <!-- Official Tax Invoice Sheet Document -->
        <div class="invoice-sheet" id="invoiceSheet">

            <!-- 1. Header / Enterprise Metadata -->
            <div class="invoice-header">
                
                <div class="invoice-brand-block">
                    <a href="../index.php" class="invoice-brand-title">
                        <div class="brand-icon-box">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M18 8h1a4 4 0 0 1 0 8h-1"></path>
                                <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path>
                                <line x1="6" y1="1" x2="6" y2="4"></line>
                                <line x1="10" y1="1" x2="10" y2="4"></line>
                                <line x1="14" y1="1" x2="14" y2="4"></line>
                            </svg>
                        </div>
                        <span>Urban<span class="highlight" style="color: var(--primary);">Eats</span></span>
                    </a>
                    <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.5; margin-top: 0.35rem;">
                        <strong>UrbanEats Cloud Kitchen Network (Pvt) Ltd.</strong><br>
                        Main Culinary Hub: 124 Galle Road, Kollupitiya, Colombo 03, Sri Lanka<br>
                        Hotline: +94 11 234 5678 &bull; Support: support@urbaneats.local<br>
                        VAT Registration No: <strong>104-889-210-7000</strong> &bull; BRN: <strong>PV-002891</strong>
                    </div>
                </div>

                <div class="invoice-meta-block">
                    <span class="invoice-type-pill">Official Tax Invoice</span>
                    <div class="invoice-doc-id">
                        <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        <strong>Invoice Date:</strong> <?= date('F j, Y - g:i A', strtotime($order['created_at'])); ?>
                    </div>
                    <?php if (!empty($order['estimated_delivery_time'])): ?>
                        <div style="font-size: 0.85rem; color: var(--text-muted);">
                            <strong>Target Delivery:</strong> <?= date('F j, Y - g:i A', strtotime($order['estimated_delivery_time'])); ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- 2. Parties Grid (Customer Details & Delivery Destination) -->
            <div class="invoice-parties-grid">
                
                <!-- Billed To (Customer Details) -->
                <div class="invoice-party-box">
                    <h4>Billed To (Customer Information)</h4>
                    <div class="invoice-party-name">
                        <?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="invoice-party-lines">
                        <div><strong>Customer ID:</strong> #CUST-<?= str_pad((string)$order['user_id'], 5, '0', STR_PAD_LEFT); ?></div>
                        <div><strong>Email Address:</strong> <?= htmlspecialchars($order['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Contact Phone:</strong> <?= htmlspecialchars($order['contact_phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Account Username:</strong> <?= htmlspecialchars($order['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>

                <!-- Delivery Destination -->
                <div class="invoice-party-box">
                    <h4>Deliver To (Fulfillment Address)</h4>
                    <div class="invoice-party-name">
                        <?= htmlspecialchars($order['delivery_city'], ENT_QUOTES, 'UTF-8'); ?> Destination
                    </div>
                    <div class="invoice-party-lines">
                        <div><strong>Address:</strong> <?= nl2br(htmlspecialchars($order['delivery_address'], ENT_QUOTES, 'UTF-8')); ?></div>
                        <div><strong>City / Zone:</strong> <?= htmlspecialchars($order['delivery_city'], ENT_QUOTES, 'UTF-8'); ?>, Sri Lanka</div>
                        <div><strong>Delivery Phone:</strong> <?= htmlspecialchars($order['contact_phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if (!empty($order['delivery_notes'])): ?>
                            <div style="margin-top: 0.35rem; padding: 0.4rem 0.65rem; background: var(--bg-surface); border-radius: var(--radius-sm); border-left: 3px solid var(--accent); font-size: 0.85rem;">
                                <strong>Delivery Instructions:</strong><br>
                                <?= htmlspecialchars($order['delivery_notes'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- 3. Status & Payment Highlights Bar -->
            <div class="invoice-highlights-bar">
                
                <div class="highlight-item">
                    <span class="highlight-label">Current Order Status</span>
                    <span class="status-badge <?= $orderStatusBadge['class']; ?>">
                        <?= htmlspecialchars($orderStatusBadge['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>

                <div class="highlight-item">
                    <span class="highlight-label">Payment Settlement</span>
                    <span class="status-badge <?= $paymentStatusBadge['class']; ?>">
                        <?= htmlspecialchars($paymentStatusBadge['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>

                <div class="highlight-item">
                    <span class="highlight-label">Payment Method</span>
                    <div class="highlight-value" style="font-size: 0.875rem;">
                        <?= htmlspecialchars($paymentMethodFormatted, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>

                <div class="highlight-item">
                    <span class="highlight-label">Transaction Reference</span>
                    <div class="highlight-value" style="font-family: var(--font-mono); font-size: 0.85rem; color: var(--accent);">
                        <?= htmlspecialchars($payment['transaction_reference'] ?? 'PENDING-SETTLEMENT', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>

            </div>

            <!-- 4. Itemized Ordered Dishes Table -->
            <div class="invoice-items-table-wrap">
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th class="col-num">#</th>
                            <th>Dish &amp; Culinary Description</th>
                            <th>Category</th>
                            <th class="col-qty">Quantity</th>
                            <th class="col-price">Purchase Unit Price</th>
                            <th class="col-total">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $itemIndex = 1; foreach ($orderItems as $item): ?>
                            <tr>
                                <td class="col-num"><?= $itemIndex++; ?></td>
                                <td>
                                    <div class="item-dish-name">
                                        <?= htmlspecialchars($item['dish_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <?php if (!empty($item['special_instructions'])): ?>
                                        <div class="item-instruction-pill">
                                            <strong>Note:</strong> <?= htmlspecialchars($item['special_instructions'], ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="item-dish-cat">
                                        <?= htmlspecialchars($item['category_name'] ?? 'Kitchen Special', ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="col-qty">
                                    <?= (int)$item['quantity']; ?>
                                </td>
                                <td class="col-price">
                                    LKR <?= number_format((float)$item['unit_price_lkr'], 2); ?>
                                </td>
                                <td class="col-total">
                                    LKR <?= number_format((float)$item['subtotal_lkr'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- 5. Financial Summary / Totals Breakdown -->
            <div class="invoice-totals-container">
                <div class="invoice-totals-box">
                    
                    <!-- Subtotal -->
                    <div class="totals-row">
                        <span>Items Subtotal:</span>
                        <strong>LKR <?= number_format((float)$order['subtotal_lkr'], 2); ?></strong>
                    </div>

                    <!-- Discount (if applicable) -->
                    <?php if ((float)$order['discount_lkr'] > 0): ?>
                        <div class="totals-row discount-row">
                            <span>
                                Discount
                                <?php if (!empty($order['coupon_code'])): ?>
                                    (Coupon: <?= htmlspecialchars($order['coupon_code'], ENT_QUOTES, 'UTF-8'); ?>)
                                <?php endif; ?>:
                            </span>
                            <strong>&minus; LKR <?= number_format((float)$order['discount_lkr'], 2); ?></strong>
                        </div>
                    <?php endif; ?>

                    <!-- Delivery & Handling Fee -->
                    <div class="totals-row">
                        <span>Delivery &amp; Packaging Fee:</span>
                        <strong>
                            <?php if ((float)$order['delivery_fee_lkr'] > 0): ?>
                                LKR <?= number_format((float)$order['delivery_fee_lkr'], 2); ?>
                            <?php else: ?>
                                FREE
                            <?php endif; ?>
                        </strong>
                    </div>

                    <!-- Grand Total -->
                    <div class="totals-row grand-total">
                        <span>Final Total:</span>
                        <strong>LKR <?= number_format((float)$order['total_amount_lkr'], 2); ?></strong>
                    </div>

                </div>
            </div>

            <!-- 6. Bottom Terms & Stamp -->
            <div class="invoice-bottom-notes">
                <div>
                    <h5>Important Terms &amp; Conditions</h5>
                    <p style="margin-bottom: 0.4rem;">
                        1. This document serves as an authentic commercial tax invoice and receipt of payment for culinary services provided by UrbanEats Cloud Kitchen Platform.
                    </p>
                    <p style="margin-bottom: 0.4rem;">
                        2. All perishable food items are sealed in tamper-evident hygienic packaging. Please inspect the seal upon courier delivery.
                    </p>
                    <p style="margin: 0;">
                        3. For billing inquiries, cancellations, or delivery tracking, quote Order <strong><?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8'); ?></strong> to our 24/7 support line at <strong>+94 11 234 5678</strong>.
                    </p>
                </div>

                <div class="invoice-stamp">
                    <div class="invoice-stamp-badge">
                        <?= ($rawPaymentStatus === 'completed') ? '&check; PAID &amp; VERIFIED' : '&bull; OFFICIAL RECEIPT'; ?>
                    </div>
                    <div style="font-size: 0.725rem; color: var(--text-muted); margin-top: 0.5rem; text-align: right;">
                        Computer Generated Invoice<br>
                        No Signature Required<br>
                        Printed on <?= date('Y-m-d H:i:s'); ?>
                    </div>
                </div>
            </div>

        </div><!-- /.invoice-sheet -->

    </div><!-- /.invoice-wrapper -->
</div><!-- /.container -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
