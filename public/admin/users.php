<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Administrator User Management Dashboard
 *
 * PHP version 8.x
 */

declare(strict_types=1);

// Dependencies
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';

// Enforce Administrator-only access
require_role(['Administrator', 'Admin']);

$adminUserId = (int)$_SESSION['user_id'];
$adminUsername = (string)($_SESSION['username'] ?? 'Administrator');

$successMessage = '';
$errorMessage = '';

// -----------------------------------------------------------------------------
// Handle Admin Actions (POST)
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // 1. Verify CSRF Token
    if (!validate_csrf_token()) {
        $errorMessage = 'Security validation failed: Invalid or expired CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if ($targetUserId <= 0) {
            $errorMessage = 'Invalid target user specified.';
        } else {
            // Fetch target user details
            $targetStmt = $pdo->prepare('
                SELECT u.user_id, u.username, u.email, u.role_id, u.is_active, r.role_name 
                FROM users u 
                JOIN roles r ON u.role_id = r.role_id 
                WHERE u.user_id = :id
            ');
            $targetStmt->execute([':id' => $targetUserId]);
            $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $errorMessage = 'The requested user could not be found.';
            } else {
                // Action A: Toggle Account Active/Inactive Status
                if ($action === 'toggle_status') {
                    $newStatus = ((int)$targetUser['is_active'] === 1) ? 0 : 1;

                    // Prevent admin from deactivating their own account
                    if ($targetUserId === $adminUserId && $newStatus === 0) {
                        $errorMessage = 'Safety Protection: You cannot deactivate your own administrator account.';
                    } else {
                        try {
                            $stmt = $pdo->prepare('UPDATE users SET is_active = :status WHERE user_id = :id');
                            $stmt->execute([':status' => $newStatus, ':id' => $targetUserId]);

                            $statusLabel = ($newStatus === 1) ? 'Activated' : 'Deactivated';

                            // Log the admin action in audit_log
                            log_action(
                                $pdo,
                                $adminUserId,
                                'USER_STATUS_CHANGE',
                                sprintf(
                                    "Administrator '%s' %s account for user '%s' (#%d).",
                                    $adminUsername,
                                    strtolower($statusLabel),
                                    $targetUser['username'],
                                    $targetUserId
                                )
                            );

                            regenerate_csrf_token();
                            $successMessage = sprintf("Account for '%s' was successfully %s.", $targetUser['username'], strtolower($statusLabel));
                        } catch (PDOException $e) {
                            error_log('Status change error: ' . $e->getMessage());
                            $errorMessage = 'Failed to update user status due to a database error.';
                        }
                    }
                }

                // Action B: Change User Role
                elseif ($action === 'change_role') {
                    $newRoleId = (int)($_POST['new_role_id'] ?? 0);

                    // Fetch new role name
                    $roleCheck = $pdo->prepare('SELECT role_id, role_name FROM roles WHERE role_id = :role_id');
                    $roleCheck->execute([':role_id' => $newRoleId]);
                    $roleRecord = $roleCheck->fetch(PDO::FETCH_ASSOC);

                    if (!$roleRecord) {
                        $errorMessage = 'The selected role is invalid.';
                    } elseif ($targetUserId === $adminUserId && (int)$roleRecord['role_id'] !== 1) {
                        // Prevent admin from revoking their own admin role
                        $errorMessage = 'Safety Protection: You cannot revoke your own administrator privileges.';
                    } else {
                        try {
                            $stmt = $pdo->prepare('UPDATE users SET role_id = :role_id WHERE user_id = :id');
                            $stmt->execute([':role_id' => $newRoleId, ':id' => $targetUserId]);

                            // Log role change in audit_log
                            log_action(
                                $pdo,
                                $adminUserId,
                                'USER_ROLE_CHANGE',
                                sprintf(
                                    "Administrator '%s' updated role of '%s' (#%d) from '%s' to '%s'.",
                                    $adminUsername,
                                    $targetUser['username'],
                                    $targetUserId,
                                    $targetUser['role_name'],
                                    $roleRecord['role_name']
                                )
                            );

                            regenerate_csrf_token();
                            $successMessage = sprintf("Role for '%s' updated to '%s'.", $targetUser['username'], $roleRecord['role_name']);
                        } catch (PDOException $e) {
                            error_log('Role update error: ' . $e->getMessage());
                            $errorMessage = 'Failed to update role due to a database error.';
                        }
                    }
                }
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Search and Filter Handling (GET)
// -----------------------------------------------------------------------------
$searchKeyword = trim((string)($_GET['search'] ?? ''));
$filterRoleId  = (int)($_GET['role_filter'] ?? 0);
$filterStatus  = (string)($_GET['status_filter'] ?? 'all');

// Fetch all available roles for filters and dropdowns
$allRoles = $pdo->query('SELECT role_id, role_name FROM roles ORDER BY role_id ASC')->fetchAll(PDO::FETCH_ASSOC);

// Fetch overall user metrics
$stats = [
    'total'     => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active'    => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn(),
    'inactive'  => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn(),
    'customers' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 3')->fetchColumn(),
    'staff'     => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 2')->fetchColumn(),
    'admins'    => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 1')->fetchColumn(),
];

// Build filtered query
$query = '
    SELECT u.user_id, u.first_name, u.last_name, u.email, u.username, 
           u.role_id, u.is_active, u.created_at, r.role_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    WHERE 1=1
';
$params = [];

if ($searchKeyword !== '') {
    $query .= ' AND (u.first_name LIKE :kw OR u.last_name LIKE :kw OR u.email LIKE :kw OR u.username LIKE :kw)';
    $params[':kw'] = '%' . $searchKeyword . '%';
}

if ($filterRoleId > 0) {
    $query .= ' AND u.role_id = :role_id';
    $params[':role_id'] = $filterRoleId;
}

if ($filterStatus === 'active') {
    $query .= ' AND u.is_active = 1';
} elseif ($filterStatus === 'inactive') {
    $query .= ' AND u.is_active = 0';
}

$query .= ' ORDER BY u.user_id DESC';

$userListStmt = $pdo->prepare($query);
$userListStmt->execute($params);
$users = $userListStmt->fetchAll(PDO::FETCH_ASSOC);

// Layout page configuration
$pageTitle = 'User Management - Admin Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container">
    
    <!-- Top Breadcrumb & Actions Bar -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span class="role-badge badge-admin" style="margin-bottom: 0.5rem;">Administration</span>
            <h1 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.25rem;">User Management</h1>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin: 0;">
                Control platform accounts, assign kitchen roles, and manage access credentials.
            </p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="audit-log.php" class="btn btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                </svg>
                View Audit Logs
            </a>
            <a href="../register.php" class="btn btn-primary" target="_blank">
                + Register New User
            </a>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($successMessage)) : ?>
        <div class="alert alert-success" role="alert">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
            <span><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)) : ?>
        <div class="alert alert-danger" role="alert">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <span><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <!-- User Metrics Cards -->
    <div class="grid grid-4" style="margin-bottom: 2rem;">
        
        <div class="card" style="padding: 1.25rem;">
            <div style="color: var(--text-muted); font-size: 0.8125rem; font-weight: 700; text-transform: uppercase;">Total Users</div>
            <div style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary); margin-top: 0.25rem;">
                <?= number_format($stats['total']); ?>
            </div>
            <div style="font-size: 0.775rem; color: #34d399; margin-top: 0.25rem;">
                <?= $stats['active']; ?> Active &bull; <?= $stats['inactive']; ?> Inactive
            </div>
        </div>

        <div class="card" style="padding: 1.25rem;">
            <div style="color: var(--text-muted); font-size: 0.8125rem; font-weight: 700; text-transform: uppercase;">Customers</div>
            <div style="font-size: 1.85rem; font-weight: 800; color: #34d399; margin-top: 0.25rem;">
                <?= number_format($stats['customers']); ?>
            </div>
            <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.25rem;">Registered patrons</div>
        </div>

        <div class="card" style="padding: 1.25rem;">
            <div style="color: var(--text-muted); font-size: 0.8125rem; font-weight: 700; text-transform: uppercase;">Kitchen Staff</div>
            <div style="font-size: 1.85rem; font-weight: 800; color: #38bdf8; margin-top: 0.25rem;">
                <?= number_format($stats['staff']); ?>
            </div>
            <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.25rem;">Culinary team members</div>
        </div>

        <div class="card" style="padding: 1.25rem;">
            <div style="color: var(--text-muted); font-size: 0.8125rem; font-weight: 700; text-transform: uppercase;">Administrators</div>
            <div style="font-size: 1.85rem; font-weight: 800; color: #c084fc; margin-top: 0.25rem;">
                <?= number_format($stats['admins']); ?>
            </div>
            <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.25rem;">Platform managers</div>
        </div>

    </div>

    <!-- Search and Filter Form -->
    <div class="card" style="padding: 1.25rem; margin-bottom: 2rem;">
        <form action="users.php" method="GET" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
            
            <!-- Keyword Search -->
            <div style="flex: 2; min-width: 220px;">
                <label for="search" class="form-label" style="font-size: 0.8125rem;">Search by Keyword</label>
                <input 
                    type="text" 
                    id="search" 
                    name="search" 
                    class="form-control" 
                    placeholder="Name, email, or username..."
                    value="<?= htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>"
                >
            </div>

            <!-- Role Filter -->
            <div style="flex: 1; min-width: 160px;">
                <label for="role_filter" class="form-label" style="font-size: 0.8125rem;">Filter by Role</label>
                <select id="role_filter" name="role_filter" class="form-control">
                    <option value="0">All Roles</option>
                    <?php foreach ($allRoles as $r) : ?>
                        <option value="<?= (int)$r['role_id']; ?>" <?= ($filterRoleId === (int)$r['role_id']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($r['role_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status Filter -->
            <div style="flex: 1; min-width: 140px;">
                <label for="status_filter" class="form-label" style="font-size: 0.8125rem;">Account Status</label>
                <select id="status_filter" name="status_filter" class="form-control">
                    <option value="all" <?= ($filterStatus === 'all') ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="active" <?= ($filterStatus === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?= ($filterStatus === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <!-- Filter Buttons -->
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary" style="height: 42px;">
                    Filter Users
                </button>
                <?php if ($searchKeyword !== '' || $filterRoleId > 0 || $filterStatus !== 'all') : ?>
                    <a href="users.php" class="btn btn-secondary" style="height: 42px;">
                        Reset
                    </a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <!-- Users Table Card -->
    <div class="card glass-panel">
        
        <div class="card-header">
            <div>
                <h2 style="font-size: 1.15rem; margin-bottom: 0.2rem;">All Platform Accounts</h2>
                <span style="font-size: 0.8125rem; color: var(--text-muted);">
                    Showing <?= count($users); ?> registered <?= count($users) === 1 ? 'user' : 'users'; ?>
                </span>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role Assignment</th>
                        <th>Account Status</th>
                        <th>Joined</th>
                        <th style="text-align: right;">Quick Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)) : ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                                No users matched your search criteria.
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($users as $u) : ?>
                            <?php
                                $isSelf = ($u['user_id'] === $adminUserId);
                                $statusBadgeClass = ($u['is_active'] == 1) ? 'badge-customer' : 'badge-guest';

                                $rBadge = match (strtolower((string)$u['role_name'])) {
                                    'admin', 'administrator' => 'badge-admin',
                                    'staff', 'kitchen staff' => 'badge-staff',
                                    default                  => 'badge-customer',
                                };
    ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 700;">
                                    #<?= (int)$u['user_id']; ?>
                                </td>
                                
                                <td>
                                    <div style="font-weight: 700; color: var(--text-primary);">
                                        <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($isSelf) : ?>
                                            <span style="font-size: 0.7rem; background: rgba(249,115,22,0.2); color: var(--primary); padding: 1px 6px; border-radius: 4px; margin-left: 4px;">You</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.8125rem; color: var(--text-muted); font-family: monospace;">
                                        @<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </td>

                                <td>
                                    <span style="font-size: 0.875rem;">
                                        <?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>

                                <!-- Role Form Column -->
                                <td>
                                    <form action="users.php" method="POST" style="display: inline-flex; align-items: center; gap: 0.4rem;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="change_role">
                                        <input type="hidden" name="target_user_id" value="<?= (int)$u['user_id']; ?>">
                                        
                                        <select 
                                            name="new_role_id" 
                                            class="form-control" 
                                            style="padding: 0.35rem 0.65rem; font-size: 0.8125rem; height: 32px; width: 140px;"
                                            onchange="if(confirm('Change role for <?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>?')) { this.form.submit(); } else { this.value = '<?= (int)$u['role_id']; ?>'; }"
                                            <?= $isSelf ? 'title="Self-demotion disabled" disabled' : ''; ?>
                                        >
                                            <?php foreach ($allRoles as $roleOption) : ?>
                                                <option value="<?= (int)$roleOption['role_id']; ?>" <?= ((int)$u['role_id'] === (int)$roleOption['role_id']) ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($roleOption['role_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>

                                <!-- Status Column -->
                                <td>
                                    <?php if ((int)$u['is_active'] === 1) : ?>
                                        <span class="role-badge badge-customer">Active</span>
                                    <?php else : ?>
                                        <span class="role-badge" style="background: rgba(244,63,94,0.15); color: #fb7185; border: 1px solid rgba(244,63,94,0.3);">
                                            Deactivated
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Creation Date -->
                                <td style="font-size: 0.8125rem; color: var(--text-muted); white-space: nowrap;">
                                    <?= date('M j, Y', strtotime((string)$u['created_at'])); ?>
                                </td>

                                <!-- Action Buttons -->
                                <td style="text-align: right; white-space: nowrap;">
                                    <!-- Toggle Status Form -->
                                    <form action="users.php" method="POST" style="display: inline;">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="target_user_id" value="<?= (int)$u['user_id']; ?>">

                                        <?php if ((int)$u['is_active'] === 1) : ?>
                                            <button 
                                                type="submit" 
                                                class="btn btn-sm btn-danger" 
                                                onclick="return confirm('Deactivate account for <?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>? User will be blocked from logging in.');"
                                                <?= $isSelf ? 'disabled title="You cannot deactivate your own account"' : ''; ?>
                                            >
                                                Deactivate
                                            </button>
                                        <?php else : ?>
                                            <button 
                                                type="submit" 
                                                class="btn btn-sm btn-secondary" 
                                                style="color: #34d399; border-color: rgba(16,185,129,0.3);"
                                                onclick="return confirm('Re-activate account for <?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>?');"
                                            >
                                                Activate
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- Filter Logs Shortcut -->
                                    <a href="audit-log.php?search=<?= urlencode((string)$u['username']); ?>" class="btn btn-sm btn-secondary" style="margin-left: 4px;" title="View user audit history">
                                        Logs
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
