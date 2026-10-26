-- =============================================================================
-- UrbanEats Cloud Kitchen Platform - Component B: Menu, Catalogue & Inventory
-- DBMS: MySQL 8.x / MariaDB 10.x
-- Database: urbaneats_db
-- =============================================================================

USE urbaneats_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS dishes;
DROP TABLE IF EXISTS categories;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- 1. categories Table (3NF)
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
-- 2. dishes Table (3NF)
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
-- 3. inventory Table (3NF)
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
-- 4. stock_movements Table (3NF)
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
-- SEED DATA INSERTION
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. categories Seed (6 Sri Lankan Food Categories)
-- -----------------------------------------------------------------------------
INSERT INTO categories (category_id, name, description, is_active) VALUES
(1, 'Kottu Specialties', 'Authentic godamba roti chopped on sizzling iron griddles with vegetables, eggs, meats, and aromatic spices.', 1),
(2, 'Rice & Biriyani', 'Fragrant basmati biriyanis, traditional Dutch Burgher lamprais, and high-flame wok tossed fried rice varieties.', 1),
(3, 'Traditional Rice & Curry', 'Wholesome clay-pot curries served with red raw rice or samba, coconut pol sambol, dhal, and fresh garden vegetables.', 1),
(4, 'Devilled & Fast Food', 'Fiery wok-tossed devilled meats with capsicums and big onions, alongside crispy fried chicken and crunchy finger foods.', 1),
(5, 'Authentic Short Eats', 'Handmade savory pastries, golden crumbed chicken rolls, spiced tuna fish cutlets, and griddle vegetable rotis.', 1),
(6, 'Refreshing Beverages', 'Chilled tropical beverages including royal rose faluda, iced Milo, wood apple juice, and fresh seasonal fruit blends.', 1);

-- -----------------------------------------------------------------------------
-- 2. dishes Seed (22 Dishes with realistic Sri Lankan Rupee pricing & images)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 3. inventory Seed (22 items including Low Stock and Out-of-Stock test cases)
-- -----------------------------------------------------------------------------
INSERT INTO inventory (inventory_id, dish_id, current_stock, reorder_level) VALUES
(1,  1,  25, 10), -- Royal Chicken Dum Biriyani (Healthy)
(2,  2,  18, 8),  -- Nasi Goreng (Healthy)
(3,  3,  30, 10), -- Signature Mixed Fried Rice (Healthy)
(4,  4,  0,  6),  -- Lamprais (OUT OF STOCK - Stock = 0)
(5,  5,  3,  8),  -- Special Mutton Dum Biriyani (LOW STOCK - 3 <= 8)
(6,  6,  40, 12), -- Classic Chicken Kottu (Healthy)
(7,  7,  4,  10), -- Gourmet Chicken Cheese Kottu (LOW STOCK - 4 <= 10)
(8,  8,  0,  5),  -- Spicy Seafood Dolphin Kottu (OUT OF STOCK - Stock = 0)
(9,  9,  35, 10), -- Traditional Egg Kottu (Healthy)
(10, 10, 20, 6),  -- Roast Paan Kottu (Healthy)
(11, 11, 45, 15), -- Ceylon Village Chicken Rice & Curry (Healthy)
(12, 12, 2,  8),  -- Southern Thalapath Fish Curry (LOW STOCK - 2 <= 8)
(13, 13, 22, 8),  -- Pol Sambol & Egg Rice & Curry (Healthy)
(14, 14, 16, 5),  -- Vegetarian Lotus Root Rice & Curry (Healthy)
(15, 15, 28, 10), -- Spicy Hot Chicken Devilled (Healthy)
(16, 16, 19, 6),  -- Crispy Sausage Devilled Platter (Healthy)
(17, 17, 24, 8),  -- Golden Spice Fried Chicken (Healthy)
(18, 18, 35, 10), -- Masala Seasoned French Fries (Healthy)
(19, 19, 50, 15), -- Crispy Crumbed Chicken Rolls (Healthy)
(20, 20, 5,  12), -- Spiced Tuna Fish Cutlets (LOW STOCK - 5 <= 12)
(21, 21, 38, 12), -- Stuffed Spicy Vegetable Roti (Healthy)
(22, 22, 42, 15); -- Classic Royal Rose Faluda (Healthy)

-- -----------------------------------------------------------------------------
-- 4. stock_movements Seed (25 audit-trail records tracking restock, orders, wastage, adjustment)
-- -----------------------------------------------------------------------------
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
