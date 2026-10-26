-- =============================================================================
-- UrbanEats Cloud Kitchen Platform - Component C: Orders, Checkout & Payments
-- DBMS: MySQL 8.x / MariaDB 10.x
-- Database: urbaneats_db
-- Architecture: 3NF (Third Normal Form) Relational Schema
-- =============================================================================

USE urbaneats_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS order_status_history;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS coupons;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- 1. coupons Table (3NF)
-- Stores promotional discount vouchers applicable to customer orders.
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
-- 2. orders Table (3NF)
-- Master order records representing checkout transactions and delivery metadata.
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
-- 3. order_items Table (3NF)
-- Normalized line items belonging to orders.
-- unit_price_lkr captures the exact unit price snapshot at time of purchase.
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
-- 4. payments Table (3NF)
-- Financial transaction records linked 1-to-many or 1-to-1 with orders.
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
-- 5. order_status_history Table (3NF)
-- State-machine audit trail tracking all lifecycle transitions for orders.
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
-- SEED DATA INSERTION FOR COMPONENT C TESTING & VERIFICATION
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. coupons Seed (Promotional discount codes)
-- -----------------------------------------------------------------------------
INSERT INTO coupons (coupon_id, code, discount_type, discount_value, min_order_amount, max_discount_amount, usage_limit, used_count, valid_from, valid_until, is_active) VALUES
(1, 'WELCOME10', 'percentage', 10.00, 1500.00, 500.00, 100, 2, '2026-01-01 00:00:00', '2026-12-31 23:59:59', 1),
(2, 'LKR500OFF', 'fixed',      500.00, 3000.00, NULL,   50,  1, '2026-01-01 00:00:00', '2026-12-31 23:59:59', 1),
(3, 'FEAST20',   'percentage', 20.00, 4000.00, 1000.00, 30,  0, '2026-02-01 00:00:00', '2026-12-31 23:59:59', 1),
(4, 'EXPIRED50', 'percentage', 50.00, 2000.00, 1500.00, 10,  10,'2025-01-01 00:00:00', '2025-12-31 23:59:59', 0);

-- -----------------------------------------------------------------------------
-- 2. orders Seed (Customer Orders in various lifecycle states)
-- -----------------------------------------------------------------------------
INSERT INTO orders (order_id, order_number, user_id, coupon_id, order_status, subtotal_lkr, discount_lkr, delivery_fee_lkr, total_amount_lkr, delivery_address, delivery_city, contact_phone, delivery_notes, estimated_delivery_time, created_at) VALUES
-- Order 1: Delivered to Customer Nimesh Bandara (#10)
(1, 'UE-20260220-0001', 10, 1, 'delivered', 2900.00, 290.00, 350.00, 2960.00, '45/2 Galle Road, Bambalapitiya', 'Colombo 04', '+94 77 123 4567', 'Ring front bell, leave with security if unavailable.', '2026-02-20 12:45:00', '2026-02-20 11:30:00'),

-- Order 2: Out for delivery to Customer Chamari Atapattu (#11)
(2, 'UE-20260221-0002', 11, NULL, 'out_for_delivery', 2700.00, 0.00, 350.00, 3050.00, '12A Flower Road, Cinnamon Gardens', 'Colombo 07', '+94 71 987 6543', 'Deliver to 3rd floor reception.', '2026-02-21 14:15:00', '2026-02-21 13:10:00'),

-- Order 3: Preparing in kitchen for Customer Sajith Gunawardena (#12)
(3, 'UE-20260222-0003', 12, 2, 'preparing', 3900.00, 500.00, 350.00, 3750.00, '88 Kandy Road, Kiribathgoda', 'Kelaniya', '+94 76 555 1234', 'Make biriyani extra spicy please.', '2026-02-22 19:30:00', '2026-02-22 18:25:00'),

-- Order 4: Confirmed & pending prep for Customer Sanduni De Silva (#13)
(4, 'UE-20260222-0004', 13, NULL, 'confirmed', 1950.00, 0.00, 350.00, 2300.00, '23 Havelock Road, Havelock Town', 'Colombo 05', '+94 70 333 8899', 'Call upon arrival.', '2026-02-22 20:45:00', '2026-02-22 19:50:00'),

-- Order 5: Pending approval for Customer Lakshitha Alwis (#14)
(5, 'UE-20260222-0005', 14, NULL, 'pending', 1450.00, 0.00, 350.00, 1800.00, '77 Station Road, Dehiwala', 'Dehiwala', '+94 75 444 7788', NULL, NULL, '2026-02-22 20:15:00'),

-- Order 6: Cancelled order for Customer Priyantha Cooray (#15)
(6, 'UE-20260222-0006', 15, NULL, 'cancelled', 2200.00, 0.00, 350.00, 2550.00, '15 Nawala Road, Nugegoda', 'Nugegoda', '+94 72 666 9911', 'Customer cancelled due to meeting delay.', NULL, '2026-02-22 20:30:00');

-- -----------------------------------------------------------------------------
-- 3. order_items Seed (Line items with snapshot unit_price_lkr)
-- -----------------------------------------------------------------------------
INSERT INTO order_items (order_item_id, order_id, dish_id, quantity, unit_price_lkr, subtotal_lkr, special_instructions) VALUES
-- Order 1: 2x Royal Chicken Dum Biriyani (1450.00 each)
(1, 1, 1, 2, 1450.00, 2900.00, 'Include extra mint sambol and raita.'),

-- Order 2: 2x Indonesian Nasi Goreng (1350.00 each)
(2, 2, 2, 2, 1350.00, 2700.00, 'Well-done fried egg on top.'),

-- Order 3: 2x Special Mutton Dum Biriyani (1950.00 each)
(3, 3, 5, 2, 1950.00, 3900.00, 'Extra spicy gravy on the side.'),

-- Order 4: 1x Special Mutton Dum Biriyani (1950.00)
(4, 4, 5, 1, 1950.00, 1950.00, NULL),

-- Order 5: 1x Royal Chicken Dum Biriyani (1450.00)
(5, 5, 1, 1, 1450.00, 1450.00, 'Standard portion.'),

-- Order 6: 2x Classic Chicken Kottu (1100.00 each)
(6, 6, 6, 2, 1100.00, 2200.00, 'Spicy chicken gravy poured over.');

-- -----------------------------------------------------------------------------
-- 4. payments Seed (Settlements linked to orders)
-- -----------------------------------------------------------------------------
INSERT INTO payments (payment_id, order_id, payment_method, payment_status, amount_lkr, transaction_reference, payment_details, paid_at, created_at) VALUES
(1, 1, 'card',             'completed', 2960.00, 'TXN-CARD-20260220-7812', 'Visa ending in 4112, AuthCode: 981245', '2026-02-20 11:32:00', '2026-02-20 11:30:00'),
(2, 2, 'online',           'completed', 3050.00, 'TXN-IPG-20260221-5509',  'MasterCard ending in 8804, Gateway: PayHere', '2026-02-21 13:12:00', '2026-02-21 13:10:00'),
(3, 3, 'card',             'completed', 3750.00, 'TXN-CARD-20260222-3491', 'Visa ending in 1923, AuthCode: 334912', '2026-02-22 18:27:00', '2026-02-22 18:25:00'),
(4, 4, 'cash_on_delivery', 'pending',   2300.00, 'COD-UE-20260222-0004',   'Cash on Delivery. Rider to collect exact cash LKR 2,300.00', NULL, '2026-02-22 19:50:00'),
(5, 5, 'cash_on_delivery', 'pending',   1800.00, 'COD-UE-20260222-0005',   'Cash on Delivery payment awaiting dispatch.', NULL, '2026-02-22 20:15:00'),
(6, 6, 'card',             'refunded',  2550.00, 'TXN-CARD-20260222-9014', 'Visa ending in 5541. Full refund processed upon order cancellation.', '2026-02-22 20:31:00', '2026-02-22 20:30:00');

-- -----------------------------------------------------------------------------
-- 5. order_status_history Seed (State transitions for orders)
-- -----------------------------------------------------------------------------
INSERT INTO order_status_history (history_id, order_id, changed_by_user_id, previous_status, new_status, comment, created_at) VALUES
-- Order 1 progression (Pending -> Confirmed -> Preparing -> Out for Delivery -> Delivered)
(1, 1, 10, NULL,               'pending',          'Customer placed order online.',                              '2026-02-20 11:30:00'),
(2, 1, 4,  'pending',          'confirmed',        'Head Chef Malith confirmed order in kitchen queue.',         '2026-02-20 11:35:00'),
(3, 1, 5,  'confirmed',        'preparing',        'Sous Chef Tharindu commenced Dum Biriyani preparation.',     '2026-02-20 11:45:00'),
(4, 1, 4,  'preparing',        'ready_for_pickup', 'Packaging complete, sealed in heat-insulated bag.',          '2026-02-20 12:10:00'),
(5, 1, 1,  'ready_for_pickup', 'out_for_delivery', 'Handed to dispatch courier rider.',                         '2026-02-20 12:20:00'),
(6, 1, 1,  'out_for_delivery', 'delivered',        'Courier confirmed safe delivery at customer destination.',   '2026-02-20 12:45:00'),

-- Order 2 progression (Pending -> Confirmed -> Preparing -> Out for delivery)
(7, 2, 11, NULL,               'pending',          'Customer submitted cart checkout.',                          '2026-02-21 13:10:00'),
(8, 2, 4,  'pending',          'confirmed',        'Kitchen accepted order.',                                    '2026-02-21 13:15:00'),
(9, 2, 5,  'confirmed',        'preparing',        'High-flame wok cooking initiated.',                         '2026-02-21 13:20:00'),
(10,2, 4,  'preparing',        'out_for_delivery', 'Dispatched with express delivery runner.',                  '2026-02-21 13:50:00'),

-- Order 3 progression (Pending -> Confirmed -> Preparing)
(11, 3, 12, NULL,              'pending',          'Customer checked out with LKR500OFF coupon.',                '2026-02-22 18:25:00'),
(12, 3, 4,  'pending',         'confirmed',        'Payment verified. Kitchen station 2 assigned.',              '2026-02-22 18:30:00'),
(13, 3, 5,  'confirmed',       'preparing',        'Mutton Dum Biriyani assembled with extra spices.',           '2026-02-22 18:40:00'),

-- Order 4 progression (Pending -> Confirmed)
(14, 4, 13, NULL,              'pending',          'Order submitted via website.',                               '2026-02-22 19:50:00'),
(15, 4, 4,  'pending',         'confirmed',        'Order acknowledged by chef on duty.',                        '2026-02-22 19:55:00'),

-- Order 5 progression (Pending)
(16, 5, 14, NULL,              'pending',          'New order awaiting kitchen acknowledgement.',                '2026-02-22 20:15:00'),

-- Order 6 progression (Pending -> Cancelled)
(17, 6, 15, NULL,              'pending',          'Customer submitted order.',                                  '2026-02-22 20:30:00'),
(18, 6, 1,  'pending',         'cancelled',        'Order cancelled by customer request; card payment refunded.','2026-02-22 20:35:00');
