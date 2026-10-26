<?php

/**
 * UrbanEats Cloud Kitchen Platform
 * Shared Layout Footer
 *
 * PHP version 8.x
 */

declare(strict_types=1);

$webRoot = $webRoot ?? '/UrbanEats-Cloud-Kitchen-Platform/public';
?>
</main><!-- /.main-content -->

<!-- Semantic Site Footer -->
<footer class="site-footer">
    <div class="container">
        
        <div class="footer-grid">
            
            <!-- Column 1: Brand & Bio -->
            <div class="footer-col">
                <a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/index.php" class="nav-brand" style="margin-bottom: 0.85rem;">
                    <div class="brand-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M18 8h1a4 4 0 0 1 0 8h-1"></path>
                            <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path>
                        </svg>
                    </div>
                    <span>Urban<span class="highlight">Eats</span></span>
                </a>
                <p style="font-size: 0.875rem; color: var(--text-muted); line-height: 1.6;">
                    Premier multi-brand cloud kitchen ecosystem engineered for ultra-fast gourmet deliveries, hygienic culinary stations, and real-time kitchen tracking.
                </p>
            </div>

            <!-- Column 2: Platform Links -->
            <div class="footer-col">
                <h4>Explore</h4>
                <ul class="footer-links">
                    <li><a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/menu.php">Menu Catalog</a></li>
                    <li><a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/about.php">About Kitchens</a></li>
                    <li><a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/register.php">Create Account</a></li>
                    <li><a href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/login.php">Partner & Staff Sign In</a></li>
                </ul>
            </div>

            <!-- Column 3: Kitchen Operations -->
            <div class="footer-col">
                <h4>Kitchen Operations</h4>
                <ul class="footer-links" style="font-size: 0.875rem; color: var(--text-secondary);">
                    <li><strong>Service Hours:</strong> 10:00 AM – 11:00 PM</li>
                    <li><strong>Kitchen Dispatch:</strong> Live 7 Days / Week</li>
                    <li>
                        <span style="display: inline-flex; align-items: center; gap: 6px; color: #34d399; font-weight: 600;">
                            <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981;"></span>
                            Order Dispatch Active
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Column 4: Contact & Help -->
            <div class="footer-col">
                <h4>Support</h4>
                <ul class="footer-links">
                    <li><a href="mailto:support@urbaneats.local">support@urbaneats.local</a></li>
                    <li><a href="tel:+15550198234">+1 (555) 019-8234</a></li>
                    <li><a href="#privacy">Privacy & Security</a></li>
                    <li><a href="#terms">Terms of Service</a></li>
                </ul>
            </div>

        </div>

        <!-- Footer Bottom Bar -->
        <div class="footer-bottom">
            <div>
                &copy; <?= date('Y'); ?> UrbanEats Cloud Kitchen Platform. All rights reserved.
            </div>
            <div>
                Built with PHP 8.x, Secure PDO &amp; Pure Responsive CSS3.
            </div>
        </div>

    </div>
</footer>

<!-- Interactive Mobile Menu Toggle Script -->
<script>
    (function () {
        const toggleBtn = document.getElementById('mobileMenuToggle');
        const navMenu = document.getElementById('navMenuWrapper');

        if (toggleBtn && navMenu) {
            toggleBtn.addEventListener('click', function () {
                const isOpen = navMenu.classList.toggle('is-open');
                toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            // Close mobile menu when clicking outside
            document.addEventListener('click', function (event) {
                if (!toggleBtn.contains(event.target) && !navMenu.contains(event.target)) {
                    navMenu.classList.remove('is-open');
                    toggleBtn.setAttribute('aria-expanded', 'false');
                }
            });
        }
    })();
</script>

<!-- Theme Mode Toggle Script with LocalStorage Persistence -->
<script>
    (function () {
        function getSavedTheme() {
            try {
                return localStorage.getItem('urbaneats_theme') || 'dark';
            } catch (e) {
                return 'dark';
            }
        }

        function setSavedTheme(theme) {
            try {
                localStorage.setItem('urbaneats_theme', theme);
            } catch (e) {}
        }

        function applyTheme(theme) {
            if (theme === 'light') {
                document.documentElement.classList.add('light-mode');
                if (document.body) {
                    document.body.classList.add('light-mode');
                }
            } else {
                document.documentElement.classList.remove('light-mode');
                if (document.body) {
                    document.body.classList.remove('light-mode');
                }
            }
        }

        // Apply immediately
        applyTheme(getSavedTheme());

        // Attach click listener to all theme toggle buttons
        const toggleButtons = document.querySelectorAll('.theme-toggle-btn');
        toggleButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const isCurrentlyLight = document.documentElement.classList.contains('light-mode');
                const targetTheme = isCurrentlyLight ? 'dark' : 'light';
                applyTheme(targetTheme);
                setSavedTheme(targetTheme);
            });
        });
    })();
</script>

</body>
</html>
