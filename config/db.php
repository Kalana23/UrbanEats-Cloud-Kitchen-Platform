<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Database Connection Configuration (PHP 8.x)
 *
 * Configured for local XAMPP environment.
 */

declare(strict_types=1);

// Database connection parameters
$host     = 'localhost';
$port     = 3306;
$dbname   = 'urbaneats_db';
$user     = 'root';
$password = '';
$charset  = 'utf8mb4';

// Data Source Name (DSN)
$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

// Secure PDO driver attributes
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Initialize PDO connection
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (PDOException $e) {
    // Gracefully handle database connection errors without leaking sensitive credentials or stack traces
    error_log('[UrbanEats Database Error] Connection failed: ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
    }

    exit('Database connection error. A technical incident has been logged. Please try again later.');
}

return $pdo;

