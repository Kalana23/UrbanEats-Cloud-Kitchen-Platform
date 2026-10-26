<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Landing Page & Featured Menu Showcase
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// -----------------------------------------------------------------------------
// Fetch Featured Categories & Dishes
// -----------------------------------------------------------------------------

// Active Categories
$categoriesStmt = $pdo->query('
    SELECT c.category_id, c.name, c.description, COUNT(d.dish_id) AS dish_count
    FROM categories c
    LEFT JOIN dishes d ON c.category_id = d.category_id AND d.is_active = 1
    WHERE c.is_active = 1
    GROUP BY c.category_id
    ORDER BY c.category_id ASC
');
$categories = $categoriesStmt->fetchAll();

// 6 Chef Highlight Dishes with Inventory
$highlightsStmt = $pdo->query('
    SELECT d.dish_id, d.name, d.description, d.price, d.image_path,
           c.name AS category_name, i.current_stock, i.reorder_level
    FROM dishes d
    JOIN categories c ON d.category_id = c.category_id
    LEFT JOIN inventory i ON d.dish_id = i.dish_id
    WHERE d.is_active = 1 AND c.is_active = 1
    ORDER BY d.price DESC
    LIMIT 6
');
$highlightDishes = $highlightsStmt->fetchAll();

// Category image map for visual cards
$categoryImages = [
    1 => 'uploads/cheese kottu.jpg',
    2 => 'uploads/chicken biriyani.jpg',
    3 => 'uploads/chicken rice and curry.webp',
    4 => 'uploads/chicken devilled.webp',
    5 => 'uploads/chicken roll.jpg',
    6 => 'uploads/faluda.jpg',
];

$pageTitle = 'Artisanal Cloud Kitchen & Gourmet Ordering';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top: 2.5rem; padding-bottom: 5rem;">

    <!-- Hero Section -->
    <section class="card" style="margin-bottom: 4rem; background: radial-gradient(circle at 80% 20%, rgba(249, 115, 22, 0.22), transparent 55%), radial-gradient(circle at 20% 80%, rgba(6, 182, 212, 0.15), transparent 50%), var(--bg-surface); border: 1px solid var(--border-card); overflow: hidden;">
        <div class="card-body" style="padding: 4rem 2.5rem; position: relative;">
            <div style="max-width: 680px;">
                <div style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0.85rem; border-radius: var(--radius-pill); background: var(--primary-subtle); border: 1px solid rgba(249, 115, 22, 0.3); color: var(--primary); font-size: 0.85rem; font-weight: 700; margin-bottom: 1.25rem;">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--primary); animation: pulse 2s infinite;"></span>
                    Sri Lanka's Premier Cloud Kitchen
                </div>

                <h1 style="font-size: 3.25rem; font-weight: 800; color: var(--text-primary); line-height: 1.15; margin-bottom: 1.25rem; letter-spacing: -0.02em;">
                    Sizzling Kottu, Royal Biriyani & Authentic Ceylon Flavors
                </h1>

                <p style="font-size: 1.15rem; color: var(--text-secondary); line-height: 1.65; margin-bottom: 2.25rem;">
                    Zero dining room compromises. Every dish is master-crafted exclusively for speedy delivery and fresh counter pickup with real-time portion inventory tracking.
                </p>

                <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                    <a href="menu/list.php" class="btn btn-primary btn-lg">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                        Explore Complete Menu
                    </a>
                    <?php if (!is_logged_in()): ?>
                        <a href="register.php" class="btn btn-secondary btn-lg">
                            Register Account
                        </a>
                    <?php else: ?>
                        <a href="profile.php" class="btn btn-secondary btn-lg">
                            My Account Profile
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Operational Highlights -->
                <div style="display: flex; gap: 2rem; margin-top: 3rem; padding-top: 2rem; border-top: 1px solid var(--border-subtle); flex-wrap: wrap;">
                    <div>
                        <strong style="display: block; font-size: 1.5rem; color: var(--text-primary); font-weight: 800;">22+</strong>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Gourmet Recipes</span>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 1.5rem; color: var(--text-primary); font-weight: 800;">6</strong>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Food Categories</span>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 1.5rem; color: var(--text-primary); font-weight: 800;">100%</strong>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Live Stock Guard</span>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 1.5rem; color: var(--text-primary); font-weight: 800;">LKR</strong>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Transparent Pricing</span>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- Culinary Categories Grid -->
    <section style="margin-bottom: 5rem;">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span class="role-badge badge-customer" style="margin-bottom: 0.5rem;">Explore Catalog</span>
                <h2 style="font-size: 2rem; font-weight: 800; color: var(--text-primary); margin: 0;">Browse Food Categories</h2>
            </div>
            <a href="menu/list.php" class="btn btn-secondary btn-sm">View All Dishes &rarr;</a>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
            <?php foreach ($categories as $cat): ?>
                <?php $catImg = $categoryImages[(int)$cat['category_id']] ?? 'uploads/beverages.webp'; ?>
                <a href="menu/list.php?category=<?= (int)$cat['category_id']; ?>" class="card" style="text-decoration: none; display: flex; flex-direction: column; overflow: hidden; transition: transform var(--transition-base), border-color var(--transition-base);">
                    <div style="height: 180px; position: relative; background: var(--bg-surface-elevated); overflow: hidden;">
                        <img 
                            src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($catImg, ENT_QUOTES, 'UTF-8'); ?>" 
                            alt="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            style="width: 100%; height: 100%; object-fit: cover;"
                        >
                        <div style="position: absolute; inset: 0; background: linear-gradient(to top, rgba(18, 24, 38, 0.95), transparent 70%);"></div>
                        <div style="position: absolute; bottom: 1rem; left: 1.25rem;">
                            <span class="role-badge badge-staff" style="font-size: 0.75rem;">
                                <?= (int)$cat['dish_count']; ?> Recipes Available
                            </span>
                        </div>
                    </div>
                    <div class="card-body" style="padding: 1.25rem;">
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.35rem;">
                            <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </h3>
                        <p style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.5; margin: 0;">
                            <?= htmlspecialchars($cat['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Chef's Highlights -->
    <section style="margin-bottom: 5rem;">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span class="role-badge badge-admin" style="margin-bottom: 0.5rem;">Signature Selections</span>
                <h2 style="font-size: 2rem; font-weight: 800; color: var(--text-primary); margin: 0;">Chef's Special Highlights</h2>
            </div>
            <a href="menu/list.php?sort=price_desc" class="btn btn-secondary btn-sm">Full Premium Range &rarr;</a>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.75rem;">
            <?php foreach ($highlightDishes as $dish): ?>
                <?php
                    $stock = (int)($dish['current_stock'] ?? 0);
                    $reorder = (int)($dish['reorder_level'] ?? 5);
                    $isOutOfStock = ($stock <= 0);
                    $isLowStock   = (!$isOutOfStock && $stock <= $reorder);
                ?>
                <div class="card" style="display: flex; flex-direction: column;">
                    <div style="position: relative; height: 210px; overflow: hidden; background: var(--bg-surface-elevated);">
                        <?php if (!empty($dish['image_path'])): ?>
                            <img src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($dish['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php endif; ?>
                        
                        <div style="position: absolute; top: 1rem; right: 1rem;">
                            <?php if ($isOutOfStock): ?>
                                <span class="role-badge" style="background: rgba(244, 63, 94, 0.9); color: #ffffff;">Sold Out</span>
                            <?php elseif ($isLowStock): ?>
                                <span class="role-badge" style="background: rgba(245, 158, 11, 0.9); color: #ffffff;">Only <?= $stock; ?> Left</span>
                            <?php else: ?>
                                <span class="role-badge" style="background: rgba(16, 185, 129, 0.9); color: #ffffff;">In Stock</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-body" style="padding: 1.5rem; display: flex; flex-direction: column; flex: 1;">
                        <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 0.25rem;">
                            <?= htmlspecialchars($dish['category_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                            <a href="menu/detail.php?id=<?= (int)$dish['dish_id']; ?>" style="color: inherit; text-decoration: none;">
                                <?= htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </h3>
                        <p style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.5; margin-bottom: 1.25rem; flex: 1; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                            <?= htmlspecialchars($dish['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </p>

                        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 1rem; border-top: 1px solid var(--border-subtle);">
                            <strong style="font-size: 1.25rem; font-weight: 800; color: var(--primary);">
                                LKR <?= number_format((float)$dish['price'], 2); ?>
                            </strong>
                            <a href="menu/detail.php?id=<?= (int)$dish['dish_id']; ?>" class="btn btn-primary btn-sm">
                                View Details &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Cloud Kitchen Value Pillars -->
    <section class="card" style="background: var(--bg-surface-elevated); padding: 3rem 2rem;">
        <div style="text-align: center; max-width: 600px; margin: 0 auto 3rem;">
            <span class="role-badge badge-staff">Why UrbanEats</span>
            <h2 style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary); margin-top: 0.5rem;">Engineered for Cloud Kitchen Excellence</h2>
            <p style="color: var(--text-secondary); font-size: 0.95rem;">Solving real-world cloud kitchen bottlenecks through synchronized inventory and automated order pipelines.</p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 2rem;">
            <div style="padding: 1.5rem; border-radius: var(--radius-lg); background: rgba(0,0,0,0.2); border: 1px solid var(--border-subtle);">
                <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                </div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Real-Time Stock Depletion</h3>
                <p style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.6;">
                    Transaction-safe stock reduction locks prevent customers from ordering ingredients that have run out during high-volume rushes.
                </p>
            </div>

            <div style="padding: 1.5rem; border-radius: var(--radius-lg); background: rgba(0,0,0,0.2); border: 1px solid var(--border-subtle);">
                <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: rgba(6, 182, 212, 0.15); color: var(--accent); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                </div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Fast Hot Delivery Dispatch</h3>
                <p style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.6;">
                    Direct kitchen station dispatch streamlines packaging and ensures food reaches your doorstep at peak temperature.
                </p>
            </div>

            <div style="padding: 1.5rem; border-radius: var(--radius-lg); background: rgba(0,0,0,0.2); border: 1px solid var(--border-subtle);">
                <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: rgba(16, 185, 129, 0.15); color: var(--success); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                </div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Verified Staff Attribution</h3>
                <p style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.6;">
                    Every restock, food wastage log, and order deduction is cryptographically tracked in staff audit logs for accountability.
                </p>
            </div>
        </div>
    </section>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
