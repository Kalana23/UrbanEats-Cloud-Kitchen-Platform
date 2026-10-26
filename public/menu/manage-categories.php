<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Category Management (CRUD with Soft Delete)
 * 
 * Accessible to Kitchen Staff and Administrators.
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';

// Access Control: Staff and Admin only
require_role(['Kitchen Staff', 'Administrator', 'Admin']);

$userId   = (int)($_SESSION['user_id'] ?? 0);
$username = (string)($_SESSION['username'] ?? 'Staff');
$feedback = ['type' => '', 'message' => ''];

// -----------------------------------------------------------------------------
// Handle Category Form Submissions (Add, Edit, Soft Delete Toggle)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token()) {
        $feedback = ['type' => 'danger', 'message' => 'Security token invalid or expired. Please refresh and try again.'];
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        // 1. Create New Category
        if ($action === 'create_category') {
            $name        = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $isActive    = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name)) {
                $feedback = ['type' => 'danger', 'message' => 'Category name is required.'];
            } elseif (mb_strlen($name) > 100) {
                $feedback = ['type' => 'danger', 'message' => 'Category name cannot exceed 100 characters.'];
            } else {
                // Check uniqueness
                $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE name = :name');
                $checkStmt->execute([':name' => $name]);
                if ((int)$checkStmt->fetchColumn() > 0) {
                    $feedback = ['type' => 'danger', 'message' => "Category '{$name}' already exists."];
                } else {
                    $insertStmt = $pdo->prepare('
                        INSERT INTO categories (name, description, is_active, created_at)
                        VALUES (:name, :desc, :active, NOW())
                    ');
                    $insertStmt->execute([
                        ':name'   => $name,
                        ':desc'   => !empty($description) ? $description : null,
                        ':active' => $isActive,
                    ]);

                    $newCatId = (int)$pdo->lastInsertId();
                    log_action(
                        $pdo,
                        $userId,
                        'CATEGORY_CREATE',
                        sprintf("Created food category '%s' (ID #%d).", $name, $newCatId)
                    );

                    $feedback = ['type' => 'success', 'message' => "Category '{$name}' created successfully."];
                }
            }
        }

        // 2. Update Existing Category
        elseif ($action === 'update_category') {
            $catId       = (int)($_POST['category_id'] ?? 0);
            $name        = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $isActive    = isset($_POST['is_active']) ? 1 : 0;

            if ($catId <= 0 || empty($name)) {
                $feedback = ['type' => 'danger', 'message' => 'Valid Category ID and Name are required.'];
            } elseif (mb_strlen($name) > 100) {
                $feedback = ['type' => 'danger', 'message' => 'Category name cannot exceed 100 characters.'];
            } else {
                // Check uniqueness ignoring current
                $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE name = :name AND category_id != :id');
                $checkStmt->execute([':name' => $name, ':id' => $catId]);
                if ((int)$checkStmt->fetchColumn() > 0) {
                    $feedback = ['type' => 'danger', 'message' => "Another category with the name '{$name}' already exists."];
                } else {
                    $updateStmt = $pdo->prepare('
                        UPDATE categories 
                        SET name = :name, description = :desc, is_active = :active 
                        WHERE category_id = :id
                    ');
                    $updateStmt->execute([
                        ':name'   => $name,
                        ':desc'   => !empty($description) ? $description : null,
                        ':active' => $isActive,
                        ':id'     => $catId,
                    ]);

                    log_action(
                        $pdo,
                        $userId,
                        'CATEGORY_UPDATE',
                        sprintf("Updated category details for '%s' (ID #%d). Status: %s.", $name, $catId, $isActive ? 'Active' : 'Inactive')
                    );

                    $feedback = ['type' => 'success', 'message' => "Category '{$name}' updated successfully."];
                }
            }
        }

        // 3. Soft Delete / Toggle Active Status
        elseif ($action === 'toggle_status') {
            $catId = (int)($_POST['category_id'] ?? 0);
            if ($catId > 0) {
                $fetchStmt = $pdo->prepare('SELECT name, is_active FROM categories WHERE category_id = :id');
                $fetchStmt->execute([':id' => $catId]);
                $cat = $fetchStmt->fetch();

                if ($cat) {
                    $newStatus = $cat['is_active'] ? 0 : 1;
                    $toggleStmt = $pdo->prepare('UPDATE categories SET is_active = :status WHERE category_id = :id');
                    $toggleStmt->execute([':status' => $newStatus, ':id' => $catId]);

                    $statusWord = $newStatus ? 'activated' : 'soft-deleted (deactivated)';
                    log_action(
                        $pdo,
                        $userId,
                        'CATEGORY_STATUS_TOGGLE',
                        sprintf("Category '%s' (ID #%d) was %s.", $cat['name'], $catId, $statusWord)
                    );

                    $feedback = ['type' => 'success', 'message' => "Category '{$cat['name']}' has been {$statusWord}."];
                }
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Fetch Categories with Dish Counts
// -----------------------------------------------------------------------------
$categoriesStmt = $pdo->query('
    SELECT c.category_id, c.name, c.description, c.is_active, c.created_at,
           COUNT(d.dish_id) AS total_dishes,
           SUM(CASE WHEN d.is_active = 1 THEN 1 ELSE 0 END) AS active_dishes
    FROM categories c
    LEFT JOIN dishes d ON c.category_id = d.category_id
    GROUP BY c.category_id
    ORDER BY c.name ASC
');
$categories = $categoriesStmt->fetchAll();

// Determine if we are editing a category
$editCat = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($categories as $c) {
        if ((int)$c['category_id'] === $editId) {
            $editCat = $c;
            break;
        }
    }
}

$pageTitle = 'Manage Food Categories';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 4rem;">
    
    <!-- Breadcrumb & Top Bar -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.35rem;">
                <span class="role-badge badge-staff">Component B: Catalogue</span>
                <span style="color: var(--text-muted); font-size: 0.85rem;">Menu Category Organization</span>
            </div>
            <h1 style="font-size: 2rem; font-weight: 800; color: var(--text-primary); margin: 0;">Food Categories Management</h1>
            <p style="color: var(--text-secondary); margin-top: 0.35rem; font-size: 0.95rem;">
                Create, modify, and soft-delete food categories. Soft-deleted categories preserve historic order associations.
            </p>
        </div>

        <div style="display: flex; gap: 0.75rem;">
            <a href="stock.php" class="btn btn-secondary btn-sm">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
                Stock Dashboard
            </a>
            <a href="manage-dishes.php" class="btn btn-secondary btn-sm">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                Manage Dishes
            </a>
            <a href="list.php" class="btn btn-primary btn-sm">
                Public Menu View &rarr;
            </a>
        </div>
    </div>

    <!-- Alert / Feedback -->
    <?php if (!empty($feedback['message'])): ?>
        <div class="alert alert-<?= htmlspecialchars($feedback['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars($feedback['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr; gap: 2rem;">
        
        <!-- Category Add / Edit Form -->
        <div class="card" id="categoryFormCard">
            <div class="card-header" style="background: rgba(255, 255, 255, 0.02);">
                <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    <?= $editCat ? 'Edit Category: ' . htmlspecialchars($editCat['name'], ENT_QUOTES, 'UTF-8') : 'Create New Food Category'; ?>
                </h2>
                <?php if ($editCat): ?>
                    <a href="manage-categories.php" class="btn btn-secondary btn-sm">Cancel Edit</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form action="manage-categories.php" method="POST">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="<?= $editCat ? 'update_category' : 'create_category'; ?>">
                    <?php if ($editCat): ?>
                        <input type="hidden" name="category_id" value="<?= (int)$editCat['category_id']; ?>">
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
                        <div class="form-group">
                            <label for="name" class="form-label">Category Name <span class="required">*</span></label>
                            <input 
                                type="text" 
                                name="name" 
                                id="name" 
                                class="form-control" 
                                placeholder="e.g. Seafood Specialties" 
                                value="<?= htmlspecialchars($editCat['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                required 
                                maxlength="100"
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status & Visibility</label>
                            <div style="display: flex; align-items: center; gap: 0.65rem; height: 48px;">
                                <input 
                                    type="checkbox" 
                                    name="is_active" 
                                    id="is_active" 
                                    value="1" 
                                    <?= ($editCat === null || !empty($editCat['is_active'])) ? 'checked' : ''; ?>
                                    style="width: 18px; height: 18px; accent-color: var(--primary);"
                                >
                                <label for="is_active" style="color: var(--text-primary); cursor: pointer; font-size: 0.925rem;">
                                    Active (Visible in public catalog and search filters)
                                </label>
                            </div>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="description" class="form-label">Description (Optional)</label>
                            <textarea 
                                name="description" 
                                id="description" 
                                class="form-control" 
                                placeholder="Brief overview of dishes and ingredients within this category..."
                                rows="3"
                            ><?= htmlspecialchars($editCat['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <?= $editCat ? 'Update Category' : 'Save New Category'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Categories Table -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0;">Existing Categories (<?= count($categories); ?>)</h2>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                        Active categories are featured in menu filters; inactive ones are preserved for historical order integrity.
                    </p>
                </div>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Category Name</th>
                                <th>Description</th>
                                <th>Dishes</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--text-primary); font-size: 0.95rem;"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <div style="font-size: 0.785rem; color: var(--text-muted);">ID: #<?= (int)$cat['category_id']; ?></div>
                                    </td>
                                    <td style="max-width: 320px; font-size: 0.85rem; color: var(--text-secondary);">
                                        <?= htmlspecialchars($cat['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td>
                                        <span style="font-weight: 700; color: var(--text-primary);"><?= (int)$cat['active_dishes']; ?></span>
                                        <span style="font-size: 0.8rem; color: var(--text-muted);">/ <?= (int)$cat['total_dishes']; ?> total</span>
                                    </td>
                                    <td>
                                        <?php if (!empty($cat['is_active'])): ?>
                                            <span class="role-badge badge-customer">Active</span>
                                        <?php else: ?>
                                            <span class="role-badge" style="background: var(--danger-bg); color: var(--danger); border-color: var(--danger-border);">Soft-Deleted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                                            <a href="manage-categories.php?edit=<?= (int)$cat['category_id']; ?>#categoryFormCard" class="btn btn-secondary btn-sm" title="Edit Category">
                                                Edit
                                            </a>
                                            
                                            <form action="manage-categories.php" method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to change the status of this category?');">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="category_id" value="<?= (int)$cat['category_id']; ?>">
                                                <?php if (!empty($cat['is_active'])): ?>
                                                    <button type="submit" class="btn btn-danger btn-sm" title="Deactivate / Soft Delete">
                                                        Soft Delete
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-outline btn-sm" title="Restore / Activate">
                                                        Activate
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
