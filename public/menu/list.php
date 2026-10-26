<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Public Menu & Dish Catalogue
 * 
 * Features:
 * - Category filter tabs
 * - Full-text keyword search
 * - Whitelisted sorting (Price Low/High, Name A-Z/Z-A)
 * - Server-side pagination with query preservation
 * - Real-time stock status badges
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';

// -----------------------------------------------------------------------------
// Validated & Whitelisted GET Parameters
// -----------------------------------------------------------------------------
$categoryId   = isset($_GET['category']) ? max(0, (int)$_GET['category']) : 0;
$searchQuery  = trim((string)($_GET['search'] ?? ''));
$sortParam    = trim((string)($_GET['sort'] ?? 'name_asc'));
$inStockOnly  = isset($_GET['in_stock']) && $_GET['in_stock'] === '1';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 9; // 9 dishes per page for 3x3 responsive grid

// Whitelist allowed sort orders to strictly prevent SQL Injection
$allowedSorts = [
    'price_asc'  => 'd.price ASC',
    'price_desc' => 'd.price DESC',
    'name_asc'   => 'd.name ASC',
    'name_desc'  => 'd.name DESC',
    'stock_desc' => 'i.current_stock DESC',
];
$orderBySql = $allowedSorts[$sortParam] ?? 'd.name ASC';

// -----------------------------------------------------------------------------
// Fetch Active Categories for Filter Tabs
// -----------------------------------------------------------------------------
$categoriesStmt = $pdo->query('
    SELECT c.category_id, c.name, COUNT(d.dish_id) AS total_active_dishes
    FROM categories c
    LEFT JOIN dishes d ON c.category_id = d.category_id AND d.is_active = 1
    WHERE c.is_active = 1
    GROUP BY c.category_id
    ORDER BY c.name ASC
');
$categories = $categoriesStmt->fetchAll();

// -----------------------------------------------------------------------------
// Build Filter SQL Query
// -----------------------------------------------------------------------------
$whereClauses = ['d.is_active = 1', 'c.is_active = 1'];
$params = [];

if ($categoryId > 0) {
    $whereClauses[] = 'd.category_id = :cat_id';
    $params[':cat_id'] = $categoryId;
}

if (!empty($searchQuery)) {
    $whereClauses[] = '(d.name LIKE :search OR d.description LIKE :search_desc)';
    $params[':search']      = '%' . $searchQuery . '%';
    $params[':search_desc'] = '%' . $searchQuery . '%';
}

if ($inStockOnly) {
    $whereClauses[] = 'i.current_stock > 0';
}

$whereSql = implode(' AND ', $whereClauses);

// 1. Get Total Count for Pagination
$countSql = "
    SELECT COUNT(*) 
    FROM dishes d
    JOIN categories c ON d.category_id = c.category_id
    LEFT JOIN inventory i ON d.dish_id = i.dish_id
    WHERE {$whereSql}
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalDishes = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalDishes / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// 2. Fetch Paginated Dishes
$dishesSql = "
    SELECT d.dish_id, d.name, d.description, d.price, d.image_path, d.created_at,
           c.category_id, c.name AS category_name,
           i.current_stock, i.reorder_level
    FROM dishes d
    JOIN categories c ON d.category_id = c.category_id
    LEFT JOIN inventory i ON d.dish_id = i.dish_id
    WHERE {$whereSql}
    ORDER BY {$orderBySql}
    LIMIT :offset, :per_page
";
$dishesStmt = $pdo->prepare($dishesSql);
foreach ($params as $k => $v) {
    $dishesStmt->bindValue($k, $v);
}
$dishesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dishesStmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);
$dishesStmt->execute();
$dishes = $dishesStmt->fetchAll();

// Helper to preserve active query string parameters during pagination
function build_menu_url(array $overrides = []): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return 'list.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

$pageTitle = 'Gourmet Cloud Kitchen Menu';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 5rem;">

    <!-- Menu Hero Banner -->
    <div class="card" style="margin-bottom: 2.5rem; background: radial-gradient(circle at top right, rgba(249, 115, 22, 0.18), transparent 60%), var(--bg-surface); border: 1px solid var(--border-card);">
        <div class="card-body" style="padding: 2.5rem 2rem;">
            <div style="max-width: 720px;">
                <span class="role-badge badge-staff" style="margin-bottom: 0.75rem;">Ceylon Gourmet Cloud Kitchen</span>
                <h1 style="font-size: 2.5rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; margin-bottom: 0.75rem;">
                    Artisanal Sri Lankan Cloud Kitchen Menu
                </h1>
                <p style="font-size: 1.05rem; color: var(--text-secondary); line-height: 1.6; margin-bottom: 1.5rem;">
                    Freshly prepared on demand. Explore sizzling kottu varieties, slow-cooked basmati biriyanis, traditional clay-pot curries, and crispy Ceylon short eats.
                </p>

                <!-- Search and Filter Bar Form -->
                <form action="list.php" method="GET" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <?php if ($categoryId > 0): ?>
                        <input type="hidden" name="category" value="<?= (int)$categoryId; ?>">
                    <?php endif; ?>
                    
                    <div style="flex: 1; min-width: 240px; position: relative;">
                        <input 
                            type="text" 
                            name="search" 
                            class="form-control" 
                            placeholder="Search dishes by name or ingredients..." 
                            value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                        >
                    </div>

                    <div style="width: 180px;">
                        <select name="sort" class="form-control" onchange="this.form.submit()">
                            <option value="name_asc" <?= $sortParam === 'name_asc' ? 'selected' : ''; ?>>Name: A to Z</option>
                            <option value="name_desc" <?= $sortParam === 'name_desc' ? 'selected' : ''; ?>>Name: Z to A</option>
                            <option value="price_asc" <?= $sortParam === 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
                            <option value="price_desc" <?= $sortParam === 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
                            <option value="stock_desc" <?= $sortParam === 'stock_desc' ? 'selected' : ''; ?>>Highest In-Stock</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        Search
                    </button>

                    <?php if (!empty($searchQuery) || $categoryId > 0 || $sortParam !== 'name_asc'): ?>
                        <a href="list.php" class="btn btn-secondary">Clear Filters</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Category Filter Tabs -->
    <div style="display: flex; gap: 0.65rem; overflow-x: auto; padding-bottom: 1rem; margin-bottom: 2rem;">
        <a 
            href="<?= htmlspecialchars(build_menu_url(['category' => null, 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>" 
            class="btn <?= $categoryId === 0 ? 'btn-primary' : 'btn-secondary'; ?> btn-sm"
            style="border-radius: var(--radius-pill); white-space: nowrap;"
        >
            All Dishes (<?= $totalDishes; ?>)
        </a>
        <?php foreach ($categories as $cat): ?>
            <a 
                href="<?= htmlspecialchars(build_menu_url(['category' => (int)$cat['category_id'], 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>" 
                class="btn <?= $categoryId === (int)$cat['category_id'] ? 'btn-primary' : 'btn-secondary'; ?> btn-sm"
                style="border-radius: var(--radius-pill); white-space: nowrap;"
            >
                <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>
                <span style="opacity: 0.75; font-size: 0.8em; margin-left: 0.35rem;">(<?= (int)$cat['total_active_dishes']; ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Active Filters Feedback & Count -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div style="color: var(--text-secondary); font-size: 0.95rem;">
            Showing <strong style="color: var(--text-primary);"><?= count($dishes); ?></strong> of <strong style="color: var(--text-primary);"><?= $totalDishes; ?></strong> dishes
            <?php if (!empty($searchQuery)): ?>
                matching "<strong style="color: var(--primary);"><?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?></strong>"
            <?php endif; ?>
        </div>

        <div>
            <label style="display: inline-flex; align-items: center; gap: 0.5rem; color: var(--text-secondary); font-size: 0.875rem; cursor: pointer;">
                <input 
                    type="checkbox" 
                    <?= $inStockOnly ? 'checked' : ''; ?> 
                    onchange="location.href = '<?= htmlspecialchars(build_menu_url(['in_stock' => $inStockOnly ? null : '1', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>'"
                    style="accent-color: var(--primary);"
                >
                Show Available Only
            </label>
        </div>
    </div>

    <!-- Dishes 3x3 Responsive Grid -->
    <?php if (empty($dishes)): ?>
        <div class="card" style="text-align: center; padding: 4rem 2rem;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" style="margin-bottom: 1rem;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <h3 style="font-size: 1.35rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">No Dishes Found</h3>
            <p style="color: var(--text-muted); max-width: 480px; margin: 0 auto 1.5rem;">
                No culinary items match your search or filter settings. Try adjusting keywords or category selection.
            </p>
            <a href="list.php" class="btn btn-secondary">Reset Menu View</a>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.75rem;">
            <?php foreach ($dishes as $dish): ?>
                <?php
                    $stock = (int)($dish['current_stock'] ?? 0);
                    $reorder = (int)($dish['reorder_level'] ?? 5);
                    $isOutOfStock = ($stock <= 0);
                    $isLowStock   = (!$isOutOfStock && $stock <= $reorder);
                ?>
                <div class="card" style="display: flex; flex-direction: column; height: 100%; transition: transform var(--transition-base), box-shadow var(--transition-base);">
                    
                    <!-- Dish Image & Stock Badge -->
                    <div style="position: relative; width: 100%; height: 210px; overflow: hidden; background: var(--bg-surface-elevated);">
                        <?php if (!empty($dish['image_path'])): ?>
                            <img 
                                src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($dish['image_path'], ENT_QUOTES, 'UTF-8'); ?>" 
                                alt="<?= htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease;"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                                No Image Available
                            </div>
                        <?php endif; ?>

                        <!-- Availability Badge -->
                        <div style="position: absolute; top: 1rem; right: 1rem;">
                            <?php if ($isOutOfStock): ?>
                                <span class="role-badge" style="background: rgba(244, 63, 94, 0.9); color: #ffffff; backdrop-filter: blur(8px);">Sold Out</span>
                            <?php elseif ($isLowStock): ?>
                                <span class="role-badge" style="background: rgba(245, 158, 11, 0.9); color: #ffffff; backdrop-filter: blur(8px);">Only <?= $stock; ?> Left</span>
                            <?php else: ?>
                                <span class="role-badge" style="background: rgba(16, 185, 129, 0.9); color: #ffffff; backdrop-filter: blur(8px);">In Stock</span>
                            <?php endif; ?>
                        </div>

                        <!-- Category Overlay -->
                        <div style="position: absolute; bottom: 0.75rem; left: 0.75rem;">
                            <span class="role-badge badge-guest" style="background: rgba(11, 15, 25, 0.85); backdrop-filter: blur(8px); border-color: rgba(255,255,255,0.15);">
                                <?= htmlspecialchars($dish['category_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Dish Information Body -->
                    <div class="card-body" style="display: flex; flex-direction: column; flex: 1; padding: 1.5rem;">
                        <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem; line-height: 1.35;">
                            <a href="detail.php?id=<?= (int)$dish['dish_id']; ?>" style="color: inherit; text-decoration: none;">
                                <?= htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </h3>

                        <p style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.5; margin-bottom: 1.25rem; flex: 1; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                            <?= htmlspecialchars($dish['description'] ?? 'Authentic freshly prepared specialty.', ENT_QUOTES, 'UTF-8'); ?>
                        </p>

                        <!-- Price and Order Row -->
                        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 1rem; border-top: 1px solid var(--border-subtle);">
                            <div>
                                <span style="font-size: 0.75rem; color: var(--text-muted); display: block; text-transform: uppercase;">Price</span>
                                <strong style="font-size: 1.25rem; font-weight: 800; color: var(--primary);">
                                    LKR <?= number_format((float)$dish['price'], 2); ?>
                                </strong>
                            </div>

                            <div style="display: flex; gap: 0.5rem;">
                                <a href="detail.php?id=<?= (int)$dish['dish_id']; ?>" class="btn btn-secondary btn-sm" title="View Ingredients & Nutrition">
                                    Details
                                </a>

                                <?php if ($isOutOfStock): ?>
                                    <button type="button" class="btn btn-secondary btn-sm" disabled style="opacity: 0.5; cursor: not-allowed;">
                                        Unavailable
                                    </button>
                                <?php else: ?>
                                    <form action="/UrbanEats-Cloud-Kitchen-Platform/public/orders/cart.php" method="POST" style="margin: 0;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="dish_id" value="<?= (int)$dish['dish_id']; ?>">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="btn btn-primary btn-sm" title="Add to Order Cart">
                                            + Add
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>

                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top: 3.5rem; flex-wrap: wrap;">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars(build_menu_url(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary btn-sm">
                        &larr; Prev
                    </a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a 
                        href="<?= htmlspecialchars(build_menu_url(['page' => $p]), ENT_QUOTES, 'UTF-8'); ?>" 
                        class="btn <?= $p === $page ? 'btn-primary' : 'btn-secondary'; ?> btn-sm"
                        style="min-width: 38px;"
                    >
                        <?= $p; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars(build_menu_url(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary btn-sm">
                        Next &rarr;
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
