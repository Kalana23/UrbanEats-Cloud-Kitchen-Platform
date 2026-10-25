-- =============================================================================
-- UrbanEats Cloud Kitchen Platform - Component A: Database Architecture & Seeds
-- DBMS: MySQL 8.x / MariaDB 10.x
-- Database: urbaneats_db
-- =============================================================================
-- TEST CREDENTIALS DOCUMENTATION:
-- All seeded users share the universal password:
--   Password: Password123!
--
-- Key Demo Accounts:
--   1. Administrator:  kasun.admin@urbaneats.lk     / Password123!  (Username: kasun_admin)
--   2. Administrator:  dinithi.admin@urbaneats.lk   / Password123!  (Username: dinithi_admin)
--   3. Kitchen Staff:  malith.chef@urbaneats.lk     / Password123!  (Username: malith_chef)
--   4. Kitchen Staff:  tharindu.kitchen@urbaneats.lk/ Password123!  (Username: tharindu_silva)
--   5. Customer:       nimesh.bandara@gmail.com     / Password123!  (Username: nimesh_b)
--   6. Customer:       chamari.a@outlook.com        / Password123!  (Username: chamari_a)
--   7. Inactive User:  ruwantha.perera@gmail.com    / Password123!  (Username: ruwantha_p)
-- =============================================================================

CREATE DATABASE IF NOT EXISTS urbaneats_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE urbaneats_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
SET FOREIGN_KEY_CHECKS = 1;

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
-- SEED DATA
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Seed: roles (3 standard roles)
-- -----------------------------------------------------------------------------
INSERT INTO roles (role_id, role_name) VALUES
(1, 'Admin'),
(2, 'Kitchen Staff'),
(3, 'Customer');

-- -----------------------------------------------------------------------------
-- Seed: users (22 Realistic Sri Lankan Accounts)
-- Valid password_hash for Password123!:
-- $2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa
-- -----------------------------------------------------------------------------
INSERT INTO users (user_id, first_name, last_name, email, username, password_hash, role_id, is_active, created_at) VALUES
-- Administrators (role_id = 1)
(1,  'Kasun',       'Perera',           'kasun.admin@urbaneats.lk',      'kasun_admin',     '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 1, 1, '2026-01-10 08:30:00'),
(2,  'Dinithi',     'Jayawardena',      'dinithi.admin@urbaneats.lk',    'dinithi_admin',   '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 1, 1, '2026-01-12 09:15:00'),
(3,  'Nuwan',       'Pradeep',          'nuwan.admin@urbaneats.lk',      'nuwan_admin',     '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 1, 1, '2026-01-15 11:00:00'),

-- Kitchen Culinary Staff (role_id = 2)
(4,  'Malith',      'Fernando',         'malith.chef@urbaneats.lk',      'malith_chef',     '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-16 10:20:00'),
(5,  'Tharindu',    'Silva',            'tharindu.kitchen@urbaneats.lk', 'tharindu_silva',  '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-18 14:05:00'),
(6,  'Anuki',       'Wijesinghe',       'anuki.kitchen@urbaneats.lk',    'anuki_wije',      '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-20 15:30:00'),
(7,  'Roshan',      'Wickramasinghe',   'roshan.kitchen@urbaneats.lk',   'roshan_wicky',    '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-22 16:45:00'),
(8,  'Dilani',      'Senanayake',       'dilani.kitchen@urbaneats.lk',   'dilani_sena',     '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-25 08:50:00'),
(9,  'Kaveen',      'Mendis',           'kaveen.kitchen@urbaneats.lk',   'kaveen_mendis',   '$2y$10$j5MsCU5dTosvfbnHKOQy/OpS4q6epdcFIW7kdtJgK1MNcTFby/qKa', 2, 1, '2026-01-28 12:10:00'),

-- Customers (role_id = 3)
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

-- -----------------------------------------------------------------------------
-- Seed: login_attempts (25 Realistic Sri Lankan Activity Logs)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- Seed: audit_log (25 Realistic Events)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- Seed: password_resets (Sample active and expired tokens)
-- -----------------------------------------------------------------------------
INSERT INTO password_resets (reset_id, email, token, expires_at, created_at) VALUES
(1, 'priyantha.cooray@gmail.com', 'a1b2c3d4e5f67890123456789abcdef0123456789abcdef0123456789abcdef0', '2026-02-15 11:00:00', '2026-02-15 10:00:00'),
(2, 'kavindi.j@gmail.com',        'f0e1d2c3b4a567890123456789abcdef0123456789abcdef0123456789abcdef1', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW());