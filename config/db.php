<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Database Connection Configuration (PHP 8.x)
 */

declare(strict_types=1);

// Database configuration variables
$host = 'localhost';
$dbname = 'urbaneats_db';
$user = 'root';
$password = '';
$charset = 'utf8mb4';

// Data Source Name (DSN)
$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

// PDO options for security and error handling
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Create a new PDO instance
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (PDOException $e) {
    // Log sensitive error details to the server error log
    error_log('Database Connection Error: ' . $e->getMessage());

    // Prevent sensitive database error details from being printed to the screen
    http_response_code(500);
    exit('Database connection error. Please try again later.');
}
