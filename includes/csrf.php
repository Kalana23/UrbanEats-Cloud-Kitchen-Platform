<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * CSRF Protection Utilities
 *
 * PHP version 8.x
 */

declare(strict_types=1);

// Ensure session is started safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate a new cryptographically secure CSRF token and store it in session.
 *
 * @return string The newly generated 64-character hex token.
 */
function generate_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

/**
 * Retrieve the active CSRF token, generating one if not already present.
 *
 * @return string
 */
function get_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return generate_csrf_token();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Convenience alias for get_csrf_token().
 *
 * @return string
 */
function csrf_token(): string
{
    return get_csrf_token();
}

/**
 * Return an HTML hidden input element containing the CSRF token.
 * Ready for immediate embedding inside any POST <form>.
 *
 * @return string Safe HTML input string.
 */
function csrf_field(): string
{
    $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Return a meta tag containing the CSRF token for JavaScript/AJAX headers.
 *
 * @return string
 */
function csrf_meta(): string
{
    $token = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<meta name="csrf-token" content="' . $token . '">';
}

/**
 * Validate a given token or the submitted $_POST token against the session token.
 *
 * @param string|null $token Optional token to validate. If null, inspects $_POST['csrf_token']
 *                           or the X-CSRF-TOKEN HTTP request header.
 * @return bool True if valid, false otherwise.
 */
function validate_csrf_token(?string $token = null): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $session_token = $_SESSION['csrf_token'] ?? null;
    if (empty($session_token) || !is_string($session_token)) {
        return false;
    }

    // If no explicit token provided, look up from POST body or HTTP headers
    if ($token === null) {
        $token = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? null;
    }

    if (!is_string($token) || empty($token)) {
        return false;
    }

    return hash_equals($session_token, $token);
}

/**
 * Enforce CSRF protection on incoming POST requests.
 * Halts execution with HTTP 403 Forbidden if validation fails.
 *
 * @param string $error_message Optional message to display upon failure.
 * @return void
 */
function verify_csrf_or_abort(string $error_message = 'Security check failed: Invalid or expired CSRF token. Please refresh the page and try again.'): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token()) {
            http_response_code(403);
            exit('<div style="font-family: system-ui, sans-serif; max-width: 500px; margin: 40px auto; padding: 20px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; color: #9f1239;">'
                . '<h3 style="margin-top: 0;">403 Forbidden</h3>'
                . '<p>' . htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><a href="javascript:history.back()" style="color: #be123c; font-weight: bold;">&larr; Go Back</a></p>'
                . '</div>');
        }
    }
}

/**
 * Regenerate the CSRF token (recommended after authentication state changes).
 *
 * @return string
 */
function regenerate_csrf_token(): string
{
    return generate_csrf_token();
}
