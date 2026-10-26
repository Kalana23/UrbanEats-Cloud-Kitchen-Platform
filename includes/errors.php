<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Centralized Error & Exception Handler
 *
 * PHP version 8.x
 */

declare(strict_types=1);

// Disable direct error display to clients to prevent sensitive information leakage
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Ensure the logs directory exists
$logDirectory = dirname(__DIR__) . '/logs';
if (!is_dir($logDirectory)) {
    @mkdir($logDirectory, 0755, true);
}

// Define the central log file path
define('URBANEATS_ERROR_LOG', $logDirectory . '/error.log');
ini_set('error_log', URBANEATS_ERROR_LOG);

/**
 * Write a formatted error entry to the custom log file.
 *
 * @param string $level Severity level (e.g. ERROR, WARNING, EXCEPTION, FATAL).
 * @param string $message Detailed error message.
 * @param string $file Source file where error occurred.
 * @param int $line Line number in source file.
 * @param string|null $trace Optional stack trace.
 * @return string Reference ID generated for this error instance.
 */
function log_system_error(string $level, string $message, string $file, int $line, ?string $trace = null): string
{
    $refId = 'ERR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $timestamp = date('Y-m-d H:i:s T');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    $requestUri = $_SERVER['REQUEST_URI'] ?? 'CLI';

    $logEntry = sprintf(
        "[%s] [%s] [Ref: %s] [IP: %s] [URI: %s]\nMessage: %s\nLocation: %s:%d\n",
        $timestamp,
        $level,
        $refId,
        $clientIp,
        $requestUri,
        $message,
        $file,
        $line
    );

    if ($trace !== null && $trace !== '') {
        $logEntry .= "Stack Trace:\n" . $trace . "\n";
    }

    $logEntry .= str_repeat('-', 80) . "\n";

    @file_put_contents(URBANEATS_ERROR_LOG, $logEntry, FILE_APPEND | LOCK_EX);

    return $refId;
}

/**
 * Display a user-friendly error response without exposing sensitive details.
 *
 * @param string $refId The unique incident reference ID for troubleshooting.
 * @return void
 */
function render_friendly_error_page(string $refId): void
{
    // Clean any prior output buffers so partial HTML or traces aren't output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    // Check if client expects JSON
    $isJson = (
        (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    );

    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'   => false,
            'error'     => 'An internal server error occurred. Please try again later.',
            'reference' => $refId,
        ], JSON_PRETTY_PRINT);
        exit();
    }

    // Render polished, production-grade error screen
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Server Error - UrbanEats Cloud Kitchen</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>
            :root {
                --primary: #f97316;
                --primary-hover: #ea580c;
                --bg-base: #0b0f19;
                --bg-card: rgba(18, 24, 38, 0.9);
                --border-card: rgba(255, 255, 255, 0.08);
                --text-head: #f8fafc;
                --text-body: #94a3b8;
                --danger: #ef4444;
            }
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
                background-color: var(--bg-base);
                color: var(--text-body);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1.5rem;
                background-image: radial-gradient(circle at 50% 20%, rgba(249, 115, 22, 0.12), transparent 50%);
            }
            .error-card {
                background: var(--bg-card);
                border: 1px solid var(--border-card);
                border-radius: 20px;
                padding: 2.75rem 2.25rem;
                max-width: 540px;
                width: 100%;
                text-align: center;
                box-shadow: 0 20px 40px rgba(0,0,0,0.6);
            }
            .icon-badge {
                width: 64px;
                height: 64px;
                border-radius: 50%;
                background: rgba(239, 68, 68, 0.12);
                border: 1px solid rgba(239, 68, 68, 0.3);
                color: var(--danger);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 1.5rem;
            }
            h1 {
                color: var(--text-head);
                font-size: 1.75rem;
                font-weight: 800;
                margin-bottom: 0.75rem;
            }
            p {
                font-size: 0.95rem;
                line-height: 1.6;
                margin-bottom: 1.25rem;
            }
            .ref-box {
                background: #0e1422;
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 10px;
                padding: 0.75rem 1rem;
                display: inline-block;
                font-family: monospace;
                font-size: 0.85rem;
                color: #e2e8f0;
                margin-bottom: 1.75rem;
            }
            .btn-home {
                display: inline-block;
                background: linear-gradient(135deg, var(--primary), var(--primary-hover));
                color: #fff;
                text-decoration: none;
                font-weight: 700;
                padding: 0.85rem 1.75rem;
                border-radius: 12px;
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .btn-home:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(249, 115, 22, 0.4);
            }
        </style>
    </head>
    <body>
        <main class="error-card">
            <div class="icon-badge">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <h1>Something Went Wrong</h1>
            <p>Our kitchen platform encountered an unexpected technical issue. Our engineering team has been notified and the event has been logged securely.</p>
            <div class="ref-box">
                Incident Ref: <strong><?= htmlspecialchars($refId, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div>
                <a href="javascript:history.back()" class="btn-home" style="margin-right: 10px; background: #334155;">&larr; Go Back</a>
                <a href="/UrbanEats-Cloud-Kitchen-Platform/public/index.php" class="btn-home">Return Home</a>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit();
}

/**
 * Custom PHP Error Handler.
 */
function urbaneats_error_handler(int $severity, string $message, string $file, int $line): bool
{
    // If error was silenced with @, respect error_reporting
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $severityNames = [
        E_ERROR             => 'E_ERROR',
        E_WARNING           => 'E_WARNING',
        E_PARSE             => 'E_PARSE',
        E_NOTICE            => 'E_NOTICE',
        E_CORE_ERROR        => 'E_CORE_ERROR',
        E_CORE_WARNING      => 'E_CORE_WARNING',
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
        E_USER_ERROR        => 'E_USER_ERROR',
        E_USER_WARNING      => 'E_USER_WARNING',
        E_USER_NOTICE       => 'E_USER_NOTICE',
        E_DEPRECATED        => 'E_DEPRECATED',
        E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
    ];

    $level = $severityNames[$severity] ?? 'ERROR_' . $severity;
    $refId = log_system_error($level, $message, $file, $line);

    // Fatal or user errors halt and render friendly screen
    if ($severity === E_USER_ERROR || $severity === E_RECOVERABLE_ERROR) {
        render_friendly_error_page($refId);
    }

    return true; // Error was handled
}

/**
 * Custom Exception Handler.
 */
function urbaneats_exception_handler(Throwable $exception): void
{
    $message = sprintf(
        '[%s] %s',
        get_class($exception),
        $exception->getMessage()
    );

    $refId = log_system_error(
        'EXCEPTION',
        $message,
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );

    render_friendly_error_page($refId);
}

/**
 * Shutdown Handler to intercept fatal errors.
 */
function urbaneats_shutdown_handler(): void
{
    $lastError = error_get_last();
    if ($lastError !== null && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $refId = log_system_error(
            'FATAL_ERROR',
            $lastError['message'],
            $lastError['file'],
            $lastError['line']
        );
        render_friendly_error_page($refId);
    }
}

// Register global handlers
set_error_handler('urbaneats_error_handler');
set_exception_handler('urbaneats_exception_handler');
register_shutdown_function('urbaneats_shutdown_handler');
