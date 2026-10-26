<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Application & Database Configuration (PHP 8.x)
 * 
 * Local XAMPP Environment Configuration
 */

declare(strict_types=1);

return [
    // Database Connection Parameters
    'database' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => 'urbaneats_db',
        'user'     => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    ],

    // Application Environment & URLs
    'app' => [
        'name'        => 'UrbanEats Cloud Kitchen',
        'env'         => 'development', // Options: 'development', 'staging', 'production'
        'debug'       => true,           // Set to false in production
        'base_url'    => 'http://localhost/UrbanEats-Cloud-Kitchen-Platform/public',
        'timezone'    => 'Asia/Colombo',
    ],

    // Session & Security Settings
    'security' => [
        'session_lifetime' => 7200,      // Session expiration time in seconds (2 hours)
        'csrf_token_name'  => 'csrf_token',
        'password_min_len' => 8,
    ],

    // Cloud Kitchen Operational Settings
    'kitchen' => [
        'operating_hours' => '10:00 AM - 11:00 PM',
        'support_email'   => 'support@urbaneats.local',
        'contact_phone'   => '+1 (555) 019-8234',
    ],
];
