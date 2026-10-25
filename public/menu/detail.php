<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Dish Detail Page
 * 
 * Features:
 * - High-resolution culinary imagery
 * - Portion pricing in LKR
 * - Real-time kitchen inventory availability indicators
 * - Portion quantity selector and Add-to-Cart form (POST to Component C orders/cart.php)
 * - Related recommendations from the same category
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';

$dishId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($dishId <= 0) {
    header('Location: list.php');
    exit();
}

// -----------------------------------------------------------------------------
// Fetch Dish Details with Category and Inventory
// -----------------------------------------------------------------------------
$dishStmt = $pdo->prepare('
    SELECT d.dish_id, d.name, d.description, d.price, d.image_path, d.is_active, d.created_at,
           c.category_id, c.name AS category_name, c.description AS category_desc,
           i.current_stock, i.reorder_level
    FROM dishes d
    JOIN categories c ON d.category_id = c.category_id
    LEFT JOIN inventory i ON d.dish_id = i.dish_id
    WHERE d.dish_id = :id AND d.is_active = 1
');
$dishStmt->execute([':id' => $dishId]);
$dish = $dishStmt->fetch();

if (!$dish) {
    http_response_code(404);
    $pageTitle = 'Dish Not Found - UrbanEats';
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="container" style="padding: 5rem 1rem; text-align: center;">
            <h1 style="font-size: 2rem; color: var(--text-primary); margin-bottom: 1rem;">Dish Not Available</h1>
            <p style="color: var(--text-secondary); margin-bottom: 2rem;">The selected culinary item is currently inactive or does not exist.</p>
            <a href="list.php" class="btn btn-primary">Browse Full Menu</a>
          </div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit();
}

$stock        = (int)($dish['current_stock'] ?? 0);
$reorderLevel = (int)($dish['reorder_level'] ?? 5);
$isOutOfStock = ($stock <= 0);
$isLowStock   = (!$isOutOfStock && $stock <= $reorderLevel);

// Fetch Related Dishes from same category
$relatedStmt = $pdo->prepare('
    SELECT d.dish_id, d.name, d.price, d.image_path, i.current_stock
    FROM dishes d
    LEFT JOIN inventory i ON d.dish_id = i.dish_id
    WHERE d.category_id = :cat_id AND d.dish_id != :dish_id AND d.is_active = 1
    ORDER BY RAND()
    LIMIT 3
');
$relatedStmt->execute([
    ':cat_id'  => $dish['category_id'],
    ':dish_id' => $dishId,
]);
$relatedDishes = $relatedStmt->fetchAll();

$pageTitle = htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8') . ' - UrbanEats';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 5rem;">

    <!-- Breadcrumb -->
    <nav style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: var(--text-muted); margin-bottom: 2rem;">
        <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/index.php" style="color: var(--text-muted);">Home</a>
        <span>/</span>
        <a href="list.php" style="color: var(--text-muted);">Menu</a>
        <span>/</span>
        <a href="list.php?category=<?= (int)$dish['category_id']; ?>" style="color: var(--text-muted);"><?= htmlspecialchars($dish['category_name'], ENT_QUOTES, 'UTF-8'); ?></a>
        <span>/</span>
        <span style="color: var(--primary); font-weight: 600;"><?= htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8'); ?></span>
    </nav>

    <!-- Main Dish Showcase Section -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 3rem; align-items: flex-start; margin-bottom: 4rem;">
        
        <!-- Dish Image Visualizer -->
        <div class="card" style="padding: 0; overflow: hidden; border: 1px solid var(--border-card);">
            <div style="position: relative; width: 100%; height: 420px; background: var(--bg-surface-elevated);">
                <?php if (!empty($dish['image_path'])): ?>
                    <img 
                        src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($dish['image_path'], ENT_QUOTES, 'UTF-8'); ?>" 
                        alt="<?= htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8'); ?>"
                        style="width: 100%; height: 100%; object-fit: cover;"
                    >
                <?php else: ?>
                    <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                        No Image Available
                    </div>
                <?php endif; ?>

                <!-- Category Badge Overlay -->
                <div style="position: absolute; top: 1.25rem; left: 1.25rem;">
                    <span class="role-badge badge-guest" style="background: rgba(11, 15, 25, 0.85); backdrop-filter: blur(8px);">
                        <?= htmlspecialchars($dish['category_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Dish Details & Ordering Column -->
        <div>
            <!-- Header & Badges -->
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                <?php if ($isOutOfStock): ?>
                    <span class="role-badge" style="background: var(--danger-bg); color: var(--danger); border-color: var(--danger-border); font-size: 0.825rem;">
                        Out of Stock
                    </span>
                <?php elseif ($isLowStock): ?>
                    <span class="role-badge" style="background: var(--warning-bg); color: var(--warning); border-color: var(--warning-border); font-size: 0.825rem;">
                        Low Stock: Only <?= $stock; ?> Portions Remaining
                    </span>
                <?php else: ?>
                    <span class="role-badge badge-customer" style="font-size: 0.825rem;">
                        Available in Kitchen (<?= $stock; ?> portions)
                    </span>
                <?php endif; ?>

                <span style="font-size: 0.8rem; color: var(--text-muted);">Dish ID: #<?= (int)$dish['dish_id']; ?></span>
            </div>

            <h1 style="font-size: 2.35rem; font-weight: 800; color: var(--text-primary); margin-bottom: 1rem; line-height: 1.25;">
                <?= htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8'); ?>
            </h1>

            <!-- Price Display -->
            <div style="display: flex; align-items: baseline; gap: 0.75rem; margin-bottom: 1.75rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-subtle);">
                <span style="font-size: 1.1rem; color: var(--text-muted); font-weight: 600;">LKR</span>
                <span style="font-size: 2.4rem; font-weight: 800; color: var(--primary);">
                    <?= number_format((float)$dish['price'], 2); ?>
                </span>
                <span style="font-size: 0.85rem; color: var(--text-secondary);">(inclusive of all applicable taxes)</span>
            </div>

            <!-- Description -->
            <div style="margin-bottom: 2rem;">
                <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em;">
                    Culinary Profile & Ingredients
                </h3>
                <p style="font-size: 1rem; color: var(--text-secondary); line-height: 1.7;">
                    <?= nl2br(htmlspecialchars($dish['description'] ?? 'Made freshly to order using Ceylon spices and locally sourced ingredients.', ENT_QUOTES, 'UTF-8')); ?>
                </p>
            </div>

            <!-- Add to Cart Form (Component C Integration) -->
            <div class="card" style="padding: 1.75rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-medium); margin-bottom: 2rem;">
                <?php if ($isOutOfStock): ?>
                    <div style="text-align: center; padding: 1rem 0;">
                        <div style="color: var(--danger); font-size: 1.15rem; font-weight: 700; margin-bottom: 0.35rem;">
                            Temporarily Sold Out
                        </div>
                        <p style="font-size: 0.875rem; color: var(--text-muted);">
                            Our cloud kitchen has depleted portions for this dish today. Please check back shortly or explore related menu items below.
                        </p>
                    </div>
                <?php else: ?>
                    <form action="/UrbanEats-Cloud-Kitchen-Platform/public/orders/cart.php" method="POST">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="dish_id" value="<?= (int)$dish['dish_id']; ?>">

                        <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                            <div style="width: 120px;">
                                <label for="quantity" class="form-label">Portions</label>
                                <input 
                                    type="number" 
                                    name="quantity" 
                                    id="quantity" 
                                    class="form-control" 
                                    value="1" 
                                    min="1" 
                                    max="<?= min(20, $stock); ?>"
                                    required
                                    style="text-align: center; font-size: 1.1rem; font-weight: 700;"
                                >
                            </div>

                            <div style="flex: 1; min-width: 200px;">
                                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                                    Add to Order Cart
                                </button>
                            </div>
                        </div>

                        <div style="font-size: 0.8125rem; color: var(--text-muted); margin-top: 0.85rem; display: flex; align-items: center; gap: 0.5rem;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            Prepared fresh on hot station upon order confirmation
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Dietary & Preparation Highlights -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
                <div style="padding: 1rem; border-radius: var(--radius-md); background: rgba(255,255,255,0.02); border: 1px solid var(--border-subtle);">
                    <strong style="color: var(--text-primary); font-size: 0.9rem; display: block;">Cloud Kitchen Fresh</strong>
                    <span style="font-size: 0.8rem; color: var(--text-muted);">Cooked under certified hygienic standards</span>
                </div>
                <div style="padding: 1rem; border-radius: var(--radius-md); background: rgba(255,255,255,0.02); border: 1px solid var(--border-subtle);">
                    <strong style="color: var(--text-primary); font-size: 0.9rem; display: block;">Fast Local Dispatch</strong>
                    <span style="font-size: 0.8rem; color: var(--text-muted);">Thermal insulated packaging for delivery</span>
                </div>
            </div>

        </div>

    </div>

    <!-- Related Dishes Recommendations -->
    <?php if (!empty($relatedDishes)): ?>
        <div style="margin-top: 4rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-primary); margin: 0;">
                    More from <?= htmlspecialchars($dish['category_name'], ENT_QUOTES, 'UTF-8'); ?>
                </h2>
                <a href="list.php?category=<?= (int)$dish['category_id']; ?>" class="btn btn-secondary btn-sm">
                    View All &rarr;
                </a>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
                <?php foreach ($relatedDishes as $r): ?>
                    <div class="card" style="display: flex; flex-direction: column;">
                        <div style="height: 180px; overflow: hidden; position: relative;">
                            <?php if (!empty($r['image_path'])): ?>
                                <img src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($r['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php endif; ?>
                        </div>
                        <div class="card-body" style="padding: 1.25rem;">
                            <h4 style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.35rem;">
                                <?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </h4>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem;">
                                <strong style="color: var(--primary);">LKR <?= number_format((float)$r['price'], 2); ?></strong>
                                <a href="detail.php?id=<?= (int)$r['dish_id']; ?>" class="btn btn-secondary btn-sm">View</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
