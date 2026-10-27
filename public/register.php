<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Customer Account Registration Page (PHP 8.x)
 */

declare(strict_types=1);

// Load database connection and authentication utilities
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Ensure session is active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if one does not exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// State variables
$errors = [];
$success_message = '';
$form_data = [
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'username'   => '',
];

// Handle form submission via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $posted_token)) {
        $errors['csrf'] = 'Invalid or expired security token. Please refresh and try again.';
    }

    // 2. Sanitize and retrieve form inputs
    $first_name       = trim((string)($_POST['first_name'] ?? ''));
    $last_name        = trim((string)($_POST['last_name'] ?? ''));
    $email            = trim((string)($_POST['email'] ?? ''));
    $username         = trim((string)($_POST['username'] ?? ''));
    $password         = (string)($_POST['password'] ?? '');
    $password_confirm = (string)($_POST['password_confirm'] ?? '');

    // Retain sanitized values for repopulation
    $form_data['first_name'] = $first_name;
    $form_data['last_name']  = $last_name;
    $form_data['email']      = $email;
    $form_data['username']   = $username;

    // 3. Server-side Validation

    // First Name validation
    if ($first_name === '') {
        $errors['first_name'] = 'First name is required.';
    } elseif (mb_strlen($first_name) > 100) {
        $errors['first_name'] = 'First name cannot exceed 100 characters.';
    } elseif (!preg_match("/^[a-zA-Z\s'-]+$/u", $first_name)) {
        $errors['first_name'] = 'First name can only contain letters, spaces, hyphens, and apostrophes.';
    }

    // Last Name validation
    if ($last_name === '') {
        $errors['last_name'] = 'Last name is required.';
    } elseif (mb_strlen($last_name) > 100) {
        $errors['last_name'] = 'Last name cannot exceed 100 characters.';
    } elseif (!preg_match("/^[a-zA-Z\s'-]+$/u", $last_name)) {
        $errors['last_name'] = 'Last name can only contain letters, spaces, hyphens, and apostrophes.';
    }

    // Email validation
    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (mb_strlen($email) > 150) {
        $errors['email'] = 'Email address cannot exceed 150 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } else {
        // Check uniqueness in database
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
            $stmt->execute([':email' => $email]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors['email'] = 'This email address is already registered.';
            }
        } catch (PDOException $e) {
            error_log('Email uniqueness check failed: ' . $e->getMessage());
            $errors['system'] = 'An unexpected error occurred. Please try again later.';
        }
    }

    // Username validation
    if ($username === '') {
        $errors['username'] = 'Username is required.';
    } elseif (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
        $errors['username'] = 'Username must be between 3 and 50 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
        $errors['username'] = 'Username can only contain letters, numbers, underscores, dashes, and periods.';
    } else {
        // Check uniqueness in database
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
            $stmt->execute([':username' => $username]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors['username'] = 'This username is already taken. Please choose another.';
            }
        } catch (PDOException $e) {
            error_log('Username uniqueness check failed: ' . $e->getMessage());
            $errors['system'] = 'An unexpected error occurred. Please try again later.';
        }
    }

    // Password validation
    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (mb_strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $password_confirm) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }

    // 4. Insertion if validation passes
    if (empty($errors)) {
        // Hash password securely using standard algorithm
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $customer_role_id = 3; // Default role_id for Customer

        try {
            // Prepared insert query
            $insert_sql = 'INSERT INTO users (first_name, last_name, email, username, password_hash, role_id, is_active)
                           VALUES (:first_name, :last_name, :email, :username, :password_hash, :role_id, 1)';
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                ':first_name'    => $first_name,
                ':last_name'     => $last_name,
                ':email'         => $email,
                ':username'      => $username,
                ':password_hash' => $password_hash,
                ':role_id'       => $customer_role_id,
            ]);

            $new_user_id = (int)$pdo->lastInsertId();

            // Log action in audit_log
            log_action(
                $pdo,
                $new_user_id,
                'USER_REGISTRATION',
                "New customer registered: {$username} ({$email})"
            );

            // Rotate CSRF token on success
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // Set success state
            $success_message = "Welcome to UrbanEats, {$first_name}! Your customer account has been created successfully.";

            // Clear form data
            $form_data = [
                'first_name' => '',
                'last_name'  => '',
                'email'      => '',
                'username'   => '',
            ];
        } catch (PDOException $e) {
            error_log('User registration insert failed: ' . $e->getMessage());
            $errors['system'] = 'Registration failed due to a server error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Your Account - UrbanEats Cloud Kitchen</title>
    <meta name="description" content="Register for an UrbanEats Cloud Kitchen customer account to order gourmet chef-crafted dishes.">
    
    <!-- Modern Typography: Plus Jakarta Sans -->
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
        :root {
            /* Palette: UrbanEats Culinary Amber & Slate */
            --primary: #f97316;
            --primary-hover: #ea580c;
            --primary-glow: rgba(249, 115, 22, 0.35);
            --primary-subtle: rgba(249, 115, 22, 0.12);
            --accent: #06b6d4;

            /* Surface & Background */
            --bg-body: #0b0f19;
            --bg-card: rgba(18, 24, 38, 0.85);
            --bg-input: #0e1422;
            --bg-input-focus: #141c30;

            /* Borders */
            --border-card: rgba(255, 255, 255, 0.08);
            --border-input: rgba(255, 255, 255, 0.12);
            --border-focus: #f97316;

            /* Typography */
            --font-main: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --text-heading: #f8fafc;
            --text-body: #94a3b8;
            --text-muted: #64748b;

            /* Status & Feedback */
            --danger: #f43f5e;
            --danger-bg: rgba(244, 63, 94, 0.12);
            --danger-border: rgba(244, 63, 94, 0.35);
            --success: #10b981;
            --success-bg: rgba(16, 185, 129, 0.12);
            --success-border: rgba(16, 185, 129, 0.35);

            /* Radii & Shadows */
            --radius-input: 12px;
            --radius-card: 24px;
            --radius-btn: 12px;
            --shadow-card: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
            --shadow-glow: 0 0 40px rgba(249, 115, 22, 0.18);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Light Mode Palette Overrides */
        :root.light-mode,
        body.light-mode {
            --primary: #ea580c;
            --primary-hover: #c2410c;
            --primary-glow: rgba(234, 88, 12, 0.22);
            --primary-subtle: rgba(234, 88, 12, 0.10);
            --accent: #0284c7;

            /* Surface & Background */
            --bg-body: #f8fafc;
            --bg-card: rgba(255, 255, 255, 0.95);
            --bg-input: #ffffff;
            --bg-input-focus: #f8fafc;

            /* Borders */
            --border-card: rgba(15, 23, 42, 0.09);
            --border-input: #cbd5e1;
            --border-focus: #ea580c;

            /* Typography */
            --text-heading: #0f172a;
            --text-body: #475569;
            --text-muted: #64748b;

            /* Status & Feedback */
            --danger: #e11d48;
            --danger-bg: rgba(225, 29, 72, 0.08);
            --danger-border: rgba(225, 29, 72, 0.25);
            --success: #059669;
            --success-bg: rgba(16, 185, 129, 0.10);
            --success-border: rgba(16, 185, 129, 0.25);

            --shadow-card: 0 10px 30px -5px rgba(15, 23, 42, 0.07), 0 4px 10px -3px rgba(15, 23, 42, 0.04);
            --shadow-glow: none;
        }

        body.light-mode {
            background-color: #f8fafc !important;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(234, 88, 12, 0.05), transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(2, 132, 199, 0.04), transparent 45%) !important;
            color: #475569 !important;
        }

        :root.light-mode .meter-segment,
        body.light-mode .meter-segment {
            background: rgba(15, 23, 42, 0.08);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg-body);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(249, 115, 22, 0.15), transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(6, 182, 212, 0.12), transparent 45%);
            background-attachment: fixed;
            color: var(--text-body);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.25rem;
            line-height: 1.5;
        }

        .auth-container {
            width: 100%;
            max-width: 580px;
            perspective: 1000px;
        }

        .auth-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-card);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card), var(--shadow-glow);
            padding: 2.75rem 2.25rem;
            position: relative;
            overflow: hidden;
            animation: cardAppear 0.5s cubic-bezier(0.16, 1, 0.3, 1);
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

        @keyframes cardAppear {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .brand-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--primary-subtle);
            color: var(--primary);
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
            border: 1px solid rgba(249, 115, 22, 0.25);
        }

        .brand-badge svg {
            width: 14px;
            height: 14px;
            fill: currentColor;
        }

        .auth-title {
            color: var(--text-heading);
            font-size: 1.875rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            margin-bottom: 0.5rem;
        }

        .auth-subtitle {
            color: var(--text-body);
            font-size: 0.95rem;
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border-radius: var(--radius-input);
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            line-height: 1.45;
            animation: alertFadeIn 0.3s ease;
        }

        @keyframes alertFadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-error {
            background-color: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: #fecdd3;
        }

        .alert-success {
            background-color: var(--success-bg);
            border: 1px solid var(--success-border);
            color: #a7f3d0;
        }

        .alert-icon {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            margin-top: 1px;
        }

        /* Form Layout */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            margin-bottom: 1.25rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            color: var(--text-heading);
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-label .required {
            color: var(--primary);
            margin-left: 2px;
        }

        .input-wrapper {
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
            transition: var(--transition);
        }

        .form-control {
            width: 100%;
            background: var(--bg-input);
            border: 1px solid var(--border-input);
            border-radius: var(--radius-input);
            color: var(--text-heading);
            font-family: inherit;
            font-size: 0.9375rem;
            padding: 0.85rem 1rem 0.85rem 2.75rem;
            outline: none;
            transition: var(--transition);
        }

        .form-control.no-icon {
            padding-left: 1rem;
        }

        .form-control:focus {
            background: var(--bg-input-focus);
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .form-control:focus + .input-icon {
            color: var(--primary);
        }

        .form-control.is-invalid {
            border-color: var(--danger);
            background-color: rgba(244, 63, 94, 0.04);
        }

        .form-control.is-invalid:focus {
            box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.25);
        }

        .field-error {
            color: var(--danger);
            font-size: 0.8125rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            margin-top: 0.25rem;
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0;
            display: flex;
            align-items: center;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--text-heading);
        }

        /* Password Strength Meter */
        .password-meter-wrap {
            margin-top: 0.35rem;
            display: flex;
            gap: 4px;
            height: 4px;
        }

        .meter-segment {
            flex: 1;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 99px;
            transition: var(--transition);
        }

        .meter-segment.active-weak { background-color: var(--danger); }
        .meter-segment.active-medium { background-color: #f59e0b; }
        .meter-segment.active-strong { background-color: var(--success); }

        .helper-text {
            font-size: 0.775rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, var(--primary), #e11d48);
            color: #ffffff;
            border: none;
            border-radius: var(--radius-btn);
            font-family: inherit;
            font-size: 1rem;
            font-weight: 700;
            padding: 0.95rem 1.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.35);
            transition: var(--transition);
            margin-top: 0.5rem;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(249, 115, 22, 0.5);
            background: linear-gradient(135deg, var(--primary-hover), #be123c);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-submit svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            transition: transform 0.2s ease;
        }

        .btn-submit:hover svg {
            transform: translateX(3px);
        }

        /* Footer Link */
        .auth-footer {
            text-align: center;
            margin-top: 2rem;
            font-size: 0.925rem;
            color: var(--text-body);
        }

        .auth-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        .auth-footer a:hover {
            color: #fdba74;
            text-decoration: underline;
        }

        /* Success action box */
        .success-box {
            text-align: center;
            padding: 1.5rem 1rem;
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
            animation: pulseGlow 2s infinite;
        }

        @keyframes pulseGlow {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            70% { box-shadow: 0 0 0 16px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .success-btn {
            display: inline-block;
            margin-top: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: #ffffff;
            text-decoration: none;
            font-weight: 700;
            padding: 0.85rem 2rem;
            border-radius: var(--radius-btn);
            box-shadow: 0 4px 15px rgba(249, 115, 22, 0.4);
            transition: var(--transition);
        }

        .success-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(249, 115, 22, 0.6);
        }

        @media (max-width: 640px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .auth-card {
                padding: 2rem 1.5rem;
            }
            .auth-title {
                font-size: 1.5rem;
            }
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
            color: var(--text-heading, #f8fafc);
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

<main class="auth-container">
    <div class="auth-card">
        
        <!-- Header / Logo -->
        <header class="brand-header">
            <div class="brand-badge">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
                UrbanEats Kitchens
            </div>
            <h1 class="auth-title">Create Account</h1>
            <p class="auth-subtitle">Join us to order chef-crafted dishes from our premier cloud kitchens</p>
        </header>

        <?php if (!empty($success_message)) : ?>
            <!-- Success Display State -->
            <div class="success-box">
                <div class="success-icon-wrap">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <h2 style="color: var(--text-heading); font-size: 1.35rem; margin-bottom: 0.5rem;">Registration Complete!</h2>
                <p style="color: var(--text-body); font-size: 0.95rem; line-height: 1.5;">
                    <?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <a href="login.php" class="success-btn">
                    Proceed to Login &rarr;
                </a>
            </div>
        <?php else : ?>
            <!-- General Alert / CSRF / System Error -->
            <?php if (isset($errors['csrf'])) : ?>
                <div class="alert alert-error" role="alert">
                    <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span><?= htmlspecialchars($errors['csrf'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($errors['system'])) : ?>
                <div class="alert alert-error" role="alert">
                    <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span><?= htmlspecialchars($errors['system'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <form action="register.php" method="POST" novalidate id="registerForm">
                
                <!-- CSRF Token Hidden Input -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-grid">
                    
                    <!-- First Name -->
                    <div class="form-group">
                        <label for="first_name" class="form-label">
                            First Name <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input 
                                type="text" 
                                id="first_name" 
                                name="first_name" 
                                class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : ''; ?>" 
                                value="<?= htmlspecialchars($form_data['first_name'], ENT_QUOTES, 'UTF-8'); ?>" 
                                placeholder="e.g. John"
                                maxlength="100"
                                required
                                autocomplete="given-name"
                            >
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <?php if (isset($errors['first_name'])) : ?>
                            <span class="field-error"><?= htmlspecialchars($errors['first_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Last Name -->
                    <div class="form-group">
                        <label for="last_name" class="form-label">
                            Last Name <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input 
                                type="text" 
                                id="last_name" 
                                name="last_name" 
                                class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : ''; ?>" 
                                value="<?= htmlspecialchars($form_data['last_name'], ENT_QUOTES, 'UTF-8'); ?>" 
                                placeholder="e.g. Doe"
                                maxlength="100"
                                required
                                autocomplete="family-name"
                            >
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <?php if (isset($errors['last_name'])) : ?>
                            <span class="field-error"><?= htmlspecialchars($errors['last_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Email Address -->
                    <div class="form-group full-width">
                        <label for="email" class="form-label">
                            Email Address <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                class="form-control <?= isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                                value="<?= htmlspecialchars($form_data['email'], ENT_QUOTES, 'UTF-8'); ?>" 
                                placeholder="john.doe@example.com"
                                maxlength="150"
                                required
                                autocomplete="email"
                            >
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                            </svg>
                        </div>
                        <?php if (isset($errors['email'])) : ?>
                            <span class="field-error"><?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Username -->
                    <div class="form-group full-width">
                        <label for="username" class="form-label">
                            Username <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input 
                                type="text" 
                                id="username" 
                                name="username" 
                                class="form-control <?= isset($errors['username']) ? 'is-invalid' : ''; ?>" 
                                value="<?= htmlspecialchars($form_data['username'], ENT_QUOTES, 'UTF-8'); ?>" 
                                placeholder="johndoe_eats"
                                maxlength="50"
                                required
                                autocomplete="username"
                            >
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="4"></circle>
                                <path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"></path>
                            </svg>
                        </div>
                        <span class="helper-text">3-50 alphanumeric characters, underscores, dashes, or periods.</span>
                        <?php if (isset($errors['username'])) : ?>
                            <span class="field-error"><?= htmlspecialchars($errors['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Password -->
                    <div class="form-group full-width">
                        <label for="password" class="form-label">
                            Password <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-control <?= isset($errors['password']) ? 'is-invalid' : ''; ?>" 
                                placeholder="At least 8 characters"
                                minlength="8"
                                required
                                autocomplete="new-password"
                            >
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                            <button type="button" class="password-toggle" id="togglePassword" aria-label="Toggle password visibility">
                                <svg id="eyeIcon1" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                        
                        <!-- Visual Password Strength Indicator -->
                        <div class="password-meter-wrap" id="passwordMeter">
                            <div class="meter-segment" id="seg1"></div>
                            <div class="meter-segment" id="seg2"></div>
                            <div class="meter-segment" id="seg3"></div>
                            <div class="meter-segment" id="seg4"></div>
                        </div>
                        <span class="helper-text" id="strengthText">Must contain at least 8 characters.</span>
                        
                        <?php if (isset($errors['password'])) : ?>
                            <span class="field-error"><?= htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Confirm Password -->
                    <div class="form-group full-width">
                        <label for="password_confirm" class="form-label">
                            Confirm Password <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input 
                                type="password" 
                                id="password_confirm" 
                                name="password_confirm" 
                                class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : ''; ?>" 
                                placeholder="Repeat your password"
                                required
                                autocomplete="new-password"
                            >
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                            <button type="button" class="password-toggle" id="togglePasswordConfirm" aria-label="Toggle confirm password visibility">
                                <svg id="eyeIcon2" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                        <?php if (isset($errors['password_confirm'])) : ?>
                            <span class="field-error"><?= htmlspecialchars($errors['password_confirm'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-submit" id="submitBtn">
                    <span>Create Customer Account</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>

            </form>

            <footer class="auth-footer">
                Already have an UrbanEats account? <a href="login.php">Sign In</a>
            </footer>

        <?php endif; ?>

    </div>
</main>

<script>
    // Toggle Password Visibility
    function setupPasswordToggle(inputId, toggleBtnId) {
        const input = document.getElementById(inputId);
        const btn = document.getElementById(toggleBtnId);
        if (!input || !btn) return;

        btn.addEventListener('click', () => {
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            btn.style.color = isPassword ? 'var(--primary)' : 'var(--text-muted)';
        });
    }

    setupPasswordToggle('password', 'togglePassword');
    setupPasswordToggle('password_confirm', 'togglePasswordConfirm');

    // Dynamic Password Strength Meter
    const passwordInput = document.getElementById('password');
    const seg1 = document.getElementById('seg1');
    const seg2 = document.getElementById('seg2');
    const seg3 = document.getElementById('seg3');
    const seg4 = document.getElementById('seg4');
    const strengthText = document.getElementById('strengthText');

    if (passwordInput && seg1) {
        passwordInput.addEventListener('input', () => {
            const val = passwordInput.value;
            let score = 0;
            if (val.length >= 8) score++;
            if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            // Reset classes
            [seg1, seg2, seg3, seg4].forEach(seg => {
                seg.className = 'meter-segment';
            });

            if (val.length === 0) {
                strengthText.textContent = 'Must contain at least 8 characters.';
                strengthText.style.color = 'var(--text-muted)';
                return;
            }

            if (score <= 1) {
                seg1.classList.add('active-weak');
                strengthText.textContent = 'Weak password (add numbers or special characters)';
                strengthText.style.color = 'var(--danger)';
            } else if (score <= 2) {
                seg1.classList.add('active-medium');
                seg2.classList.add('active-medium');
                strengthText.textContent = 'Medium strength password';
                strengthText.style.color = '#f59e0b';
            } else if (score === 3) {
                seg1.classList.add('active-strong');
                seg2.classList.add('active-strong');
                seg3.classList.add('active-strong');
                strengthText.textContent = 'Strong password';
                strengthText.style.color = 'var(--success)';
            } else {
                seg1.classList.add('active-strong');
                seg2.classList.add('active-strong');
                seg3.classList.add('active-strong');
                seg4.classList.add('active-strong');
                strengthText.textContent = 'Very strong password!';
                strengthText.style.color = 'var(--success)';
            }
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
