-- =============================================================================
-- UrbanEats Cloud Kitchen Platform - Unified Enterprise Database Schema & Seeds
-- Database: urbaneats_db
-- Architecture: Normalized 3NF (Third Normal Form) 14 Relational Tables
-- Combines: Component A (Auth & Roles), Component B (Menu & Inventory), Component C (Orders & Payments)
-- DBMS: MySQL 8.x / MariaDB 10.x
-- =============================================================================

CREATE DATABASE IF NOT EXISTS urbaneats_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE urbaneats_db;

SET FOREIGN_KEY_CHECKS = 0;

-- Drop all 14 tables in reverse dependency order
DROP TABLE IF EXISTS order_status_history;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS coupons;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS dishes;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- COMPONENT A: ROLES, USERS, LOGIN AUDITING, AUDIT LOG & PASSWORD RESETS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. roles Table
-- -----------------------------------------------------------------------------
CREATE TABLE roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. users Table
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_users_role (role_id),
    INDEX idx_users_email (email),
    INDEX idx_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. login_attempts Table
-- -----------------------------------------------------------------------------
CREATE TABLE login_attempts (
    attempt_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_successful TINYINT(1) DEFAULT 0,
    INDEX idx_attempts_email (email),
    INDEX idx_attempts_ip (ip_address),
    INDEX idx_attempts_time (attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. audit_log Table
-- -----------------------------------------------------------------------------
CREATE TABLE audit_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. password_resets Table
-- -----------------------------------------------------------------------------
CREATE TABLE password_resets (
    reset_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_resets_email (email),
    INDEX idx_resets_token (token),
    INDEX idx_resets_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- COMPONENT B: MENU CATEGORIES, DISHES, INVENTORY & STOCK MOVEMENTS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 6. categories Table
-- -----------------------------------------------------------------------------
CREATE TABLE categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_categories_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. dishes Table
-- -----------------------------------------------------------------------------
CREATE TABLE dishes (
    dish_id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10, 2) NOT NULL,
    image_path VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_dishes_category (category_id),
    INDEX idx_dishes_name (name),
    INDEX idx_dishes_price (price),
    INDEX idx_dishes_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. inventory Table
-- -----------------------------------------------------------------------------
CREATE TABLE inventory (
    inventory_id INT AUTO_INCREMENT PRIMARY KEY,
    dish_id INT NOT NULL UNIQUE,
    current_stock INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 5,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (dish_id) REFERENCES dishes(dish_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_current_stock_non_negative CHECK (current_stock >= 0),
    CONSTRAINT chk_reorder_level_non_negative CHECK (reorder_level >= 0),
    INDEX idx_inventory_stock (current_stock),
    INDEX idx_inventory_reorder (reorder_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. stock_movements Table
-- -----------------------------------------------------------------------------
CREATE TABLE stock_movements (
    movement_id INT AUTO_INCREMENT PRIMARY KEY,
    dish_id INT NOT NULL,
    user_id INT NULL,
    change_qty INT NOT NULL,
    movement_type ENUM('restock', 'wastage', 'order', 'adjustment') NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dish_id) REFERENCES dishes(dish_id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_stock_movements_dish (dish_id),
    INDEX idx_stock_movements_user (user_id),
    INDEX idx_stock_movements_type (movement_type),
    INDEX idx_stock_movements_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- COMPONENT C: COUPONS, ORDERS, LINE ITEMS, PAYMENTS & STATUS HISTORY
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 10. coupons Table
-- -----------------------------------------------------------------------------
CREATE TABLE coupons (
    coupon_id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    discount_type ENUM('percentage', 'fixed') NOT NULL,
    discount_value DECIMAL(10, 2) NOT NULL,
    min_order_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    max_discount_amount DECIMAL(10, 2) NULL,
    usage_limit INT NULL,
    used_count INT NOT NULL DEFAULT 0,
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_coupon_discount_positive CHECK (discount_value > 0),
    CONSTRAINT chk_coupon_min_order_non_negative CHECK (min_order_amount >= 0),
    CONSTRAINT chk_coupon_usage_non_negative CHECK (used_count >= 0),
    CONSTRAINT chk_coupon_validity_window CHECK (valid_until >= valid_from),
    INDEX idx_coupons_code (code),
    INDEX idx_coupons_active (is_active),
    INDEX idx_coupons_validity (valid_from, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. orders Table
-- -----------------------------------------------------------------------------
CREATE TABLE orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    coupon_id INT NULL,
    order_status ENUM('pending', 'confirmed', 'preparing', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
    subtotal_lkr DECIMAL(10, 2) NOT NULL,
    discount_lkr DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    delivery_fee_lkr DECIMAL(10, 2) NOT NULL DEFAULT 350.00,
    total_amount_lkr DECIMAL(10, 2) NOT NULL,
    delivery_address TEXT NOT NULL,
    delivery_city VARCHAR(100) NOT NULL DEFAULT 'Colombo',
    contact_phone VARCHAR(25) NOT NULL,
    delivery_notes VARCHAR(255) NULL,
    estimated_delivery_time DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (coupon_id) REFERENCES coupons(coupon_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_orders_subtotal_non_negative CHECK (subtotal_lkr >= 0),
    CONSTRAINT chk_orders_discount_non_negative CHECK (discount_lkr >= 0),
    CONSTRAINT chk_orders_delivery_fee_non_negative CHECK (delivery_fee_lkr >= 0),
    CONSTRAINT chk_orders_total_non_negative CHECK (total_amount_lkr >= 0),
    INDEX idx_orders_user (user_id),
    INDEX idx_orders_number (order_number),
    INDEX idx_orders_status (order_status),
    INDEX idx_orders_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. order_items Table
-- -----------------------------------------------------------------------------
CREATE TABLE order_items (
    order_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    dish_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price_lkr DECIMAL(10, 2) NOT NULL,
    subtotal_lkr DECIMAL(10, 2) NOT NULL,
    special_instructions VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (dish_id) REFERENCES dishes(dish_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_order_items_qty_positive CHECK (quantity > 0),
    CONSTRAINT chk_order_items_unit_price_non_negative CHECK (unit_price_lkr >= 0),
    CONSTRAINT chk_order_items_subtotal_non_negative CHECK (subtotal_lkr >= 0),
    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_dish (dish_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. payments Table
-- -----------------------------------------------------------------------------
CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_method ENUM('cash_on_delivery', 'card', 'bank_transfer', 'online') NOT NULL DEFAULT 'cash_on_delivery',
    payment_status ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    amount_lkr DECIMAL(10, 2) NOT NULL,
    transaction_reference VARCHAR(100) NULL UNIQUE,
    payment_details TEXT NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_payments_amount_non_negative CHECK (amount_lkr >= 0),
    INDEX idx_payments_order (order_id),
    INDEX idx_payments_status (payment_status),
    INDEX idx_payments_method (payment_method),
    INDEX idx_payments_ref (transaction_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14. order_status_history Table
-- -----------------------------------------------------------------------------
CREATE TABLE order_status_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    changed_by_user_id INT NULL,
    previous_status ENUM('pending', 'confirmed', 'preparing', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'cancelled') NULL,
    new_status ENUM('pending', 'confirmed', 'preparing', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'cancelled') NOT NULL,
    comment VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_status_history_order (order_id),
    INDEX idx_status_history_user (changed_by_user_id),
    INDEX idx_status_history_status (new_status),
    INDEX idx_status_history_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- UNIFIED SEED DATASETS FOR ALL 14 TABLES
-- =============================================================================

-- 1. roles Seed
INSERT INTO roles (role_id, role_name) VALUES
(1, 'Admin'),
(2, 'Kitchen Staff'),
(3, 'Customer');

-- 2. users Seed (Universal Password: Password123!)
INSERT INTO users (user_id, first_name, last_name, email, username, password_hash, role_id, is_active, created_at) VALUES
-- Administrators
(1,  'Kasun',       'Perera',           'kasun.admin@urbaneats.lk',      'kasun_admin',     '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 1, 1, '2026-01-10 08:30:00'),
(2,  'Dinithi',     'Jayawardena',      'dinithi.admin@urbaneats.lk',    'dinithi_admin',   '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 1, 1, '2026-01-12 09:15:00'),
(3,  'Nuwan',       'Pradeep',          'nuwan.admin@urbaneats.lk',      'nuwan_admin',     '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 1, 1, '2026-01-15 11:00:00'),
-- Kitchen Staff
(4,  'Malith',      'Fernando',         'malith.chef@urbaneats.lk',      'malith_chef',     '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-16 10:20:00'),
(5,  'Tharindu',    'Silva',            'tharindu.kitchen@urbaneats.lk', 'tharindu_silva',  '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-18 14:05:00'),
(6,  'Anuki',       'Wijesinghe',       'anuki.kitchen@urbaneats.lk',    'anuki_wije',      '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-20 15:30:00'),
(7,  'Roshan',      'Wickramasinghe',   'roshan.kitchen@urbaneats.lk',   'roshan_wicky',    '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-22 16:45:00'),
(8,  'Dilani',      'Senanayake',       'dilani.kitchen@urbaneats.lk',   'dilani_sena',     '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-25 08:50:00'),
(9,  'Kaveen',      'Mendis',           'kaveen.kitchen@urbaneats.lk',   'kaveen_mendis',   '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-28 12:10:00'),
-- Customers
(10, 'Nimesh',      'Bandara',          'nimesh.bandara@gmail.com',      'nimesh_b',        '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-01 10:15:00'),
(11, 'Chamari',     'Atapattu',         'chamari.a@outlook.com',         'chamari_a',       '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-02 11:45:00'),
(12, 'Sajith',      'Gunawardena',      'sajith.gunawardena@gmail.com',  'sajith_g',        '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-05 13:20:00'),
(13, 'Sanduni',     'De Silva',         'sanduni.desilva@yahoo.com',     'sanduni_ds',      '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-08 17:05:00'),
(14, 'Lakshitha',   'Alwis',            'lakshitha.alwis@gmail.com',     'lakshitha_a',     '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-10 18:30:00'),
(15, 'Priyantha',   'Cooray',           'priyantha.cooray@gmail.com',    'priyantha_c',     '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-12 19:10:00'),
(16, 'Isuru',       'Udana',            'isuru.udana@gmail.com',         'isuru_u',         '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-14 20:00:00'),
(17, 'Gayani',      'Karunaratne',      'gayani.karunaratne@gmail.com',  'gayani_k',        '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-16 12:25:00'),
(18, 'Ashen',       'Rathnayake',       'ashen.rathnayake@gmail.com',    'ashen_r',         '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-18 14:40:00'),
(19, 'Chathurika',  'Samarakoon',       'chathurika.s@gmail.com',        'chathurika_s',    '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-20 16:15:00'),
(20, 'Hashan',      'Dissanayake',      'hashan.d@gmail.com',            'hashan_d',        '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-22 18:50:00'),
(21, 'Ruwantha',    'Perera',           'ruwantha.perera@gmail.com',     'ruwantha_p',      '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 0, '2026-02-24 11:00:00'),
(22, 'Kavindi',     'Jayasuriya',       'kavindi.j@gmail.com',           'kavindi_j',       '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 3, 1, '2026-02-25 09:30:00');

-- 3. login_attempts Seed
INSERT INTO login_attempts (attempt_id, email, ip_address, attempt_time, is_successful) VALUES
(1,  'kasun.admin@urbaneats.lk',      '112.134.45.12',  '2026-02-20 08:31:12', 1),
(2,  'dinithi.admin@urbaneats.lk',    '175.157.88.204', '2026-02-20 09:12:05', 1),
(3,  'malith.chef@urbaneats.lk',      '203.94.72.15',   '2026-02-20 10:02:44', 1),
(4,  'tharindu.kitchen@urbaneats.lk', '122.255.10.89',  '2026-02-20 10:15:20', 1),
(5,  'nimesh.bandara@gmail.com',      '112.135.210.4',  '2026-02-20 11:22:18', 1),
(6,  'unknown_intruder@bot.net',      '194.26.29.112',  '2026-02-20 12:00:01', 0),
(7,  'unknown_intruder@bot.net',      '194.26.29.112',  '2026-02-20 12:00:15', 0),
(8,  'chamari.a@outlook.com',         '175.157.12.90',  '2026-02-20 13:40:11', 1),
(9,  'sajith.gunawardena@gmail.com',  '112.134.190.55', '2026-02-21 08:15:30', 0),
(10, 'sajith.gunawardena@gmail.com',  '112.134.190.55', '2026-02-21 08:16:05', 1),
(11, 'anuki.kitchen@urbaneats.lk',    '203.94.80.22',   '2026-02-21 09:00:12', 1),
(12, 'roshan.kitchen@urbaneats.lk',   '175.157.65.11',  '2026-02-21 09:30:45', 1),
(13, 'dilani.kitchen@urbaneats.lk',   '112.134.22.88',  '2026-02-21 10:10:20', 1),
(14, 'kaveen.kitchen@urbaneats.lk',   '122.255.44.71',  '2026-02-21 11:05:00', 1),
(15, 'sanduni.desilva@yahoo.com',     '112.135.101.9',  '2026-02-21 12:20:33', 1),
(16, 'lakshitha.alwis@gmail.com',     '175.157.40.18',  '2026-02-21 13:50:19', 1),
(17, 'priyantha.cooray@gmail.com',    '203.94.95.103',  '2026-02-21 14:15:00', 1),
(18, 'ruwantha.perera@gmail.com',     '112.134.77.62',  '2026-02-21 15:30:22', 0),
(19, 'isuru.udana@gmail.com',         '122.255.85.34',  '2026-02-21 16:45:10', 1),
(20, 'gayani.karunaratne@gmail.com',  '112.135.88.19',  '2026-02-22 09:10:14', 1),
(21, 'ashen.rathnayake@gmail.com',    '175.157.19.45',  '2026-02-22 10:35:28', 1),
(22, 'chathurika.s@gmail.com',        '203.94.66.12',   '2026-02-22 11:55:00', 1),
(23, 'hashan.d@gmail.com',            '112.134.150.81', '2026-02-22 13:12:40', 1),
(24, 'kavindi.j@gmail.com',           '175.157.77.99',  '2026-02-22 15:00:22', 1),
(25, 'kasun.admin@urbaneats.lk',      '127.0.0.1',      '2026-02-22 16:20:00', 1);

-- 4. audit_log Seed
INSERT INTO audit_log (log_id, user_id, action, details, ip_address, created_at) VALUES
(1,  1,  'USER_REGISTRATION',       'System initialized with primary administrator account kasun_admin.',                   '127.0.0.1',      '2026-01-10 08:30:00'),
(2,  1,  'USER_LOGIN',              'Administrator kasun_admin signed in to configure cloud kitchen parameters.',           '112.134.45.12',  '2026-01-10 08:35:00'),
(3,  1,  'USER_ROLE_CHANGE',        'Assigned Administrator role privileges to user dinithi_admin (#2).',                   '112.134.45.12',  '2026-01-12 09:20:00'),
(4,  2,  'USER_LOGIN',              'Administrator dinithi_admin signed in for station security review.',                   '175.157.88.204', '2026-01-12 09:25:00'),
(5,  1,  'USER_ROLE_CHANGE',        'Assigned Kitchen Staff role privileges to user malith_chef (#4).',                     '112.134.45.12',  '2026-01-16 10:25:00'),
(6,  4,  'USER_LOGIN',              'Executive Chef malith_chef logged in to setup menu categories.',                       '203.94.72.15',   '2026-01-16 10:30:00'),
(7,  5,  'USER_LOGIN',              'Kitchen Staff tharindu_silva logged in to check inventory dispatch.',                  '122.255.10.89',  '2026-01-18 14:10:00'),
(8,  10, 'USER_REGISTRATION',       'New customer account registered: nimesh_b (nimesh.bandara@gmail.com).',                '112.135.210.4',  '2026-02-01 10:15:00'),
(9,  10, 'USER_LOGIN',              'Customer nimesh_b signed in to place orders.',                                         '112.135.210.4',  '2026-02-01 10:16:00'),
(10, 11, 'USER_REGISTRATION',       'New customer account registered: chamari_a (chamari.a@outlook.com).',                  '175.157.12.90',  '2026-02-02 11:45:00'),
(11, 10, 'PROFILE_UPDATE',          'Customer nimesh_b updated personal phone contact details.',                             '112.135.210.4',  '2026-02-03 15:10:00'),
(12, 12, 'USER_LOGIN',              'Customer sajith_g signed in successfully after previous password retry.',              '112.134.190.55', '2026-02-05 13:22:00'),
(13, 12, 'PASSWORD_CHANGE',         'User sajith_g changed account password.',                                              '112.134.190.55', '2026-02-05 13:25:00'),
(14, 1,  'USER_STATUS_CHANGE',      'Administrator kasun_admin deactivated customer account ruwantha_p (#21).',            '112.134.45.12',  '2026-02-06 11:05:00'),
(15, 6,  'USER_LOGIN',              'Kitchen prep lead anuki_wije logged in to monitor kitchen station B.',                 '203.94.80.22',   '2026-02-10 09:05:00'),
(16, 13, 'USER_LOGIN',              'Customer sanduni_ds signed in to browse dessert menu items.',                          '112.135.101.9',  '2026-02-12 17:10:00'),
(17, 14, 'USER_LOGIN',              'Customer lakshitha_a signed in from Dehiwala.',                                        '175.157.40.18',  '2026-02-14 18:35:00'),
(18, 15, 'PASSWORD_RESET_REQUESTED','Password reset link generated for priyantha.cooray@gmail.com.',                        '203.94.95.103',  '2026-02-15 10:00:00'),
(19, 15, 'PASSWORD_RESET_COMPLETED','Password reset completed via token for user priyantha_c.',                             '203.94.95.103',  '2026-02-15 10:12:00'),
(20, 7,  'USER_LOGIN',              'Kitchen Staff roshan_wicky signed in to update station orders.',                       '175.157.65.11',  '2026-02-16 11:30:00'),
(21, 16, 'USER_LOGIN',              'Customer isuru_u signed in to place weekend dinner order.',                            '122.255.85.34',  '2026-02-18 20:05:00'),
(22, 17, 'USER_LOGIN',              'Customer gayani_k signed in.',                                                         '112.135.88.19',  '2026-02-19 12:30:00'),
(23, 18, 'USER_LOGIN',              'Customer ashen_r signed in.',                                                          '175.157.19.45',  '2026-02-20 14:45:00'),
(24, 1,  'USER_LOGOUT',             'Administrator kasun_admin signed out cleanly.',                                        '112.134.45.12',  '2026-02-21 18:00:00'),
(25, 2,  'USER_LOGIN',              'Administrator dinithi_admin signed in for weekly audit log review.',                   '175.157.88.204', '2026-02-22 09:00:00');

-- 5. password_resets Seed
INSERT INTO password_resets (reset_id, email, token, expires_at, created_at) VALUES
(1, 'priyantha.cooray@gmail.com', 'a1b2c3d4e5f67890123456789abcdef0123456789abcdef0123456789abcdef0', '2026-02-15 11:00:00', '2026-02-15 10:00:00'),
(2, 'kavindi.j@gmail.com',        'f0e1d2c3b4a567890123456789abcdef0123456789abcdef0123456789abcdef1', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW());

-- 6. categories Seed
INSERT INTO categories (category_id, name, description, is_active) VALUES
(1, 'Kottu Specialties', 'Authentic godamba roti chopped on sizzling iron griddles with vegetables, eggs, meats, and aromatic spices.', 1),
(2, 'Rice & Biriyani', 'Fragrant basmati biriyanis, traditional Dutch Burgher lamprais, and high-flame wok tossed fried rice varieties.', 1),
(3, 'Traditional Rice & Curry', 'Wholesome clay-pot curries served with red raw rice or samba, coconut pol sambol, dhal, and fresh garden vegetables.', 1),
(4, 'Devilled & Fast Food', 'Fiery wok-tossed devilled meats with capsicums and big onions, alongside crispy fried chicken and crunchy finger foods.', 1),
(5, 'Authentic Short Eats', 'Handmade savory pastries, golden crumbed chicken rolls, spiced tuna fish cutlets, and griddle vegetable rotis.', 1),
(6, 'Refreshing Beverages', 'Chilled tropical beverages including royal rose faluda, iced Milo, wood apple juice, and fresh seasonal fruit blends.', 1);

-- 7. dishes Seed (22 Dishes with realistic Sri Lankan Rupee pricing)
INSERT INTO dishes (dish_id, category_id, name, description, price, image_path, is_active) VALUES
(1, 2, 'Royal Chicken Dum Biriyani', 'Fragrant aged basmati rice slow-cooked with marinated bone-in chicken, spiced boiled egg, fresh mint sambol, and cooling raita.', 1450.00, 'uploads/chicken biriyani.jpg', 1),
(2, 2, 'Indonesian Style Nasi Goreng', 'Wok-charred seafood fried rice served with chicken satay skewer, sunny-side fried egg, prawn crackers, and spicy chili paste.', 1350.00, 'uploads/nasi goreng.jpg', 1),
(3, 2, 'Signature Mixed Fried Rice', 'Aromatic basmati rice tossed on high heat with chicken, succulent prawns, calamari, farm eggs, leeks, and homemade chili paste.', 1250.00, 'uploads/mixed fried rice.jpg', 1),
(4, 2, 'Authentic Dutch Burgher Lamprais', 'Warm banana-leaf parcel of samba rice infused with meat stock, four-meat curry, frikkadels, sweet seeni sambol, and blachan.', 1650.00, 'uploads/lamprais.jpg', 1),
(5, 2, 'Special Mutton Dum Biriyani', 'Tender slow-cooked Australian mutton layered in long-grain basmati with saffron, cardamom, boiled egg, and golden cashew nuts.', 1950.00, 'uploads/rice and biriyani.jpg', 1),
(6, 1, 'Classic Chicken Kottu Roti', 'Shredded godamba roti beaten on the griddle with chicken curry, scrambled eggs, cabbage, carrots, leeks, and spicy chicken gravy.', 1100.00, 'uploads/chicken kottu.webp', 1),
(7, 1, 'Gourmet Chicken Cheese Kottu', 'Rich and creamy shredded roti kottu packed with roasted chicken cubes, melted mozzarella cheese, fresh green chilies, and egg.', 1450.00, 'uploads/cheese kottu.jpg', 1),
(8, 1, 'Spicy Seafood Dolphin Kottu', 'Chunky diced roti tossed with prawns, calamari, sailfish cubes, bell peppers, big onions, and fiery spicy devil sauce.', 1550.00, 'uploads/dolphin kottu.jpg', 1),
(9, 1, 'Traditional Egg Kottu', 'Freshly chopped godamba roti tossed on a hot plate with farm-fresh eggs, onions, green chilies, and aromatic curry sauce.', 850.00, 'uploads/egg kottu.jpg', 1),
(10, 1, 'Roast Paan Kottu Fusion', 'Crispy oven-baked traditional roast paan crushed and prepared kottu-style with curry sauce, onions, and pol sambol notes.', 1050.00, 'uploads/kottu.jpg', 1),
(11, 3, 'Ceylon Village Chicken Rice & Curry', 'Steamed red raw rice or samba rice served with clay-pot chicken curry, tempered dhal, potato curry, gotukola sambol, and papadam.', 750.00, 'uploads/chicken rice and curry.webp', 1),
(12, 3, 'Southern Thalapath Fish Curry & Rice', 'Tangy goraka-infused sailfish curry paired with steamed white rice, freshly scraped pol sambol, tempered dhal, and papadam.', 800.00, 'uploads/fish rice and curry.jpg', 1),
(13, 3, 'Pol Sambol & Egg Rice & Curry', 'Steamed samba rice paired with freshly prepared spicy coconut pol sambol, two spiced fried eggs, and creamy coconut dhal curry.', 650.00, 'uploads/egg rice and curry.jpg', 1),
(14, 3, 'Vegetarian Lotus Root Rice & Curry', 'Healthy organic rice meal with tender lotus root (nelum ala) curry, cashew nut curry, tempered parippu, and crispy chili papadam.', 600.00, 'uploads/rice and curry.webp', 1),
(15, 4, 'Spicy Hot Chicken Devilled', 'Crispy batter-fried chicken pieces wok-tossed in sweet and spicy chili sauce with chunky capsicum, tomatoes, and red onions.', 1300.00, 'uploads/chicken devilled.webp', 1),
(16, 4, 'Crispy Sausage Devilled Platter', 'Sliced premium chicken bockwurst sausages tossed in spicy devilled sauce with banana peppers, red onions, and scallions.', 1100.00, 'uploads/sausage devilled.jpg', 1),
(17, 4, 'Golden Spice Fried Chicken (4 Pcs)', 'Four pieces of succulent chicken marinated in Ceylon spices, breaded in seasoned flour, and deep-fried until shatteringly crisp.', 1400.00, 'uploads/crispy fried chicken.jpg', 1),
(18, 4, 'Masala Seasoned French Fries', 'Crispy skin-on potato fries tossed in toasted curry leaf powder, roasted cumin, and chili salt, served with garlic dip.', 650.00, 'uploads/french fries.jpg', 1),
(19, 5, 'Crispy Crumbed Chicken Rolls (2 Pcs)', 'Two handmade flaky pastry crepes filled with peppered minced chicken and spiced potatoes, coated in panko crumbs and fried golden.', 400.00, 'uploads/chicken roll.jpg', 1),
(20, 5, 'Spiced Tuna Fish Cutlets (4 Pcs)', 'Four crunchy croquettes made with wild-caught tuna, spiced mashed potatoes, black pepper, and curry leaves.', 450.00, 'uploads/fish cutlet.jpg', 1),
(21, 5, 'Stuffed Spicy Vegetable Roti (2 Pcs)', 'Two triangular godamba rotis wrapped around a zesty spiced potato, leek, and carrot filling, toasted on a dry griddle.', 350.00, 'uploads/vegetable roti.jpg', 1),
(22, 6, 'Classic Royal Rose Faluda', 'Refreshing chilled rose nectar milk drink prepared with soaked sweet basil seeds (kasakasa), grass jelly strips, and vanilla ice cream.', 550.00, 'uploads/faluda.jpg', 1);

-- 8. inventory Seed (22 dishes)
INSERT INTO inventory (inventory_id, dish_id, current_stock, reorder_level) VALUES
(1,  1,  25, 10),
(2,  2,  18, 8),
(3,  3,  30, 10),
(4,  4,  0,  6),
(5,  5,  3,  8),
(6,  6,  40, 12),
(7,  7,  4,  10),
(8,  8,  0,  5),
(9,  9,  35, 10),
(10, 10, 20, 6),
(11, 11, 45, 15),
(12, 12, 2,  8),
(13, 13, 22, 8),
(14, 14, 16, 5),
(15, 15, 28, 10),
(16, 16, 19, 6),
(17, 17, 24, 8),
(18, 18, 35, 10),
(19, 19, 50, 15),
(20, 20, 5,  12),
(21, 21, 38, 12),
(22, 22, 42, 15);

-- 9. stock_movements Seed (25 audit-trail records)
INSERT INTO stock_movements (movement_id, dish_id, user_id, change_qty, movement_type, reason, created_at) VALUES
(1,  1,  4,  30,  'restock',    'Morning kitchen prep: batch of 30 Biriyani portions prepared', DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(2,  1,  10, -3,  'order',      'Online customer order #1001 fulfillment', DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(3,  1,  11, -2,  'order',      'Online customer order #1004 fulfillment', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(4,  2,  4,  25,  'restock',    'Initial batch of Indonesian Nasi Goreng cooked', DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(5,  2,  12, -4,  'order',      'Customer dinner order #1007', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(6,  2,  5,  -3,  'wastage',    'Damaged packaging and dropped box during counter dispatch', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(7,  3,  4,  35,  'restock',    'Prepared lunch shift wok fried rice base', DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(8,  3,  13, -5,  'order',      'Catering pickup order #1010', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(9,  4,  4,  10,  'restock',    'Limited morning bake of 10 banana-leaf Lamprais packs', DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(10, 4,  14, -8,  'order',      'Pre-order fulfillment #1012', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(11, 4,  5,  -2,  'order',      'Counter order #1015 (Stock depleted to 0)', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(12, 5,  4,  8,   'restock',    'Slow-cooked mutton biriyani small batch (8 portions)', DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(13, 5,  15, -5,  'order',      'Customer order #1018 (Triggered low-stock alert)', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(14, 6,  5,  50,  'restock',    'Fresh godamba roti batch and chicken curry prep', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(15, 6,  16, -10, 'order',      'Group dinner order #1020', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(16, 7,  5,  15,  'restock',    'Cheese kottu base batch preparation', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(17, 7,  17, -11, 'order',      'Evening rush delivery order #1022 (Stock low)', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(18, 8,  4,  6,   'restock',    'Fresh catch seafood dolphin kottu prepared', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(19, 8,  18, -6,  'order',      'Online party order #1025 (Stock depleted to 0)', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(20, 11, 4,  50,  'restock',    'Lunch service village chicken curry pots ready', DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(21, 11, 10, -5,  'order',      'Takeaway order #1028', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(22, 12, 5,  10,  'restock',    'Fresh fish curry cooked', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(23, 12, 19, -8,  'order',      'Lunch order #1030 (Low stock warning)', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(24, 20, 6,  25,  'restock',    'Morning frying of 25 tuna fish cutlets', DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(25, 20, 4,  -20, 'order',      'Office snack box orders #1033', DATE_SUB(NOW(), INTERVAL 3 HOUR));

-- 10. coupons Seed (22 Vouchers)
INSERT INTO coupons (coupon_id, code, discount_type, discount_value, min_order_amount, max_discount_amount, usage_limit, used_count, valid_from, valid_until, is_active) VALUES
(1,  'WELCOME10',    'percentage', 10.00,  1500.00, 500.00, 100, 18, '2026-01-01 00:00:00', '2026-12-31 23:59:59', 1),
(2,  'LKR500OFF',    'fixed',      500.00, 3000.00, NULL,   50,  12, '2026-01-01 00:00:00', '2026-12-31 23:59:59', 1),
(3,  'FEAST20',      'percentage', 20.00,  4000.00, 1000.00,30,  8,  '2026-02-01 00:00:00', '2026-12-31 23:59:59', 1),
(4,  'BIRIHOLIC',    'fixed',      300.00, 2500.00, NULL,   100, 24, '2026-01-15 00:00:00', '2026-12-31 23:59:59', 1),
(5,  'KOTTUNIGHT',   'percentage', 15.00,  2000.00, 600.00, 75,  31, '2026-02-01 00:00:00', '2026-12-31 23:59:59', 1),
(6,  'WEEKEND15',    'percentage', 15.00,  2500.00, 750.00, 50,  19, '2026-01-01 00:00:00', '2026-12-31 23:59:59', 1),
(7,  'FAMILYMEAL',   'fixed',      750.00, 5000.00, NULL,   40,  14, '2026-01-10 00:00:00', '2026-12-31 23:59:59', 1),
(8,  'CURRYLOVER',   'percentage', 12.00,  1800.00, 400.00, 60,  11, '2026-02-01 00:00:00', '2026-12-31 23:59:59', 1),
(9,  'OFFICEBOX',    'fixed',      1000.00,6000.00, NULL,   25,  9,  '2026-01-20 00:00:00', '2026-12-31 23:59:59', 1),
(10, 'SHORTEATS',    'fixed',      200.00, 1200.00, NULL,   80,  35, '2026-01-05 00:00:00', '2026-12-31 23:59:59', 1),
(11, 'COLOMBOEATS',  'percentage', 10.00,  1500.00, 450.00, 120, 44, '2026-01-01 00:00:00', '2026-12-31 23:59:59', 1),
(12, 'FIRSTORDER',   'fixed',      400.00, 2000.00, NULL,   200, 86, '2026-01-01 00:00:00', '2026-12-31 23:59:59', 1),
(13, 'FALUDATREAT',  'fixed',      250.00, 1500.00, NULL,   50,  15, '2026-02-01 00:00:00', '2026-12-31 23:59:59', 1),
(14, 'LUNCHSPECIAL', 'percentage', 10.00,  1200.00, 350.00, 90,  29, '2026-01-15 00:00:00', '2026-12-31 23:59:59', 1),
(15, 'DEVILLEDHOT',  'fixed',      350.00, 2600.00, NULL,   60,  17, '2026-02-05 00:00:00', '2026-12-31 23:59:59', 1),
(16, 'SUPPERCLUB',   'percentage', 15.00,  3000.00, 800.00, 45,  10, '2026-02-10 00:00:00', '2026-12-31 23:59:59', 1),
(17, 'CEYLONSPICE',  'fixed',      450.00, 2800.00, NULL,   70,  22, '2026-01-12 00:00:00', '2026-12-31 23:59:59', 1),
(18, 'FREEDELIVERY', 'fixed',      350.00, 2000.00, NULL,   150, 68, '2026-01-01 00:00:00', '2026-12-31 23:59:59', 1),
(19, 'GOURMET25',    'percentage', 25.00,  4500.00, 1250.00,20,  16, '2026-02-14 00:00:00', '2026-12-31 23:59:59', 1),
(20, 'MIDNIGHT10',   'percentage', 10.00,  1600.00, 400.00, 50,  13, '2026-02-01 00:00:00', '2026-12-31 23:59:59', 1),
(21, 'EXPIRED50',    'percentage', 50.00,  2000.00, 1500.00,10,  10, '2025-01-01 00:00:00', '2025-12-31 23:59:59', 0),
(22, 'NEWYEAR2025',  'fixed',      600.00, 3500.00, NULL,   30,  30, '2025-12-20 00:00:00', '2026-01-05 23:59:59', 0);

-- 11. orders Seed (22 Customer Orders)
INSERT INTO orders (order_id, order_number, user_id, coupon_id, order_status, subtotal_lkr, discount_lkr, delivery_fee_lkr, total_amount_lkr, delivery_address, delivery_city, contact_phone, delivery_notes, estimated_delivery_time, created_at) VALUES
(1,  'UE-20260218-0001', 10, 1,    'delivered',        2900.00, 290.00, 350.00, 2960.00, '45/2 Galle Road, Bambalapitiya',         'Colombo 04', '+94 77 123 4567', 'Ring front bell, leave with security if unavailable.', '2026-02-18 12:45:00', '2026-02-18 11:30:00'),
(2,  'UE-20260218-0002', 11, NULL, 'delivered',        2700.00, 0.00,   350.00, 3050.00, '12A Flower Road, Cinnamon Gardens',       'Colombo 07', '+94 71 987 6543', 'Deliver to 3rd floor reception.',                      '2026-02-18 14:15:00', '2026-02-18 13:10:00'),
(3,  'UE-20260219-0003', 12, 2,    'delivered',        3900.00, 500.00, 350.00, 3750.00, '88 Kandy Road, Kiribathgoda',             'Kelaniya',   '+94 76 555 1234', 'Make biriyani extra spicy please.',                    '2026-02-19 19:30:00', '2026-02-19 18:25:00'),
(4,  'UE-20260219-0004', 13, NULL, 'delivered',        2200.00, 0.00,   350.00, 2550.00, '23 Havelock Road, Havelock Town',         'Colombo 05', '+94 70 333 8899', 'Call upon arrival at apartment gate.',                 '2026-02-19 20:45:00', '2026-02-19 19:50:00'),
(5,  'UE-20260219-0005', 14, 18,   'delivered',        2500.00, 350.00, 350.00, 2500.00, '77 Station Road, Dehiwala',               'Dehiwala',   '+94 75 444 7788', 'Contactless delivery requested.',                      '2026-02-19 21:15:00', '2026-02-19 20:15:00'),
(6,  'UE-20260220-0006', 15, NULL, 'delivered',        3300.00, 0.00,   350.00, 3650.00, '15 Nawala Road, Nugegoda',                'Nugegoda',   '+94 72 666 9911', 'Careful with banana leaf wrapping.',                   '2026-02-20 12:50:00', '2026-02-20 11:40:00'),
(7,  'UE-20260220-0007', 16, 5,    'delivered',        2900.00, 435.00, 350.00, 2815.00, '104 High Level Road, Maharagama',         'Maharagama', '+94 77 888 2233', 'Extra cheese packets if possible.',                    '2026-02-20 14:00:00', '2026-02-20 13:00:00'),
(8,  'UE-20260220-0008', 17, NULL, 'delivered',        1950.00, 0.00,   350.00, 2300.00, '34 Stanley Tilakaratne Mawatha',          'Nugegoda',   '+94 78 111 4455', 'Deliver to rear residential unit.',                    '2026-02-20 15:30:00', '2026-02-20 14:20:00'),
(9,  'UE-20260221-0009', 18, 4,    'delivered',        2750.00, 300.00, 350.00, 2800.00, '56 Dutugemunu Street, Kohuwala',          'Kohuwala',   '+94 76 999 5566', 'Leave bag on porch table.',                            '2026-02-21 13:10:00', '2026-02-21 12:05:00'),
(10, 'UE-20260221-0010', 19, 10,   'delivered',        1600.00, 200.00, 350.00, 1750.00, '9 Pagoda Road, Nugegoda',                'Nugegoda',   '+94 71 222 3344', 'Office tea time delivery.',                            '2026-02-21 16:15:00', '2026-02-21 15:10:00'),
(11, 'UE-20260221-0011', 20, NULL, 'delivered',        3100.00, 0.00,   350.00, 3450.00, '62 Cotta Road, Borella',                  'Colombo 08', '+94 75 888 6677', 'Extra fiery devil chili requested.',                   '2026-02-21 19:20:00', '2026-02-21 18:15:00'),
(12, 'UE-20260221-0012', 22, 11,   'delivered',        2600.00, 260.00, 350.00, 2690.00, '18 Horton Place',                        'Colombo 07', '+94 77 444 3322', 'Weekend dinner delivery.',                              '2026-02-21 20:30:00', '2026-02-21 19:20:00'),
(13, 'UE-20260222-0013', 10, 6,    'delivered',        3050.00, 457.50, 350.00, 2942.50, '45/2 Galle Road, Bambalapitiya',         'Colombo 04', '+94 77 123 4567', 'Family lunch treat.',                                    '2026-02-22 13:00:00', '2026-02-22 11:55:00'),
(14, 'UE-20260222-0014', 11, 7,    'delivered',        5100.00, 750.00, 350.00, 4700.00, '12A Flower Road, Cinnamon Gardens',       'Colombo 07', '+94 71 987 6543', 'Large family order - include extra napkins.',           '2026-02-22 14:10:00', '2026-02-22 13:00:00'),
(15, 'UE-20260222-0015', 12, NULL, 'out_for_delivery', 2550.00, 0.00,   350.00, 2900.00, '88 Kandy Road, Kiribathgoda',             'Kelaniya',   '+94 76 555 1234', 'Rider to call when near bridge.',                      '2026-02-22 18:45:00', '2026-02-22 17:40:00'),
(16, 'UE-20260222-0016', 13, 12,   'out_for_delivery', 2500.00, 400.00, 350.00, 2450.00, '23 Havelock Road, Havelock Town',         'Colombo 05', '+94 70 333 8899', 'Please do not honk at gate.',                           '2026-02-22 19:15:00', '2026-02-22 18:10:00'),
(17, 'UE-20260222-0017', 14, NULL, 'ready_for_pickup', 2200.00, 0.00,   350.00, 2550.00, '77 Station Road, Dehiwala',               'Dehiwala',   '+94 75 444 7788', 'Keep faluda cold in separate thermal container.',      '2026-02-22 19:45:00', '2026-02-22 18:50:00'),
(18, 'UE-20260222-0018', 15, NULL, 'preparing',        2750.00, 0.00,   350.00, 3100.00, '15 Nawala Road, Nugegoda',                'Nugegoda',   '+94 72 666 9911', 'Spicy chicken crisp well.',                            '2026-02-22 20:15:00', '2026-02-22 19:15:00'),
(19, 'UE-20260222-0019', 16, 14,   'preparing',        1600.00, 160.00, 350.00, 1790.00, '104 High Level Road, Maharagama',         'Maharagama', '+94 77 888 2233', 'Traditional clay pot fish curry preferred.',           '2026-02-22 20:30:00', '2026-02-22 19:30:00'),
(20, 'UE-20260222-0020', 17, NULL, 'confirmed',        1950.00, 0.00,   350.00, 2300.00, '34 Stanley Tilakaratne Mawatha',          'Nugegoda',   '+94 78 111 4455', 'Tempered dhal with less salt.',                        '2026-02-22 20:50:00', '2026-02-22 20:00:00'),
(21, 'UE-20260222-0021', 18, NULL, 'pending',          1700.00, 0.00,   350.00, 2050.00, '56 Dutugemunu Street, Kohuwala',          'Kohuwala',   '+94 76 999 5566', 'Just placed; notify if egg kottu runs low.',          '2026-02-22 21:15:00', '2026-02-22 20:25:00'),
(22, 'UE-20260222-0022', 19, NULL, 'cancelled',        1900.00, 0.00,   350.00, 2250.00, '9 Pagoda Road, Nugegoda',                'Nugegoda',   '+94 71 222 3344', 'Customer called to cancel due to travel schedule.',    NULL,                  '2026-02-22 20:30:00');

-- 12. order_items Seed (37 Line Items)
INSERT INTO order_items (order_item_id, order_id, dish_id, quantity, unit_price_lkr, subtotal_lkr, special_instructions) VALUES
(1,  1,  1,  2, 1450.00, 2900.00, 'Include extra mint sambol and cooling raita.'),
(2,  2,  2,  2, 1350.00, 2700.00, 'Well-done sunny side fried eggs.'),
(3,  3,  5,  2, 1950.00, 3900.00, 'Extra spicy gravy and saffron aroma.'),
(4,  4,  6,  1, 1100.00, 1100.00, 'Spicy chicken gravy poured over.'),
(5,  4,  22, 2, 550.00,  1100.00, 'Serve chilled with extra ice cream and kasakasa.'),
(6,  5,  3,  2, 1250.00, 2500.00, 'Homemade spicy chili paste on the side.'),
(7,  6,  4,  2, 1650.00, 3300.00, 'Authentic Dutch Burgher parcel.'),
(8,  7,  7,  2, 1450.00, 2900.00, 'Extra melted mozzarella cheese.'),
(9,  8,  11, 2, 750.00,  1500.00, 'Clay pot chicken curry with tempered dhal.'),
(10, 8,  20, 1, 450.00,  450.00,  'Four crunchy tuna cutlets.'),
(11, 9,  1,  1, 1450.00, 1450.00, 'Standard portion chicken biriyani.'),
(12, 9,  15, 1, 1300.00, 1300.00, 'Wok tossed fiery devilled chicken.'),
(13, 10, 19, 2, 400.00,  800.00,  'Two handmade crumbed chicken rolls.'),
(14, 10, 20, 1, 450.00,  450.00,  'Four spiced fish cutlets.'),
(15, 10, 21, 1, 350.00,  350.00,  'Two triangular vegetable rotis.'),
(16, 11, 8,  2, 1550.00, 3100.00, 'Chunky diced roti with prawns, calamari and sailfish.'),
(17, 12, 17, 1, 1400.00, 1400.00, 'Four pieces crispy spiced chicken.'),
(18, 12, 18, 1, 650.00,  650.00,  'Curry leaf powder seasoned fries.'),
(19, 12, 22, 1, 550.00,  550.00,  'Royal rose faluda with grass jelly.'),
(20, 13, 5,  1, 1950.00, 1950.00, 'Slow cooked mutton biriyani.'),
(21, 13, 16, 1, 1100.00, 1100.00, 'Sliced chicken bockwurst sausage platter.'),
(22, 14, 1,  2, 1450.00, 2900.00, 'Chicken biriyani duo.'),
(23, 14, 6,  2, 1100.00, 2200.00, 'Chicken kottu duo.'),
(24, 15, 3,  1, 1250.00, 1250.00, 'Wok-charred seafood rice.'),
(25, 15, 15, 1, 1300.00, 1300.00, 'Spicy capsicum and red onion devilled chicken.'),
(26, 16, 7,  1, 1450.00, 1450.00, 'Creamy mozzarella cheese kottu.'),
(27, 16, 10, 1, 1050.00, 1050.00, 'Traditional crispy roast paan kottu fusion.'),
(28, 17, 4,  1, 1650.00, 1650.00, 'Four-meat curry lamprais with frikkadels.'),
(29, 17, 22, 1, 550.00,  550.00,  'Sweet basil seeds faluda.'),
(30, 18, 2,  1, 1350.00, 1350.00, 'Indonesian seafood rice with chicken satay.'),
(31, 18, 17, 1, 1400.00, 1400.00, 'Golden fried chicken platter.'),
(32, 19, 12, 2, 800.00,  1600.00, 'Tangy sailfish curry with steamed white rice and pol sambol.'),
(33, 20, 13, 2, 650.00,  1300.00, 'Samba rice with two spiced fried eggs and dhal.'),
(34, 20, 18, 1, 650.00,  650.00,  'Masala fries with roasted cumin.'),
(35, 21, 9,  2, 850.00,  1700.00, 'Griddle tossed godamba roti with farm eggs and curry gravy.'),
(36, 22, 14, 2, 600.00,  1200.00, 'Vegetarian nelum ala curry with cashew nuts.'),
(37, 22, 21, 2, 350.00,  700.00,  'Spiced potato and leek stuffed vegetable rotis.');

-- 13. payments Seed (22 Settlements)
INSERT INTO payments (payment_id, order_id, payment_method, payment_status, amount_lkr, transaction_reference, payment_details, paid_at, created_at) VALUES
(1,  1,  'card',             'completed', 2960.00, 'TXN-CARD-20260218-7812', 'Visa ending in 4112, AuthCode: 981245', '2026-02-18 11:32:00', '2026-02-18 11:30:00'),
(2,  2,  'online',           'completed', 3050.00, 'TXN-IPG-20260218-5509',  'MasterCard ending in 8804, Gateway: PayHere', '2026-02-18 13:12:00', '2026-02-18 13:10:00'),
(3,  3,  'card',             'completed', 3750.00, 'TXN-CARD-20260219-3491', 'Visa ending in 1923, AuthCode: 334912', '2026-02-19 18:27:00', '2026-02-19 18:25:00'),
(4,  4,  'cash_on_delivery', 'completed', 2550.00, 'COD-UE-20260219-0004',   'Cash on Delivery. Collected by Rider Malith.', '2026-02-19 20:45:00', '2026-02-19 19:50:00'),
(5,  5,  'online',           'completed', 2500.00, 'TXN-IPG-20260219-8812',  'Commercial Bank Direct Pay, Ref: CB-881203', '2026-02-19 20:17:00', '2026-02-19 20:15:00'),
(6,  6,  'card',             'completed', 3650.00, 'TXN-CARD-20260220-1104', 'Visa ending in 6621, AuthCode: 771290', '2026-02-20 11:42:00', '2026-02-20 11:40:00'),
(7,  7,  'online',           'completed', 2815.00, 'TXN-IPG-20260220-4491',  'MasterCard ending in 3144, Gateway: WebXPay', '2026-02-20 13:02:00', '2026-02-20 13:00:00'),
(8,  8,  'cash_on_delivery', 'completed', 2300.00, 'COD-UE-20260220-0008',   'Cash on Delivery settled with courier receipt #441.', '2026-02-20 15:30:00', '2026-02-20 14:20:00'),
(9,  9,  'card',             'completed', 2800.00, 'TXN-CARD-20260221-9920', 'Visa ending in 9012, AuthCode: 441029', '2026-02-21 12:07:00', '2026-02-21 12:05:00'),
(10, 10, 'online',           'completed', 1750.00, 'TXN-IPG-20260221-3310',  'FriMi Digital Wallet Payment, Ref: FRM-3310', '2026-02-21 15:12:00', '2026-02-21 15:10:00'),
(11, 11, 'card',             'completed', 3450.00, 'TXN-CARD-20260221-5582', 'MasterCard ending in 7741, AuthCode: 991823', '2026-02-21 18:17:00', '2026-02-21 18:15:00'),
(12, 12, 'online',           'completed', 2690.00, 'TXN-IPG-20260221-7714',  'Genie Digital Wallet, Ref: GNE-771420', '2026-02-21 19:22:00', '2026-02-21 19:20:00'),
(13, 13, 'card',             'completed', 2942.50, 'TXN-CARD-20260222-2281', 'Visa ending in 4112, AuthCode: 665120', '2026-02-22 11:57:00', '2026-02-22 11:55:00'),
(14, 14, 'online',           'completed', 4700.00, 'TXN-IPG-20260222-6631',  'MasterCard ending in 8804, Gateway: PayHere', '2026-02-22 13:02:00', '2026-02-22 13:00:00'),
(15, 15, 'card',             'completed', 2900.00, 'TXN-CARD-20260222-8819', 'Visa ending in 1923, AuthCode: 554109', '2026-02-22 17:42:00', '2026-02-22 17:40:00'),
(16, 16, 'online',           'completed', 2450.00, 'TXN-IPG-20260222-9901',  'Commercial Bank Direct Pay, Ref: CB-990145', '2026-02-22 18:12:00', '2026-02-22 18:10:00'),
(17, 17, 'card',             'completed', 2550.00, 'TXN-CARD-20260222-4412', 'Visa ending in 8831, AuthCode: 221094', '2026-02-22 18:52:00', '2026-02-22 18:50:00'),
(18, 18, 'card',             'completed', 3100.00, 'TXN-CARD-20260222-7711', 'MasterCard ending in 4410, AuthCode: 881023', '2026-02-22 19:17:00', '2026-02-22 19:15:00'),
(19, 19, 'online',           'completed', 1790.00, 'TXN-IPG-20260222-1120',  'FriMi Digital Wallet Payment, Ref: FRM-1120', '2026-02-22 19:32:00', '2026-02-22 19:30:00'),
(20, 20, 'cash_on_delivery', 'pending',   2300.00, 'COD-UE-20260222-0020',   'Cash on Delivery. Pending courier dispatch and payment collection.', NULL, '2026-02-22 20:00:00'),
(21, 21, 'cash_on_delivery', 'pending',   2050.00, 'COD-UE-20260222-0021',   'Cash on Delivery. Order awaiting kitchen approval.', NULL, '2026-02-22 20:25:00'),
(22, 22, 'card',             'refunded',  2250.00, 'TXN-CARD-20260222-9014', 'Visa ending in 5541. Full refund processed upon order cancellation.', '2026-02-22 20:31:00', '2026-02-22 20:30:00');

-- 14. order_status_history Seed (62 State Transitions)
INSERT INTO order_status_history (history_id, order_id, changed_by_user_id, previous_status, new_status, comment, created_at) VALUES
(1,  1,  10, NULL,               'pending',          'Customer placed order online.',                              '2026-02-18 11:30:00'),
(2,  1,  4,  'pending',          'confirmed',        'Head Chef Malith confirmed order in kitchen queue.',         '2026-02-18 11:35:00'),
(3,  1,  5,  'confirmed',        'preparing',        'Sous Chef Tharindu commenced Dum Biriyani preparation.',     '2026-02-18 11:45:00'),
(4,  1,  4,  'preparing',        'ready_for_pickup', 'Packaging complete, sealed in heat-insulated bag.',          '2026-02-18 12:10:00'),
(5,  1,  1,  'ready_for_pickup', 'out_for_delivery', 'Handed to dispatch courier rider.',                         '2026-02-18 12:20:00'),
(6,  1,  1,  'out_for_delivery', 'delivered',        'Courier confirmed safe delivery at customer destination.',   '2026-02-18 12:45:00'),
(7,  2,  11, NULL,               'pending',          'Customer submitted cart checkout.',                          '2026-02-18 13:10:00'),
(8,  2,  4,  'pending',          'confirmed',        'Kitchen accepted order.',                                    '2026-02-18 13:15:00'),
(9,  2,  5,  'confirmed',        'preparing',        'High-flame wok cooking initiated.',                         '2026-02-18 13:20:00'),
(10, 2,  4,  'preparing',        'ready_for_pickup', 'Ready at counter.',                                          '2026-02-18 13:45:00'),
(11, 2,  1,  'ready_for_pickup', 'out_for_delivery', 'Dispatched with express delivery runner.',                  '2026-02-18 13:50:00'),
(12, 2,  1,  'out_for_delivery', 'delivered',        'Customer received order at reception.',                      '2026-02-18 14:15:00'),
(13, 3,  12, NULL,               'pending',          'Customer checked out with LKR500OFF coupon.',                '2026-02-19 18:25:00'),
(14, 3,  4,  'pending',          'confirmed',        'Payment verified. Kitchen station 2 assigned.',              '2026-02-19 18:30:00'),
(15, 3,  5,  'confirmed',        'preparing',        'Mutton Dum Biriyani assembled with extra spices.',           '2026-02-19 18:40:00'),
(16, 3,  4,  'preparing',        'ready_for_pickup', 'Packed in insulated container.',                             '2026-02-19 19:05:00'),
(17, 3,  1,  'ready_for_pickup', 'out_for_delivery', 'Courier dispatched on Kandy road route.',                   '2026-02-19 19:10:00'),
(18, 3,  1,  'out_for_delivery', 'delivered',        'Delivered to customer gate.',                                '2026-02-19 19:30:00'),
(19, 4,  13, NULL,               'pending',          'Order submitted via website.',                               '2026-02-19 19:50:00'),
(20, 4,  4,  'pending',          'confirmed',        'Order acknowledged by chef on duty.',                        '2026-02-19 19:55:00'),
(21, 4,  6,  'confirmed',        'preparing',        'Kottu chopped on hot griddle and faluda blended.',           '2026-02-19 20:05:00'),
(22, 4,  4,  'preparing',        'out_for_delivery', 'Direct courier dispatch.',                                  '2026-02-19 20:25:00'),
(23, 4,  1,  'out_for_delivery', 'delivered',        'Cash on delivery settled and delivered.',                    '2026-02-19 20:45:00'),
(24, 5,  14, NULL,               'pending',          'Order placed with FREEDELIVERY code.',                       '2026-02-19 20:15:00'),
(25, 5,  4,  'pending',          'confirmed',        'Order confirmed.',                                           '2026-02-19 20:20:00'),
(26, 5,  5,  'confirmed',        'preparing',        'Fried rice wok tossed.',                                     '2026-02-19 20:30:00'),
(27, 5,  1,  'preparing',        'out_for_delivery', 'Courier en route to Dehiwala.',                              '2026-02-19 20:55:00'),
(28, 5,  1,  'out_for_delivery', 'delivered',        'Package handed to customer.',                                '2026-02-19 21:15:00'),
(29, 6,  15, NULL,               'pending',          'Online order placed.',                                       '2026-02-20 11:40:00'),
(30, 6,  4,  'pending',          'confirmed',        'Kitchen approved.',                                          '2026-02-20 11:45:00'),
(31, 6,  5,  'confirmed',        'preparing',        'Lamprais heated and packed in banana leaves.',               '2026-02-20 11:55:00'),
(32, 6,  1,  'preparing',        'out_for_delivery', 'Courier dispatched to Nugegoda.',                            '2026-02-20 12:20:00'),
(33, 6,  1,  'out_for_delivery', 'delivered',        'Delivered successfully.',                                    '2026-02-20 12:50:00'),
(34, 7,  16, NULL,               'pending',          'Order placed with KOTTUNIGHT.',                              '2026-02-20 13:00:00'),
(35, 7,  4,  'pending',          'confirmed',        'Confirmed in queue.',                                        '2026-02-20 13:05:00'),
(36, 7,  6,  'confirmed',        'preparing',        'Cheese kottu cooking.',                                      '2026-02-20 13:15:00'),
(37, 7,  1,  'preparing',        'out_for_delivery', 'Out for delivery.',                                          '2026-02-20 13:35:00'),
(38, 7,  1,  'out_for_delivery', 'delivered',        'Completed delivery.',                                        '2026-02-20 14:00:00'),
(39, 15, 12, NULL,               'pending',          'Dinner order submitted.',                                    '2026-02-22 17:40:00'),
(40, 15, 4,  'pending',          'confirmed',        'Kitchen confirmed order.',                                   '2026-02-22 17:45:00'),
(41, 15, 5,  'confirmed',        'preparing',        'Preparing rice and spicy devilled chicken.',                 '2026-02-22 17:55:00'),
(42, 15, 4,  'preparing',        'ready_for_pickup', 'Packed in thermal bag.',                                     '2026-02-22 18:20:00'),
(43, 15, 1,  'ready_for_pickup', 'out_for_delivery', 'Courier on way to Kiribathgoda.',                           '2026-02-22 18:25:00'),
(44, 16, 13, NULL,               'pending',          'Checked out with FIRSTORDER.',                               '2026-02-22 18:10:00'),
(45, 16, 4,  'pending',          'confirmed',        'Kitchen accepted.',                                          '2026-02-22 18:15:00'),
(46, 16, 6,  'confirmed',        'preparing',        'Cooking cheese kottu and roast paan kottu.',                 '2026-02-22 18:25:00'),
(47, 16, 1,  'preparing',        'out_for_delivery', 'Courier en route to Havelock Town.',                         '2026-02-22 18:50:00'),
(48, 17, 14, NULL,               'pending',          'Order placed.',                                              '2026-02-22 18:50:00'),
(49, 17, 4,  'pending',          'confirmed',        'Kitchen accepted order.',                                    '2026-02-22 18:55:00'),
(50, 17, 5,  'confirmed',        'preparing',        'Lamprais warming and faluda chilling.',                      '2026-02-22 19:05:00'),
(51, 17, 4,  'preparing',        'ready_for_pickup', 'Packed and awaiting courier pickup at kitchen counter.',     '2026-02-22 19:25:00'),
(52, 18, 15, NULL,               'pending',          'Order placed.',                                              '2026-02-22 19:15:00'),
(53, 18, 4,  'pending',          'confirmed',        'Kitchen accepted.',                                          '2026-02-22 19:20:00'),
(54, 18, 5,  'confirmed',        'preparing',        'Nasi goreng wok charring and chicken deep frying.',          '2026-02-22 19:30:00'),
(55, 19, 16, NULL,               'pending',          'Checked out with LUNCHSPECIAL.',                             '2026-02-22 19:30:00'),
(56, 19, 4,  'pending',          'confirmed',        'Kitchen confirmed order.',                                   '2026-02-22 19:35:00'),
(57, 19, 5,  'confirmed',        'preparing',        'Thalapath fish curry clay pot simmering.',                   '2026-02-22 19:45:00'),
(58, 20, 17, NULL,               'pending',          'Cash on delivery order placed.',                             '2026-02-22 20:00:00'),
(59, 20, 4,  'pending',          'confirmed',        'Order acknowledged by chef on duty.',                        '2026-02-22 20:05:00'),
(60, 21, 18, NULL,               'pending',          'Customer submitted cart checkout; awaiting kitchen queue.',  '2026-02-22 20:25:00'),
(61, 22, 19, NULL,               'pending',          'Customer submitted order.',                                  '2026-02-22 20:30:00'),
(62, 22, 1,  'pending',          'cancelled',        'Order cancelled by customer request; card payment refunded.','2026-02-22 20:35:00');
