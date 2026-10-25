<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C Integration Stub: Order Cart
 * 
 * Manages session-based cart items and provides an integration bridge
 * for Component B's Add-to-Cart form actions.
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';

// Ensure session cart array exists
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$feedback = ['type' => '', 'message' => ''];

// -----------------------------------------------------------------------------
// Handle Cart POST Actions (Add, Update Quantity, Remove)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token()) {
        $feedback = ['type' => 'danger', 'message' => 'Security token expired. Please try again.'];
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $dishId = (int)($_POST['dish_id'] ?? 0);

        // 1. ADD DISH TO CART
        if ($action === 'add' && $dishId > 0) {
            $qty = max(1, (int)($_POST['quantity'] ?? 1));

            // Verify dish and inventory from database (Never trust price or stock from browser)
            $dishStmt = $pdo->prepare('
                SELECT d.dish_id, d.name, d.price, d.image_path, i.current_stock
                FROM dishes d
                LEFT JOIN inventory i ON d.dish_id = i.dish_id
                WHERE d.dish_id = :id AND d.is_active = 1
            ');
            $dishStmt->execute([':id' => $dishId]);
            $dish = $dishStmt->fetch();

            if (!$dish) {
                $feedback = ['type' => 'danger', 'message' => 'The selected dish is no longer available.'];
            } else {
                $availableStock = (int)($dish['current_stock'] ?? 0);
                $existingQty = (int)($_SESSION['cart'][$dishId]['quantity'] ?? 0);
                $newQty = $existingQty + $qty;

                if ($availableStock <= 0) {
                    $feedback = ['type' => 'danger', 'message' => "Sorry, '{$dish['name']}' is currently out of stock."];
                } elseif ($newQty > $availableStock) {
                    $feedback = ['type' => 'warning', 'message' => "Only {$availableStock} portions available for '{$dish['name']}'. Quantity capped."];
                    $_SESSION['cart'][$dishId] = [
                        'dish_id'    => $dishId,
                        'name'       => $dish['name'],
                        'price'      => (float)$dish['price'],
                        'image_path' => $dish['image_path'],
                        'quantity'   => $availableStock,
                    ];
                } else {
                    $_SESSION['cart'][$dishId] = [
                        'dish_id'    => $dishId,
                        'name'       => $dish['name'],
                        'price'      => (float)$dish['price'],
                        'image_path' => $dish['image_path'],
                        'quantity'   => $newQty,
                    ];
                    $feedback = ['type' => 'success', 'message' => "Added {$qty}x '{$dish['name']}' to your order cart!"];
                }
            }
        }

        // 2. REMOVE ITEM FROM CART
        elseif ($action === 'remove' && $dishId > 0) {
            if (isset($_SESSION['cart'][$dishId])) {
                $removedName = $_SESSION['cart'][$dishId]['name'];
                unset($_SESSION['cart'][$dishId]);
                $feedback = ['type' => 'info', 'message' => "Removed '{$removedName}' from cart."];
            }
        }

        // 3. CLEAR CART
        elseif ($action === 'clear') {
            $_SESSION['cart'] = [];
            $feedback = ['type' => 'info', 'message' => 'Your cart has been cleared.'];
        }
    }
}

// Calculate totals
$subtotal = 0.0;
foreach ($_SESSION['cart'] as $item) {
    $subtotal += ($item['price'] * $item['quantity']);
}
$deliveryFee = empty($_SESSION['cart']) ? 0.0 : 350.00;
$grandTotal = $subtotal + $deliveryFee;

$pageTitle = 'Order Cart - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 5rem;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 style="font-size: 2rem; font-weight: 800; color: var(--text-primary); margin: 0;">Your Order Cart</h1>
            <p style="color: var(--text-secondary); margin-top: 0.35rem; font-size: 0.95rem;">
                Review portions and order totals before proceeding to delivery checkout.
            </p>
        </div>
        <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/menu/list.php" class="btn btn-secondary btn-sm">
            &larr; Continue Browsing Menu
        </a>
    </div>

    <?php if (!empty($feedback['message'])): ?>
        <div class="alert alert-<?= htmlspecialchars($feedback['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars($feedback['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($_SESSION['cart'])): ?>
        <div class="card" style="text-align: center; padding: 4rem 2rem;">
            <svg width="54" height="54" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" style="margin-bottom: 1rem;"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
            <h2 style="font-size: 1.45rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Your Cart is Empty</h2>
            <p style="color: var(--text-muted); max-width: 440px; margin: 0 auto 2rem;">
                You haven't added any Sri Lankan specialties yet. Check out our sizzling kottu, dum biriyanis, and authentic curries!
            </p>
            <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/menu/list.php" class="btn btn-primary btn-lg">
                Explore Menu Catalog
            </a>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; align-items: flex-start;">
            
            <!-- Cart Items Table -->
            <div class="card">
                <div class="card-header">
                    <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin: 0;">Cart Portions (<?= count($_SESSION['cart']); ?> Items)</h3>
                    <form action="cart.php" method="POST" style="margin: 0;" onsubmit="return confirm('Empty the entire cart?');">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="btn btn-outline btn-sm">Clear Cart</button>
                    </form>
                </div>
                <div class="card-body" style="padding: 0;">
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Dish</th>
                                    <th>Price</th>
                                    <th>Portions</th>
                                    <th>Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($_SESSION['cart'] as $item): ?>
                                    <?php $itemTotal = $item['price'] * $item['quantity']; ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.85rem;">
                                                <?php if (!empty($item['image_path'])): ?>
                                                    <img src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($item['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 46px; height: 46px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
                                                <?php endif; ?>
                                                <strong style="color: var(--text-primary); font-size: 0.95rem;">
                                                    <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </strong>
                                            </div>
                                        </td>
                                        <td>LKR <?= number_format((float)$item['price'], 2); ?></td>
                                        <td>
                                            <span style="font-weight: 700; color: var(--text-primary);"><?= (int)$item['quantity']; ?></span>
                                        </td>
                                        <td>
                                            <strong style="color: var(--primary);">LKR <?= number_format((float)$itemTotal, 2); ?></strong>
                                        </td>
                                        <td>
                                            <form action="cart.php" method="POST" style="margin: 0;">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="dish_id" value="<?= (int)$item['dish_id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Remove Item">&times;</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Order Summary Sidebar -->
            <div class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated);">
                <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem;">Order Summary</h3>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem; font-size: 0.925rem; color: var(--text-secondary);">
                    <span>Portions Subtotal:</span>
                    <strong style="color: var(--text-primary);">LKR <?= number_format($subtotal, 2); ?></strong>
                </div>

                <div style="display: flex; justify-content: space-between; margin-bottom: 1rem; font-size: 0.925rem; color: var(--text-secondary);">
                    <span>Insulated Delivery:</span>
                    <strong style="color: var(--text-primary);">LKR <?= number_format($deliveryFee, 2); ?></strong>
                </div>

                <div style="display: flex; justify-content: space-between; padding-top: 1rem; border-top: 1px solid var(--border-medium); margin-bottom: 1.5rem;">
                    <span style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary);">Grand Total:</span>
                    <strong style="font-size: 1.45rem; font-weight: 800; color: var(--primary);">LKR <?= number_format($grandTotal, 2); ?></strong>
                </div>

                <?php if (is_logged_in()): ?>
                    <button type="button" class="btn btn-primary btn-lg" style="width: 100%;" onclick="alert('Checkout pipeline will be integrated with Component C (Ordering, Payment & Tracking). During checkout, reduce_stock() is executed inside a transaction!');">
                        Proceed to Checkout &rarr;
                    </button>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/login.php?redirect=orders/cart.php" class="btn btn-primary btn-lg" style="width: 100%;">
                        Sign In to Checkout &rarr;
                    </a>
                <?php endif; ?>

                <div style="font-size: 0.8rem; color: var(--text-muted); text-align: center; margin-top: 1rem;">
                    Prices re-verified against live kitchen database
                </div>
            </div>

        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
