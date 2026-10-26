<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Shared Layout Header & Dynamic Role-Based Navigation
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

// Ensure session is started safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include CSRF helpers
require_once __DIR__ . '/csrf.php';

// Base web path for assets and navigation links
$webRoot = '/UrbanEats-Cloud-Kitchen-Platform/public';

// Determine active page name for navigation highlighting
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

// Page title fallback
$pageTitle = $pageTitle ?? 'UrbanEats Cloud Kitchen';

// Determine Authentication & Role
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$rawRole = $_SESSION['role'] ?? $_SESSION['role_name'] ?? null;
$roleId = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : null;

// Normalize user role
$userRole = 'Guest';
$roleBadgeClass = 'badge-guest';

if ($isLoggedIn) {
    if ($roleId === 1 || ($rawRole !== null && in_array(strtolower((string)$rawRole), ['admin', 'administrator'], true))) {
        $userRole = 'Administrator';
        $roleBadgeClass = 'badge-admin';
    } elseif ($roleId === 2 || ($rawRole !== null && in_array(strtolower((string)$rawRole), ['staff', 'kitchen staff', 'kitchen_staff'], true))) {
        $userRole = 'Kitchen Staff';
        $roleBadgeClass = 'badge-staff';
    } else {
        $userRole = 'Customer';
        $roleBadgeClass = 'badge-customer';
    }
}

// User display name
$userName = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User';

// Role-based Navigation Menus
$navItems = [];

switch ($userRole) {
    case 'Customer':
        $navItems = [
            ['label' => 'Browse Menu', 'url' => $webRoot . '/menu/list.php', 'icon' => 'dish'],
            ['label' => 'Cart',        'url' => $webRoot . '/orders/cart.php', 'icon' => 'cart'],
            ['label' => 'My Orders',   'url' => $webRoot . '/orders/history.php', 'icon' => 'receipt'],
            ['label' => 'Profile',     'url' => $webRoot . '/profile.php', 'icon' => 'user'],
        ];
        break;

    case 'Kitchen Staff':
        $navItems = [
            ['label' => 'Kitchen Queue',   'url' => $webRoot . '/orders/kitchen-queue.php', 'icon' => 'clock'],
            ['label' => 'Stock Dashboard', 'url' => $webRoot . '/menu/stock.php', 'icon' => 'box'],
            ['label' => 'Manage Dishes',   'url' => $webRoot . '/menu/manage-dishes.php', 'icon' => 'dish'],
            ['label' => 'Categories',      'url' => $webRoot . '/menu/manage-categories.php', 'icon' => 'kitchen'],
            ['label' => 'Staff Profile',   'url' => $webRoot . '/profile.php', 'icon' => 'user'],
        ];
        break;

    case 'Administrator':
        $navItems = [
            ['label' => 'Dashboard',       'url' => $webRoot . '/admin/dashboard.php', 'icon' => 'home'],
            ['label' => 'Sales Reports',   'url' => $webRoot . '/admin/reports.php', 'icon' => 'chart'],
            ['label' => 'Kitchen Queue',   'url' => $webRoot . '/orders/kitchen-queue.php', 'icon' => 'clock'],
            ['label' => 'Manage Users',    'url' => $webRoot . '/admin/users.php', 'icon' => 'users'],
            ['label' => 'Stock Control',   'url' => $webRoot . '/menu/stock.php', 'icon' => 'box'],
            ['label' => 'Manage Dishes',   'url' => $webRoot . '/menu/manage-dishes.php', 'icon' => 'dish'],
            ['label' => 'Audit Logs',      'url' => $webRoot . '/admin/audit-log.php', 'icon' => 'shield'],
        ];
        break;

    case 'Guest':
    default:
        $navItems = [
            ['label' => 'Home',        'url' => $webRoot . '/index.php', 'icon' => 'home'],
            ['label' => 'Browse Menu', 'url' => $webRoot . '/menu/list.php', 'icon' => 'dish'],
        ];
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> - UrbanEats</title>
    
    <!-- CSRF Meta Tag for AJAX -->
    <?= csrf_meta(); ?>

    <!-- Typography: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Platform Master Stylesheet -->
    <link rel="stylesheet" href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/css/style.css">
</head>
<body>

<!-- Header & Dynamic Navigation -->
<header class="site-header">
    <div class="container nav-container">
        
        <!-- Brand Logo -->
        <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/index.php" class="nav-brand">
            <div class="brand-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8h1a4 4 0 0 1 0 8h-1"></path>
                    <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path>
                    <line x1="6" y1="1" x2="6" y2="4"></line>
                    <line x1="10" y1="1" x2="10" y2="4"></line>
                    <line x1="14" y1="1" x2="14" y2="4"></line>
                </svg>
            </div>
            <span>Urban<span class="highlight">Eats</span></span>
        </a>

        <!-- Mobile Navigation Toggle -->
        <button class="mobile-toggle" id="mobileMenuToggle" aria-label="Toggle navigation menu" aria-expanded="false">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <!-- Dynamic Menu Container -->
        <nav class="nav-menu-wrapper" id="navMenuWrapper">
            <ul class="nav-links">
                <?php foreach ($navItems as $item): ?>
                    <?php 
                        $itemFile = basename(parse_url($item['url'], PHP_URL_PATH) ?? '');
                        $isActive = ($currentPage === $itemFile);
                    ?>
                    <li>
                        <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>" 
                           class="nav-link <?= $isActive ? 'active' : ''; ?>">
                            <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- User Status & Actions -->
            <div class="nav-user-section">
                <?php if ($isLoggedIn): ?>
                    <div class="user-identity">
                        <div class="user-avatar">
                            <?= strtoupper(substr((string)$userName, 0, 1)); ?>
                        </div>
                        <div class="user-meta">
                            <span class="user-name"><?= htmlspecialchars((string)$userName, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="role-badge <?= $roleBadgeClass; ?>">
                                <?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>
                    <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/logout.php" class="btn btn-sm btn-danger">
                        Sign Out
                    </a>
                <?php else: ?>
                    <span class="role-badge badge-guest">Guest</span>
                    <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/login.php" class="btn btn-sm btn-secondary">
                        Sign In
                    </a>
                    <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/register.php" class="btn btn-sm btn-primary">
                        Register
                    </a>
                <?php endif; ?>
            </div>
        </nav>

    </div>
</header>

<!-- Main Page Content Section -->
<main class="main-content">
