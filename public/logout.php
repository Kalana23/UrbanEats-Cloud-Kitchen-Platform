<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * User Logout & Session Termination
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

// Load database connection and auth utilities
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'User';

// 1. Record the logout event in the audit_log table
if ($userId !== null && isset($pdo)) {
    log_action(
        $pdo,
        (int)$userId,
        'USER_LOGOUT',
        sprintf("User '%s' signed out successfully.", $username)
    );
}

// 2. Unset all session variables
$_SESSION = [];

// 3. Clear session cookie if cookies are used
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 4. Destroy the session completely
session_destroy();

// 5. Redirect to login.php with logged_out status indicator
header('Location: login.php?logged_out=1');
exit();
