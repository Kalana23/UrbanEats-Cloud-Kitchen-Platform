<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * User Profile Management & Security (PHP 8.x)
 */

declare(strict_types=1);

// Include database, auth, and CSRF utilities
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// Enforce authentication
require_login();

$userId = (int)$_SESSION['user_id'];

// State tracking
$profileSuccess = '';
$passwordSuccess = '';
$profileErrors = [];
$passwordErrors = [];

// Fetch current user data from database
try {
    $stmt = $pdo->prepare('
        SELECT u.user_id, u.first_name, u.last_name, u.email, u.username, 
               u.password_hash, u.role_id, u.created_at, r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.user_id = :user_id
        LIMIT 1
    ');
    $stmt->execute([':user_id' => $userId]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser) {
        // User record no longer exists
        header('Location: logout.php');
        exit();
    }
} catch (PDOException $e) {
    error_log('Failed to fetch user profile: ' . $e->getMessage());
    die('An unexpected error occurred while loading your profile.');
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $actionType = (string)($_POST['action_type'] ?? '');

    // Validate CSRF token
    if (!validate_csrf_token()) {
        if ($actionType === 'update_profile') {
            $profileErrors['csrf'] = 'Invalid or expired CSRF token. Please try again.';
        } else {
            $passwordErrors['csrf'] = 'Invalid or expired CSRF token. Please try again.';
        }
    } else {
        // ---------------------------------------------------------------------
        // Form 1: Update Profile Details
        // ---------------------------------------------------------------------
        if ($actionType === 'update_profile') {
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName  = trim((string)($_POST['last_name'] ?? ''));
            $email     = trim((string)($_POST['email'] ?? ''));
            $username  = trim((string)($_POST['username'] ?? ''));

            // First Name validation
            if ($firstName === '') {
                $profileErrors['first_name'] = 'First name is required.';
            } elseif (mb_strlen($firstName) > 100) {
                $profileErrors['first_name'] = 'First name cannot exceed 100 characters.';
            } elseif (!preg_match("/^[a-zA-Z\s'-]+$/u", $firstName)) {
                $profileErrors['first_name'] = 'First name can only contain letters, spaces, hyphens, and apostrophes.';
            }

            // Last Name validation
            if ($lastName === '') {
                $profileErrors['last_name'] = 'Last name is required.';
            } elseif (mb_strlen($lastName) > 100) {
                $profileErrors['last_name'] = 'Last name cannot exceed 100 characters.';
            } elseif (!preg_match("/^[a-zA-Z\s'-]+$/u", $lastName)) {
                $profileErrors['last_name'] = 'Last name can only contain letters, spaces, hyphens, and apostrophes.';
            }

            // Email validation
            if ($email === '') {
                $profileErrors['email'] = 'Email address is required.';
            } elseif (mb_strlen($email) > 150) {
                $profileErrors['email'] = 'Email cannot exceed 150 characters.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $profileErrors['email'] = 'Please enter a valid email address.';
            } else {
                // Check uniqueness excluding current user
                try {
                    $chkEmail = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND user_id != :user_id');
                    $chkEmail->execute([':email' => $email, ':user_id' => $userId]);
                    if ((int)$chkEmail->fetchColumn() > 0) {
                        $profileErrors['email'] = 'This email is already in use by another account.';
                    }
                } catch (PDOException $e) {
                    error_log('Profile email check failed: ' . $e->getMessage());
                    $profileErrors['system'] = 'Database check failed.';
                }
            }

            // Username validation
            if ($username === '') {
                $profileErrors['username'] = 'Username is required.';
            } elseif (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
                $profileErrors['username'] = 'Username must be between 3 and 50 characters.';
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                $profileErrors['username'] = 'Username can only contain letters, numbers, underscores, hyphens, and dots.';
            } else {
                // Check uniqueness excluding current user
                try {
                    $chkUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username AND user_id != :user_id');
                    $chkUser->execute([':username' => $username, ':user_id' => $userId]);
                    if ((int)$chkUser->fetchColumn() > 0) {
                        $profileErrors['username'] = 'This username is already taken.';
                    }
                } catch (PDOException $e) {
                    error_log('Profile username check failed: ' . $e->getMessage());
                    $profileErrors['system'] = 'Database check failed.';
                }
            }

            // Update database if clean
            if (empty($profileErrors)) {
                try {
                    $updateStmt = $pdo->prepare('
                        UPDATE users 
                        SET first_name = :first_name, last_name = :last_name, email = :email, username = :username 
                        WHERE user_id = :user_id
                    ');
                    $updateStmt->execute([
                        ':first_name' => $firstName,
                        ':last_name'  => $lastName,
                        ':email'      => $email,
                        ':username'   => $username,
                        ':user_id'    => $userId,
                    ]);

                    // Update session variables
                    $_SESSION['first_name'] = $firstName;
                    $_SESSION['last_name']  = $lastName;
                    $_SESSION['email']      = $email;
                    $_SESSION['username']   = $username;

                    // Update local state for display
                    $currentUser['first_name'] = $firstName;
                    $currentUser['last_name']  = $lastName;
                    $currentUser['email']      = $email;
                    $currentUser['username']   = $username;

                    // Log action in audit_log
                    log_action(
                        $pdo,
                        $userId,
                        'PROFILE_UPDATE',
                        sprintf("User profile updated: Name='%s %s', Email='%s', Username='%s'", $firstName, $lastName, $email, $username)
                    );

                    regenerate_csrf_token();
                    $profileSuccess = 'Your profile information has been successfully updated.';
                } catch (PDOException $e) {
                    error_log('Profile update failed: ' . $e->getMessage());
                    $profileErrors['system'] = 'Failed to update profile due to a server error.';
                }
            }
        }

        // ---------------------------------------------------------------------
        // Form 2: Change Password
        // ---------------------------------------------------------------------
        elseif ($actionType === 'change_password') {
            $currentPassword    = (string)($_POST['current_password'] ?? '');
            $newPassword        = (string)($_POST['new_password'] ?? '');
            $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');

            // Verify current password
            if ($currentPassword === '') {
                $passwordErrors['current_password'] = 'Current password is required.';
            } elseif (!password_verify($currentPassword, (string)$currentUser['password_hash'])) {
                $passwordErrors['current_password'] = 'The current password you entered is incorrect.';
            }

            // Validate new password
            if ($newPassword === '') {
                $passwordErrors['new_password'] = 'New password is required.';
            } elseif (mb_strlen($newPassword) < 8) {
                $passwordErrors['new_password'] = 'New password must be at least 8 characters long.';
            }

            // Confirm new password
            if ($newPasswordConfirm === '') {
                $passwordErrors['new_password_confirm'] = 'Please confirm your new password.';
            } elseif ($newPassword !== $newPasswordConfirm) {
                $passwordErrors['new_password_confirm'] = 'New passwords do not match.';
            }

            // Check if identical to old password
            if (empty($passwordErrors) && password_verify($newPassword, (string)$currentUser['password_hash'])) {
                $passwordErrors['new_password'] = 'New password cannot be the same as your current password.';
            }

            // Update password if clean
            if (empty($passwordErrors)) {
                try {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pwdStmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :user_id');
                    $pwdStmt->execute([':hash' => $newHash, ':user_id' => $userId]);

                    // Refresh local user hash
                    $currentUser['password_hash'] = $newHash;

                    // Log action in audit_log
                    log_action(
                        $pdo,
                        $userId,
                        'PASSWORD_CHANGE',
                        "Password changed successfully for user ID {$userId}."
                    );

                    regenerate_csrf_token();
                    $passwordSuccess = 'Your password has been changed successfully.';
                } catch (PDOException $e) {
                    error_log('Password change failed: ' . $e->getMessage());
                    $passwordErrors['system'] = 'Failed to update password due to a server error.';
                }
            }
        }
    }
}

// Page title for layout header
$pageTitle = 'Account Settings & Profile - UrbanEats';

// Include the shared platform header
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    
    <!-- Profile Page Header -->
    <div style="margin-bottom: 2rem;">
        <span class="role-badge <?= htmlspecialchars($roleBadgeClass, ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom: 0.5rem;">
            <?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?> Account
        </span>
        <h1 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.35rem;">Profile &amp; Security</h1>
        <p style="color: var(--text-muted); font-size: 0.95rem;">
            Manage your personal kitchen details, contact credentials, and security settings.
        </p>
    </div>

    <!-- Responsive 2-Column Grid -->
    <div class="grid grid-2" style="gap: 2rem;">
        
        <!-- ================================================================= -->
        <!-- Card 1: Edit Profile Details                                      -->
        <!-- ================================================================= -->
        <div class="card glass-panel">
            <div class="card-header">
                <div>
                    <h2 style="font-size: 1.25rem;">Personal Information</h2>
                    <p style="font-size: 0.8125rem; color: var(--text-muted); margin: 0;">Update your identity and contact info</p>
                </div>
                <div class="brand-icon" style="width: 32px; height: 32px; font-size: 0.85rem;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                </div>
            </div>

            <div class="card-body">
                
                <?php if (!empty($profileSuccess)) : ?>
                    <div class="alert alert-success" role="alert">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <span><?= htmlspecialchars($profileSuccess, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (isset($profileErrors['csrf'])) : ?>
                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($profileErrors['csrf'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if (isset($profileErrors['system'])) : ?>
                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($profileErrors['system'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <form action="profile.php" method="POST" novalidate id="profileForm">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action_type" value="update_profile">

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <!-- First Name -->
                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name <span class="required">*</span></label>
                            <input 
                                type="text" 
                                id="first_name" 
                                name="first_name" 
                                class="form-control" 
                                value="<?= htmlspecialchars((string)$currentUser['first_name'], ENT_QUOTES, 'UTF-8'); ?>" 
                                required
                            >
                            <?php if (isset($profileErrors['first_name'])) : ?>
                                <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($profileErrors['first_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Last Name -->
                        <div class="form-group">
                            <label for="last_name" class="form-label">Last Name <span class="required">*</span></label>
                            <input 
                                type="text" 
                                id="last_name" 
                                name="last_name" 
                                class="form-control" 
                                value="<?= htmlspecialchars((string)$currentUser['last_name'], ENT_QUOTES, 'UTF-8'); ?>" 
                                required
                            >
                            <?php if (isset($profileErrors['last_name'])) : ?>
                                <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($profileErrors['last_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Email Address -->
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address <span class="required">*</span></label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-control" 
                            value="<?= htmlspecialchars((string)$currentUser['email'], ENT_QUOTES, 'UTF-8'); ?>" 
                            required
                        >
                        <?php if (isset($profileErrors['email'])) : ?>
                            <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($profileErrors['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Username -->
                    <div class="form-group">
                        <label for="username" class="form-label">Username <span class="required">*</span></label>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            class="form-control" 
                            value="<?= htmlspecialchars((string)$currentUser['username'], ENT_QUOTES, 'UTF-8'); ?>" 
                            required
                        >
                        <?php if (isset($profileErrors['username'])) : ?>
                            <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($profileErrors['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Read-Only Context Details -->
                    <div style="background: rgba(0,0,0,0.2); border-radius: var(--radius-sm); padding: 0.85rem; margin-bottom: 1.5rem; font-size: 0.8125rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                            <span style="color: var(--text-muted);">Account ID:</span>
                            <span style="color: var(--text-primary); font-family: monospace;">#<?= (int)$currentUser['user_id']; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-muted);">Member Since:</span>
                            <span style="color: var(--text-secondary);"><?= date('F j, Y', strtotime((string)$currentUser['created_at'])); ?></span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        Save Profile Changes
                    </button>
                </form>

            </div>
        </div>

        <!-- ================================================================= -->
        <!-- Card 2: Change Password                                          -->
        <!-- ================================================================= -->
        <div class="card glass-panel">
            <div class="card-header">
                <div>
                    <h2 style="font-size: 1.25rem;">Change Password</h2>
                    <p style="font-size: 0.8125rem; color: var(--text-muted); margin: 0;">Keep your kitchen account safe and secure</p>
                </div>
                <div class="brand-icon" style="width: 32px; height: 32px; background: linear-gradient(135deg, #06b6d4, #3b82f6);">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
            </div>

            <div class="card-body">
                
                <?php if (!empty($passwordSuccess)) : ?>
                    <div class="alert alert-success" role="alert">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <span><?= htmlspecialchars($passwordSuccess, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (isset($passwordErrors['csrf'])) : ?>
                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($passwordErrors['csrf'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if (isset($passwordErrors['system'])) : ?>
                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($passwordErrors['system'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <form action="profile.php" method="POST" novalidate id="passwordForm">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action_type" value="change_password">

                    <!-- Current Password -->
                    <div class="form-group">
                        <label for="current_password" class="form-label">Current Password <span class="required">*</span></label>
                        <div class="input-icon-wrap">
                            <input 
                                type="password" 
                                id="current_password" 
                                name="current_password" 
                                class="form-control" 
                                placeholder="Enter current password"
                                required
                            >
                        </div>
                        <?php if (isset($passwordErrors['current_password'])) : ?>
                            <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($passwordErrors['current_password'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- New Password -->
                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password <span class="required">*</span></label>
                        <input 
                            type="password" 
                            id="new_password" 
                            name="new_password" 
                            class="form-control" 
                            placeholder="At least 8 characters"
                            minlength="8"
                            required
                        >
                        <?php if (isset($passwordErrors['new_password'])) : ?>
                            <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($passwordErrors['new_password'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="form-group">
                        <label for="new_password_confirm" class="form-label">Confirm New Password <span class="required">*</span></label>
                        <input 
                            type="password" 
                            id="new_password_confirm" 
                            name="new_password_confirm" 
                            class="form-control" 
                            placeholder="Repeat new password"
                            required
                        >
                        <?php if (isset($passwordErrors['new_password_confirm'])) : ?>
                            <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($passwordErrors['new_password_confirm'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-secondary" style="width: 100%;">
                            Update Password
                        </button>
                    </div>
                </form>

            </div>
        </div>

    </div>

</div>

<?php
// Include the shared platform footer
require_once __DIR__ . '/../includes/footer.php';
?>
