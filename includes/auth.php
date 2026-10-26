<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Authentication, Session Management, and Access Control
 *
 * PHP version 8.x
 */

declare(strict_types=1);

// Ensure session is started safely if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if the user is currently logged in.
 *
 * @return bool True if $_SESSION['user_id'] exists and is not empty.
 */
function is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Enforce authentication by checking if $_SESSION['user_id'] exists.
 * Redirects the user to the login page if not authenticated.
 *
 * @param string $login_url The destination URL for unauthenticated users.
 * @return void
 */
function require_login(string $login_url = 'login.php'): void
{
    if (!is_logged_in()) {
        header("Location: {$login_url}");
        exit();
    }
}

/**
 * Check if the current user possesses one of the permitted roles.
 * Supports role names (e.g., 'admin', 'manager') or role IDs (e.g., 1, 2)
 * stored under $_SESSION['role'], $_SESSION['role_name'], or $_SESSION['role_id'].
 *
 * @param array|string $allowed_roles_array List of permitted roles or single role name/id.
 * @return bool True if the user's role matches any permitted role.
 */
function has_role(array|string $allowed_roles_array): bool
{
    if (!is_logged_in()) {
        return false;
    }

    $allowed = is_array($allowed_roles_array) ? $allowed_roles_array : [$allowed_roles_array];
    $current_role = $_SESSION['role'] ?? $_SESSION['role_name'] ?? null;
    $current_role_id = $_SESSION['role_id'] ?? null;

    foreach ($allowed as $role) {
        // Match against role name (case-insensitive)
        if ($current_role !== null && strcasecmp((string)$current_role, (string)$role) === 0) {
            return true;
        }
        // Match against numeric or string role ID
        if ($current_role_id !== null && (string)$current_role_id === (string)$role) {
            return true;
        }
    }

    return false;
}

/**
 * Enforce role-based access control.
 * Verifies that the user is logged in and their role is included in $allowed_roles_array.
 * If unauthorized, sends an HTTP 403 Forbidden response or redirects.
 *
 * @param array|string $allowed_roles_array Array of allowed role names or IDs.
 * @param string $redirect_url Optional redirection URL instead of 403 response.
 * @return void
 */
function require_role(array|string $allowed_roles_array, string $redirect_url = ''): void
{
    require_login();

    if (!has_role($allowed_roles_array)) {
        if (!empty($redirect_url)) {
            header("Location: {$redirect_url}");
            exit();
        }

        // Redirect to shared platform 403 Access Denied page
        $access_denied_target = '/UrbanEats-Cloud-Kitchen-Platform/public/access-denied.php';
        header("Location: {$access_denied_target}");
        exit();
    }
}

/**
 * Log an administrative, user, or system action into the audit_log table using prepared statements.
 *
 * @param PDO $pdo Active PDO database connection instance.
 * @param int|string|null $user_id ID of the user performing the action (or null for guest/system).
 * @param string $action Brief descriptor of the action performed (e.g., 'USER_LOGIN', 'ORDER_UPDATE').
 * @param string|null $details Detailed metadata or context regarding the action.
 * @param string|null $ip_address Client IP address (defaults to $_SERVER['REMOTE_ADDR']).
 * @return bool True on successful record insertion, false on failure.
 */
function log_action(
    PDO $pdo,
    int|string|null $user_id,
    string $action,
    ?string $details = null,
    ?string $ip_address = null
): bool {
    $ip = $ip_address ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $normalized_user_id = (!empty($user_id)) ? (int)$user_id : null;

    $sql = 'INSERT INTO audit_log (user_id, action, details, ip_address) 
            VALUES (:user_id, :action, :details, :ip_address)';

    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':user_id'    => $normalized_user_id,
            ':action'     => $action,
            ':details'    => $details,
            ':ip_address' => $ip,
        ]);
    } catch (PDOException $e) {
        error_log('Audit log insertion failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Safely destroy the current session and log out the user.
 *
 * @param string $redirect_url Redirection destination after logout.
 * @return void
 */
function logout_user(string $redirect_url = 'login.php'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION = [];

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

    session_destroy();

    header("Location: {$redirect_url}");
    exit();
}
