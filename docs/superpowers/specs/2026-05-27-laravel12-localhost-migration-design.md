---
title: Laravel 12 Localhost Migration & Production-Ready Cleanup
date: 2026-05-27
status: approved
---

# Laravel 12 Localhost Migration & Production-Ready Cleanup

## Context

The project `laptop-scraper` is a Laravel 12 web scraper admin panel. The codebase was updated to Laravel 12 syntax on the live server, but the local XAMPP copy has a stale `vendor/` folder from Laravel 10.48 — causing `php artisan` to crash with `Application::configure does not exist`. The goal is to make the local environment fully working and clean.

**Environment:**
- PHP 8.2.12 (XAMPP on Windows 10)
- MySQL (XAMPP)
- `composer.json` requires `laravel/framework: ^12.0`
- `composer.lock` pins `laravel/framework` at `v12.47.0`
- Actual installed vendor: `laravel/framework 10.48.29` (stale from live server)

**Live DB dump available at:** `docs/compx_db.sql` (from `compx_db` on live server → imported as `aethyrtech` locally)

---

## Section 1: Dependency & Environment Fix

### Steps
1. Run `composer install` to install packages from `composer.lock` (Laravel 12.47.0), replacing the stale L10 vendor.
2. Verify `APP_KEY` is present in `.env` (already set: `base64:x0mm...`).
3. Clear all framework caches: `config:clear`, `cache:clear`, `route:clear`, `view:clear`, `event:clear`.
4. `.env` audit:
   - Confirm `DB_HOST=127.0.0.1`, `DB_DATABASE=aethyrtech`, `DB_USERNAME=root`, `DB_PASSWORD=` (blank for XAMPP)
   - Remove commented-out live-server lines (`#DB_HOST=13.201.17.184`, `#DB_DATABASE=compx_db`)

---

## Section 2: Database Setup

### Steps
1. Create local database: `CREATE DATABASE IF NOT EXISTS aethyrtech CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
2. Import live dump: `mysql -u root aethyrtech < docs/compx_db.sql`
3. **Delete duplicate Laravel 12 default migrations** (these create the same tables already in the dump, tracked by old migrations in the `migrations` table):
   - `database/migrations/0001_01_01_000000_create_users_table.php`
   - `database/migrations/0001_01_01_000001_create_cache_table.php`
   - `database/migrations/0001_01_01_000002_create_jobs_table.php`
4. Run `php artisan migrate` — applies only the new application migrations (`2024_*`, `2025_*`, `2026_*`) not present in the dump.
5. Run seeders if roles/permissions are missing: `php artisan db:seed --class=DatabaseSeeder`

### Why delete the `0001_*` migrations?
The live dump already contains these tables (users, cache, jobs) created by the old `2014_*`/`2019_*` migrations. The `migrations` table in the dump tracks those old migrations as "run". If the new `0001_*` files remain, `artisan migrate` will try to create tables that already exist → "Table already exists" error.

---

## Section 3: Route & Namespace Fixes

### Problems
| File | Issue |
|------|-------|
| `routes/web.php` | Imports `Spatie\Permission\Middlewares\RoleMiddleware` (with 's') — wrong namespace |
| `routes/web.php` | Imports `Admin\ScrapingUrlController` but the file lives in root `Http\Controllers\` |
| `routes/web.php` | Imports `Admin\Auth\LoginController` — folder/file does not exist |
| `routes/web.php` | Imports `Admin\AdminUserController` — file does not exist |
| `routes/web.php` | Imports `Admin\RoleController` — file does not exist |
| `routes/web.php` | Imports `Admin\ExportController` — file does not exist |
| `routes/web.php` | Imports `Admin\ComparisonController` — file does not exist |

### Fixes

**Fix Spatie namespace in `routes/web.php`:**
```php
// Before (wrong):
use Spatie\Permission\Middlewares\RoleMiddleware;
use Spatie\Permission\Middlewares\PermissionMiddleware;

// After (correct):
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
```

**Move `ScrapingUrlController` into Admin namespace:**
- Move `app/Http/Controllers/ScrapingUrlController.php` → `app/Http/Controllers/Admin/ScrapingUrlController.php`
- Update namespace inside the file from `App\Http\Controllers` → `App\Http\Controllers\Admin`

**Create stub controllers** (functional stubs, not empty) under `app/Http/Controllers/Admin/`:

| Controller | Path | Purpose |
|---|---|---|
| `Auth/LoginController` | `Admin/Auth/LoginController.php` | Show login form, handle login, logout |
| `AdminUserController` | `Admin/AdminUserController.php` | CRUD for admin users |
| `RoleController` | `Admin/RoleController.php` | Roles management |
| `ExportController` | `Admin/ExportController.php` | Export handler |
| `ComparisonController` | `Admin/ComparisonController.php` | Comparison page |

Stubs return a view or a `todo` response so routes don't 404, and have the correct method signatures matching the routes defined in `web.php`.

---

## Section 4: Cleanup (Dead Files & Unused Code)

### Safety rule: Verify new file is complete before deleting old

**Delete `.php_old` files** (after verifying new versions are complete):
- `app/Console/Commands/CleanupCommand.php_old`
- `app/Console/Commands/ScrapeCommand.php_old`
- `app/Console/Commands/StatusCommand.php_old`

**Delete `ScraperConfigurationController.php`** (no routes, scraper-config views were deleted):
- `app/Http/Controllers/Admin/ScraperConfigurationController.php`

**Delete root `DashboardController.php`** (Admin version exists in `Admin/DashboardController.php`; verify root one is not referenced):
- `app/Http/Controllers/DashboardController.php` (root level)

**Delete duplicate L12 default migrations** (covered in Section 2):
- `database/migrations/0001_01_01_000000_create_users_table.php`
- `database/migrations/0001_01_01_000001_create_cache_table.php`
- `database/migrations/0001_01_01_000002_create_jobs_table.php`

---

## Section 5: Verification & Final State

### Verification checklist
1. `php artisan --version` → `Laravel Framework 12.x.x` (no crash)
2. `php artisan config:cache` → no errors
3. `php artisan route:list` → all routes resolve, no "class not found" errors
4. `php artisan migrate --pretend` → pending migrations listed cleanly
5. Browse `http://localhost/laptop-scraper/public/admin/login` → page loads, no 500 error
6. Run a scraper command: `php artisan scrape:run --platform=amazon_jp` → executes without crash

### Expected end state
- `php artisan` runs without crashing
- All routes load (no "class not found" errors in route list)
- DB schema is in sync (dump imported + new migrations applied)
- No dead `.php_old` files
- All admin controllers live under `App\Http\Controllers\Admin\` namespace
- Spatie namespace consistent in both `routes/web.php` and `bootstrap/app.php`
- `vendor/` contains Laravel 12.47.0

---

## Files Changed Summary

| Action | Files |
|---|---|
| Install | `vendor/` (via composer install) |
| Fix | `routes/web.php` (namespace fixes) |
| Move + update namespace | `ScrapingUrlController.php` → `Admin/` |
| Create | `Admin/Auth/LoginController.php`, `Admin/AdminUserController.php`, `Admin/RoleController.php`, `Admin/ExportController.php`, `Admin/ComparisonController.php` |
| Delete | 3x `.php_old` files, `ScraperConfigurationController.php`, root `DashboardController.php`, 3x `0001_01_01_*` migrations |
| Import | `docs/compx_db.sql` → local MySQL |
