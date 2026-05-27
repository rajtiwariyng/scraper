# Laravel 12 Localhost Migration & Production-Ready Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the Laravel 10 → 12 vendor mismatch, resolve all broken route imports, create missing Admin model and controllers, move ScrapingUrlController into Admin namespace, and remove dead files so the app runs cleanly on localhost.

**Architecture:** Five sequential phases — (1) install deps, (2) database import, (3) create missing model/controllers, (4) fix routes and clean up dead files, (5) verify. Each phase produces a committed, stable checkpoint.

**Tech Stack:** Laravel 12.47, PHP 8.2, MySQL (XAMPP), Spatie Laravel-Permission 6.x, AdminLTE (frontend)

---

## File Map

| Action | File |
|--------|------|
| Install | `vendor/` (composer install from lock) |
| **Create** | `app/Models/Admin.php` |
| **Create** | `app/Http/Controllers/Admin/Auth/LoginController.php` |
| **Create** | `app/Http/Controllers/Admin/AdminUserController.php` |
| **Create** | `app/Http/Controllers/Admin/RoleController.php` |
| **Create** | `app/Http/Controllers/Admin/ExportController.php` |
| **Create** | `app/Http/Controllers/Admin/ComparisonController.php` |
| **Move + rename namespace** | `app/Http/Controllers/ScrapingUrlController.php` → `app/Http/Controllers/Admin/ScrapingUrlController.php` |
| **Modify** | `routes/web.php` (fix Spatie namespace + ScrapingUrlController import) |
| **Delete** | `app/Console/Commands/CleanupCommand.php_old` |
| **Delete** | `app/Console/Commands/ScrapeCommand.php_old` |
| **Delete** | `app/Console/Commands/StatusCommand.php_old` |
| **Delete** | `app/Http/Controllers/Admin/ScraperConfigurationController.php` |
| **Delete** | `app/Http/Controllers/DashboardController.php` (root, references deleted views) |
| **Delete** | `database/migrations/0001_01_01_000000_create_users_table.php` |
| **Delete** | `database/migrations/0001_01_01_000001_create_cache_table.php` |
| **Delete** | `database/migrations/0001_01_01_000002_create_jobs_table.php` |

---

## Task 1: Install Laravel 12 Dependencies

**Files:**
- Install: `vendor/` (via composer)

- [ ] **Step 1: Run composer install**

```bash
cd c:\xampp\htdocs\laptop-scraper
composer install --no-interaction --prefer-dist
```

Expected output ends with:
```
Generating optimized autoload files
> @php artisan config:clear
> @php artisan clear-compiled
> @php artisan package:discover --ansi
Discovered Package: ...
Application ready! Build something amazing.
```

If you see `Method Illuminate\Foundation\Application::configure does not exist` during `config:clear`, ignore it — it will be gone after install completes and vendor is replaced.

- [ ] **Step 2: Verify Laravel version**

```bash
php artisan --version
```

Expected: `Laravel Framework 12.x.x` (not 10.x)

- [ ] **Step 3: Clear all framework caches**

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear
```

Each should output: `... cache cleared successfully.`

- [ ] **Step 4: Commit**

```bash
git add composer.lock
git commit -m "chore: composer install - upgrade vendor to Laravel 12.47"
```

---

## Task 2: Clean .env for Localhost

**Files:**
- Modify: `.env`

- [ ] **Step 1: Open `.env` and remove the commented live-server lines**

Find and remove these two commented lines:
```
#DB_HOST=13.201.17.184
#DB_DATABASE=compx_db
```

The active DB config must read:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=aethyrtech
DB_USERNAME=root
DB_PASSWORD=
```

Also confirm `APP_URL=http://localhost` and `APP_ENV=local` are set.

- [ ] **Step 2: Verify no active reference to live server remains**

```bash
grep -n "13.201" .env
```

Expected: no output (empty).

- [ ] **Step 3: Commit**

```bash
git add .env
git commit -m "chore: clean .env - remove live server references, confirm localhost config"
```

---

## Task 3: Import Database Dump

**Files:**
- None (MySQL operation only)

- [ ] **Step 1: Create the local database**

Open XAMPP MySQL console or run:
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS aethyrtech CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Expected: no error output.

- [ ] **Step 2: Import the live dump**

```bash
mysql -u root aethyrtech < docs/compx_db.sql
```

This may take 30–60 seconds. No output = success. If you get `ERROR 1046: No database selected`, the database wasn't created — repeat Step 1.

- [ ] **Step 3: Verify core tables exist**

```bash
mysql -u root aethyrtech -e "SHOW TABLES;"
```

Expected output includes: `admins`, `users`, `products`, `reviews`, `keywords`, `product_rankings`, `scraping_urls`, `migrations`.

- [ ] **Step 4: Check what the migrations table already tracks**

```bash
mysql -u root aethyrtech -e "SELECT migration FROM migrations ORDER BY migration;" | head -20
```

You should see the old `2014_10_12_*` and `2019_*` migrations listed. These were from the live server.

No commit needed (this is a DB operation).

---

## Task 4: Delete Duplicate Default Migrations

**Files:**
- Delete: `database/migrations/0001_01_01_000000_create_users_table.php`
- Delete: `database/migrations/0001_01_01_000001_create_cache_table.php`
- Delete: `database/migrations/0001_01_01_000002_create_jobs_table.php`

**Why:** These L12 defaults create the same tables as the old `2014_*`/`2019_*` migrations already tracked in the dump's `migrations` table. Running them would cause "Table already exists" errors.

- [ ] **Step 1: Delete the three files**

```bash
rm database/migrations/0001_01_01_000000_create_users_table.php
rm database/migrations/0001_01_01_000001_create_cache_table.php
rm database/migrations/0001_01_01_000002_create_jobs_table.php
```

- [ ] **Step 2: Confirm only application migrations remain**

```bash
ls database/migrations/ | grep "^0001"
```

Expected: no output (all `0001_*` files gone).

- [ ] **Step 3: Run migrate to apply only new application migrations**

```bash
php artisan migrate
```

Expected: migrations from `2024_*`, `2025_*`, and `2026_*` that aren't in the dump run successfully. If you see "Nothing to migrate", the dump already had all migrations — that's fine.

If any migration fails with "Table already exists", add it to the migrations table manually:
```bash
mysql -u root aethyrtech -e "INSERT INTO migrations (migration, batch) VALUES ('<migration_name>', 99);"
```
Then re-run `php artisan migrate`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "chore: remove duplicate L12 default migrations (covered by live dump)"
```

---

## Task 5: Create Missing Admin Model

**Files:**
- Create: `app/Models/Admin.php`

The `config/auth.php` defines an `admin` guard that uses `App\Models\Admin::class`, but this model doesn't exist. The `admins` table IS in the dump.

- [ ] **Step 1: Create `app/Models/Admin.php`**

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable
{
    use Notifiable, HasRoles;

    protected $guard_name = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];
}
```

- [ ] **Step 2: Verify artisan can load the model**

```bash
php artisan tinker --execute="echo App\Models\Admin::count();"
```

Expected: prints a number (0 or more), no error.

- [ ] **Step 3: Commit**

```bash
git add app/Models/Admin.php
git commit -m "feat: add Admin model for admin guard authentication"
```

---

## Task 6: Fix routes/web.php Namespace Issues

**Files:**
- Modify: `routes/web.php`

Two problems: (a) Spatie namespace has extra `s` (`Middlewares\` instead of `Middleware\`), (b) `ScrapingUrlController` is imported from `Admin\` but currently lives in the root.

- [ ] **Step 1: Fix the Spatie import lines in `routes/web.php`**

Find lines 3–4 of `routes/web.php`:
```php
use Spatie\Permission\Middlewares\RoleMiddleware;
use Spatie\Permission\Middlewares\PermissionMiddleware;
```

Replace with:
```php
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
```

- [ ] **Step 2: Verify the correct Spatie namespace exists**

```bash
ls vendor/spatie/laravel-permission/src/Middleware/
```

Expected: you see `RoleMiddleware.php`, `PermissionMiddleware.php` in that path (not `Middlewares/`).

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "fix: correct Spatie Permission middleware namespace in web routes"
```

---

## Task 7: Move ScrapingUrlController to Admin Namespace

**Files:**
- Modify: `app/Http/Controllers/ScrapingUrlController.php` (change namespace + move)
- Delete (original): `app/Http/Controllers/ScrapingUrlController.php`

The routes file imports `App\Http\Controllers\Admin\ScrapingUrlController` but the file sits at the root controller level with namespace `App\Http\Controllers`.

- [ ] **Step 1: Create `app/Http/Controllers/Admin/ScrapingUrlController.php` with updated namespace**

Copy the full content of the existing file but change only the namespace and class declaration:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScrapingUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ScrapingUrlController extends Controller
{
    public function index(Request $request)
    {
        $platform = $request->get('platform', 'all');
        $status = $request->get('status', 'all');

        $query = ScrapingUrl::query()->orderBy('created_at', 'desc');

        if ($platform !== 'all') {
            $query->where('platform', $platform);
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $urls = $query->paginate(50);

        $stats = [
            'total' => ScrapingUrl::count(),
            'pending' => ScrapingUrl::where('status', 'pending')->count(),
            'processing' => ScrapingUrl::where('status', 'processing')->count(),
            'completed' => ScrapingUrl::where('status', 'completed')->count(),
            'failed' => ScrapingUrl::where('status', 'failed')->count(),
        ];

        return view('admin.scraping-urls.index', compact('urls', 'stats', 'platform', 'status'));
    }

    public function create()
    {
        return view('admin.scraping-urls.create');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'platform' => 'required|in:amazon,flipkart,vijaysales,croma,reliancedigital,blinkit,bigbasket,zepto',
            'urls' => 'required|string',
            'priority' => 'nullable|integer|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $platform = $request->input('platform');
        $urlsText = $request->input('urls');
        $priority = $request->input('priority', 0);

        $urls = array_filter(array_map('trim', explode("\n", $urlsText)));
        $count = ScrapingUrl::addUrls($platform, $urls, $priority);

        return redirect()->route('admin.scraping-urls.index')
            ->with('success', "Added {$count} new URLs for {$platform}");
    }

    public function retry($id)
    {
        $scrapingUrl = ScrapingUrl::findOrFail($id);
        $scrapingUrl->resetForRetry();

        return redirect()->back()->with('success', 'URL reset for retry');
    }

    public function destroy($id)
    {
        $scrapingUrl = ScrapingUrl::findOrFail($id);
        $scrapingUrl->delete();

        return redirect()->back()->with('success', 'URL deleted successfully');
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return redirect()->back()->with('error', 'No URLs selected');
        }

        ScrapingUrl::whereIn('id', $ids)->delete();

        return redirect()->back()->with('success', 'Deleted ' . count($ids) . ' URLs');
    }

    public function bulkRetry(Request $request)
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return redirect()->back()->with('error', 'No URLs selected');
        }

        ScrapingUrl::whereIn('id', $ids)->update([
            'status' => 'pending',
            'error_message' => null,
        ]);

        return redirect()->back()->with('success', 'Reset ' . count($ids) . ' URLs for retry');
    }
}
```

- [ ] **Step 2: Delete the old root controller file**

```bash
rm app/Http/Controllers/ScrapingUrlController.php
```

- [ ] **Step 3: Verify no other file references the old namespace**

```bash
grep -r "Controllers\\\\ScrapingUrlController" app/ routes/ --include="*.php"
```

Expected: no output (all references now via `Admin\ScrapingUrlController`).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/ScrapingUrlController.php
git add app/Http/Controllers/ScrapingUrlController.php
git commit -m "refactor: move ScrapingUrlController into Admin namespace"
```

---

## Task 8: Create Admin Auth LoginController

**Files:**
- Create: `app/Http/Controllers/Admin/Auth/LoginController.php`

The route `admin.login` points to `LoginController@showLoginForm` and `@login`. The view `resources/views/admin/auth/login.blade.php` already exists.

- [ ] **Step 1: Create `app/Http/Controllers/Admin/Auth/` directory and `LoginController.php`**

```php
<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended(route('admin.dashboard'));
        }

        return back()->withErrors([
            'email' => 'These credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
```

- [ ] **Step 2: Verify the view path matches**

```bash
ls resources/views/admin/auth/
```

Expected: `login.blade.php` is present.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Admin/Auth/LoginController.php
git commit -m "feat: add Admin Auth LoginController with admin guard"
```

---

## Task 9: Create AdminUserController

**Files:**
- Create: `app/Http/Controllers/Admin/AdminUserController.php`

Routes define: `index`, `create`, `store`, `edit`, `update`, `destroy`. Views already exist at `resources/views/admin/users/`.

- [ ] **Step 1: Create `app/Http/Controllers/Admin/AdminUserController.php`**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserController extends Controller
{
    public function index()
    {
        $users = Admin::with('roles')->paginate(20);
        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        $roles = Role::where('guard_name', 'admin')->get();
        return view('admin.users.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admins,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|exists:roles,name',
        ]);

        $admin = Admin::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $admin->assignRole($validated['role']);

        return redirect()->route('admin.users.index')
            ->with('success', 'Admin user created successfully.');
    }

    public function edit(Admin $user)
    {
        $roles = Role::where('guard_name', 'admin')->get();
        return view('admin.users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, Admin $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admins,email,' . $user->id,
            'password' => 'nullable|string|min:8|confirmed',
            'role' => 'required|exists:roles,name',
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            ...(isset($validated['password']) ? ['password' => Hash::make($validated['password'])] : []),
        ]);

        $user->syncRoles([$validated['role']]);

        return redirect()->route('admin.users.index')
            ->with('success', 'Admin user updated successfully.');
    }

    public function destroy(Admin $user)
    {
        $user->delete();
        return redirect()->route('admin.users.index')
            ->with('success', 'Admin user deleted successfully.');
    }
}
```

- [ ] **Step 2: Verify user views exist**

```bash
ls resources/views/admin/users/
```

Expected: `index.blade.php`, `create.blade.php`, `edit.blade.php`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Admin/AdminUserController.php
git commit -m "feat: add AdminUserController with CRUD for admin users"
```

---

## Task 10: Create RoleController

**Files:**
- Create: `app/Http/Controllers/Admin/RoleController.php`

Route `admin.roles` is a resource route. Views exist at `resources/views/admin/roles/`.

- [ ] **Step 1: Create `app/Http/Controllers/Admin/RoleController.php`**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::where('guard_name', 'admin')->with('permissions')->paginate(20);
        return view('admin.roles.index', compact('roles'));
    }

    public function create()
    {
        $permissions = Permission::where('guard_name', 'admin')->get()->groupBy(function ($p) {
            return explode(' ', $p->name)[1] ?? 'general';
        });
        return view('admin.roles.create', compact('permissions'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'admin']);

        if (!empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return redirect()->route('admin.roles.index')
            ->with('success', 'Role created successfully.');
    }

    public function show(Role $role)
    {
        $role->load('permissions');
        return view('admin.roles.show', compact('role'));
    }

    public function edit(Role $role)
    {
        $permissions = Permission::where('guard_name', 'admin')->get()->groupBy(function ($p) {
            return explode(' ', $p->name)[1] ?? 'general';
        });
        $rolePermissions = $role->permissions->pluck('name')->toArray();
        return view('admin.roles.edit', compact('role', 'permissions', 'rolePermissions'));
    }

    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role->update(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions'] ?? []);

        return redirect()->route('admin.roles.index')
            ->with('success', 'Role updated successfully.');
    }

    public function destroy(Role $role)
    {
        $role->delete();
        return redirect()->route('admin.roles.index')
            ->with('success', 'Role deleted successfully.');
    }
}
```

- [ ] **Step 2: Verify role views exist**

```bash
ls resources/views/admin/roles/
```

Expected: `index.blade.php`, `create.blade.php`, `edit.blade.php`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Admin/RoleController.php
git commit -m "feat: add RoleController with CRUD for Spatie roles/permissions"
```

---

## Task 11: Create ExportController

**Files:**
- Create: `app/Http/Controllers/Admin/ExportController.php`

Route: `GET admin/export/{module}` → `ExportController@export`. No view needed — it streams a file download.

- [ ] **Step 1: Create `app/Http/Controllers/Admin/ExportController.php`**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Keyword;
use App\Models\Product;
use App\Models\ProductRanking;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExportController extends Controller
{
    public function export(string $module)
    {
        $allowed = ['keywords', 'products', 'reviews', 'rankings'];

        if (!in_array($module, $allowed)) {
            abort(404, 'Unknown export module');
        }

        $data = match ($module) {
            'keywords' => Keyword::all(),
            'products' => Product::all(),
            'reviews'  => Review::all(),
            'rankings' => ProductRanking::all(),
        };

        $filename = $module . '_export_' . now()->format('Y-m-d_His') . '.json';

        return response()->json($data)
            ->header('Content-Disposition', "attachment; filename={$filename}");
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Admin/ExportController.php
git commit -m "feat: add ExportController stub for keyword/product/review/ranking exports"
```

---

## Task 12: Create ComparisonController

**Files:**
- Create: `app/Http/Controllers/Admin/ComparisonController.php`

Routes: `GET admin/comparison` → `index`, `POST admin/comparison/compare` → `compare`. View at `resources/views/admin/comparison/`.

- [ ] **Step 1: Create `app/Http/Controllers/Admin/ComparisonController.php`**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ComparisonController extends Controller
{
    public function index()
    {
        return view('admin.comparison.index');
    }

    public function compare(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array|min:2|max:4',
            'product_ids.*' => 'exists:products,id',
        ]);

        $products = Product::whereIn('id', $validated['product_ids'])->get();

        return view('admin.comparison.index', compact('products'));
    }
}
```

- [ ] **Step 2: Verify comparison view exists**

```bash
ls resources/views/admin/comparison/
```

Expected: `index.blade.php` present.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Admin/ComparisonController.php
git commit -m "feat: add ComparisonController stub for product comparison"
```

---

## Task 13: Delete Dead Files (After Verification)

**Files to delete:**
- `app/Console/Commands/CleanupCommand.php_old`
- `app/Console/Commands/ScrapeCommand.php_old`
- `app/Console/Commands/StatusCommand.php_old`
- `app/Http/Controllers/Admin/ScraperConfigurationController.php`
- `app/Http/Controllers/DashboardController.php` (root level, references deleted views `dashboard.*`)

**Verification before deletion:**

- [ ] **Step 1: Verify new CleanupCommand is complete (has `handle`, `cleanupLogs`, `cleanupInactiveProducts`, `showStatistics` methods)**

```bash
grep -n "function " app/Console/Commands/CleanupCommand.php
```

Expected output:
```
public function __construct(DatabaseService $databaseService)
public function handle(): int
protected function cleanupLogs(int $retentionDays, bool $dryRun): void
protected function cleanupInactiveProducts(int $retentionDays, bool $dryRun): void
protected function showStatistics(): void
```

- [ ] **Step 2: Verify new ScrapeCommand is complete (has `handle` method and platform logic)**

```bash
grep -n "function " app/Console/Commands/ScrapeCommand.php
```

Expected: `handle()` method present.

- [ ] **Step 3: Verify new StatusCommand is complete**

```bash
grep -n "function " app/Console/Commands/StatusCommand.php
```

Expected: `handle()` method present.

- [ ] **Step 4: Confirm ScraperConfigurationController has no route**

```bash
grep -r "ScraperConfigurationController" routes/ --include="*.php"
```

Expected: no output.

- [ ] **Step 5: Confirm root DashboardController is not in any route**

```bash
grep -r "Controllers\\\\DashboardController" routes/ --include="*.php"
```

Expected: no output (routes only reference `Admin\DashboardController`).

- [ ] **Step 6: Delete all dead files**

```bash
rm app/Console/Commands/CleanupCommand.php_old
rm app/Console/Commands/ScrapeCommand.php_old
rm app/Console/Commands/StatusCommand.php_old
rm app/Http/Controllers/Admin/ScraperConfigurationController.php
rm app/Http/Controllers/DashboardController.php
```

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore: remove dead .php_old files, ScraperConfigurationController, and root DashboardController"
```

---

## Task 14: Final Verification

- [ ] **Step 1: Check artisan runs cleanly**

```bash
php artisan --version
```

Expected: `Laravel Framework 12.x.x`

- [ ] **Step 2: Check route list loads without errors**

```bash
php artisan route:list --columns=method,uri,name,action 2>&1 | head -40
```

Expected: table of routes with no "Class ... does not exist" errors.

- [ ] **Step 3: Cache config and routes for speed**

```bash
php artisan config:cache
php artisan route:cache
```

Both should complete without errors.

- [ ] **Step 4: Check migrate status**

```bash
php artisan migrate:status
```

Expected: all migrations show `Ran` status (no `Pending` migrations that don't exist on disk).

- [ ] **Step 5: Test login page in browser**

Open `http://localhost/laptop-scraper/public/admin/login`

Expected: AdminLTE login form loads. No 500 error, no "Class not found" error.

- [ ] **Step 6: Test a scraper CLI command**

```bash
php artisan scraper:status
```

Expected: outputs status without crashing.

- [ ] **Step 7: Final commit**

```bash
git add -A
git commit -m "chore: final cache and route verification for localhost migration"
```

---

## Summary of Commits

| Task | Commit message |
|------|---------------|
| 1 | `chore: composer install - upgrade vendor to Laravel 12.47` |
| 2 | `chore: clean .env - remove live server references` |
| 4 | `chore: remove duplicate L12 default migrations` |
| 5 | `feat: add Admin model for admin guard authentication` |
| 6 | `fix: correct Spatie Permission middleware namespace in web routes` |
| 7 | `refactor: move ScrapingUrlController into Admin namespace` |
| 8 | `feat: add Admin Auth LoginController with admin guard` |
| 9 | `feat: add AdminUserController with CRUD for admin users` |
| 10 | `feat: add RoleController with CRUD for Spatie roles/permissions` |
| 11 | `feat: add ExportController stub for exports` |
| 12 | `feat: add ComparisonController stub for product comparison` |
| 13 | `chore: remove dead files` |
| 14 | `chore: final verification` |
