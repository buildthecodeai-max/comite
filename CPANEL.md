# cPanel Deployment Guide — Committee Manager

This app is a PHP + MySQL committee manager. Follow these steps on shared hosting.

## Files to upload

Upload the full project folder, or zip everything **except**:

- `.env` (create a new one on the server)
- `.DS_Store`
- `Index.html`, `Code.gs`, `appsscript.json` (legacy Google Apps Script files; optional)

Required folders/files:

```
api/
config/
public/          ← website document root should point here
scripts/
sql/
src/
templates/
.env             ← create from .env.example
.htaccess        ← root fallback protection
```

## A) Create MySQL database (cPanel)

1. Open **cPanel → MySQL® Databases**
2. Create a database, e.g. `youruser_committee`
3. Create a MySQL user and assign a strong password
4. Add the user to the database with **ALL PRIVILEGES**
5. Note the full names (cPanel often prefixes them), e.g.:
   - DB: `youruser_committee`
   - User: `youruser_comuser`
   - Host: usually `localhost`

## B) Import MySQL file (phpMyAdmin)

1. Open **cPanel → phpMyAdmin**
2. Select your empty database on the left
3. Click **Import**
4. Choose one of these files:

| File | Purpose |
|------|---------|
| `sql/committee_manager.sql` | **Recommended** — full dump of current live data (members, payments, winners, settings) |
| `sql/install.sql` | Same content as above (alias for deploy) |
| `sql/schema.sql` | Empty schema only (no member data) |

5. Click **Go** and confirm success

Default login after a seed (empty DB + `php scripts/seed.php`):

- Username: `admin`
- Password: `admin123`

Change this password immediately.

## C) Configure `.env`

In the project root (same level as `api/`, `public/`), create `.env`:

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=youruser_committee
DB_USER=youruser_comuser
DB_PASS=your_db_password
```

You can copy `.env.example` and edit the values.

## D) Point the domain to `public/`

### Best option (recommended)

In **cPanel → Domains / Addon Domains / Subdomains**:

- Set the **document root** to:
  - `/home/youruser/committee/public`
  - or `/home/youruser/public_html` if you uploaded the contents of `public/` carefully

The API files (`api/`), `src/`, and `.env` must remain **one level above** `public/` (sibling folders), because `public/.htaccess` routes `/api/*.php` to `../api/`.

Correct layout on server:

```
/home/youruser/committee/
  .env
  api/
  config/
  public/          ← document root
  src/
  templates/
  sql/
```

### If you must use `public_html` as document root

1. Upload the whole project into `~/committee/`
2. Set the subdomain/addon document root to `~/committee/public`
   **or**
3. Put project files beside `public_html` and map the domain to `.../public`

Avoid uploading `.env`, `api/`, or `src/` *inside* a publicly browsable folder without access rules.

## E) PHP requirements

- PHP **8.1+** (select in cPanel → MultiPHP Manager)
- Extensions: `pdo_mysql`, `mbstring`, `json`, `session`
- Apache `mod_rewrite` enabled (standard on cPanel)

After upload, optionally run once via SSH/Terminal if available:

```bash
php scripts/migrate.php
```

This ensures new columns/tables exist after future upgrades.

## F) Quick smoke test

1. Open your domain
2. Login as admin
3. Open **Members**, **Payments**, **Winners**
4. Confirm data from the imported SQL dump is visible
5. Change admin password under **User Accounts**

## Regenerating the MySQL dump locally

```bash
php scripts/export_sql.php sql/committee_manager.sql
```

This exports the current local database into a cPanel-ready `.sql` file.

## Troubleshooting

| Issue | Fix |
|-------|-----|
| `❌ Request failed (500)` on admin login | Almost always DB config. Open `https://yourdomain.com/api/health.php` and fix any `ok: false` check. Usually wrong `.env` DB name/user/pass. |
| Blank page / 500 | Check PHP version ≥ 8.1; check `error_log` in cPanel |
| Database connection failed | Verify `.env` names match cPanel MySQL user/db exactly (often `cpaneluser_dbname`) |
| `/api/...` 404 | Document root must be `public/`; ensure `.htaccess` is uploaded; re-upload updated `public/.htaccess` |
| CSS/UI loads but login fails | Import failed or wrong database selected in phpMyAdmin |
| Invalid admin credentials (401) | Username `admin`, password `admin123` after importing dump. Or run `php scripts/reset_admin.php` |
| Session not sticking | Ensure HTTPS cookie/session path is `/`; avoid mixed HTTP/HTTPS |

### Diagnose 500 quickly (IMPORTANT)

If Inspect shows `/api/public.php`, `/api/auth.php` all returning **500**:

1. Re-upload the latest package so these exist on the server:
   - `public/api/auth.php`
   - `public/api/data.php`
   - `public/api/public.php`
   - `public/api/health.php`
   - `public/health.php`
   - `public/.htaccess`
   - `src/bootstrap.php`
   - `.env`

2. Open this first (always works inside document root):

   `https://YOUR-DOMAIN/health.php`

3. Then open:

   `https://YOUR-DOMAIN/api/health.php`

4. Fix any check with `"ok": false` — usually wrong `.env` DB credentials.

`.env` must be **above** `public/`:

```
committee/
  .env
  api/
  config/
  public/
    api/
      auth.php
      data.php
      public.php
      health.php
    health.php
    index.php
  src/
  templates/
```

**Do not** put `.env` only inside `public_html` if the app root is elsewhere.

### Reset admin password on server

In cPanel Terminal (or SSH), from the project root:

```bash
php scripts/reset_admin.php
```

Or set a custom password:

```bash
php scripts/reset_admin.php YourNewPassword
```

