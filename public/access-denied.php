<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * 403 Forbidden - Access Denied Page
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

// Send 403 Forbidden HTTP Status Code
http_response_code(403);

// Page configuration
$pageTitle = '403 Access Denied - UrbanEats Cloud Kitchen';

// Include the shared platform header
require_once __DIR__ . '/../includes/header.php';

// Detect active session information for helpful feedback
$currentRoleDisplay = $userRole ?? 'Guest';
$currentUsername = $_SESSION['username'] ?? $_SESSION['first_name'] ?? 'Guest';
?>

<div class="container">
    <div class="denied-container">
        
        <div class="denied-card">
            
            <!-- Lock Icon Visual Badge -->
            <div class="denied-icon-wrap">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    <line x1="12" y1="15" x2="12" y2="17"></line>
                </svg>
            </div>

            <!-- Error Code & Title -->
            <span class="denied-badge">HTTP 403 &bull; FORBIDDEN</span>
            <h1 class="denied-title">Restricted Access</h1>
            
            <p class="denied-text">
                You do not possess the necessary authorization or role permissions to view this kitchen resource. 
                Access to this section is restricted to authorized platform personnel.
            </p>

            <!-- Authentication Context Box -->
            <div class="denied-meta">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                    <span style="color: var(--text-muted);">Current User:</span>
                    <strong style="color: var(--text-primary);"><?= htmlspecialchars((string)$currentUsername, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: var(--text-muted);">Assigned Role:</span>
                    <span class="role-badge <?= $roleBadgeClass ?? 'badge-guest'; ?>">
                        <?= htmlspecialchars((string)$currentRoleDisplay, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </div>

            <!-- Navigation Actions -->
            <div class="denied-actions">
                <a href="javascript:history.back()" class="btn btn-secondary">
                    &larr; Return to Previous Page
                </a>
                <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/index.php" class="btn btn-primary">
                    Go to Homepage
                </a>
                <?php if ($isLoggedIn): ?>
                    <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/logout.php" class="btn btn-outline">
                        Sign In as Different User
                    </a>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/login.php" class="btn btn-outline">
                        Sign In
                    </a>
                <?php endif; ?>
            </div>

        </div>

    </div>
</div>

<?php
// Include the shared platform footer
require_once __DIR__ . '/../includes/footer.php';
?>
