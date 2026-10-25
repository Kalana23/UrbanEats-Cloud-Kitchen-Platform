<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Password Reset Request Flow (Forgot Password)
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

$email = '';
$errors = [];
$generatedResetLink = null;
$tokenExpirationMinutes = 60; // 1-hour expiration

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // 1. Verify CSRF Token
    if (!validate_csrf_token()) {
        $errors['csrf'] = 'Invalid or expired CSRF token. Please refresh and try again.';
    }

    // 2. Validate Email
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    // 3. Process Reset Request
    if (empty($errors)) {
        try {
            $userStmt = $pdo->prepare('SELECT user_id, email, username FROM users WHERE email = :email LIMIT 1');
            $userStmt->execute([':email' => $email]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Generate secure 64-char hex token
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + ($tokenExpirationMinutes * 60));

                // Invalidate any previous reset tokens for this email
                $delStmt = $pdo->prepare('DELETE FROM password_resets WHERE email = :email');
                $delStmt->execute([':email' => $email]);

                // Insert token record into password_resets using MySQL native time
                $insStmt = $pdo->prepare('
                    INSERT INTO password_resets (email, token, expires_at)
                    VALUES (:email, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))
                ');
                $insStmt->execute([
                    ':email' => $email,
                    ':token' => $token,
                ]);

                // Record event in audit_log
                log_action(
                    $pdo,
                    (int)$user['user_id'],
                    'PASSWORD_RESET_REQUESTED',
                    sprintf("Password reset link requested for '%s' (%s).", $user['username'], $email)
                );

                // Rotate CSRF token
                regenerate_csrf_token();

                // Compute relative and absolute URLs
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $dir = rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/UrbanEats-Cloud-Kitchen-Platform/public/'), '/\\');
                
                $generatedResetLink = "reset-password.php?token={$token}";
                $fullResetUrl = "{$protocol}://{$host}{$dir}/reset-password.php?token={$token}";

            } else {
                $errors['email'] = 'No account was found with that email address. Please check for typos or register.';
            }
        } catch (PDOException $e) {
            error_log('Password reset request error: ' . $e->getMessage());
            $errors['system'] = 'An unexpected server error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - UrbanEats Cloud Kitchen</title>
    
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
            background: linear-gradient(135deg, var(--primary), #e11d48);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            margin-bottom: 0.85rem;
            box-shadow: 0 6px 20px var(--primary-glow);
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

        /* Token Simulation Display Box */
        .simulation-box {
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: var(--radius-md);
            padding: 1.5rem 1.25rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .simulation-badge {
            display: inline-block;
            background: rgba(16, 185, 129, 0.18);
            color: #34d399;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.25rem 0.65rem;
            border-radius: 99px;
            margin-bottom: 0.75rem;
        }

        .token-url-box {
            background: #090c14;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-sm);
            padding: 0.75rem;
            font-family: var(--font-mono);
            font-size: 0.8125rem;
            word-break: break-all;
            color: #cbd5e1;
            margin: 1rem 0;
            user-select: all;
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

        <!-- Header -->
        <header class="brand-header">
            <div class="brand-icon-wrap">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <h1 class="auth-title">Forgot Password</h1>
            <p class="auth-subtitle">Enter your registered email address and we will generate a secure reset link.</p>
        </header>

        <!-- Simulated Link Display State -->
        <?php if ($generatedResetLink !== null): ?>
            
            <div class="simulation-box">
                <span class="simulation-badge">Reset Token Generated</span>
                <p style="color: var(--text-primary); font-size: 0.95rem; margin-bottom: 0.5rem; font-weight: 600;">
                    Reset link generated for <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p style="font-size: 0.8125rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                    (Direct screen display per project assignment specification &bull; valid for <?= $tokenExpirationMinutes; ?> minutes)
                </p>

                <div class="token-url-box">
                    <?= htmlspecialchars($fullResetUrl ?? $generatedResetLink, ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <a href="<?= htmlspecialchars($generatedResetLink, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary" style="width: 100%;">
                    Reset Password Now &rarr;
                </a>
            </div>

            <div style="text-align: center; margin-top: 1rem;">
                <a href="forgot-password.php" style="font-size: 0.85rem; color: var(--text-muted);">
                    Request for a different email
                </a>
            </div>

        <?php else: ?>

            <!-- Alerts -->
            <?php if (isset($errors['csrf'])): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($errors['csrf'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if (isset($errors['system'])): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($errors['system'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <!-- Form -->
            <form action="forgot-password.php" method="POST" novalidate id="forgotForm">
                <?= csrf_field(); ?>

                <div class="form-group">
                    <label for="email" class="form-label">
                        Email Address <span class="required">*</span>
                    </label>
                    <div class="input-icon-wrap" style="position: relative;">
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-control <?= isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" 
                            placeholder="your.email@example.com"
                            required
                            autocomplete="email"
                            autofocus
                        >
                    </div>
                    <?php if (isset($errors['email'])): ?>
                        <span style="color: var(--danger); font-size: 0.8125rem; margin-top: 0.35rem; display: block;">
                            <?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;" id="submitForgotBtn">
                    Generate Reset Link &rarr;
                </button>
            </form>

        <?php endif; ?>

        <footer class="auth-footer">
            Remembered your credentials? <a href="login.php">Back to Sign In</a>
        </footer>

    </div>
</main>

</body>
</html>
