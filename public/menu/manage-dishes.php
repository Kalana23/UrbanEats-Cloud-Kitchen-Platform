<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Staff Dish Management (Full CRUD + Secure Image Upload + Soft Delete)
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

// Ensure upload directory exists
$uploadDirPublic = dirname(__DIR__) . '/uploads';
$uploadDirRoot   = dirname(__DIR__, 2) . '/uploads';

if (!is_dir($uploadDirPublic)) {
    @mkdir($uploadDirPublic, 0755, true);
}
if (!is_dir($uploadDirRoot)) {
    @mkdir($uploadDirRoot, 0755, true);
}

// -----------------------------------------------------------------------------
// Image Upload Processing Function
// -----------------------------------------------------------------------------
function handle_dish_image_upload(array $file, array &$feedback): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // No new file submitted
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $feedback = ['type' => 'danger', 'message' => 'Image upload error code: ' . $file['error']];
        return null;
    }

    // 1. Enforce max file size: 2MB (2 * 1024 * 1024 bytes)
    $maxBytes = 2 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        $feedback = ['type' => 'danger', 'message' => 'Image file is too large. Maximum allowed size is 2MB.'];
        return null;
    }

    // 2. Validate file extension
    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($extension, $allowedExtensions, true)) {
        $feedback = ['type' => 'danger', 'message' => 'Invalid image format. Allowed formats: JPG, JPEG, PNG, WEBP.'];
        return null;
    }

    // 3. Verify actual MIME type using Fileinfo
    $tmpPath = (string)($file['tmp_name'] ?? '');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $tmpPath) : mime_content_type($tmpPath);
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowedMimes = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/webp',
    ];

    if (!in_array($mimeType, $allowedMimes, true)) {
        $feedback = ['type' => 'danger', 'message' => 'Security validation failed: File MIME type is not an authentic image (' . htmlspecialchars((string)$mimeType) . ').'];
        return null;
    }

    // 4. Generate cryptographically random filename
    $normalizedExt = ($extension === 'jpeg') ? 'jpg' : $extension;
    $newFileName = 'dish_' . bin2hex(random_bytes(10)) . '.' . $normalizedExt;

    $targetPublic = dirname(__DIR__) . '/uploads/' . $newFileName;
    $targetRoot   = dirname(__DIR__, 2) . '/uploads/' . $newFileName;

    if (!move_uploaded_file($tmpPath, $targetPublic)) {
        $feedback = ['type' => 'danger', 'message' => 'Failed to save uploaded image to destination directory.'];
        return null;
    }

    // Also mirror to root uploads/ for complete consistency
    @copy($targetPublic, $targetRoot);

    // Stored relative path in database
    return 'uploads/' . $newFileName;
}

// -----------------------------------------------------------------------------
// Handle POST Actions: Add Dish, Edit Dish, Soft Delete
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token()) {
        $feedback = ['type' => 'danger', 'message' => 'Security token invalid or expired. Please refresh and try again.'];
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        // 1. CREATE DISH
        if ($action === 'create_dish') {
            $name         = trim((string)($_POST['name'] ?? ''));
            $categoryId   = (int)($_POST['category_id'] ?? 0);
            $description  = trim((string)($_POST['description'] ?? ''));
            $price        = (float)($_POST['price'] ?? 0.0);
            $initialStock = max(0, (int)($_POST['initial_stock'] ?? 0));
            $reorderLevel = max(1, (int)($_POST['reorder_level'] ?? 5));
            $isActive     = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name) || $categoryId <= 0 || $price <= 0.0) {
                $feedback = ['type' => 'danger', 'message' => 'Please provide a valid dish name, category, and positive price in LKR.'];
            } else {
                // Handle optional image upload
                $uploadedImagePath = null;
                if (!empty($_FILES['image']['name'])) {
                    $uploadedImagePath = handle_dish_image_upload($_FILES['image'], $feedback);
                    if ($uploadedImagePath === null && !empty($feedback['message'])) {
                        // Image validation failed, abort
                        goto end_post;
                    }
                }

                // If no image uploaded, provide a default or placeholder
                if ($uploadedImagePath === null) {
                    $uploadedImagePath = 'uploads/beverages.webp';
                }

                try {
                    $pdo->beginTransaction();

                    $insertDishStmt = $pdo->prepare('
                        INSERT INTO dishes (category_id, name, description, price, image_path, is_active, created_at)
                        VALUES (:cat_id, :name, :desc, :price, :img, :active, NOW())
                    ');
                    $insertDishStmt->execute([
                        ':cat_id' => $categoryId,
                        ':name'   => $name,
                        ':desc'   => !empty($description) ? $description : null,
                        ':price'  => $price,
                        ':img'    => $uploadedImagePath,
                        ':active' => $isActive,
                    ]);

                    $dishId = (int)$pdo->lastInsertId();

                    // Create corresponding inventory row
                    $insertInvStmt = $pdo->prepare('
                        INSERT INTO inventory (dish_id, current_stock, reorder_level)
                        VALUES (:dish_id, :stock, :reorder)
                    ');
                    $insertInvStmt->execute([
                        ':dish_id' => $dishId,
                        ':stock'   => $initialStock,
                        ':reorder' => $reorderLevel,
                    ]);

                    // If initial stock was provided, log as movement
                    if ($initialStock > 0) {
                        $movementStmt = $pdo->prepare('
                            INSERT INTO stock_movements (dish_id, user_id, change_qty, movement_type, reason, created_at)
                            VALUES (:dish_id, :user_id, :qty, "restock", "Initial dish stock allocation", NOW())
                        ');
                        $movementStmt->execute([
                            ':dish_id' => $dishId,
                            ':user_id' => $userId,
                            ':qty'     => $initialStock,
                        ]);
                    }

                    $pdo->commit();

                    log_action(
                        $pdo,
                        $userId,
                        'DISH_CREATE',
                        sprintf("Created new dish '%s' (ID #%d) at LKR %.2f with initial stock %d.", $name, $dishId, $price, $initialStock)
                    );

                    $feedback = ['type' => 'success', 'message' => "Dish '{$name}' created successfully with inventory tracking."];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $feedback = ['type' => 'danger', 'message' => 'Failed to create dish: ' . $e->getMessage()];
                }
            }
        }

        // 2. UPDATE DISH
        elseif ($action === 'update_dish') {
            $dishId       = (int)($_POST['dish_id'] ?? 0);
            $name         = trim((string)($_POST['name'] ?? ''));
            $categoryId   = (int)($_POST['category_id'] ?? 0);
            $description  = trim((string)($_POST['description'] ?? ''));
            $price        = (float)($_POST['price'] ?? 0.0);
            $reorderLevel = max(1, (int)($_POST['reorder_level'] ?? 5));
            $isActive     = isset($_POST['is_active']) ? 1 : 0;

            if ($dishId <= 0 || empty($name) || $categoryId <= 0 || $price <= 0.0) {
                $feedback = ['type' => 'danger', 'message' => 'Please provide valid dish details.'];
            } else {
                // Fetch existing dish to keep existing image if no new file
                $existingStmt = $pdo->prepare('SELECT image_path FROM dishes WHERE dish_id = :id');
                $existingStmt->execute([':id' => $dishId]);
                $existingImagePath = (string)$existingStmt->fetchColumn();

                $newImagePath = $existingImagePath;
                if (!empty($_FILES['image']['name'])) {
                    $uploadedPath = handle_dish_image_upload($_FILES['image'], $feedback);
                    if ($uploadedPath !== null) {
                        $newImagePath = $uploadedPath;
                    } elseif (!empty($feedback['message'])) {
                        goto end_post;
                    }
                }

                try {
                    $pdo->beginTransaction();

                    $updateDishStmt = $pdo->prepare('
                        UPDATE dishes 
                        SET category_id = :cat_id, name = :name, description = :desc, price = :price,
                            image_path = :img, is_active = :active
                        WHERE dish_id = :id
                    ');
                    $updateDishStmt->execute([
                        ':cat_id' => $categoryId,
                        ':name'   => $name,
                        ':desc'   => !empty($description) ? $description : null,
                        ':price'  => $price,
                        ':img'    => $newImagePath,
                        ':active' => $isActive,
                        ':id'     => $dishId,
                    ]);

                    // Update reorder level in inventory
                    $updateInvStmt = $pdo->prepare('
                        UPDATE inventory 
                        SET reorder_level = :reorder 
                        WHERE dish_id = :id
                    ');
                    $updateInvStmt->execute([
                        ':reorder' => $reorderLevel,
                        ':id'      => $dishId,
                    ]);

                    $pdo->commit();

                    log_action(
                        $pdo,
                        $userId,
                        'DISH_UPDATE',
                        sprintf("Updated dish '%s' (ID #%d). Price: LKR %.2f, Status: %s.", $name, $dishId, $price, $isActive ? 'Active' : 'Inactive')
                    );

                    $feedback = ['type' => 'success', 'message' => "Dish '{$name}' updated successfully."];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $feedback = ['type' => 'danger', 'message' => 'Failed to update dish: ' . $e->getMessage()];
                }
            }
        }

        // 3. SOFT DELETE (TOGGLE ACTIVE STATUS)
        elseif ($action === 'toggle_status') {
            $dishId = (int)($_POST['dish_id'] ?? 0);
            if ($dishId > 0) {
                $checkStmt = $pdo->prepare('SELECT name, is_active FROM dishes WHERE dish_id = :id');
                $checkStmt->execute([':id' => $dishId]);
                $dish = $checkStmt->fetch();

                if ($dish) {
                    $newStatus = $dish['is_active'] ? 0 : 1;
                    $toggleStmt = $pdo->prepare('UPDATE dishes SET is_active = :status WHERE dish_id = :id');
                    $toggleStmt->execute([':status' => $newStatus, ':id' => $dishId]);

                    $statusWord = $newStatus ? 'restored / activated' : 'soft-deleted (hidden from public menu)';
                    log_action(
                        $pdo,
                        $userId,
                        'DISH_STATUS_TOGGLE',
                        sprintf("Dish '%s' (ID #%d) was %s.", $dish['name'], $dishId, $statusWord)
                    );

                    $feedback = ['type' => 'success', 'message' => "Dish '{$dish['name']}' has been {$statusWord}."];
                }
            }
        }
    }
}
end_post:

// -----------------------------------------------------------------------------
// Fetch Categories for Form Dropdown
// -----------------------------------------------------------------------------
$categoriesListStmt = $pdo->query('SELECT category_id, name FROM categories WHERE is_active = 1 ORDER BY name ASC');
$categoriesList = $categoriesListStmt->fetchAll();

// -----------------------------------------------------------------------------
// Fetch All Dishes with Inventory & Category info
// -----------------------------------------------------------------------------
$filterCat = isset($_GET['filter_category']) ? (int)$_GET['filter_category'] : 0;
$searchTerm = trim((string)($_GET['search'] ?? ''));

$sql = '
    SELECT d.dish_id, d.name, d.description, d.price, d.image_path, d.is_active, d.created_at,
           c.category_id, c.name AS category_name,
           i.current_stock, i.reorder_level
    FROM dishes d
    JOIN categories c ON d.category_id = c.category_id
    LEFT JOIN inventory i ON d.dish_id = i.dish_id
    WHERE 1=1
';
$params = [];

if ($filterCat > 0) {
    $sql .= ' AND d.category_id = :cat';
    $params[':cat'] = $filterCat;
}

if (!empty($searchTerm)) {
    $sql .= ' AND (d.name LIKE :search OR d.description LIKE :search)';
    $params[':search'] = '%' . $searchTerm . '%';
}

$sql .= ' ORDER BY d.dish_id DESC';
$dishesStmt = $pdo->prepare($sql);
$dishesStmt->execute($params);
$dishes = $dishesStmt->fetchAll();

// Check if an Edit request is open
$editDish = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editStmt = $pdo->prepare('
        SELECT d.*, i.current_stock, i.reorder_level 
        FROM dishes d 
        LEFT JOIN inventory i ON d.dish_id = i.dish_id 
        WHERE d.dish_id = :id
    ');
    $editStmt->execute([':id' => $editId]);
    $editDish = $editStmt->fetch();
}

$pageTitle = 'Dish Catalog & Staff Management';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 4rem;">

    <!-- Header & Action Buttons -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.35rem;">
                <span class="role-badge badge-staff">Component B: Food Items</span>
                <span style="color: var(--text-muted); font-size: 0.85rem;">Dish Catalog & Image Uploads</span>
            </div>
            <h1 style="font-size: 2rem; font-weight: 800; color: var(--text-primary); margin: 0;">Dishes & Food Items Management</h1>
            <p style="color: var(--text-secondary); margin-top: 0.35rem; font-size: 0.95rem;">
                Add dishes, configure pricing in LKR, upload verified dish images, and manage soft deletions safely.
            </p>
        </div>

        <div style="display: flex; gap: 0.75rem;">
            <a href="stock.php" class="btn btn-secondary btn-sm">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
                Stock Dashboard
            </a>
            <a href="manage-categories.php" class="btn btn-secondary btn-sm">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                Manage Categories
            </a>
            <a href="list.php" class="btn btn-primary btn-sm">
                Public Menu View &rarr;
            </a>
        </div>
    </div>

    <!-- Feedback Message -->
    <?php if (!empty($feedback['message'])): ?>
        <div class="alert alert-<?= htmlspecialchars($feedback['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars($feedback['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- Add / Edit Dish Card Form -->
    <div class="card" id="dishFormCard" style="margin-bottom: 2.5rem;">
        <div class="card-header" style="background: rgba(255, 255, 255, 0.02);">
            <div>
                <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    <?= $editDish ? 'Edit Dish: ' . htmlspecialchars($editDish['name'], ENT_QUOTES, 'UTF-8') : 'Add New Gourmet Dish'; ?>
                </h2>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Upload high-resolution images (JPG/PNG/WEBP up to 2MB). MIME verified & renamed automatically.
                </p>
            </div>
            <?php if ($editDish): ?>
                <a href="manage-dishes.php" class="btn btn-secondary btn-sm">Cancel Edit</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form action="manage-dishes.php" method="POST" enctype="multipart/form-data" id="dishForm">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="<?= $editDish ? 'update_dish' : 'create_dish'; ?>">
                <?php if ($editDish): ?>
                    <input type="hidden" name="dish_id" value="<?= (int)$editDish['dish_id']; ?>">
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem;">
                    
                    <!-- Dish Name -->
                    <div class="form-group">
                        <label for="name" class="form-label">Dish Name <span class="required">*</span></label>
                        <input 
                            type="text" 
                            name="name" 
                            id="name" 
                            class="form-control" 
                            placeholder="e.g. Seafood Cheese Kottu" 
                            value="<?= htmlspecialchars($editDish['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                            required 
                            maxlength="150"
                        >
                    </div>

                    <!-- Category -->
                    <div class="form-group">
                        <label for="category_id" class="form-label">Food Category <span class="required">*</span></label>
                        <select name="category_id" id="category_id" class="form-control" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categoriesList as $c): ?>
                                <option value="<?= (int)$c['category_id']; ?>" <?= ($editDish && (int)$editDish['category_id'] === (int)$c['category_id']) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Price in LKR -->
                    <div class="form-group">
                        <label for="price" class="form-label">Selling Price (LKR) <span class="required">*</span></label>
                        <input 
                            type="number" 
                            name="price" 
                            id="price" 
                            step="0.01" 
                            min="1.00" 
                            class="form-control" 
                            placeholder="e.g. 1450.00" 
                            value="<?= htmlspecialchars(isset($editDish['price']) ? (string)$editDish['price'] : '', ENT_QUOTES, 'UTF-8'); ?>" 
                            required
                        >
                    </div>

                    <!-- Reorder Level -->
                    <div class="form-group">
                        <label for="reorder_level" class="form-label">Low-Stock Alert Level <span class="required">*</span></label>
                        <input 
                            type="number" 
                            name="reorder_level" 
                            id="reorder_level" 
                            min="1" 
                            class="form-control" 
                            placeholder="e.g. 5" 
                            value="<?= htmlspecialchars(isset($editDish['reorder_level']) ? (string)$editDish['reorder_level'] : '5', ENT_QUOTES, 'UTF-8'); ?>" 
                            required
                        >
                    </div>

                    <!-- Initial Stock (Only for new dish) -->
                    <?php if (!$editDish): ?>
                        <div class="form-group">
                            <label for="initial_stock" class="form-label">Initial Portion Stock</label>
                            <input 
                                type="number" 
                                name="initial_stock" 
                                id="initial_stock" 
                                min="0" 
                                class="form-control" 
                                placeholder="e.g. 25" 
                                value="20"
                            >
                        </div>
                    <?php endif; ?>

                    <!-- Active Visibility Toggle -->
                    <div class="form-group">
                        <label class="form-label">Menu Visibility</label>
                        <div style="display: flex; align-items: center; gap: 0.65rem; height: 48px;">
                            <input 
                                type="checkbox" 
                                name="is_active" 
                                id="is_active" 
                                value="1" 
                                <?= ($editDish === null || !empty($editDish['is_active'])) ? 'checked' : ''; ?>
                                style="width: 18px; height: 18px; accent-color: var(--primary);"
                            >
                            <label for="is_active" style="color: var(--text-primary); cursor: pointer; font-size: 0.925rem;">
                                Active (Listed in customer ordering menu)
                            </label>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="description" class="form-label">Culinary Description & Ingredients</label>
                        <textarea 
                            name="description" 
                            id="description" 
                            class="form-control" 
                            placeholder="Describe ingredients, cooking style, portion size, and flavor profile..." 
                            rows="3"
                        ><?= htmlspecialchars($editDish['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <!-- Image Upload & Live Preview -->
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="dish_image" class="form-label">Dish Image (JPG, PNG, or WEBP &bull; Max 2MB)</label>
                        <div style="display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 250px;">
                                <input 
                                    type="file" 
                                    name="image" 
                                    id="dish_image" 
                                    class="form-control" 
                                    accept=".jpg,.jpeg,.png,.webp"
                                    onchange="previewDishImage(this)"
                                >
                                <span style="font-size: 0.785rem; color: var(--text-muted); margin-top: 0.35rem; display: block;">
                                    Files are verified on the server via `finfo_file` MIME inspection and renamed safely.
                                </span>
                            </div>

                            <div id="imagePreviewContainer" style="display: flex; align-items: center; gap: 1rem; padding: 0.5rem; border: 1px dashed var(--border-medium); border-radius: var(--radius-md); background: rgba(0,0,0,0.2);">
                                <?php 
                                    $currentImg = !empty($editDish['image_path']) ? '/UrbanEats-Cloud-Kitchen-Platform/' . $editDish['image_path'] : '';
                                ?>
                                <img 
                                    id="imagePreview" 
                                    src="<?= htmlspecialchars($currentImg, ENT_QUOTES, 'UTF-8'); ?>" 
                                    alt="Image Preview" 
                                    style="width: 80px; height: 80px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle); <?= empty($currentImg) ? 'display: none;' : ''; ?>"
                                >
                                <span id="noPreviewText" style="font-size: 0.8125rem; color: var(--text-muted); padding: 0 1rem; <?= !empty($currentImg) ? 'display: none;' : ''; ?>">
                                    No image selected
                                </span>
                            </div>
                        </div>
                    </div>

                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary" id="btnSubmitDish">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <?= $editDish ? 'Update Dish Details' : 'Create & Register Dish'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Filters & Dish Catalog List -->
    <div class="card">
        <div class="card-header" style="flex-wrap: wrap; gap: 1rem;">
            <div>
                <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin: 0;">Dishes Catalog (<?= count($dishes); ?>)</h2>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Soft-deleting a dish preserves all past customer order history.
                </p>
            </div>

            <!-- Search & Category Filters -->
            <form action="manage-dishes.php" method="GET" style="display: flex; gap: 0.65rem; align-items: center; flex-wrap: wrap; margin: 0;">
                <input 
                    type="text" 
                    name="search" 
                    class="form-control" 
                    placeholder="Search dishes..." 
                    value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>"
                    style="width: 180px; padding: 0.45rem 0.75rem; font-size: 0.85rem;"
                >
                <select name="filter_category" class="form-control" style="width: 180px; padding: 0.45rem 2rem 0.45rem 0.75rem; font-size: 0.85rem;">
                    <option value="">All Categories</option>
                    <?php foreach ($categoriesList as $c): ?>
                        <option value="<?= (int)$c['category_id']; ?>" <?= ($filterCat === (int)$c['category_id']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
                <?php if (!empty($searchTerm) || $filterCat > 0): ?>
                    <a href="manage-dishes.php" class="btn btn-outline btn-sm">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card-body" style="padding: 0;">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Dish & Media</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock / Level</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dishes)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                                    No dishes match your filter criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dishes as $d): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.85rem;">
                                            <?php if (!empty($d['image_path'])): ?>
                                                <img src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($d['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 52px; height: 52px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
                                            <?php else: ?>
                                                <div style="width: 52px; height: 52px; border-radius: var(--radius-sm); background: var(--bg-surface-elevated); display: flex; align-items: center; justify-content: center; color: var(--text-muted);">No Img</div>
                                            <?php endif; ?>
                                            <div>
                                                <strong style="color: var(--text-primary); font-size: 0.95rem;"><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <div style="font-size: 0.785rem; color: var(--text-muted);">Dish ID: #<?= (int)$d['dish_id']; ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($d['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><strong style="color: var(--text-primary);">LKR <?= number_format((float)$d['price'], 2); ?></strong></td>
                                    <td>
                                        <?php 
                                            $currStock = (int)($d['current_stock'] ?? 0);
                                            $reorderLvl = (int)($d['reorder_level'] ?? 5);
                                            if ($currStock === 0) {
                                                echo '<span style="color: var(--danger); font-weight: 700;">0 (Out)</span>';
                                            } elseif ($currStock <= $reorderLvl) {
                                                echo '<span style="color: var(--warning); font-weight: 700;">' . $currStock . ' (Low)</span>';
                                            } else {
                                                echo '<span style="color: var(--success); font-weight: 700;">' . $currStock . '</span>';
                                            }
                                        ?>
                                        <span style="font-size: 0.75rem; color: var(--text-muted);">/ Lvl <?= $reorderLvl; ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($d['is_active'])): ?>
                                            <span class="role-badge badge-customer">Active</span>
                                        <?php else: ?>
                                            <span class="role-badge" style="background: var(--danger-bg); color: var(--danger); border-color: var(--danger-border);">Soft-Deleted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                                            <a href="manage-dishes.php?edit=<?= (int)$d['dish_id']; ?>#dishFormCard" class="btn btn-secondary btn-sm" title="Edit Dish">
                                                Edit
                                            </a>
                                            <a href="detail.php?id=<?= (int)$d['dish_id']; ?>" class="btn btn-outline btn-sm" target="_blank" title="View Public Dish Page">
                                                View
                                            </a>
                                            <form action="manage-dishes.php" method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to change the active status of this dish?');">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="dish_id" value="<?= (int)$d['dish_id']; ?>">
                                                <?php if (!empty($d['is_active'])): ?>
                                                    <button type="submit" class="btn btn-danger btn-sm" title="Soft Delete / Hide from Menu">
                                                        Soft Delete
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-outline btn-sm" title="Restore / Activate Dish">
                                                        Activate
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
function previewDishImage(input) {
    const preview = document.getElementById('imagePreview');
    const noPreview = document.getElementById('noPreviewText');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            if (noPreview) noPreview.style.display = 'none';
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
