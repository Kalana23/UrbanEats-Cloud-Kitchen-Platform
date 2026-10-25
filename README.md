# UrbanEats Cloud Kitchen Platform

UrbanEats is a cloud kitchen management and gourmet food ordering platform built with **PHP 8.x**, **MySQL/MariaDB**, **Secure PDO**, and **Pure CSS3** (no external CSS frameworks).

---

## 🔐 Demo Credentials & Test Accounts

All seeded accounts share the universal development/testing password:

> **Universal Test Password:** `Password123!`

### Key Test Accounts by Role

| Role | Name | Email | Username | Password | Account Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Administrator** | Kasun Perera | `kasun.admin@urbaneats.lk` | `kasun_admin` | `Password123!` | Active |
| **Administrator** | Dinithi Jayawardena | `dinithi.admin@urbaneats.lk` | `dinithi_admin` | `Password123!` | Active |
| **Administrator** | Nuwan Pradeep | `nuwan.admin@urbaneats.lk` | `nuwan_admin` | `Password123!` | Active |
| **Kitchen Staff** | Malith Fernando (Head Chef) | `malith.chef@urbaneats.lk` | `malith_chef` | `Password123!` | Active |
| **Kitchen Staff** | Tharindu Silva (Sous Chef) | `tharindu.kitchen@urbaneats.lk` | `tharindu_silva` | `Password123!` | Active |
| **Kitchen Staff** | Anuki Wijesinghe (Station Lead) | `anuki.kitchen@urbaneats.lk` | `anuki_wije` | `Password123!` | Active |
| **Kitchen Staff** | Roshan Wickramasinghe | `roshan.kitchen@urbaneats.lk` | `roshan_wicky` | `Password123!` | Active |
| **Kitchen Staff** | Dilani Senanayake | `dilani.kitchen@urbaneats.lk` | `dilani_sena` | `Password123!` | Active |
| **Kitchen Staff** | Kaveen Mendis | `kaveen.kitchen@urbaneats.lk` | `kaveen_mendis` | `Password123!` | Active |
| **Customer** | Nimesh Bandara | `nimesh.bandara@gmail.com` | `nimesh_b` | `Password123!` | Active |
| **Customer** | Chamari Atapattu | `chamari.a@outlook.com` | `chamari_a` | `Password123!` | Active |
| **Customer** | Sajith Gunawardena | `sajith.gunawardena@gmail.com` | `sajith_g` | `Password123!` | Active |
| **Customer** | Sanduni De Silva | `sanduni.desilva@yahoo.com` | `sanduni_ds` | `Password123!` | Active |
| **Customer** | Lakshitha Alwis | `lakshitha.alwis@gmail.com` | `lakshitha_a` | `Password123!` | Active |
| **Customer** | Priyantha Cooray | `priyantha.cooray@gmail.com` | `priyantha_c` | `Password123!` | Active |
| **Customer** | Isuru Udana | `isuru.udana@gmail.com` | `isuru_u` | `Password123!` | Active |
| **Customer** | Gayani Karunaratne | `gayani.karunaratne@gmail.com` | `gayani_k` | `Password123!` | Active |
| **Customer** | Ashen Rathnayake | `ashen.rathnayake@gmail.com` | `ashen_r` | `Password123!` | Active |
| **Customer** | Chathurika Samarakoon | `chathurika.s@gmail.com` | `chathurika_s` | `Password123!` | Active |
| **Customer** | Hashan Dissanayake | `hashan.d@gmail.com` | `hashan_d` | `Password123!` | Active |
| **Customer** | Kavindi Jayasuriya | `kavindi.j@gmail.com` | `kavindi_j` | `Password123!` | Active |
| **Customer (Inactive)** | Ruwantha Perera | `ruwantha.perera@gmail.com` | `ruwantha_p` | `Password123!` | **Deactivated** *(Tests lockout)* |

---

## 🗄️ Database Architecture (`urbaneats_db`)

The database script is located at [`database/component_a.sql`](database/component_a.sql) and includes all 5 required tables:

1. **`roles`**: System permission tiers (`1: Admin`, `2: Kitchen Staff`, `3: Customer`).
2. **`users`**: Platform user accounts with Bcrypt hashed passwords (`password_hash`), foreign key references to `roles(role_id)`, and `is_active` status flags.
3. **`login_attempts`**: Security tracking recording IP address, target email, timestamp, and success flag for brute-force rate limiting.
4. **`audit_log`**: Traceability table logging administrative, security, and authentication events with foreign keys to `users(user_id)`.
5. **`password_resets`**: Stores time-limited (1-hour) cryptographic reset tokens for password recovery.

### Seed Counts Included

- **Roles**: 3 standard roles (`Admin`, `Kitchen Staff`, `Customer`).
- **Users**: 22 realistic accounts with valid Sri Lankan names, real emails, and valid Bcrypt password hashes.
- **Login Attempts**: 25 realistic rows spanning Sri Lankan IP ranges with both successful logins and failed attempt traces.
- **Audit Logs**: 25 realistic events tracking logins, logouts, role changes, profile updates, and status modifications.
- **Password Resets**: Sample active and expired reset tokens.

---

## 🚀 Key Architectural Modules

### 1. Database Connection ([`config/db.php`](config/db.php))
- Strict types enabled (`declare(strict_types=1)`).
- UTF-8 multibyte encoding (`charset=utf8mb4`).
- Error mode set to `PDO::ERRMODE_EXCEPTION`.
- Default fetch mode set to `PDO::FETCH_ASSOC`.
- Prepared statement emulation disabled (`PDO::ATTR_EMULATE_PREPARES => false`).
- Internal error logging without exposing database credentials or stack traces to clients.

### 2. Authentication & Access Control ([`includes/auth.php`](includes/auth.php))
- `require_login()`: Redirects unauthenticated users to `login.php`.
- `require_role($allowed_roles_array)`: Enforces role permissions. Automatically redirects unauthorized users to [`public/access-denied.php`](public/access-denied.php).
- `log_action($pdo, $user_id, $action, $details)`: Records auditable events using prepared statements.

### 3. CSRF Protection Utilities ([`includes/csrf.php`](includes/csrf.php))
- `generate_csrf_token()`: Generates 64-char cryptographically secure hex tokens via `random_bytes(32)`.
- `csrf_field()`: Outputs `<input type="hidden" name="csrf_token" value="...">` for HTML forms.
- `csrf_meta()`: Outputs `<meta name="csrf-token" content="...">` for front-end AJAX requests.
- `validate_csrf_token()`: Timing-safe validation using `hash_equals()`.
- `verify_csrf_or_abort()`: Halts invalid POST requests with HTTP 403 Forbidden.

### 4. Error & Exception Handling ([`includes/errors.php`](includes/errors.php))
- Direct error printing disabled (`display_errors = 0`).
- Global error, exception, and fatal shutdown interceptors.
- Automatically creates [`logs/error.log`](logs/error.log) and records formatted logs with incident reference IDs.
- Delivers polished, non-leaking HTTP 500 error screens and JSON payloads.

### 5. Layout & Pure CSS3 ([`public/css/style.css`](public/css/style.css))
- Zero external CSS frameworks (no Bootstrap, Tailwind, etc.).
- Modern dark-slate aesthetic with culinary amber accents (`:root` CSS variables).
- Responsive breakpoints at `360px`, `768px`, and `1200px`.
- Dynamic navigation in [`includes/header.php`](includes/header.php) adapting dynamically to Customer, Kitchen Staff, Administrator, or Guest sessions.

### 6. Authentication Pages ([`public/login.php`](public/login.php) & [`public/logout.php`](public/logout.php))
- **Brute-Force Protection**: Temporarily locks authentication for **15 minutes** after **5 consecutive failed attempts** for an email or IP using the `login_attempts` table.
- **Password Verification**: Secure verification via `password_verify()`.
- **Account Status Guard**: Inactive accounts (`is_active = 0`) are prohibited from signing in.
- **Session Fixation Defense**: Invokes `session_regenerate_id(true)` upon successful login.
- **Audit Logging**: Logs both `USER_LOGIN` and `USER_LOGOUT` events to `audit_log`.

### 7. Profile & Password Reset Flow
- **Profile Management** ([`public/profile.php`](public/profile.php)): Edit name, username, email, and change password with current password verification and Bcrypt hashing.
- **Forgot Password** ([`public/forgot-password.php`](public/forgot-password.php)): Generates time-limited tokens and displays the reset link directly on screen.
- **Reset Password** ([`public/reset-password.php`](public/reset-password.php)): Verifies active tokens, updates the password, consumes the token, and logs the event.

### 8. Administrator Dashboard ([`public/admin/users.php`](public/admin/users.php) & [`public/admin/audit-log.php`](public/admin/audit-log.php))
- Search users by keyword (name, email, username).
- Filter by role and account status.
- Activate/deactivate accounts with self-deactivation protection.
- Change user roles with self-demotion protection.
- View and filter audit log events joined with the `users` table with server-side pagination.

### 9. Component B: Menu, Catalogue & Inventory
- **Database Schema ([`database/component_b.sql`](database/component_b.sql))**: 4 3NF normalized tables: `categories`, `dishes`, `inventory`, and `stock_movements`.
  - Includes 6 Sri Lankan food categories, 22 dishes with LKR pricing and images, 22 inventory tracking rows (with low-stock and out-of-stock cases), and 25 stock movement audit records.
- **Transaction-Safe Stock Reduction ([`includes/stock.php`](includes/stock.php))**:
  - `reduce_stock($pdo, $dish_id, $qty, $user_id)`: Atomic conditional UPDATE (`current_stock = current_stock - :qty WHERE current_stock >= :qty`) preventing negative inventory, logging movement as `'order'`.
  - `record_stock_adjustment()`: Staff restocking and wastage logging.
- **Kitchen Stock Dashboard ([`public/menu/stock.php`](public/menu/stock.php))**:
  - Protected by `require_role(['Kitchen Staff', 'Administrator'])`.
  - Highlights Out-of-Stock (`current_stock = 0`) and Low-Stock (`current_stock <= reorder_level`) items.
  - CSRF-protected Restock and Wastage adjustment form with staff attribution and audit logging.
- **Staff Dish & Category CRUD**:
  - Category Management ([`public/menu/manage-categories.php`](public/menu/manage-categories.php)): Add, edit, and soft-delete categories.
  - Dish Management ([`public/menu/manage-dishes.php`](public/menu/manage-dishes.php)): Add, edit, and soft-delete dishes with secure image upload (validating JPG/PNG/WEBP extension, `finfo_file` MIME check, 2MB size limit, and randomized filenames).
- **Public Menu & Ordering Flow**:
  - Landing Page ([`public/index.php`](public/index.php)): Featured categories, chef highlights, and cloud kitchen value pillars.
  - Menu Catalog ([`public/menu/list.php`](public/menu/list.php)): Category tabs, keyword search, whitelisted sorting (`price_asc`, `price_desc`, `name_asc`, `name_desc`), availability filter, and server-side pagination.
  - Dish Detail ([`public/menu/detail.php`](public/menu/detail.php)): High-resolution imagery, portion pricing, stock status indicators, and Add-to-Cart form.
  - Cart Integration ([`public/orders/cart.php`](public/orders/cart.php)): Session-based cart with server-side database price re-verification.

---

## 🛠️ Installation & Setup

1. Start **Apache** and **MySQL** in XAMPP.
2. Verify that the project is in `c:/xampp/htdocs/UrbanEats-Cloud-Kitchen-Platform/`.
3. Import the database schema and seed data:
   - Option A: Open `http://localhost/phpmyadmin/` and import:
     - `database/component_a.sql` (Component A)
     - `database/component_b.sql` (Component B)
   - Option B: Run via command line:
     ```bash
     mysql -u root urbaneats_db < database/component_a.sql
     mysql -u root urbaneats_db < database/component_b.sql
     ```
4. Access the platform in your browser:
   ```text
   http://localhost/UrbanEats-Cloud-Kitchen-Platform/public/index.php
   ```