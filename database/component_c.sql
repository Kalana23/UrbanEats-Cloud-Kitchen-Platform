-- =============================================================================
-- UrbanEats Cloud Kitchen Platform - Component C: Orders, Checkout & Payments
-- DBMS: MySQL 8.x / MariaDB 10.x
-- Database: urbaneats_db
-- Architecture: 3NF (Third Normal Form) Relational Schema & Seed Datasets
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
-- SEED DATA INSERTION FOR COMPONENT C (AT LEAST 20 REALISTIC ROWS EACH)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. coupons Seed (22 Promotional Vouchers)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 2. orders Seed (22 Customer Orders)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 3. order_items Seed (37 Line Items across 22 Orders)
-- -----------------------------------------------------------------------------
INSERT INTO order_items (order_item_id, order_id, dish_id, quantity, unit_price_lkr, subtotal_lkr, special_instructions) VALUES
-- Order 1: 2x Royal Chicken Dum Biriyani (1450.00)
(1,  1,  1,  2, 1450.00, 2900.00, 'Include extra mint sambol and cooling raita.'),

-- Order 2: 2x Indonesian Nasi Goreng (1350.00)
(2,  2,  2,  2, 1350.00, 2700.00, 'Well-done sunny side fried eggs.'),

-- Order 3: 2x Special Mutton Dum Biriyani (1950.00)
(3,  3,  5,  2, 1950.00, 3900.00, 'Extra spicy gravy and saffron aroma.'),

-- Order 4: 1x Classic Chicken Kottu (1100.00) + 2x Royal Rose Faluda (550.00)
(4,  4,  6,  1, 1100.00, 1100.00, 'Spicy chicken gravy poured over.'),
(5,  4,  22, 2, 550.00,  1100.00, 'Serve chilled with extra ice cream and kasakasa.'),

-- Order 5: 2x Signature Mixed Fried Rice (1250.00)
(6,  5,  3,  2, 1250.00, 2500.00, 'Homemade spicy chili paste on the side.'),

-- Order 7: 2x Gourmet Chicken Cheese Kottu (1450.00)
(7,  6,  4,  2, 1650.00, 3300.00, 'Authentic Dutch Burgher parcel.'),
(8,  7,  7,  2, 1450.00, 2900.00, 'Extra melted mozzarella cheese.'),

-- Order 8: 2x Ceylon Village Chicken Rice & Curry (750.00) + 1x Spiced Tuna Fish Cutlets (450.00)
(9,  8,  11, 2, 750.00,  1500.00, 'Clay pot chicken curry with tempered dhal.'),
(10, 8,  20, 1, 450.00,  450.00,  'Four crunchy tuna cutlets.'),

-- Order 9: 1x Royal Chicken Dum Biriyani (1450.00) + 1x Spicy Hot Chicken Devilled (1300.00)
(11, 9,  1,  1, 1450.00, 1450.00, 'Standard portion chicken biriyani.'),
(12, 9,  15, 1, 1300.00, 1300.00, 'Wok tossed fiery devilled chicken.'),

-- Order 10: 2x Crumbed Chicken Rolls (400.00) + 1x Fish Cutlets (450.00) + 1x Vegetable Roti (350.00)
(13, 10, 19, 2, 400.00,  800.00,  'Two handmade crumbed chicken rolls.'),
(14, 10, 20, 1, 450.00,  450.00,  'Four spiced fish cutlets.'),
(15, 10, 21, 1, 350.00,  350.00,  'Two triangular vegetable rotis.'),

-- Order 11: 2x Spicy Seafood Dolphin Kottu (1550.00)
(16, 11, 8,  2, 1550.00, 3100.00, 'Chunky diced roti with prawns, calamari and sailfish.'),

-- Order 12: 1x Golden Spice Fried Chicken (1400.00) + 1x French Fries (650.00) + 1x Faluda (550.00)
(17, 12, 17, 1, 1400.00, 1400.00, 'Four pieces crispy spiced chicken.'),
(18, 12, 18, 1, 650.00,  650.00,  'Curry leaf powder seasoned fries.'),
(19, 12, 22, 1, 550.00,  550.00,  'Royal rose faluda with grass jelly.'),

-- Order 13: 1x Special Mutton Biriyani (1950.00) + 1x Sausage Devilled (1100.00)
(20, 13, 5,  1, 1950.00, 1950.00, 'Slow cooked mutton biriyani.'),
(21, 13, 16, 1, 1100.00, 1100.00, 'Sliced chicken bockwurst sausage platter.'),

-- Order 14: 2x Royal Chicken Biriyani (1450.00) + 2x Classic Chicken Kottu (1100.00)
(22, 14, 1,  2, 1450.00, 2900.00, 'Chicken biriyani duo.'),
(23, 14, 6,  2, 1100.00, 2200.00, 'Chicken kottu duo.'),

-- Order 15: 1x Mixed Fried Rice (1250.00) + 1x Chicken Devilled (1300.00)
(24, 15, 3,  1, 1250.00, 1250.00, 'Wok-charred seafood rice.'),
(25, 15, 15, 1, 1300.00, 1300.00, 'Spicy capsicum and red onion devilled chicken.'),

-- Order 16: 1x Cheese Kottu (1450.00) + 1x Roast Paan Kottu (1050.00)
(26, 16, 7,  1, 1450.00, 1450.00, 'Creamy mozzarella cheese kottu.'),
(27, 16, 10, 1, 1050.00, 1050.00, 'Traditional crispy roast paan kottu fusion.'),

-- Order 17: 1x Authentic Lamprais (1650.00) + 1x Faluda (550.00)
(28, 17, 4,  1, 1650.00, 1650.00, 'Four-meat curry lamprais with frikkadels.'),
(29, 17, 22, 1, 550.00,  550.00,  'Sweet basil seeds faluda.'),

-- Order 18: 1x Nasi Goreng (1350.00) + 1x Fried Chicken (1400.00)
(30, 18, 2,  1, 1350.00, 1350.00, 'Indonesian seafood rice with chicken satay.'),
(31, 18, 17, 1, 1400.00, 1400.00, 'Golden fried chicken platter.'),

-- Order 19: 2x Southern Thalapath Fish Curry (800.00)
(32, 19, 12, 2, 800.00,  1600.00, 'Tangy sailfish curry with steamed white rice and pol sambol.'),

-- Order 20: 2x Pol Sambol & Egg Rice (650.00) + 1x French Fries (650.00)
(33, 20, 13, 2, 650.00,  1300.00, 'Samba rice with two spiced fried eggs and dhal.'),
(34, 20, 18, 1, 650.00,  650.00,  'Masala fries with roasted cumin.'),

-- Order 21: 2x Traditional Egg Kottu (850.00)
(35, 21, 9,  2, 850.00,  1700.00, 'Griddle tossed godamba roti with farm eggs and curry gravy.'),

-- Order 22: 2x Lotus Root Rice & Curry (600.00) + 2x Vegetable Roti (350.00)
(36, 22, 14, 2, 600.00,  1200.00, 'Vegetarian nelum ala curry with cashew nuts.'),
(37, 22, 21, 2, 350.00,  700.00,  'Spiced potato and leek stuffed vegetable rotis.');

-- -----------------------------------------------------------------------------
-- 4. payments Seed (22 Settlements matching Orders)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 5. order_status_history Seed (52 State Transitions for 22 Orders)
-- -----------------------------------------------------------------------------
INSERT INTO order_status_history (history_id, order_id, changed_by_user_id, previous_status, new_status, comment, created_at) VALUES
-- Order 1 (Delivered)
(1,  1,  10, NULL,               'pending',          'Customer placed order online.',                              '2026-02-18 11:30:00'),
(2,  1,  4,  'pending',          'confirmed',        'Head Chef Malith confirmed order in kitchen queue.',         '2026-02-18 11:35:00'),
(3,  1,  5,  'confirmed',        'preparing',        'Sous Chef Tharindu commenced Dum Biriyani preparation.',     '2026-02-18 11:45:00'),
(4,  1,  4,  'preparing',        'ready_for_pickup', 'Packaging complete, sealed in heat-insulated bag.',          '2026-02-18 12:10:00'),
(5,  1,  1,  'ready_for_pickup', 'out_for_delivery', 'Handed to dispatch courier rider.',                         '2026-02-18 12:20:00'),
(6,  1,  1,  'out_for_delivery', 'delivered',        'Courier confirmed safe delivery at customer destination.',   '2026-02-18 12:45:00'),

-- Order 2 (Delivered)
(7,  2,  11, NULL,               'pending',          'Customer submitted cart checkout.',                          '2026-02-18 13:10:00'),
(8,  2,  4,  'pending',          'confirmed',        'Kitchen accepted order.',                                    '2026-02-18 13:15:00'),
(9,  2,  5,  'confirmed',        'preparing',        'High-flame wok cooking initiated.',                         '2026-02-18 13:20:00'),
(10, 2,  4,  'preparing',        'ready_for_pickup', 'Ready at counter.',                                          '2026-02-18 13:45:00'),
(11, 2,  1,  'ready_for_pickup', 'out_for_delivery', 'Dispatched with express delivery runner.',                  '2026-02-18 13:50:00'),
(12, 2,  1,  'out_for_delivery', 'delivered',        'Customer received order at reception.',                      '2026-02-18 14:15:00'),

-- Order 3 (Delivered)
(13, 3,  12, NULL,               'pending',          'Customer checked out with LKR500OFF coupon.',                '2026-02-19 18:25:00'),
(14, 3,  4,  'pending',          'confirmed',        'Payment verified. Kitchen station 2 assigned.',              '2026-02-19 18:30:00'),
(15, 3,  5,  'confirmed',        'preparing',        'Mutton Dum Biriyani assembled with extra spices.',           '2026-02-19 18:40:00'),
(16, 3,  4,  'preparing',        'ready_for_pickup', 'Packed in insulated container.',                             '2026-02-19 19:05:00'),
(17, 3,  1,  'ready_for_pickup', 'out_for_delivery', 'Courier dispatched on Kandy road route.',                   '2026-02-19 19:10:00'),
(18, 3,  1,  'out_for_delivery', 'delivered',        'Delivered to customer gate.',                                '2026-02-19 19:30:00'),

-- Order 4 (Delivered)
(19, 4,  13, NULL,               'pending',          'Order submitted via website.',                               '2026-02-19 19:50:00'),
(20, 4,  4,  'pending',          'confirmed',        'Order acknowledged by chef on duty.',                        '2026-02-19 19:55:00'),
(21, 4,  6,  'confirmed',        'preparing',        'Kottu chopped on hot griddle and faluda blended.',           '2026-02-19 20:05:00'),
(22, 4,  4,  'preparing',        'out_for_delivery', 'Direct courier dispatch.',                                  '2026-02-19 20:25:00'),
(23, 4,  1,  'out_for_delivery', 'delivered',        'Cash on delivery settled and delivered.',                    '2026-02-19 20:45:00'),

-- Order 5 (Delivered)
(24, 5,  14, NULL,               'pending',          'Order placed with FREEDELIVERY code.',                       '2026-02-19 20:15:00'),
(25, 5,  4,  'pending',          'confirmed',        'Order confirmed.',                                           '2026-02-19 20:20:00'),
(26, 5,  5,  'confirmed',        'preparing',        'Fried rice wok tossed.',                                     '2026-02-19 20:30:00'),
(27, 5,  1,  'preparing',        'out_for_delivery', 'Courier en route to Dehiwala.',                              '2026-02-19 20:55:00'),
(28, 5,  1,  'out_for_delivery', 'delivered',        'Package handed to customer.',                                '2026-02-19 21:15:00'),

-- Order 6 (Delivered)
(29, 6,  15, NULL,               'pending',          'Online order placed.',                                       '2026-02-20 11:40:00'),
(30, 6,  4,  'pending',          'confirmed',        'Kitchen approved.',                                          '2026-02-20 11:45:00'),
(31, 6,  5,  'confirmed',        'preparing',        'Lamprais heated and packed in banana leaves.',               '2026-02-20 11:55:00'),
(32, 6,  1,  'preparing',        'out_for_delivery', 'Courier dispatched to Nugegoda.',                            '2026-02-20 12:20:00'),
(33, 6,  1,  'out_for_delivery', 'delivered',        'Delivered successfully.',                                    '2026-02-20 12:50:00'),

-- Order 7 (Delivered)
(34, 7,  16, NULL,               'pending',          'Order placed with KOTTUNIGHT.',                              '2026-02-20 13:00:00'),
(35, 7,  4,  'pending',          'confirmed',        'Confirmed in queue.',                                        '2026-02-20 13:05:00'),
(36, 7,  6,  'confirmed',        'preparing',        'Cheese kottu cooking.',                                      '2026-02-20 13:15:00'),
(37, 7,  1,  'preparing',        'out_for_delivery', 'Out for delivery.',                                          '2026-02-20 13:35:00'),
(38, 7,  1,  'out_for_delivery', 'delivered',        'Completed delivery.',                                        '2026-02-20 14:00:00'),

-- Order 15 (Out for delivery)
(39, 15, 12, NULL,               'pending',          'Dinner order submitted.',                                    '2026-02-22 17:40:00'),
(40, 15, 4,  'pending',          'confirmed',        'Kitchen confirmed order.',                                   '2026-02-22 17:45:00'),
(41, 15, 5,  'confirmed',        'preparing',        'Preparing rice and spicy devilled chicken.',                 '2026-02-22 17:55:00'),
(42, 15, 4,  'preparing',        'ready_for_pickup', 'Packed in thermal bag.',                                     '2026-02-22 18:20:00'),
(43, 15, 1,  'ready_for_pickup', 'out_for_delivery', 'Courier on way to Kiribathgoda.',                           '2026-02-22 18:25:00'),

-- Order 16 (Out for delivery)
(44, 16, 13, NULL,               'pending',          'Checked out with FIRSTORDER.',                               '2026-02-22 18:10:00'),
(45, 16, 4,  'pending',          'confirmed',        'Kitchen accepted.',                                          '2026-02-22 18:15:00'),
(46, 16, 6,  'confirmed',        'preparing',        'Cooking cheese kottu and roast paan kottu.',                 '2026-02-22 18:25:00'),
(47, 16, 1,  'preparing',        'out_for_delivery', 'Courier en route to Havelock Town.',                         '2026-02-22 18:50:00'),

-- Order 17 (Ready for pickup)
(48, 17, 14, NULL,               'pending',          'Order placed.',                                              '2026-02-22 18:50:00'),
(49, 17, 4,  'pending',          'confirmed',        'Kitchen accepted order.',                                    '2026-02-22 18:55:00'),
(50, 17, 5,  'confirmed',        'preparing',        'Lamprais warming and faluda chilling.',                      '2026-02-22 19:05:00'),
(51, 17, 4,  'preparing',        'ready_for_pickup', 'Packed and awaiting courier pickup at kitchen counter.',     '2026-02-22 19:25:00'),

-- Order 18 (Preparing)
(52, 18, 15, NULL,               'pending',          'Order placed.',                                              '2026-02-22 19:15:00'),
(53, 18, 4,  'pending',          'confirmed',        'Kitchen accepted.',                                          '2026-02-22 19:20:00'),
(54, 18, 5,  'confirmed',        'preparing',        'Nasi goreng wok charring and chicken deep frying.',          '2026-02-22 19:30:00'),

-- Order 19 (Preparing)
(55, 19, 16, NULL,               'pending',          'Checked out with LUNCHSPECIAL.',                             '2026-02-22 19:30:00'),
(56, 19, 4,  'pending',          'confirmed',        'Kitchen confirmed order.',                                   '2026-02-22 19:35:00'),
(57, 19, 5,  'confirmed',        'preparing',        'Thalapath fish curry clay pot simmering.',                   '2026-02-22 19:45:00'),

-- Order 20 (Confirmed)
(58, 20, 17, NULL,               'pending',          'Cash on delivery order placed.',                             '2026-02-22 20:00:00'),
(59, 20, 4,  'pending',          'confirmed',        'Order acknowledged by chef on duty.',                        '2026-02-22 20:05:00'),

-- Order 21 (Pending)
(60, 21, 18, NULL,               'pending',          'Customer submitted cart checkout; awaiting kitchen queue.',  '2026-02-22 20:25:00'),

-- Order 22 (Cancelled)
(61, 22, 19, NULL,               'pending',          'Customer submitted order.',                                  '2026-02-22 20:30:00'),
(62, 22, 1,  'pending',          'cancelled',        'Order cancelled by customer request; card payment refunded.','2026-02-22 20:35:00');
