<div align="center">

# 🏦 KametiPro

**A modern, full-featured Kameti (ROSCA) management platform**

Manage rotating savings committees with multi-member tracking, real-time financial analytics, prize draws, and a beautiful dark/light UI — all in one self-hosted PHP app.

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://mysql.com)
[![Deploy](https://img.shields.io/badge/Deploy-cPanel%20%7C%20VPS-success?style=flat-square)](CPANEL.md)
[![License](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)
[![GitHub Actions](https://img.shields.io/github/actions/workflow/status/buildthecodeai-max/comite/deploy.yml?style=flat-square&label=CI%2FCD)](https://github.com/buildthecodeai-max/comite/actions)

</div>

---

## ✨ What is KametiPro?

A **Kameti** (also known as a *committee*, *chit fund*, or ROSCA — Rotating Savings and Credit Association) is a group savings system where members contribute a fixed amount monthly and one member wins the full pot each round.

**KametiPro** digitises this entire process — from member onboarding and monthly payment tracking to prize draws and financial reporting — in a fast, offline-capable single-page web app.

---

## 🚀 Features

### 👥 Member Management
- Add, edit, and archive members with full profile support
- **Share-based system** — each member holds one or more shares
- Soft-delete with a recoverable **Trash** bin
- Member PIN login for self-service payment views
- Per-member payment history, prize history, and progress stats

### 💰 Payment Tracking
- Record payments per member per month in one click
- Visual payment grid — instantly see who's paid and who hasn't
- Bulk month-close with audit trail
- Supports advance payments and partial tracking

### 🎯 Prize Draw & Winners
- **Random Draw** — weighted by shares, with exclusion rules
- Full winners history with prize amounts and draw dates
- Win limits per member (configurable)
- Prize voiding and re-draw support

### 📊 Financial Analytics
- **Total Collected / Pending** — calculated against current month only
- **Collection Rate** — real-time % for months elapsed
- **Fully Paid** member count vs partially paid vs no payments
- Month-by-Month Summary table with per-month collected, pending, and rate
- Monthly Collection grouped bar chart (collected vs pending per month)
- Member Payment Status donut chart
- Prize Distribution donut (distributed vs remaining pool)
- Top Defaulters list — click to open member profile

### 📅 Calendar & Reports
- Committee calendar with draw dates and month markers
- Exportable reports: monthly summary, member payments, winner history
- JSON backup / restore

### ⚙️ Settings & Configuration
- Committee name, start month, active month, total months
- **Amount Per Share/Month** — drives all financial calculations
- Prize Per Share Win amount
- Committee rules: max prize per month, max winners, max wins per member
- Admin password management
- Member PIN reset

### 👤 Multi-Role Access
| Role | Access |
|------|--------|
| `admin` (superadmin) | Full access — all committees, user management |
| `owner` | Manage their assigned committee |
| `app_user` | Operate a committee without owner-level admin |
| `user` (member PIN) | View own payments and profile only |

### 🔐 Authentication
- Email + password login
- **Google OAuth** sign-in
- **Facebook OAuth** sign-in
- Member PIN login (for members without an account)
- Password reset via email

### 🎨 UI & UX
- **Dark / Light mode** toggle
- Fully responsive — works on mobile and desktop
- Fast single-page app — no page reloads
- Toast notifications, loading states, keyboard shortcuts
- Accessible colour contrast in both themes

### 🏢 Multi-Committee SaaS Architecture
- One installation, multiple committees
- Each committee has its own members, payments, winners, and settings
- Owners assigned to their committees
- Superadmin dashboard across all committees

---

## 🛠 Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.1+, PDO |
| Database | MySQL 8+ |
| Frontend | Vanilla JS SPA (no framework) |
| Auth | PHP sessions + bcrypt, Google OAuth, Facebook OAuth |
| Hosting | cPanel shared hosting or any VPS with Apache/Nginx |
| CI/CD | GitHub Actions → FTP deploy |

---

## 📁 Project Structure

```
comite/
├── api/
│   ├── auth.php          # Login, logout, OAuth, password reset
│   ├── data.php          # Committee data CRUD (auth required)
│   ├── health.php        # DB health check endpoint
│   └── public.php        # Public metadata (login screen)
├── config/
│   ├── app.php           # App name, timezone
│   └── database.php      # PDO settings
├── public/               # ← Document root
│   ├── index.php         # Serves templates/app.html
│   ├── router.php        # Dev server router
│   └── .htaccess         # Apache rewrite rules
├── scripts/
│   ├── seed.php          # Seed default committee data
│   ├── migrate.php       # Run DB migrations
│   ├── reset_admin.php   # Reset admin password
│   └── export_sql.php    # Export DB to SQL file
├── sql/
│   ├── schema.sql        # Empty schema
│   ├── install.sql       # Full install with seed data
│   └── migrations/       # Incremental migration files
├── src/
│   ├── Auth.php                    # Session + credential checks
│   ├── DataRepository.php          # MySQL ↔ D object mapping
│   ├── MultiCommitteeRepository.php # Multi-tenant committee logic
│   ├── Database.php                # PDO connection
│   ├── Mailer.php                  # Email (password reset)
│   └── bootstrap.php               # App bootstrap
├── templates/
│   └── app.html          # Full SPA — all UI, CSS, JS
├── .env.example
├── .gitignore
└── CPANEL.md             # cPanel deployment guide
```

---

## ⚡ Quick Start (Local)

### Prerequisites
- PHP 8.1+ with `pdo_mysql`, `mbstring`, `json`, `session`
- MySQL 8+

### 1. Clone & configure

```bash
git clone https://github.com/buildthecodeai-max/comite.git
cd comite
cp .env.example .env
```

Edit `.env`:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=committee_manager
DB_USER=root
DB_PASS=your_password

# Optional — enables social login
GOOGLE_CLIENT_ID=
FACEBOOK_APP_ID=
FACEBOOK_APP_SECRET=
```

### 2. Create the database

```bash
mysql -u root -p -e "CREATE DATABASE committee_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p committee_manager < sql/schema.sql
php scripts/migrate.php
php scripts/seed.php
```

### 3. Run the app

```bash
php -S localhost:8080 -t public public/router.php
```

Open [http://localhost:8080](http://localhost:8080)

**Default login:** `admin` / `admin123` — change this immediately.

---

## 🌐 API Reference

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/public.php` | GET | — | Committee name + member list for login screen |
| `/api/auth.php?action=session` | GET | — | Current session info |
| `/api/auth.php?action=login` | POST | — | Admin, owner, app_user, or member PIN login |
| `/api/auth.php?action=logout` | POST | ✓ | End session |
| `/api/auth.php?action=google-login` | POST | — | Google OAuth token exchange |
| `/api/auth.php?action=facebook-login` | POST | — | Facebook OAuth token exchange |
| `/api/auth.php?action=forgot-admin-password` | POST | — | Request password reset email |
| `/api/auth.php?action=reset-admin-password` | POST | — | Reset password with email code |
| `/api/auth.php?action=change-admin-pass` | POST | Admin | Change admin password |
| `/api/data.php` | GET | ✓ | Load full committee data |
| `/api/data.php` | POST | ✓ | Save committee data |
| `/api/health.php` | GET | — | DB connection health check |

---

## 🚀 Deployment

### cPanel (Shared Hosting)

See **[CPANEL.md](CPANEL.md)** for the full step-by-step guide.

**Quick summary:**
1. Create MySQL database + user in cPanel
2. Import `sql/install.sql` via phpMyAdmin
3. Upload project files, set document root to `public/`
4. Create `.env` from `.env.example` with cPanel DB credentials
5. Select PHP 8.1+ in cPanel MultiPHP Manager

### Automated Deploy via GitHub Actions

Every push to `main` automatically deploys to your cPanel server.

**Setup — add these secrets in GitHub → Settings → Secrets → Actions:**

| Secret | Value |
|--------|-------|
| `FTP_SERVER` | Your cPanel FTP hostname |
| `FTP_USERNAME` | FTP username |
| `FTP_PASSWORD` | FTP password |
| `FTP_REMOTE_DIR` | Remote path, e.g. `/home/youruser/comite/` |

After secrets are set, just push:

```bash
git push origin main
# → auto-deploys to cPanel
```

---

## 🔒 Security

- Passwords and PINs hashed with `bcrypt` via `password_hash()`
- All data mutations require a valid PHP session
- Members can only update their own PIN and preferred month
- `.env` is excluded from the repository and web-accessible paths
- OAuth tokens validated server-side before any session is created
- Prepared statements throughout — no raw SQL interpolation

---

## 🤝 Contributing

1. Fork the repo
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Commit your changes: `git commit -m "add your feature"`
4. Push: `git push origin feature/your-feature`
5. Open a Pull Request

---

## 📄 License

MIT © [saitsam](https://github.com/buildthecodeai-max)
