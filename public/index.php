<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Public Landing & Welcome Experience
 *
 * PHP version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// Public welcome page: specifically does NOT enforce require_login()

// -----------------------------------------------------------------------------
// Fetch Active Categories & Culinary Statistics
// -----------------------------------------------------------------------------
$categoriesStmt = $pdo->query('
    SELECT c.category_id, c.name, c.description, COUNT(d.dish_id) AS dish_count
    FROM categories c
    LEFT JOIN dishes d ON c.category_id = d.category_id AND d.is_active = 1
    WHERE c.is_active = 1
    GROUP BY c.category_id
    ORDER BY c.category_id ASC
');
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch 6 Highlight Signature Dishes with Live Inventory
$highlightsStmt = $pdo->query('
    SELECT d.dish_id, d.name, d.description, d.price, d.image_path,
           c.name AS category_name, i.current_stock, i.reorder_level
    FROM dishes d
    JOIN categories c ON d.category_id = c.category_id
    LEFT JOIN inventory i ON d.dish_id = i.dish_id
    WHERE d.is_active = 1 AND c.is_active = 1
    ORDER BY d.price DESC
    LIMIT 6
');
$highlightDishes = $highlightsStmt->fetchAll(PDO::FETCH_ASSOC);

// High-resolution category image mapping
$categoryImages = [
    1 => 'uploads/cheese kottu.jpg',
    2 => 'uploads/chicken biriyani.jpg',
    3 => 'uploads/chicken rice and curry.webp',
    4 => 'uploads/chicken devilled.webp',
    5 => 'uploads/chicken roll.jpg',
    6 => 'uploads/faluda.jpg',
];

$pageTitle = 'Gourmet Cloud Kitchen & Artisanal Food Delivery';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 5rem;">

    <!-- =======================================================================
         1. Hero Section
         ======================================================================= -->
    <section class="card" style="margin-bottom: 4.5rem; background: radial-gradient(circle at 85% 15%, rgba(249, 115, 22, 0.22), transparent 50%), radial-gradient(circle at 15% 85%, rgba(6, 182, 212, 0.14), transparent 45%), var(--bg-surface); border: 1px solid var(--border-card); overflow: hidden;">
        <div class="card-body" style="padding: 4.5rem 2.5rem; position: relative;">
            <div style="max-width: 760px;">
                
                <!-- Live Operational Status Badge -->
                <div style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0.85rem; border-radius: var(--radius-pill); background: var(--primary-subtle); border: 1px solid rgba(249, 115, 22, 0.3); color: var(--primary); font-size: 0.85rem; font-weight: 700; margin-bottom: 1.5rem;">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--primary); animation: pulse 2s infinite;"></span>
                    Sri Lanka's Premier Artisanal Cloud Kitchen
                </div>

                <!-- Food-Centric Catchy Headline -->
                <h1 style="font-size: clamp(2.35rem, 5vw, 3.5rem); font-weight: 800; color: var(--text-primary); line-height: 1.15; margin-bottom: 1.35rem; letter-spacing: -0.025em;">
                    Sizzling Ceylon Kottu, Fragrant Biriyanis &amp; Gourmet Cloud Comfort
                </h1>

                <!-- Subheadline -->
                <p style="font-size: clamp(1rem, 2vw, 1.185rem); color: var(--text-secondary); line-height: 1.65; margin-bottom: 2.5rem; max-width: 680px;">
                    Engineered exclusively for blazing-fast doorstep delivery and rapid counter pickup. Master-crafted with authentic hand-ground spices, zero dining room delays, and 100% real-time portion stock tracking.
                </p>

                <!-- Prominent Call-to-Action Buttons -->
                <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                    <!-- View Menu CTA -->
                    <a href="menu/list.php" class="btn btn-primary btn-lg" style="box-shadow: 0 10px 25px rgba(249, 115, 22, 0.35);">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="9" cy="21" r="1"></circle>
                            <circle cx="20" cy="21" r="1"></circle>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                        </svg>
                        <span>View Menu</span>
                    </a>

                    <?php if (!is_logged_in()) : ?>
                        <!-- Sign In CTA -->
                        <a href="login.php" class="btn btn-secondary btn-lg">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                                <polyline points="10 17 15 12 10 7"></polyline>
                                <line x1="15" y1="12" x2="3" y2="12"></line>
                            </svg>
                            <span>Sign In</span>
                        </a>

                        <!-- Register CTA -->
                        <a href="register.php" class="btn btn-secondary btn-lg" style="border-color: var(--primary); color: var(--primary);">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="8.5" cy="7" r="4"></circle>
                                <line x1="20" y1="8" x2="20" y2="14"></line>
                                <line x1="23" y1="11" x2="17" y2="11"></line>
                            </svg>
                            <span>Register</span>
                        </a>
                    <?php else : ?>
                        <!-- Customer Authenticated CTAs -->
                        <a href="orders/history.php" class="btn btn-secondary btn-lg">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                            </svg>
                            <span>My Orders</span>
                        </a>

                        <a href="profile.php" class="btn btn-secondary btn-lg">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <span>My Profile</span>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Live Metrics Strip -->
                <div style="display: flex; gap: 2.25rem; margin-top: 3.25rem; padding-top: 2rem; border-top: 1px solid var(--border-subtle); flex-wrap: wrap;">
                    <div>
                        <strong style="display: block; font-size: 1.65rem; color: var(--text-primary); font-weight: 800; font-family: var(--font-mono);">&lt; 30m</strong>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Hot Dispatch</span>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 1.65rem; color: var(--text-primary); font-weight: 800; font-family: var(--font-mono);">22+</strong>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Artisanal Recipes</span>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 1.65rem; color: var(--text-primary); font-weight: 800; font-family: var(--font-mono);">100%</strong>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Real-Time Stock Guard</span>
                    </div>
                    <div>
                        <strong style="display: block; font-size: 1.65rem; color: var(--primary); font-weight: 800; font-family: var(--font-mono);">4.9 ★</strong>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Foodie Satisfaction</span>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- =======================================================================
         2. Featured Highlights Section
         ======================================================================= -->
    <section style="margin-bottom: 5.5rem;">
        <div style="text-align: center; max-width: 680px; margin: 0 auto 3rem;">
            <span class="role-badge badge-admin" style="margin-bottom: 0.65rem;">Platform Superiority</span>
            <h2 style="font-size: clamp(1.85rem, 3.5vw, 2.5rem); font-weight: 800; color: var(--text-primary); margin-top: 0.25rem; letter-spacing: -0.02em;">
                Featured Highlights &amp; Kitchen Standards
            </h2>
            <p style="color: var(--text-secondary); font-size: 1rem; line-height: 1.6;">
                Engineered from the ground up to solve cloud kitchen bottlenecks, guarantee ingredient freshness, and eliminate cancelled orders.
            </p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.75rem;">
            
            <!-- Highlight 1: Fast Delivery -->
            <div class="card" style="padding: 2rem 1.75rem; background: var(--bg-surface); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="width: 52px; height: 52px; border-radius: var(--radius-md); background: var(--primary-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; margin-bottom: 1.35rem; border: 1px solid rgba(249, 115, 22, 0.25);">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                    </div>
                    <span style="font-size: 0.75rem; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.35rem;">
                        Under 30 Minutes
                    </span>
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.75rem;">
                        Lightning-Fast Delivery
                    </h3>
                    <p style="font-size: 0.9rem; color: var(--text-secondary); line-height: 1.6; margin-bottom: 1.25rem;">
                        Our dedicated prep stations eliminate dining room queues. Orders route straight to the wok and into thermal dispatch within minutes of confirmation.
                    </p>
                </div>
                <div style="padding-top: 1rem; border-top: 1px solid var(--border-subtle); font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem;">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: var(--primary);"></span>
                    Average transit time: 24.2 mins
                </div>
            </div>

            <!-- Highlight 2: Fresh Ingredients -->
            <div class="card" style="padding: 2rem 1.75rem; background: var(--bg-surface); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="width: 52px; height: 52px; border-radius: var(--radius-md); background: rgba(16, 185, 129, 0.12); color: var(--success); display: flex; align-items: center; justify-content: center; margin-bottom: 1.35rem; border: 1px solid rgba(16, 185, 129, 0.25);">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        </svg>
                    </div>
                    <span style="font-size: 0.75rem; font-weight: 800; color: var(--success); text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.35rem;">
                        Farm to Wok
                    </span>
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.75rem;">
                        100% Fresh Ingredients
                    </h3>
                    <p style="font-size: 0.9rem; color: var(--text-secondary); line-height: 1.6; margin-bottom: 1.25rem;">
                        Direct morning sourcing from local highland farmers and certified suppliers. Authentic roasted curry powders ground fresh daily without artificial additives.
                    </p>
                </div>
                <div style="padding-top: 1rem; border-top: 1px solid var(--border-subtle); font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem;">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: var(--success);"></span>
                    Zero frozen shortcuts
                </div>
            </div>

            <!-- Highlight 3: Master Chefs -->
            <div class="card" style="padding: 2rem 1.75rem; background: var(--bg-surface); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="width: 52px; height: 52px; border-radius: var(--radius-md); background: rgba(6, 182, 212, 0.12); color: var(--accent); display: flex; align-items: center; justify-content: center; margin-bottom: 1.35rem; border: 1px solid rgba(6, 182, 212, 0.25);">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 13.87A8 8 0 0 1 18 13.87V19a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-5.13z"></path>
                            <line x1="6" y1="17" x2="18" y2="17"></line>
                        </svg>
                    </div>
                    <span style="font-size: 0.75rem; font-weight: 800; color: var(--accent); text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.35rem;">
                        Culinary Artisans
                    </span>
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.75rem;">
                        Master Cloud Chefs
                    </h3>
                    <p style="font-size: 0.9rem; color: var(--text-secondary); line-height: 1.6; margin-bottom: 1.25rem;">
                        Each culinary station is headed by seasoned chefs specializing in high-heat wok kottu, dum biriyani layering, and traditional slow-simmered gravies.
                    </p>
                </div>
                <div style="padding-top: 1rem; border-top: 1px solid var(--border-subtle); font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem;">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: var(--accent);"></span>
                    HACCP certified food safety
                </div>
            </div>

            <!-- Highlight 4: Live Stock Guarantee -->
            <div class="card" style="padding: 2rem 1.75rem; background: var(--bg-surface); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="width: 52px; height: 52px; border-radius: var(--radius-md); background: rgba(139, 92, 246, 0.12); color: #a78bfa; display: flex; align-items: center; justify-content: center; margin-bottom: 1.35rem; border: 1px solid rgba(139, 92, 246, 0.25);">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                            <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                        </svg>
                    </div>
                    <span style="font-size: 0.75rem; font-weight: 800; color: #a78bfa; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.35rem;">
                        Zero Disappointments
                    </span>
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.75rem;">
                        Live Stock Guarantee
                    </h3>
                    <p style="font-size: 0.9rem; color: var(--text-secondary); line-height: 1.6; margin-bottom: 1.25rem;">
                        Proprietary ACID transaction locks instantly synchronize kitchen inventory. If you order it, it is 100% reserved and prepared without post-order cancellations.
                    </p>
                </div>
                <div style="padding-top: 1rem; border-top: 1px solid var(--border-subtle); font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem;">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: #a78bfa;"></span>
                    Automated portion sync
                </div>
            </div>

        </div>
    </section>

    <!-- =======================================================================
         3. Culinary Categories Grid
         ======================================================================= -->
    <section style="margin-bottom: 5.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2.25rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span class="role-badge badge-customer" style="margin-bottom: 0.5rem;">Curated Menus</span>
                <h2 style="font-size: clamp(1.75rem, 3vw, 2.25rem); font-weight: 800; color: var(--text-primary); margin: 0; letter-spacing: -0.02em;">
                    Browse Food Categories
                </h2>
            </div>
            <a href="menu/list.php" class="btn btn-secondary btn-sm">View Complete Menu &rarr;</a>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
            <?php foreach ($categories as $cat) : ?>
                <?php $catImg = $categoryImages[(int)$cat['category_id']] ?? 'uploads/cheese kottu.jpg'; ?>
                <a href="menu/list.php?category=<?= (int)$cat['category_id']; ?>" class="card" style="text-decoration: none; display: flex; flex-direction: column; overflow: hidden; transition: transform var(--transition-base), border-color var(--transition-base);">
                    <div style="height: 190px; position: relative; background: var(--bg-surface-elevated); overflow: hidden;">
                        <img 
                            src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($catImg, ENT_QUOTES, 'UTF-8'); ?>" 
                            alt="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            style="width: 100%; height: 100%; object-fit: cover;"
                            loading="lazy"
                        >
                        <div style="position: absolute; inset: 0; background: linear-gradient(to top, rgba(11, 15, 25, 0.95), transparent 65%);"></div>
                        <div style="position: absolute; bottom: 1rem; left: 1.25rem;">
                            <span class="role-badge badge-staff" style="font-size: 0.75rem;">
                                <?= (int)$cat['dish_count']; ?> Recipes Available
                            </span>
                        </div>
                    </div>
                    <div class="card-body" style="padding: 1.35rem;">
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.35rem;">
                            <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </h3>
                        <p style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.55; margin: 0;">
                            <?= htmlspecialchars($cat['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- =======================================================================
         4. Chef's Special Highlights (Signature Dishes with Stock Status)
         ======================================================================= -->
    <section style="margin-bottom: 5.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2.25rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span class="role-badge badge-admin" style="margin-bottom: 0.5rem;">Signature Selections</span>
                <h2 style="font-size: clamp(1.75rem, 3vw, 2.25rem); font-weight: 800; color: var(--text-primary); margin: 0; letter-spacing: -0.02em;">
                    Chef's Special Highlights
                </h2>
            </div>
            <a href="menu/list.php?sort=price_desc" class="btn btn-secondary btn-sm">Full Premium Range &rarr;</a>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.75rem;">
            <?php foreach ($highlightDishes as $dish) : ?>
                <?php
                    $stock = (int)($dish['current_stock'] ?? 0);
                    $reorder = (int)($dish['reorder_level'] ?? 5);
                    $isOutOfStock = ($stock <= 0);
                    $isLowStock   = (!$isOutOfStock && $stock <= $reorder);
                ?>
                <div class="card" style="display: flex; flex-direction: column;">
                    <div style="position: relative; height: 215px; overflow: hidden; background: var(--bg-surface-elevated);">
                        <?php if (!empty($dish['image_path'])) : ?>
                            <img 
                                src="/UrbanEats-Cloud-Kitchen-Platform/<?= htmlspecialchars($dish['image_path'], ENT_QUOTES, 'UTF-8'); ?>" 
                                alt="<?= htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8'); ?>" 
                                style="width: 100%; height: 100%; object-fit: cover;"
                                loading="lazy"
                            >
                        <?php endif; ?>
                        
                        <!-- Inventory Badge -->
                        <div style="position: absolute; top: 1rem; right: 1rem;">
                            <?php if ($isOutOfStock) : ?>
                                <span class="role-badge" style="background: rgba(244, 63, 94, 0.9); color: #ffffff;">Sold Out</span>
                            <?php elseif ($isLowStock) : ?>
                                <span class="role-badge" style="background: rgba(245, 158, 11, 0.9); color: #ffffff;">Only <?= $stock; ?> Left</span>
                            <?php else : ?>
                                <span class="role-badge" style="background: rgba(16, 185, 129, 0.9); color: #ffffff;">In Stock</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-body" style="padding: 1.5rem; display: flex; flex-direction: column; flex: 1;">
                        <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 0.25rem;">
                            <?= htmlspecialchars($dish['category_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                            <a href="menu/detail.php?id=<?= (int)$dish['dish_id']; ?>" style="color: inherit; text-decoration: none;">
                                <?= htmlspecialchars($dish['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </h3>
                        <p style="font-size: 0.875rem; color: var(--text-secondary); line-height: 1.55; margin-bottom: 1.35rem; flex: 1; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                            <?= htmlspecialchars($dish['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </p>

                        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 1rem; border-top: 1px solid var(--border-subtle);">
                            <div>
                                <span style="font-size: 0.725rem; color: var(--text-muted); display: block; text-transform: uppercase;">Portion Price</span>
                                <strong style="font-size: 1.3rem; font-weight: 800; color: var(--primary); font-family: var(--font-mono);">
                                    LKR <?= number_format((float)$dish['price'], 2); ?>
                                </strong>
                            </div>
                            <a href="menu/detail.php?id=<?= (int)$dish['dish_id']; ?>" class="btn btn-primary btn-sm">
                                View Details &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- =======================================================================
         5. Bottom Conversion Call-To-Action Banner
         ======================================================================= -->
    <section class="card" style="background: linear-gradient(135deg, rgba(249, 115, 22, 0.15), rgba(6, 182, 212, 0.12)), var(--bg-surface-elevated); border: 1px solid var(--border-card); padding: 3.5rem 2rem; text-align: center; border-radius: var(--radius-lg);">
        <div style="max-width: 640px; margin: 0 auto;">
            <span class="role-badge badge-customer" style="margin-bottom: 0.85rem;">Ready To Eat?</span>
            <h2 style="font-size: clamp(2rem, 4vw, 2.75rem); font-weight: 800; color: var(--text-primary); margin-bottom: 1rem; letter-spacing: -0.02em;">
                Your Gourmet Meal Is 30 Minutes Away
            </h2>
            <p style="font-size: 1.05rem; color: var(--text-secondary); line-height: 1.6; margin-bottom: 2.25rem;">
                Experience authentic Sri Lankan spices, sizzling kottu, and gourmet biriyanis prepared fresh in specialized cloud kitchen stations.
            </p>
            <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                <a href="menu/list.php" class="btn btn-primary btn-lg">
                    <span>Explore All Dishes</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
                <?php if (!is_logged_in()) : ?>
                    <a href="register.php" class="btn btn-secondary btn-lg">
                        <span>Create Free Account</span>
                    </a>
                <?php else : ?>
                    <a href="orders/cart.php" class="btn btn-secondary btn-lg">
                        <span>View My Cart</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
