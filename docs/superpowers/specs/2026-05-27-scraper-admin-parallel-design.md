# Scraper Admin URL Management + Parallel Execution + Auto scraper_id — Design Spec

**Date:** 2026-05-27
**Status:** Approved
**Scope:** Three interconnected improvements to the scraper system

---

## 1. Goals

- **Feature A:** Admin UI to manage scraper category URLs from the database (add, edit, delete, toggle status) instead of hardcoded `config/scraper.php`
- **Feature B:** Auto-generate a sequential `scraper_id` per batch run — shared across all platforms in one run, incrementing each batch
- **Feature C:** Run all platforms in parallel when `scraper:run all` or `scraper:rankings all` is called — one subprocess per platform, same batch `scraper_id`

---

## 2. Architecture

### 2.1 What Already Exists (no changes needed)

- `scraper_configurations` table — already migrated (platform, category, category_url, status, last_run_at, last_scraper_id)
- `ScraperConfiguration` model — scopes, relationships, `getStatistics()` all ready
- `ScraperConfigurationController` — full CRUD + `toggleStatus()` + `run()` — only missing routes and views
- `ScraperRun::generateScraperId()` — exists but generates random IDs; we replace its usage with a sequential counter

### 2.2 What Changes

```
Feature A: ScrapeCommand reads category_urls from scraper_configurations (active) instead of config
Feature B: ScrapeCommand / ScrapeRankingsCommand derive next scraper_id as MAX(products.scraper_id) + 1
Feature C: scrapeAllPlatforms() spawns proc_open subprocess per platform; all share the same scraper_id
```

### 2.3 scraper_id Flow

```
ScrapeCommand::handle() (platform = 'all')
  → getNextScraperId()                          // SELECT MAX(scraper_id) + 1 FROM products
  → scrapeAllPlatforms($scraperId)
    → foreach platform:
        proc_open("php artisan scraper:run {platform} --scraper-id={id}")
    → proc_close all handles (wait)

ScrapeCommand::handle() (platform = 'amazon')
  → getNextScraperId()                          // same logic
  → scrapePlatform('amazon', $scraperId)
    → createScraper('amazon') → $scraper->setScraperId($id)
    → getCategoryUrls('amazon')                 // DB: scraper_configurations where status=active
    → $scraper->scrape($urls)
      → saveProductData() injects $this->scraperId into product data

ScrapeRankingsCommand::handle() (platform = 'all')
  → getNextRankingsScraperId()                  // SELECT MAX(scraper_id) + 1 FROM product_rankings
  → foreach platform: proc_open("php artisan scraper:rankings {platform} --scraper-id={id}")
  → proc_close all handles

ScrapeRankingsCommand::handle() (platform = 'amazon')
  → receives --scraper-id option OR derives own
  → $scraper->setScraperId($id)
  → $scraper->scrapeRankings()
    → saveRanking() uses $this->scraperId
```

### 2.4 Admin URL Management Flow

```
Admin visits /admin/scraper-config
  → ScraperConfigurationController::index()
  → Lists all configurations, filterable by platform/category/status

Admin adds URL → store() → ScraperConfiguration::create()
Admin edits URL → update() → $configuration->update()
Admin toggles status → toggleStatus() → flips active ↔ inactive
Admin clicks "Run" on a config → run()
  → ScraperRun::create() with generated scraper_id
  → Artisan::call('scraper:run', ['--scraper-id' => $id, '--category-url' => $url])
  → ScrapeCommand handles single-URL run
```

---

## 3. Feature A — Admin URL Management

### 3.1 Routes (`routes/admin.php`)

Add inside the `admin.` prefix group:

```php
use App\Http\Controllers\Admin\ScraperConfigurationController;

Route::prefix('scraper-config')->name('scraper-config.')->group(function () {
    Route::get('/', [ScraperConfigurationController::class, 'index'])->name('index');
    Route::get('/create', [ScraperConfigurationController::class, 'create'])->name('create');
    Route::post('/', [ScraperConfigurationController::class, 'store'])->name('store');
    Route::get('/{configuration}', [ScraperConfigurationController::class, 'show'])->name('show');
    Route::get('/{configuration}/edit', [ScraperConfigurationController::class, 'edit'])->name('edit');
    Route::put('/{configuration}', [ScraperConfigurationController::class, 'update'])->name('update');
    Route::delete('/{configuration}', [ScraperConfigurationController::class, 'destroy'])->name('destroy');
    Route::post('/{configuration}/run', [ScraperConfigurationController::class, 'run'])->name('run');
    Route::post('/{configuration}/toggle-status', [ScraperConfigurationController::class, 'toggleStatus'])->name('toggle-status');
});
```

### 3.2 Controller Fix (`ScraperConfigurationController`)

Update the `$platforms` array in `create()` and `edit()` to include `amazon_jp`:

```php
$platforms = ['amazon', 'amazon_jp', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket', 'zepto'];
```

### 3.3 Views (4 new Blade files under `resources/views/admin/scraper-config/`)

All extend `layouts.admin` and use Bootstrap 5 + Font Awesome 6 (same as existing admin views).

**`index.blade.php`**
- Filter bar: platform dropdown, category text, status select, tag text — GET filters
- Table columns: Platform, Category, Tag, URL (truncated), Status badge, Last Run, Total Runs, Actions
- Actions per row: Edit button, Run button (POST form), Toggle Status button (POST form), Delete button (DELETE form with confirm)
- Status badge colors: active=green, inactive=gray, paused=yellow
- Pagination

**`create.blade.php`**
- Form fields: Platform (select from `$platforms`), Category (text), Tag (optional text), URL (url input), Description (textarea), Status (select: active/inactive/paused)
- Submit → POST `/admin/scraper-config`

**`edit.blade.php`**
- Same fields pre-filled from `$configuration`
- Submit → PUT `/admin/scraper-config/{configuration}`

**`show.blade.php`**
- Config details card (platform, category, URL, status, total runs, last run)
- Statistics card (from `$statistics`: successful runs, failed runs, total products, avg duration)
- Last 10 runs table (scraper_id, status, products scraped, duration, triggered at)
- Run button (POST form) and Edit link

### 3.4 `ScrapeCommand` — getCategoryUrls() change

```php
// Add import
use App\Models\ScraperConfiguration;

protected function getCategoryUrls(string $platform): array
{
    // If a specific URL was passed via --category-url option, use only that
    if ($categoryUrl = $this->option('category-url')) {
        return [$categoryUrl];
    }

    return ScraperConfiguration::where('platform', $platform)
        ->where('status', 'active')
        ->pluck('category_url')
        ->toArray();
}
```

### 3.5 `ScrapeCommand` — add new options to signature

```php
protected $signature = 'scraper:run
                        {platform? : Platform to scrape (amazon, amazon_jp, flipkart, vijaysales, reliancedigital, croma, blinkit, bigbasket, meesho, zepto, all)}
                        {--force : Force scraping even if recently scraped}
                        {--limit=70 : Limit number of products per platform}
                        {--timeout=83200 : Maximum execution time in seconds}
                        {--scraper-id= : Scraper batch ID (auto-generated if not provided)}
                        {--category-url= : Run a single specific URL instead of all active DB URLs}';
```

### 3.6 Seeder (`database/seeders/ScraperConfigurationSeeder.php`)

Reads every URL from `config('scraper.platforms.*.category_urls')`, creates one `ScraperConfiguration` row per URL. Idempotent: skips if `platform + category_url` already exists.

Category name is inferred from the URL query string:
- `k=printer` → "printer"
- `k=smartphone` → "smartphone"
- `k=diaper` → "diaper"
- `k=baby+wipes` → "baby wipes"
- `k=laundry+detergent` → "laundry detergent"
- Fallback: `k={value}` → use value; no `k` param → "general"

```php
<?php

namespace Database\Seeders;

use App\Models\ScraperConfiguration;
use Illuminate\Database\Seeder;

class ScraperConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $platforms = config('scraper.platforms', []);

        foreach ($platforms as $platformKey => $platformConfig) {
            $urls = $platformConfig['category_urls'] ?? [];

            foreach ($urls as $url) {
                $category = $this->inferCategory($url);

                ScraperConfiguration::firstOrCreate(
                    ['platform' => $platformKey, 'category_url' => $url],
                    ['category' => $category, 'status' => 'active']
                );
            }
        }
    }

    private function inferCategory(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $k = $params['k'] ?? null;
        return $k ? urldecode(str_replace('+', ' ', $k)) : 'general';
    }
}
```

Register in `DatabaseSeeder::run()`:
```php
$this->call(ScraperConfigurationSeeder::class);
```

---

## 4. Feature B — Auto scraper_id

### 4.1 `BaseScraper` — add scraperId property

```php
protected string $scraperId = '';

public function setScraperId(string $id): void
{
    $this->scraperId = $id;
}
```

In `saveProductData()`, inject before sanitization:
```php
$productData['scraper_id'] = $this->scraperId ?: null;
```

### 4.2 `AmazonRankingScraper` — add scraperId property

```php
protected string $scraperId = '';

public function setScraperId(string $id): void
{
    $this->scraperId = $id;
}
```

In `saveRanking()`, replace `'scraper_id' => "1000000044"` with:
```php
'scraper_id' => $this->scraperId ?: null,
```

### 4.3 `FlipkartRankingScraper` — same as AmazonRankingScraper

Same property, same setter, same replacement in `saveRanking()`.

### 4.4 `DataSanitizer` — remove hardcoded fallback

Change line 20:
```php
// Before:
$sanitized['scraper_id'] = self::sanitizeString($data['scraper_id'] ?? '1000000044');

// After:
$sanitized['scraper_id'] = self::sanitizeString($data['scraper_id'] ?? null);
```

### 4.5 `ScrapeCommand` — getNextScraperId()

```php
use App\Models\Product;

protected function getNextScraperId(): string
{
    // Accept passed --scraper-id option (used when called as subprocess)
    if ($id = $this->option('scraper-id')) {
        return $id;
    }

    $max = Product::max('scraper_id');
    $next = $max ? ((int) $max) + 1 : 1000000044;
    return (string) $next;
}
```

Called once per `handle()` invocation, stored in a local variable, passed to `scrapePlatform()` and forwarded to each subprocess.

### 4.6 `ScrapeRankingsCommand` — getNextScraperId()

Both commands derive from `Product::max('scraper_id')` so products and rankings scraped on the same Monday share the same batch ID. If products run first that day, rankings see the same max and reuse it. If either runs first, they both arrive at the same incremented value.

```php
use App\Models\Product;

protected function getNextScraperId(): string
{
    if ($id = $this->option('scraper-id')) {
        return $id;
    }

    $max = Product::max('scraper_id');
    $next = $max ? ((int) $max) + 1 : 1000000044;
    return (string) $next;
}
```

Add `{--scraper-id=}` to `ScrapeRankingsCommand::$signature`.

---

## 5. Feature C — Parallel Execution

### 5.1 `ScrapeCommand::scrapeAllPlatforms()`

```php
protected function scrapeAllPlatforms(bool $force, string $scraperId): void
{
    $platforms = array_keys(config('scraper.platforms', []));
    $this->info("Launching " . count($platforms) . " platform scrapers in parallel (scraper_id: {$scraperId})...");

    $processes = [];
    foreach ($platforms as $platform) {
        $cmd = PHP_BINARY . ' ' . base_path('artisan') . ' scraper:run ' . $platform
            . ' --scraper-id=' . $scraperId
            . ($force ? ' --force' : '');

        $processes[$platform] = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->info("Started: {$platform}");
    }

    foreach ($processes as $platform => $process) {
        if (is_resource($process)) {
            proc_close($process);
        }
    }

    $this->info("All platforms complete.");
}
```

### 5.2 `ScrapeRankingsCommand` — parallel for `all`

Replace the sequential if-blocks with a subprocess launcher when `platform === 'all'`:

```php
if ($platform === 'all') {
    $scraperId = $this->getNextScraperId();
    $rankingPlatforms = ['amazon', 'amazon_jp', 'flipkart', 'vijaysales'];
    $processes = [];

    foreach ($rankingPlatforms as $p) {
        $cmd = PHP_BINARY . ' ' . base_path('artisan') . ' scraper:rankings ' . $p
            . ' --scraper-id=' . $scraperId;
        $processes[$p] = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->info("Started rankings: {$p}");
    }

    foreach ($processes as $p => $process) {
        if (is_resource($process)) {
            proc_close($process);
        }
    }
    return Command::SUCCESS;
}
```

Single-platform runs keep the existing synchronous scraper instantiation.

---

## 6. Files Changed

| File | Change Type | Risk |
|---|---|---|
| `routes/admin.php` | Add route group | None |
| `resources/views/admin/scraper-config/index.blade.php` | New | None |
| `resources/views/admin/scraper-config/create.blade.php` | New | None |
| `resources/views/admin/scraper-config/edit.blade.php` | New | None |
| `resources/views/admin/scraper-config/show.blade.php` | New | None |
| `app/Http/Controllers/Admin/ScraperConfigurationController.php` | Add `amazon_jp` to platforms array | None |
| `database/seeders/ScraperConfigurationSeeder.php` | New | None |
| `database/seeders/DatabaseSeeder.php` | Register seeder | None |
| `app/Console/Commands/ScrapeCommand.php` | Add options, change getCategoryUrls, parallel scrapeAllPlatforms, getNextScraperId | Medium |
| `app/Console/Commands/ScrapeRankingsCommand.php` | Add `--scraper-id` option, parallel `all` run, getNextScraperId | Medium |
| `app/Services/Scrapers/BaseScraper.php` | Add `$scraperId` property + setter, inject into saveProductData | Low |
| `app/Services/Scrapers/AmazonRankingScraper.php` | Add `$scraperId` property + setter, remove hardcoded ID | Low |
| `app/Services/Scrapers/FlipkartRankingScraper.php` | Add `$scraperId` property + setter, remove hardcoded ID | Low |
| `app/Services/DataSanitizer.php` | Remove hardcoded `'1000000044'` fallback | Low |

---

## 7. Out of Scope

- Queue-based parallel execution (future upgrade path from proc_open)
- VijaySalesRankingScraper and other ranking scrapers beyond Amazon/Amazon JP/Flipkart (same pattern applies but not added here)
- Admin authentication / authorization on new routes (follows existing pattern — no auth middleware currently on admin routes)
- Migrating `scraper:run` to also accept `--keyword-ids` style filtering
