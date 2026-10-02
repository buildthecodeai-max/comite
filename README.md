# Committee Manager — PHP + MySQL

Committee Manager converted from Google Apps Script to a PHP REST API with MySQL storage. The existing UI and JavaScript rendering logic are preserved; data is loaded and saved through PHP endpoints.

## Requirements

- PHP 8.1+ with PDO MySQL extension
- MySQL 8+
- Apache with `mod_rewrite` **or** PHP built-in server with `router.php`

## Quick start (local)

### 1. Create database

```bash
mysql -u root -p < sql/schema.sql
```

### 2. Configure environment

```bash
cp .env.example .env
```

Edit `.env` with your MySQL credentials:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=committee_manager
DB_USER=root
DB_PASS=your_password
```

### 3. Seed default data

```bash
php scripts/seed.php
php scripts/migrate.php   # run after upgrades to add new columns/tables
```

This creates:
- Admin login: **admin** / **admin123**
- 250 placeholder members (PIN defaults to member ID)

### 4. Run the app

**PHP built-in server (development):**

```bash
php -S localhost:8080 -t public public/router.php
```

Open http://localhost:8080

**Apache:** Point the virtual host document root to the `public/` folder. Ensure `.htaccess` is enabled.

## Project structure

```
├── api/
│   auth.php      POST login/logout, change passwords
│   data.php      GET/POST committee data (auth required)
│   public.php    GET login screen metadata (no auth)
├── config/
│   app.php       App name, timezone
│   database.php  PDO settings
├── public/
│   index.php     Serves templates/app.html
│   router.php    Dev server router for /api/*
│   .htaccess     Apache rewrite rules
├── scripts/
│   seed.php      Seed default committee data
├── sql/
│   schema.sql    MySQL schema
├── src/
│   Auth.php      Session + credential checks
│   DataRepository.php  MySQL ↔ D object mapping
│   Database.php  PDO connection
│   Seeder.php    Default data seeder
├── templates/
│   app.html      Committee Manager UI
└── .env.example
```

## API endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/public.php` | GET | No | Committee name + member list for login |
| `/api/auth.php?action=session` | GET | No | Current session |
| `/api/auth.php?action=login` | POST | No | Admin or member login |
| `/api/auth.php?action=logout` | POST | Yes | End session |
| `/api/auth.php?action=change-admin-pass` | POST | Admin | Change admin password |
| `/api/auth.php?action=forgot-admin-password` | POST | No | Request admin password reset code (uses configured admin email) |
| `/api/auth.php?action=reset-admin-password` | POST | No | Reset admin password with email code |
| `/api/data.php` | GET | Yes | Load full committee data |
| `/api/data.php` | POST | Yes | Save committee data |

## Security notes

- Admin password and member PINs are stored with `password_hash()` (bcrypt)
- All data mutations require a valid PHP session
- Members can only update their own PIN and preferred month
- Change the default admin password immediately after first login

## cPanel upload

See **[CPANEL.md](CPANEL.md)** for full hosting steps.

Quick summary:

1. Create MySQL database + user in cPanel
2. Import `sql/committee_manager.sql` (or `sql/install.sql`) in phpMyAdmin
3. Upload the project and set the domain document root to the `public/` folder
4. Copy `.env.example` → `.env` and set your cPanel DB credentials
5. Use PHP 8.1+

Regenerate the MySQL dump anytime from local data:

```bash
php scripts/export_sql.php sql/committee_manager.sql
```

## Legacy Google Apps Script files

The original `Index.html`, `Code.gs`, and `appsscript.json` remain in the repo root for reference. The live PHP app is served from `public/` using `templates/app.html`.
