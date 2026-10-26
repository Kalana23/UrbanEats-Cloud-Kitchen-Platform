<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Password Reset Execution (Reset Password via Token)
 *
 * PHP version 8.x
 */

declare(strict_types=1);

// Dependencies
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$errors = [];
$resetSuccess = false;
$validResetRecord = null;

// Validate Token in Database
if ($token !== '') {
    try {
        $stmt = $pdo->prepare('
            SELECT r.reset_id, r.email, r.token, r.expires_at, u.user_id, u.username
            FROM password_resets r
            JOIN users u ON r.email = u.email
            WHERE r.token = :token 
              AND r.expires_at >= NOW()
            LIMIT 1
        ');
        $stmt->execute([':token' => $token]);
        $validResetRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Token lookup error: ' . $e->getMessage());
        $errors['system'] = 'An unexpected server error occurred. Please try again.';
    }
}

// Handle Form Submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $validResetRecord) {
    // 1. Verify CSRF Token
    if (!validate_csrf_token()) {
        $errors['csrf'] = 'Invalid or expired CSRF token. Please refresh and try again.';
    }

    // 2. Validate New Password
    $newPassword        = (string)($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');

    if ($newPassword === '') {
        $errors['new_password'] = 'New password is required.';
    } elseif (mb_strlen($newPassword) < 8) {
        $errors['new_password'] = 'Password must be at least 8 characters long.';
    }

    if ($newPasswordConfirm === '') {
        $errors['new_password_confirm'] = 'Please confirm your new password.';
    } elseif ($newPassword !== $newPasswordConfirm) {
        $errors['new_password_confirm'] = 'Passwords do not match.';
    }

    // 3. Update Password
    if (empty($errors)) {
        try {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            // Update user password
            $updateStmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :user_id');
            $updateStmt->execute([
                ':hash'    => $newHash,
                ':user_id' => (int)$validResetRecord['user_id'],
            ]);

            // Invalidate/delete tokens for this email to prevent reuse
            $delStmt = $pdo->prepare('DELETE FROM password_resets WHERE email = :email');
            $delStmt->execute([':email' => $validResetRecord['email']]);

            // Record event in audit_log
            log_action(
                $pdo,
                (int)$validResetRecord['user_id'],
                'PASSWORD_RESET_COMPLETED',
                sprintf("Password reset completed successfully for user '%s'.", $validResetRecord['username'])
            );

            // Rotate CSRF token
            regenerate_csrf_token();
            $resetSuccess = true;
        } catch (PDOException $e) {
            error_log('Password reset completion error: ' . $e->getMessage());
            $errors['system'] = 'Failed to update password due to a server error.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - UrbanEats Cloud Kitchen</title>
    
    <!-- CSRF Meta Header -->
    <?= csrf_meta(); ?>

    <!-- Typography: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Platform Stylesheet -->
    <link rel="stylesheet" href="css/style.css">

    <style>
        .auth-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.25rem;
        }

        .auth-card {
            width: 100%;
            max-width: 480px;
            background: var(--bg-surface-glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            padding: 2.75rem 2.25rem;
            position: relative;
            overflow: hidden;
            animation: fadeIn 0.4s ease;
        }

        .auth-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .brand-icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, #06b6d4, #3b82f6);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            margin-bottom: 0.85rem;
            box-shadow: 0 6px 20px rgba(6, 182, 212, 0.3);
        }

        .auth-title {
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 0.35rem;
        }

        .auth-subtitle {
            font-size: 0.925rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .success-box {
            text-align: center;
            padding: 1.5rem 0.5rem;
        }

        .success-icon-wrap {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--success-bg);
            border: 2px solid var(--success-border);
            color: var(--success);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }

        .auth-footer {
            text-align: center;
            margin-top: 1.75rem;
            font-size: 0.925rem;
            color: var(--text-muted);
        }

        .auth-footer a {
            color: var(--primary);
            font-weight: 700;
        }
    </style>
</head>
<body>

<main class="auth-wrapper">
    <div class="auth-card">

        <header class="brand-header">
            <div class="brand-icon-wrap">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <h1 class="auth-title">Set New Password</h1>
            <p class="auth-subtitle">Create a new, strong password for your kitchen platform account.</p>
        </header>

        <!-- Case 1: Successfully Reset -->
        <?php if ($resetSuccess) : ?>
            <div class="success-box">
                <div class="success-icon-wrap">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <h2 style="font-size: 1.35rem; color: var(--text-primary); margin-bottom: 0.5rem;">Password Updated!</h2>
                <p style="color: var(--text-secondary); font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem;">
                    Your account password has been successfully reset. You can now sign in with your new credentials.
                </p>
                <a href="login.php" class="btn btn-primary" style="width: 100%;">
                    Proceed to Sign In &rarr;
                </a>
            </div>

        <!-- Case 2: Invalid or Expired Token -->
        <?php elseif (!$validResetRecord) : ?>
            <div class="alert alert-danger" style="display: block; text-align: center; padding: 1.75rem 1.25rem;">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-bottom: 0.75rem; color: var(--danger);">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <h3 style="color: #fecdd3; font-size: 1.15rem; margin-bottom: 0.5rem;">Link Expired or Invalid</h3>
                <p style="color: #fecdd3; font-size: 0.875rem; line-height: 1.5; margin-bottom: 1.25rem;">
                    This password reset link is invalid or has expired (links expire after 60 minutes for security). Please generate a new link.
                </p>
                <a href="forgot-password.php" class="btn btn-primary" style="width: 100%;">
                    Request New Reset Link
                </a>
            </div>

        <!-- Case 3: Form to Enter New Password -->
        <?php else : ?>
            <?php if (isset($errors['csrf'])) : ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($errors['csrf'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if (isset($errors['system'])) : ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($errors['system'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form action="reset-password.php" method="POST" novalidate id="resetForm">
                <?= csrf_field(); ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                <!-- Context Note -->
                <div style="background: rgba(0,0,0,0.25); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.85rem;">
                    Resetting password for: <strong style="color: var(--text-primary);"><?= htmlspecialchars((string)$validResetRecord['email'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>

                <!-- New Password -->
                <div class="form-group">
                    <label for="new_password" class="form-label">
                        New Password <span class="required">*</span>
                    </label>
                    <input 
                        type="password" 
                        id="new_password" 
                        name="new_password" 
                        class="form-control" 
                        placeholder="At least 8 characters" 
                        minlength="8" 
                        required 
                        autofocus
                    >
                    <?php if (isset($errors['new_password'])) : ?>
                        <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($errors['new_password'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label for="new_password_confirm" class="form-label">
                        Confirm New Password <span class="required">*</span>
                    </label>
                    <input 
                        type="password" 
                        id="new_password_confirm" 
                        name="new_password_confirm" 
                        class="form-control" 
                        placeholder="Repeat new password" 
                        required
                    >
                    <?php if (isset($errors['new_password_confirm'])) : ?>
                        <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($errors['new_password_confirm'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.75rem;" id="submitResetBtn">
                    Update Password &rarr;
                </button>
            </form>

        <?php endif; ?>

        <footer class="auth-footer">
            Remember your credentials? <a href="login.php">Back to Sign In</a>
        </footer>

    </div>
</main>

</body>
</html>
