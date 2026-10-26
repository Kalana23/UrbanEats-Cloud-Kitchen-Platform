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

    <!-- Platform Master Stylesheet with Cache Busting -->
    <link rel="stylesheet" href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/css/style.css?v=<?= filemtime(__DIR__ . '/../public/css/style.css'); ?>">

    <!-- Anti-FOUC Theme Pre-loader & Core Theme Styles -->
    <style id="urbaneats-theme-core">
        /* Theme Toggle Button Base */
        .theme-toggle-btn {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #1a2234;
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: #f8fafc;
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

        /* Mobile Action Cluster */
        .mobile-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        @media (min-width: 768px) {
            .mobile-actions {
                display: none !important;
            }
            .desktop-theme-toggle {
                display: inline-flex !important;
            }
        }

        /* Light Mode Core Overrides */
        :root.light-mode,
        body.light-mode {
            --primary: #ea580c;
            --primary-hover: #c2410c;
            --bg-body: #f8fafc;
            --bg-surface: #ffffff;
            --bg-surface-glass: rgba(255, 255, 255, 0.92);
            --bg-surface-elevated: #f1f5f9;
            --bg-input: #ffffff;
            --bg-input-focus: #ffffff;
            --bg-navbar: rgba(255, 255, 255, 0.95);
            --border-subtle: rgba(15, 23, 42, 0.08);
            --border-medium: rgba(15, 23, 42, 0.14);
            --border-card: rgba(15, 23, 42, 0.09);
            --border-input: #cbd5e1;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
        }

        body.light-mode {
            background-color: #f8fafc !important;
            color: #475569 !important;
            background-image: 
                radial-gradient(circle at 10% 10%, rgba(234, 88, 12, 0.05), transparent 40%),
                radial-gradient(circle at 90% 90%, rgba(2, 132, 199, 0.04), transparent 45%) !important;
        }

        .light-mode .site-header {
            background: rgba(255, 255, 255, 0.95) !important;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08) !important;
        }

        .light-mode .nav-menu-wrapper {
            background: #ffffff;
        }

        .light-mode .nav-brand {
            color: #0f172a;
        }

        .light-mode .nav-link {
            color: #475569;
        }

        .light-mode .nav-link:hover,
        .light-mode .nav-link.active {
            color: #ea580c;
            background: rgba(234, 88, 12, 0.08);
        }

        .light-mode .card {
            background-color: #ffffff !important;
            border-color: rgba(15, 23, 42, 0.09) !important;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06) !important;
        }

        .light-mode .theme-toggle-btn {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #0f172a;
        }
        .light-mode .theme-toggle-btn:hover {
            background: rgba(234, 88, 12, 0.1);
            border-color: #ea580c;
            color: #ea580c;
        }
        .light-mode .theme-toggle-btn .theme-icon-sun {
            display: none !important;
        }
        .light-mode .theme-toggle-btn .theme-icon-moon {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }

        .light-mode .site-footer {
            background: #ffffff !important;
            border-top: 1px solid rgba(15, 23, 42, 0.08) !important;
        }
        .light-mode .table th {
            background: #f1f5f9 !important;
            color: #0f172a !important;
        }
        .light-mode .btn-secondary {
            background: #ffffff !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
    </style>

    <!-- Immediate Anti-FOUC Theme Pre-loader -->
    <script>
        (function () {
            try {
                if (localStorage.getItem('urbaneats_theme') === 'light') {
                    document.documentElement.classList.add('light-mode');
                }
            } catch (e) {}
        })();
    </script>
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

        <!-- Dynamic Menu Container -->
        <nav class="nav-menu-wrapper" id="navMenuWrapper">
            <ul class="nav-links">
                <?php foreach ($navItems as $item) : ?>
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
                <?php if ($isLoggedIn) : ?>
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
                <?php else : ?>
                    <span class="role-badge badge-guest">Guest</span>
                    <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/login.php" class="btn btn-sm btn-secondary">
                        Sign In
                    </a>
                    <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/register.php" class="btn btn-sm btn-primary">
                        Register
                    </a>
                <?php endif; ?>

                <!-- Theme Toggle Button (Desktop & Drawer Menu) -->
                <button type="button" class="theme-toggle-btn desktop-theme-toggle" aria-label="Toggle Light/Dark Theme" title="Toggle Light/Dark Theme">
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
        </nav>

        <!-- Mobile Action Cluster (Theme Toggle + Hamburger) -->
        <div class="mobile-actions">
            <button type="button" class="theme-toggle-btn mobile-theme-toggle" aria-label="Toggle Light/Dark Theme" title="Toggle Light/Dark Theme">
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
                <span class="theme-icon-moon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                    </svg>
                </span>
            </button>

            <!-- Mobile Navigation Toggle -->
            <button class="mobile-toggle" id="mobileMenuToggle" aria-label="Toggle navigation menu" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>

    </div>
</header>

<!-- Main Page Content Section -->
<main class="main-content">
