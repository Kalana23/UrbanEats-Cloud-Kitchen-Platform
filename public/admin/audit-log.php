<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Administrator Audit Log Viewer
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

// -----------------------------------------------------------------------------
// Filters & Search Parameters
// -----------------------------------------------------------------------------
$searchKeyword = trim((string)($_GET['search'] ?? ''));
$actionFilter  = trim((string)($_GET['action_type'] ?? ''));
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 15;

// Fetch distinct actions present in audit_log for the filter dropdown
$distinctActions = $pdo->query('
    SELECT DISTINCT action 
    FROM audit_log 
    WHERE action IS NOT NULL AND action != "" 
    ORDER BY action ASC
')->fetchAll(PDO::FETCH_COLUMN);

// Build Filter SQL Conditions
$whereClauses = ['1=1'];
$params = [];

if ($searchKeyword !== '') {
    $whereClauses[] = '(
        a.action LIKE :kw 
        OR a.details LIKE :kw 
        OR a.ip_address LIKE :kw 
        OR u.username LIKE :kw 
        OR u.email LIKE :kw 
        OR u.first_name LIKE :kw 
        OR u.last_name LIKE :kw
    )';
    $params[':kw'] = '%' . $searchKeyword . '%';
}

if ($actionFilter !== '') {
    $whereClauses[] = 'a.action = :action';
    $params[':action'] = $actionFilter;
}

$whereSql = implode(' AND ', $whereClauses);

// -----------------------------------------------------------------------------
// Count Total Records for Pagination
// -----------------------------------------------------------------------------
$countSql = "
    SELECT COUNT(*) 
    FROM audit_log a
    LEFT JOIN users u ON a.user_id = u.user_id
    WHERE {$whereSql}
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRecords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// -----------------------------------------------------------------------------
// Fetch Paginated Audit Log Records
// -----------------------------------------------------------------------------
$recordsSql = "
    SELECT a.log_id, a.user_id, a.action, a.details, a.ip_address, a.created_at,
           u.username, u.email, u.first_name, u.last_name, r.role_name
    FROM audit_log a
    LEFT JOIN users u ON a.user_id = u.user_id
    LEFT JOIN roles r ON u.role_id = r.role_id
    WHERE {$whereSql}
    ORDER BY a.log_id DESC
    LIMIT :limit OFFSET :offset
";

$recordsStmt = $pdo->prepare($recordsSql);

// Bind filters
foreach ($params as $key => $val) {
    $recordsStmt->bindValue($key, $val, PDO::PARAM_STR);
}
// Bind pagination integers explicitly
$recordsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$recordsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$recordsStmt->execute();
$logs = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to build pagination URLs with active filters preserved
function build_page_url(int $targetPage, string $searchKeyword, string $actionFilter): string {
    $query = ['page' => $targetPage];
    if ($searchKeyword !== '') {
        $query['search'] = $searchKeyword;
    }
    if ($actionFilter !== '') {
        $query['action_type'] = $actionFilter;
    }
    return 'audit-log.php?' . http_build_query($query);
}

// Layout configuration
$pageTitle = 'System Audit Logs - Admin Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container">
    
    <!-- Top Header Bar -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span class="role-badge badge-admin" style="margin-bottom: 0.5rem;">Security & Compliance</span>
            <h1 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.25rem;">System Audit Trail</h1>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin: 0;">
                Comprehensive activity log recording logins, profile changes, and administrative actions.
            </p>
        </div>
        <div>
            <a href="users.php" class="btn btn-secondary">
                &larr; Return to Users
            </a>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="card" style="padding: 1.25rem; margin-bottom: 2rem;">
        <form action="audit-log.php" method="GET" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
            
            <!-- Search Keyword -->
            <div style="flex: 2; min-width: 240px;">
                <label for="search" class="form-label" style="font-size: 0.8125rem;">Search Audit Trail</label>
                <input 
                    type="text" 
                    id="search" 
                    name="search" 
                    class="form-control" 
                    placeholder="Action, details, user, or IP..."
                    value="<?= htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>"
                >
            </div>

            <!-- Action Type Filter -->
            <div style="flex: 1.5; min-width: 200px;">
                <label for="action_type" class="form-label" style="font-size: 0.8125rem;">Filter by Action</label>
                <select id="action_type" name="action_type" class="form-control">
                    <option value="">All Actions</option>
                    <?php foreach ($distinctActions as $act): ?>
                        <option value="<?= htmlspecialchars($act, ENT_QUOTES, 'UTF-8'); ?>" <?= ($actionFilter === $act) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($act, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Buttons -->
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary" style="height: 42px;">
                    Filter Logs
                </button>
                <?php if ($searchKeyword !== '' || $actionFilter !== '' || $page > 1): ?>
                    <a href="audit-log.php" class="btn btn-secondary" style="height: 42px;">
                        Reset
                    </a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <!-- Audit Log Table Card -->
    <div class="card glass-panel">
        
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <h2 style="font-size: 1.15rem; margin-bottom: 0.2rem;">Audit Records</h2>
                <span style="font-size: 0.8125rem; color: var(--text-muted);">
                    Showing <?= count($logs); ?> of <?= number_format($totalRecords); ?> <?= $totalRecords === 1 ? 'event' : 'events'; ?>
                    <?php if ($totalPages > 1): ?>
                        &bull; Page <?= $page; ?> of <?= $totalPages; ?>
                    <?php endif; ?>
                </span>
            </div>
            
            <span style="font-size: 0.8125rem; color: #34d399; font-weight: 600;">
                Live Audit Logging Active
            </span>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 70px;">Log ID</th>
                        <th style="width: 170px;">Timestamp</th>
                        <th>Action Performed</th>
                        <th>User Responsible</th>
                        <th>Client IP</th>
                        <th>Event Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                                No audit records match your selected criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $row): ?>
                            <?php 
                                // Color-code badges based on action type
                                $act = (string)$row['action'];
                                $badgeStyle = match (true) {
                                    str_contains($act, 'LOGIN') => 'background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);',
                                    str_contains($act, 'LOGOUT') => 'background: rgba(148,163,184,0.15); color: #cbd5e1; border: 1px solid rgba(148,163,184,0.3);',
                                    str_contains($act, 'ROLE') => 'background: rgba(139,92,246,0.15); color: #c084fc; border: 1px solid rgba(139,92,246,0.3);',
                                    str_contains($act, 'STATUS') => 'background: rgba(244,63,94,0.15); color: #fb7185; border: 1px solid rgba(244,63,94,0.3);',
                                    str_contains($act, 'PASSWORD') => 'background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3);',
                                    str_contains($act, 'REGIST') => 'background: rgba(6,182,212,0.15); color: #38bdf8; border: 1px solid rgba(6,182,212,0.3);',
                                    default => 'background: rgba(249,115,22,0.15); color: #fb923c; border: 1px solid rgba(249,115,22,0.3);',
                                };
                            ?>
                            <tr>
                                <!-- Log ID -->
                                <td style="font-family: monospace; font-weight: 700; color: var(--text-muted);">
                                    #<?= (int)$row['log_id']; ?>
                                </td>

                                <!-- Timestamp -->
                                <td style="font-size: 0.8125rem; white-space: nowrap; color: var(--text-secondary);">
                                    <?= date('M j, Y H:i:s', strtotime((string)$row['created_at'])); ?>
                                </td>

                                <!-- Action Badge -->
                                <td>
                                    <span style="display: inline-block; padding: 0.25rem 0.65rem; border-radius: 99px; font-size: 0.75rem; font-weight: 700; font-family: monospace; letter-spacing: 0.02em; <?= $badgeStyle; ?>">
                                        <?= htmlspecialchars($row['action'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>

                                <!-- User -->
                                <td>
                                    <?php if (!empty($row['user_id']) && !empty($row['username'])): ?>
                                        <div style="font-weight: 600; color: var(--text-primary); font-size: 0.875rem;">
                                            <?= htmlspecialchars((string)($row['first_name'] . ' ' . $row['last_name']), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div style="font-size: 0.775rem; color: var(--text-muted); font-family: monospace;">
                                            @<?= htmlspecialchars((string)$row['username'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if (!empty($row['role_name'])): ?>
                                                &bull; <?= htmlspecialchars((string)$row['role_name'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">
                                            Guest / System
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- IP Address -->
                                <td>
                                    <code style="font-family: monospace; font-size: 0.8125rem; color: var(--text-muted); background: rgba(0,0,0,0.2); padding: 2px 6px; border-radius: 4px;">
                                        <?= htmlspecialchars((string)($row['ip_address'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>
                                    </code>
                                </td>

                                <!-- Details -->
                                <td style="font-size: 0.875rem; color: var(--text-body); max-width: 380px;">
                                    <?= htmlspecialchars((string)($row['details'] ?? 'No additional details.'), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
            <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div style="font-size: 0.85rem; color: var(--text-muted);">
                    Showing page <strong><?= $page; ?></strong> of <strong><?= $totalPages; ?></strong>
                </div>

                <div style="display: flex; gap: 0.35rem; align-items: center;">
                    <!-- First Page -->
                    <?php if ($page > 1): ?>
                        <a href="<?= build_page_url(1, $searchKeyword, $actionFilter); ?>" class="btn btn-sm btn-secondary" title="First Page">
                            &laquo; First
                        </a>
                        <a href="<?= build_page_url($page - 1, $searchKeyword, $actionFilter); ?>" class="btn btn-sm btn-secondary" title="Previous Page">
                            &lsaquo; Prev
                        </a>
                    <?php endif; ?>

                    <!-- Numbered Pages (Window of +/- 2) -->
                    <?php 
                        $startPage = max(1, $page - 2);
                        $endPage   = min($totalPages, $page + 2);
                    ?>
                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <a href="<?= build_page_url($p, $searchKeyword, $actionFilter); ?>" 
                           class="btn btn-sm <?= ($p === $page) ? 'btn-primary' : 'btn-secondary'; ?>" 
                           style="min-width: 34px;">
                            <?= $p; ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Next & Last -->
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= build_page_url($page + 1, $searchKeyword, $actionFilter); ?>" class="btn btn-sm btn-secondary" title="Next Page">
                            Next &rsaquo;
                        </a>
                        <a href="<?= build_page_url($totalPages, $searchKeyword, $actionFilter); ?>" class="btn btn-sm btn-secondary" title="Last Page">
                            Last &raquo;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
