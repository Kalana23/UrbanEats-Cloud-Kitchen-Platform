<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * User Authentication & Login Page
 *
 * PHP version 8.x
 */

declare(strict_types=1);

// Include dependencies
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, redirect to index
if (is_logged_in()) {
    header('Location: index.php');
    exit();
}

// Configuration parameters for brute-force protection
const MAX_FAILED_ATTEMPTS = 5;
const LOCKOUT_MINUTES     = 15;
const LOCKOUT_SECONDS     = LOCKOUT_MINUTES * 60;

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$errors = [];
$email = '';
$isLockedOut = false;
$lockoutRemainingMinutes = 0;

/**
 * Check login throttling status for the given email and client IP.
 * Returns array: ['locked' => bool, 'remaining_minutes' => int]
 */
function check_login_throttling(PDO $pdo, string $email, string $ip): array
{
    try {
        // Find timestamp of last successful login for this email/IP
        $lastSuccessStmt = $pdo->prepare('
            SELECT MAX(attempt_time) 
            FROM login_attempts 
            WHERE (email = :email OR ip_address = :ip) 
              AND is_successful = 1
        ');
        $lastSuccessStmt->execute([':email' => $email, ':ip' => $ip]);
        $lastSuccessTime = $lastSuccessStmt->fetchColumn() ?: null;

        // Query failed attempts within the 15-minute window occurring after last success
        $sql = '
            SELECT COUNT(*) AS failed_count, MAX(attempt_time) AS latest_attempt
            FROM login_attempts
            WHERE (email = :email OR ip_address = :ip)
              AND is_successful = 0
              AND attempt_time >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ';
        if ($lastSuccessTime !== null) {
            $sql .= ' AND attempt_time > :last_success';
        }

        $stmt = $pdo->prepare($sql);
        $params = [':email' => $email, ':ip' => $ip];
        if ($lastSuccessTime !== null) {
            $params[':last_success'] = $lastSuccessTime;
        }
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $failedCount = (int)($result['failed_count'] ?? 0);
        $latestAttempt = $result['latest_attempt'] ?? null;

        if ($failedCount >= MAX_FAILED_ATTEMPTS && $latestAttempt !== null) {
            $elapsedSeconds = time() - strtotime((string)$latestAttempt);
            $remainingSeconds = LOCKOUT_SECONDS - $elapsedSeconds;

            if ($remainingSeconds > 0) {
                return [
                    'locked'            => true,
                    'remaining_minutes' => (int)ceil($remainingSeconds / 60),
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('Throttling check error: ' . $e->getMessage());
    }

    return ['locked' => false, 'remaining_minutes' => 0];
}

/**
 * Record a login attempt in the login_attempts table.
 */
function record_login_attempt(PDO $pdo, string $email, string $ip, bool $isSuccessful): void
{
    try {
        $stmt = $pdo->prepare('
            INSERT INTO login_attempts (email, ip_address, is_successful, attempt_time)
            VALUES (:email, :ip_address, :is_successful, NOW())
        ');
        $stmt->execute([
            ':email'         => $email,
            ':ip_address'    => $ip,
            ':is_successful' => $isSuccessful ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        error_log('Failed to record login attempt: ' . $e->getMessage());
    }
}

// Process POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. CSRF Token Validation
    if (!validate_csrf_token()) {
        $errors['csrf'] = 'Invalid or expired security token. Please refresh and try again.';
    }

    // 2. Input Retrieval and Sanitization
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    // Server-side validation
    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    }

    // 3. Login Throttling Check (if email is provided)
    if ($email !== '') {
        $throttleStatus = check_login_throttling($pdo, $email, $clientIp);
        if ($throttleStatus['locked']) {
            $isLockedOut = true;
            $lockoutRemainingMinutes = $throttleStatus['remaining_minutes'];
            $errors['throttled'] = sprintf(
                'Too many consecutive failed login attempts. Access is locked for security. Please try again in %d minute%s.',
                $lockoutRemainingMinutes,
                $lockoutRemainingMinutes === 1 ? '' : 's'
            );
        }
    }

    // 4. Authentication Check (if validation passed and not locked out)
    if (empty($errors)) {
        try {
            // Fetch user and joined role information
            $userStmt = $pdo->prepare('
                SELECT u.user_id, u.first_name, u.last_name, u.email, u.username, 
                       u.password_hash, u.role_id, u.is_active, r.role_name
                FROM users u
                JOIN roles r ON u.role_id = r.role_id
                WHERE u.email = :email
                LIMIT 1
            ');
            $userStmt->execute([':email' => $email]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            // Verify password using password_verify
            if ($user && password_verify($password, (string)$user['password_hash'])) {
                // Check if account is active
                if ((int)$user['is_active'] !== 1) {
                    record_login_attempt($pdo, $email, $clientIp, false);
                    $errors['auth'] = 'Your account has been deactivated. Please contact kitchen support.';
                } else {
                    // Successful login: Record attempt
                    record_login_attempt($pdo, $email, $clientIp, true);

                    // Prevent session fixation attack
                    session_regenerate_id(true);

                    // Store required user details in $_SESSION
                    $_SESSION['user_id']   = (int)$user['user_id'];
                    $_SESSION['role_id']   = (int)$user['role_id'];
                    $_SESSION['role']      = (string)$user['role_name'];
                    $_SESSION['role_name'] = (string)$user['role_name'];
                    $_SESSION['username']  = (string)$user['username'];
                    $_SESSION['first_name'] = (string)$user['first_name'];
                    $_SESSION['last_name'] = (string)$user['last_name'];
                    $_SESSION['email']     = (string)$user['email'];

                    // Record event in audit_log
                    log_action(
                        $pdo,
                        (int)$user['user_id'],
                        'USER_LOGIN',
                        sprintf("User '%s' (%s) signed in from %s.", $user['username'], $user['role_name'], $clientIp)
                    );

                    // Rotate CSRF token
                    regenerate_csrf_token();

                    // Role-based landing redirect
                    $landing = match (strtolower((string)$user['role_name'])) {
                        'admin', 'administrator' => 'admin/dashboard.php',
                        'staff', 'kitchen staff' => 'kitchen/orders.php',
                        default                  => 'index.php',
                    };

                    // Fallback to index.php if role target does not yet exist
                    if (!file_exists(__DIR__ . '/' . $landing)) {
                        $landing = 'index.php';
                    }

                    header("Location: {$landing}");
                    exit();
                }
            } else {
                // Record failed login attempt
                record_login_attempt($pdo, $email, $clientIp, false);

                // Check if this latest failure triggered a lockout
                $throttleAfterFail = check_login_throttling($pdo, $email, $clientIp);
                if ($throttleAfterFail['locked']) {
                    $errors['throttled'] = sprintf(
                        'Account temporarily locked for %d minutes due to 5 consecutive failed login attempts.',
                        $throttleAfterFail['remaining_minutes']
                    );
                } else {
                    $errors['auth'] = 'Invalid email address or password.';
                }
            }
        } catch (PDOException $e) {
            error_log('Login processing error: ' . $e->getMessage());
            $errors['system'] = 'An unexpected system error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - UrbanEats Cloud Kitchen</title>
    <meta name="description" content="Sign in to your UrbanEats Cloud Kitchen account.">

    <!-- CSRF Meta Header -->
    <?= csrf_meta(); ?>

    <!-- Typography: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Platform Stylesheet -->
    <link rel="stylesheet" href="css/style.css">

    <!-- Immediate Anti-FOUC Theme Pre-loader -->
    <script>
        (function () {
            try {
                if (localStorage.getItem('urbaneats_theme') === 'light') {
                    document.documentElement.classList.add('light-mode');
                    if (document.body) {
                        document.body.classList.add('light-mode');
                    } else {
                        document.addEventListener('DOMContentLoaded', function () {
                            if (document.body) {
                                document.body.classList.add('light-mode');
                            }
                        });
                    }
                }
            } catch (e) {}
        })();
    </script>

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
            max-width: 460px;
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
        }

        .input-icon-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            color: var(--text-muted);
            width: 18px;
            height: 18px;
            pointer-events: none;
            transition: var(--transition-fast);
        }

        .form-control.has-icon {
            padding-left: 2.75rem;
            padding-right: 2.75rem;
        }

        .form-control:focus + .input-icon {
            color: var(--primary);
        }

        .password-toggle-btn {
            position: absolute;
            right: 0.85rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.25rem;
            display: flex;
            align-items: center;
            transition: var(--transition-fast);
        }

        .password-toggle-btn:hover {
            color: var(--text-primary);
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

        /* Standalone Auth Theme Toggle Top-Right Layout */
        .auth-top-actions {
            position: fixed;
            top: 1.25rem;
            right: 1.25rem;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .auth-nav-home {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 0.85rem;
            border-radius: var(--radius-md, 10px);
            background: var(--bg-surface-elevated, #1a2234);
            border: 1px solid var(--border-medium, rgba(255, 255, 255, 0.12));
            color: var(--text-secondary, #94a3b8);
            font-size: 0.8125rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .auth-nav-home:hover {
            background: var(--primary-subtle, rgba(249, 115, 22, 0.12));
            border-color: var(--primary, #f97316);
            color: var(--primary, #f97316);
            transform: translateY(-1px);
        }

        /* Theme Toggle Button Base */
        .theme-toggle-btn {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--bg-surface-elevated, #1a2234);
            border: 1px solid var(--border-medium, rgba(255, 255, 255, 0.14));
            color: var(--text-primary, #f8fafc);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 0;
            flex-shrink: 0;
        }

        .theme-toggle-btn:hover {
            background: rgba(249, 115, 22, 0.15);
            border-color: #f97316;
            color: #f97316;
            transform: translateY(-1px);
        }

        .theme-toggle-btn .theme-icon-sun {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .theme-toggle-btn .theme-icon-moon {
            display: none !important;
        }

        /* Light Mode Theme Overrides */
        :root.light-mode body,
        body.light-mode {
            background-color: #f8fafc !important;
            color: #475569 !important;
            background-image: 
                radial-gradient(circle at 10% 10%, rgba(234, 88, 12, 0.05), transparent 40%),
                radial-gradient(circle at 90% 90%, rgba(2, 132, 199, 0.04), transparent 45%) !important;
        }

        :root.light-mode .auth-card,
        body.light-mode .auth-card {
            background: rgba(255, 255, 255, 0.95);
            border-color: rgba(15, 23, 42, 0.09);
            box-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.08), 0 4px 10px -3px rgba(15, 23, 42, 0.04);
        }

        :root.light-mode .auth-nav-home,
        body.light-mode .auth-nav-home {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #475569;
        }

        :root.light-mode .auth-nav-home:hover,
        body.light-mode .auth-nav-home:hover {
            background: rgba(234, 88, 12, 0.08);
            border-color: #ea580c;
            color: #ea580c;
        }

        :root.light-mode .theme-toggle-btn,
        body.light-mode .theme-toggle-btn {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        :root.light-mode .theme-toggle-btn:hover,
        body.light-mode .theme-toggle-btn:hover {
            background: rgba(234, 88, 12, 0.1);
            border-color: #ea580c;
            color: #ea580c;
        }

        :root.light-mode .theme-toggle-btn .theme-icon-sun,
        body.light-mode .theme-toggle-btn .theme-icon-sun {
            display: none !important;
        }

        :root.light-mode .theme-toggle-btn .theme-icon-moon,
        body.light-mode .theme-toggle-btn .theme-icon-moon {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>

<!-- Standalone Light/Dark Theme Toggle & Top Navigation -->
<div class="auth-top-actions">
    <a href="index.php" class="auth-nav-home" title="Return to Home">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        <span>Home</span>
    </a>
    <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Toggle Light/Dark Theme" title="Toggle Light/Dark Theme">
        <!-- Sun Icon (Active in Dark Mode) -->
        <span class="theme-icon-sun">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="5"></circle>
                <line x1="12" y1="1" x2="12" y2="3"></line>
                <line x1="12" y1="21" x2="12" y2="23"></line>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                <line x1="1" y1="12" x2="3" y2="12"></line>
                <line x1="21" y1="12" x2="23" y2="12"></line>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>
        </span>
        <!-- Moon Icon (Active in Light Mode) -->
        <span class="theme-icon-moon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
            </svg>
        </span>
    </button>
</div>

<main class="auth-wrapper">
    <div class="auth-card">

        <!-- Brand Header -->
        <header class="brand-header">
            <div class="brand-icon-wrap">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M18 8h1a4 4 0 0 1 0 8h-1"></path>
                    <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path>
                </svg>
            </div>
            <h1 class="auth-title">Welcome Back</h1>
            <p class="auth-subtitle">Sign in to your UrbanEats account</p>
        </header>

        <!-- Informational Messages -->
        <?php if (isset($_GET['registered'])) : ?>
            <div class="alert alert-success" role="alert">
                Account created successfully! Please sign in with your credentials.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['logged_out'])) : ?>
            <div class="alert alert-info" role="alert">
                You have been signed out safely. See you soon!
            </div>
        <?php endif; ?>

        <!-- Throttling Lockout Alert -->
        <?php if (isset($errors['throttled'])) : ?>
            <div class="alert alert-danger" role="alert">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <span><?= htmlspecialchars($errors['throttled'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>

        <!-- General / Auth / CSRF Errors -->
        <?php if (isset($errors['auth'])) : ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($errors['auth'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['csrf'])) : ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($errors['csrf'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['system'])) : ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($errors['system'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form action="login.php" method="POST" novalidate id="loginForm">
            
            <!-- Reusable CSRF Field -->
            <?= csrf_field(); ?>

            <!-- Email Address Field -->
            <div class="form-group">
                <label for="email" class="form-label">
                    Email Address <span class="required">*</span>
                </label>
                <div class="input-icon-wrap">
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-control has-icon <?= isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                        value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" 
                        placeholder="e.g. chef@urbaneats.local"
                        required 
                        autocomplete="email"
                        autofocus
                    >
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                    </svg>
                </div>
                <?php if (isset($errors['email'])) : ?>
                    <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </div>

            <!-- Password Field -->
            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                    <label for="password" class="form-label" style="margin-bottom: 0;">
                        Password <span class="required">*</span>
                    </label>
                    <a href="forgot-password.php" style="font-size: 0.8125rem; color: var(--primary);">Forgot password?</a>
                </div>
                <div class="input-icon-wrap">
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-control has-icon <?= isset($errors['password']) ? 'is-invalid' : ''; ?>" 
                        placeholder="Enter your password"
                        required 
                        autocomplete="current-password"
                    >
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    <button type="button" class="password-toggle-btn" id="togglePasswordBtn" aria-label="Toggle password visibility">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
                <?php if (isset($errors['password'])) : ?>
                    <span style="color: var(--danger); font-size: 0.8125rem;"><?= htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.75rem;" id="loginSubmit">
                Sign In &rarr;
            </button>

        </form>

        <footer class="auth-footer">
            Don't have an account yet? <a href="register.php">Create Customer Account</a>
        </footer>

    </div>
</main>

<script>
    // Password visibility toggle
    const toggleBtn = document.getElementById('togglePasswordBtn');
    const pwdInput = document.getElementById('password');

    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', function() {
            const isPassword = pwdInput.type === 'password';
            pwdInput.type = isPassword ? 'text' : 'password';
            toggleBtn.style.color = isPassword ? 'var(--primary)' : 'var(--text-muted)';
        });
    }
</script>

<!-- Theme Mode Toggle Script with LocalStorage Persistence -->
<script>
    (function () {
        function getSavedTheme() {
            try {
                return localStorage.getItem('urbaneats_theme') || 'dark';
            } catch (e) {
                return 'dark';
            }
        }

        function setSavedTheme(theme) {
            try {
                localStorage.setItem('urbaneats_theme', theme);
            } catch (e) {}
        }

        function applyTheme(theme) {
            if (theme === 'light') {
                document.documentElement.classList.add('light-mode');
                if (document.body) {
                    document.body.classList.add('light-mode');
                }
            } else {
                document.documentElement.classList.remove('light-mode');
                if (document.body) {
                    document.body.classList.remove('light-mode');
                }
            }
        }

        // Apply immediately
        applyTheme(getSavedTheme());

        // Attach click listener to all theme toggle buttons
        const toggleButtons = document.querySelectorAll('.theme-toggle-btn');
        toggleButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const isCurrentlyLight = document.documentElement.classList.contains('light-mode');
                const targetTheme = isCurrentlyLight ? 'dark' : 'light';
                applyTheme(targetTheme);
                setSavedTheme(targetTheme);
            });
        });
    })();
</script>

</body>
</html>
