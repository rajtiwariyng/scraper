# Scraper Admin URL Management + Parallel Execution + Auto scraper_id — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the existing `scraper_configurations` DB table into the scraper pipeline (replacing hardcoded config URLs), auto-generate sequential batch scraper IDs, and run all platforms in parallel via subprocess.

**Architecture:** Three layered changes: (1) BaseScraper and ranking scrapers get a `$scraperId` property injected by the command layer; (2) `ScrapeCommand`/`ScrapeRankingsCommand` derive the next batch ID from `MAX(products.scraper_id)+1` and pass it to all platform subprocesses; (3) `scrapeAllPlatforms()` spawns one `proc_open` subprocess per platform instead of looping sequentially. `ScraperConfigurationController` (already built) gets routes and Blade views wired up.

**Tech Stack:** Laravel 10, PHP 8.1, Bootstrap 5, PHPUnit, Eloquent ORM, `proc_open` for subprocesses

---

## File Map

| File | Change |
|---|---|
| `app/Services/DataSanitizer.php` | Remove hardcoded `'1000000044'` fallback |
| `app/Services/Scrapers/BaseScraper.php` | Add `$scraperId` property, `setScraperId()`, `getScraperId()`, inject in `saveProductData()` |
| `app/Services/Scrapers/AmazonRankingScraper.php` | Add `$scraperId`, `setScraperId()`, replace hardcoded in `saveRanking()` |
| `app/Services/Scrapers/FlipkartRankingScraper.php` | Same as AmazonRankingScraper |
| `app/Services/Scrapers/VijaySalesRankingScraper.php` | Add `$scraperId`, `setScraperId()` only (hardcoded in saveRanking is out of scope) |
| `app/Console/Commands/ScrapeCommand.php` | Add `--scraper-id`/`--category-url` options, `getNextScraperId()`, `getCategoryUrls()` from DB, `scrapePlatform()` calls `setScraperId()`, `scrapeAllPlatforms()` uses `proc_open` |
| `app/Console/Commands/ScrapeRankingsCommand.php` | Add `--scraper-id` option, `getNextScraperId()`, `setScraperId()` on each scraper, `scrapeAllRankingPlatformsInParallel()` |
| `routes/admin.php` | Add `scraper-config` resource route group |
| `app/Http/Controllers/Admin/ScraperConfigurationController.php` | Add `amazon_jp` to `$platforms` array in `create()` and `edit()` |
| `database/seeders/ScraperConfigurationSeeder.php` | New: seeds from `config/scraper.platforms.*.category_urls` |
| `database/seeders/DatabaseSeeder.php` | Register `ScraperConfigurationSeeder` |
| `resources/views/admin/scraper-config/index.blade.php` | New: list with filters, toggle, run, delete |
| `resources/views/admin/scraper-config/create.blade.php` | New: add URL form |
| `resources/views/admin/scraper-config/edit.blade.php` | New: edit URL form |
| `resources/views/admin/scraper-config/show.blade.php` | New: config detail + runs history |
| `tests/Unit/DataSanitizerTest.php` | Add scraper_id null test |
| `tests/Unit/AmazonJpScraperTest.php` | Add setScraperId/getScraperId test |
| `tests/Unit/AmazonJpRankingScraperTest.php` | Add setScraperId inheritance test |

---

## Task 1: DataSanitizer — remove hardcoded scraper_id fallback

**Files:**
- Modify: `app/Services/DataSanitizer.php:20`
- Modify: `tests/Unit/DataSanitizerTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/DataSanitizerTest.php` after the last existing test:

```php
public function test_sanitize_product_data_scraper_id_is_absent_when_not_in_input(): void
{
    // When scraper_id is not provided, it should not appear in output
    // (array_filter in DataSanitizer removes null values)
    $data = [
        'platform' => 'amazon',
        'sku' => 'B00TEST123',
        'title' => 'Test Product',
    ];
    $result = DataSanitizer::sanitizeProductData($data);
    $this->assertArrayNotHasKey('scraper_id', $result);
}

public function test_sanitize_product_data_scraper_id_is_preserved_when_provided(): void
{
    $data = [
        'platform' => 'amazon',
        'sku' => 'B00TEST123',
        'title' => 'Test Product',
        'scraper_id' => '1000000045',
    ];
    $result = DataSanitizer::sanitizeProductData($data);
    $this->assertEquals('1000000045', $result['scraper_id']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/DataSanitizerTest.php -v
```

Expected: `test_sanitize_product_data_scraper_id_is_absent_when_not_in_input` FAILS because the current fallback `?? '1000000044'` always produces a value.

- [ ] **Step 3: Change the fallback in DataSanitizer**

In `app/Services/DataSanitizer.php` at line 20, change:

```php
// Before:
$sanitized['scraper_id'] = self::sanitizeString($data['scraper_id'] ?? '1000000044');

// After:
$sanitized['scraper_id'] = self::sanitizeString($data['scraper_id'] ?? null);
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Unit/DataSanitizerTest.php -v
```

Expected: all 8 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DataSanitizer.php tests/Unit/DataSanitizerTest.php
git commit -m "feat: remove hardcoded scraper_id fallback from DataSanitizer"
```

---

## Task 2: BaseScraper — add scraperId property + inject into saveProductData

**Files:**
- Modify: `app/Services/Scrapers/BaseScraper.php:19` (property) and `:509` (saveProductData)
- Modify: `tests/Unit/AmazonJpScraperTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/AmazonJpScraperTest.php`:

```php
public function test_set_scraper_id_stores_and_retrieves_the_id(): void
{
    $scraper = new AmazonJpScraper();
    $scraper->setScraperId('1000000045');
    $this->assertEquals('1000000045', $scraper->getScraperId());
}

public function test_scraper_id_defaults_to_empty_string(): void
{
    $scraper = new AmazonJpScraper();
    $this->assertEquals('', $scraper->getScraperId());
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/AmazonJpScraperTest.php -v
```

Expected: both new tests FAIL with "Call to undefined method".

- [ ] **Step 3: Add scraperId property, getter, and setter to BaseScraper**

In `app/Services/Scrapers/BaseScraper.php`, add after line 19 (`protected string $platform;`):

```php
protected string $scraperId = '';
```

Add the following two public methods anywhere in the class (e.g., after `getStats()`):

```php
public function setScraperId(string $id): void
{
    $this->scraperId = $id;
}

public function getScraperId(): string
{
    return $this->scraperId;
}
```

- [ ] **Step 4: Inject scraperId into saveProductData**

In `app/Services/Scrapers/BaseScraper.php`, find `saveProductData()` at line ~509. Change:

```php
protected function saveProductData(array $productData): void
{
    $productData['platform'] = $this->platform;
    $productData['scraped_date'] = now();

    // Add this block:
    if ($this->scraperId !== '') {
        $productData['scraper_id'] = $this->scraperId;
    }

    $existingProduct = Product::findByPlatformAndSku($this->platform, $productData['sku']);
    // ... rest of method unchanged
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test tests/Unit/AmazonJpScraperTest.php -v
```

Expected: all 6 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Scrapers/BaseScraper.php tests/Unit/AmazonJpScraperTest.php
git commit -m "feat: add scraperId property to BaseScraper, inject into saveProductData"
```

---

## Task 3: Ranking scrapers — scraperId property + replace hardcoded value

**Files:**
- Modify: `app/Services/Scrapers/AmazonRankingScraper.php:17` (property) and `:442` (saveRanking)
- Modify: `app/Services/Scrapers/FlipkartRankingScraper.php:17` (property) and `:278` (saveRanking)
- Modify: `app/Services/Scrapers/VijaySalesRankingScraper.php` (property + setter only)
- Modify: `tests/Unit/AmazonJpRankingScraperTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/AmazonJpRankingScraperTest.php`:

```php
public function test_set_scraper_id_is_inherited_from_amazon_ranking_scraper(): void
{
    // AmazonJpRankingScraper extends AmazonRankingScraper
    // After Task 3, AmazonRankingScraper will have setScraperId/getScraperId
    // AmazonJpRankingScraper inherits both
    $scraper = new AmazonJpRankingScraper();
    $scraper->setScraperId('1000000045');
    $this->assertEquals('1000000045', $scraper->getScraperId());
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/AmazonJpRankingScraperTest.php -v
```

Expected: new test FAILS with "Call to undefined method".

- [ ] **Step 3: Add scraperId to AmazonRankingScraper**

In `app/Services/Scrapers/AmazonRankingScraper.php`, add after the `protected string $platform = 'amazon';` line:

```php
protected string $scraperId = '';

public function setScraperId(string $id): void
{
    $this->scraperId = $id;
}

public function getScraperId(): string
{
    return $this->scraperId;
}
```

In `saveRanking()` at line ~442, replace:

```php
// Before:
'scraper_id' => "1000000044",

// After:
'scraper_id' => $this->scraperId ?: null,
```

- [ ] **Step 4: Add scraperId to FlipkartRankingScraper**

In `app/Services/Scrapers/FlipkartRankingScraper.php`, add after `protected string $platform = 'flipkart';` line:

```php
protected string $scraperId = '';

public function setScraperId(string $id): void
{
    $this->scraperId = $id;
}

public function getScraperId(): string
{
    return $this->scraperId;
}
```

In `saveRanking()` at line ~278, replace:

```php
// Before:
'scraper_id' =>"1000000044",

// After:
'scraper_id' => $this->scraperId ?: null,
```

- [ ] **Step 5: Add scraperId setter to VijaySalesRankingScraper**

In `app/Services/Scrapers/VijaySalesRankingScraper.php`, add after the `protected string $platform` line (find it with grep):

```php
protected string $scraperId = '';

public function setScraperId(string $id): void
{
    $this->scraperId = $id;
}

public function getScraperId(): string
{
    return $this->scraperId;
}
```

Note: Do NOT change `saveRanking()` in VijaySalesRankingScraper — its hardcoded scraper_id is out of scope.

- [ ] **Step 6: Run all unit tests**

```bash
php artisan test --testsuite=Unit -v
```

Expected: all tests PASS (including the new AmazonJpRankingScraperTest).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Scrapers/AmazonRankingScraper.php app/Services/Scrapers/FlipkartRankingScraper.php app/Services/Scrapers/VijaySalesRankingScraper.php tests/Unit/AmazonJpRankingScraperTest.php
git commit -m "feat: add scraperId to ranking scrapers, replace hardcoded 1000000044"
```

---

## Task 4: ScrapeCommand — DB category URLs + scraper_id options

**Files:**
- Modify: `app/Console/Commands/ScrapeCommand.php`

- [ ] **Step 1: Add two new options to the `$signature`**

In `app/Console/Commands/ScrapeCommand.php`, find `protected $signature` and add two options at the end of the existing options:

```php
protected $signature = 'scraper:run 
                        {platform? : Platform to scrape (amazon, amazon_jp, flipkart, vijaysales, reliancedigital, croma, blinkit, bigbasket, meesho, zepto, all)}
                        {--force : Force scraping even if recently scraped}
                        {--limit=70 : Limit number of products per platform}
                        {--timeout=83200 : Maximum execution time in seconds}
                        {--scraper-id= : Scraper batch ID (auto-generated if not provided)}
                        {--category-url= : Run a single specific URL instead of all active DB URLs}';
```

- [ ] **Step 2: Add imports and getNextScraperId method**

Add these two imports at the top of the file (after the existing `use` statements):

```php
use App\Models\Product;
use App\Models\ScraperConfiguration;
```

Add this method to the class body (e.g., after `createScraper()`):

```php
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

- [ ] **Step 3: Replace getCategoryUrls to read from DB**

Replace the existing `getCategoryUrls()` method entirely:

```php
protected function getCategoryUrls(string $platform): array
{
    if ($categoryUrl = $this->option('category-url')) {
        return [$categoryUrl];
    }

    return ScraperConfiguration::where('platform', $platform)
        ->where('status', 'active')
        ->pluck('category_url')
        ->toArray();
}
```

- [ ] **Step 4: Generate scraperId in handle() and pass to scrapePlatform()**

In `handle()`, add `$scraperId = $this->getNextScraperId();` after the existing variable assignments at the top:

```php
public function handle(): int
{
    $platform = $this->argument('platform') ?? 'all';
    $force = $this->option('force');
    $timeout = (int) $this->option('timeout');
    $scraperId = $this->getNextScraperId();   // ADD THIS LINE

    // ...
    if ($platform === 'all') {
        $this->scrapeAllPlatforms($force, $scraperId);   // pass $scraperId
    } else {
        $this->scrapePlatform($platform, $force, $scraperId);   // pass $scraperId
    }
```

- [ ] **Step 5: Update scrapePlatform() to accept and use scraperId**

Change the `scrapePlatform()` signature and add `setScraperId()` call after scraper creation:

```php
protected function scrapePlatform(string $platform, bool $force, string $scraperId = '', bool $showProgress = true): void
{
    if (!$force && $this->wasRecentlyScraped($platform)) {
        $this->warn("Platform {$platform} was recently scraped. Use --force to override.");
        return;
    }

    $scraper = $this->createScraper($platform);
    if (!$scraper) {
        throw new \InvalidArgumentException("Unknown platform: {$platform}");
    }

    if ($scraperId !== '') {
        $scraper->setScraperId($scraperId);   // ADD THIS
    }

    $categoryUrls = $this->getCategoryUrls($platform);
    if (empty($categoryUrls)) {
        $this->warn("No active category URLs found for platform: {$platform}. Add URLs at /admin/scraper-config");
        return;
    }

    if ($showProgress) {
        $this->info("Scraping {$platform} (scraper_id: {$scraperId})...");
    }

    $scraper->scrape($categoryUrls);

    if ($showProgress) {
        $this->info("Completed scraping {$platform}");
    }
}
```

- [ ] **Step 6: Run the full unit test suite**

```bash
php artisan test --testsuite=Unit -v
```

Expected: all tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/ScrapeCommand.php
git commit -m "feat: ScrapeCommand reads category URLs from DB, generates batch scraper_id"
```

---

## Task 5: ScrapeCommand — parallel scrapeAllPlatforms via proc_open

**Files:**
- Modify: `app/Console/Commands/ScrapeCommand.php`

- [ ] **Step 1: Replace scrapeAllPlatforms() with proc_open version**

> Note: Task 4 updated `handle()` to call `scrapeAllPlatforms($force, $scraperId)` with two arguments. This step updates the method signature to match AND replaces the body. Replace the entire `scrapeAllPlatforms()` method:

```php
protected function scrapeAllPlatforms(bool $force, string $scraperId): void
{
    $platforms = array_keys(config('scraper.platforms', []));

    $this->info("Launching " . count($platforms) . " platform scrapers in parallel (scraper_id: {$scraperId})...");

    $processes = [];
    foreach ($platforms as $platform) {
        $cmd = PHP_BINARY . ' ' . base_path('artisan') . ' scraper:run ' . escapeshellarg($platform)
            . ' --scraper-id=' . escapeshellarg($scraperId)
            . ($force ? ' --force' : '');

        $process = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );

        if (is_resource($process)) {
            $processes[$platform] = $process;
            $this->info("Started: {$platform}");
        } else {
            $this->error("Failed to start subprocess for: {$platform}");
        }
    }

    $this->info("Waiting for all platforms to complete...");

    foreach ($processes as $platform => $process) {
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $this->warn("Platform {$platform} exited with code {$exitCode}");
        } else {
            $this->info("Finished: {$platform}");
        }
    }

    $this->info("All platforms complete.");
}
```

- [ ] **Step 2: Verify syntax and run existing unit tests**

```bash
php artisan test --testsuite=Unit -v
```

Expected: all tests PASS (no unit tests for proc_open itself — it's tested by running the command).

- [ ] **Step 3: Smoke test — verify the command parses without error**

```bash
php artisan scraper:run --help
```

Expected: shows the command signature with `--scraper-id` and `--category-url` options listed.

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ScrapeCommand.php
git commit -m "feat: scraper:run all launches parallel subprocess per platform"
```

---

## Task 6: ScrapeRankingsCommand — scraper_id + parallel all

**Files:**
- Modify: `app/Console/Commands/ScrapeRankingsCommand.php`

- [ ] **Step 1: Add --scraper-id option to signature**

Change `protected $signature` to:

```php
protected $signature = 'scraper:rankings
                    {platform : Platform to scrape (amazon, amazon_jp, flipkart, vijaysales, all)}
                    {--keyword-ids=* : Specific keyword IDs to scrape}
                    {--scraper-id= : Scraper batch ID (auto-generated if not provided)}';
```

- [ ] **Step 2: Add import and getNextScraperId method**

Add at the top of the file:

```php
use App\Models\Product;
```

Add method to the class:

```php
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

- [ ] **Step 3: Add scrapeAllRankingPlatformsInParallel method**

Add this method to the class:

```php
protected function scrapeAllRankingPlatformsInParallel(string $scraperId): void
{
    $platforms = ['amazon', 'amazon_jp', 'flipkart', 'vijaysales'];

    $this->info("Launching " . count($platforms) . " ranking scrapers in parallel (scraper_id: {$scraperId})...");

    $processes = [];
    foreach ($platforms as $p) {
        $cmd = PHP_BINARY . ' ' . base_path('artisan') . ' scraper:rankings ' . escapeshellarg($p)
            . ' --scraper-id=' . escapeshellarg($scraperId);

        $process = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );

        if (is_resource($process)) {
            $processes[$p] = $process;
            $this->info("Started rankings: {$p}");
        } else {
            $this->error("Failed to start subprocess for rankings: {$p}");
        }
    }

    $this->info("Waiting for all ranking scrapers to complete...");

    foreach ($processes as $p => $process) {
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $this->warn("Rankings for {$p} exited with code {$exitCode}");
        } else {
            $this->info("Finished rankings: {$p}");
        }
    }

    $this->info("All ranking platforms complete.");
}
```

- [ ] **Step 4: Update handle() — add scraperId + parallel 'all' path + setScraperId on each scraper**

Replace the `handle()` method body:

```php
public function handle()
{
    $platform = $this->argument('platform');
    $keywordIds = $this->option('keyword-ids');
    $scraperId = $this->getNextScraperId();

    $this->info("Starting ranking scraping for: {$platform} (scraper_id: {$scraperId})");
    $this->newLine();

    try {
        // Parallel path for all platforms
        if ($platform === 'all') {
            $this->scrapeAllRankingPlatformsInParallel($scraperId);
            return Command::SUCCESS;
        }

        // Single-platform synchronous path
        $stats = [];

        if ($platform === 'amazon') {
            $this->info('Scraping Amazon rankings...');
            $scraper = new AmazonRankingScraper();
            $scraper->setScraperId($scraperId);
            $stats['amazon'] = $scraper->scrapeRankings($keywordIds ?: null);
        }

        if ($platform === 'amazon_jp') {
            $this->info('Scraping Amazon Japan rankings...');
            $scraper = new AmazonJpRankingScraper();
            $scraper->setScraperId($scraperId);
            $stats['amazon_jp'] = $scraper->scrapeRankings($keywordIds ?: null);
        }

        if ($platform === 'flipkart') {
            $this->info('Scraping Flipkart rankings...');
            $scraper = new FlipkartRankingScraper();
            $scraper->setScraperId($scraperId);
            $stats['flipkart'] = $scraper->scrapeRankings($keywordIds ?: null);
        }

        if ($platform === 'vijaysales') {
            $this->info('Scraping VijaySales rankings...');
            $scraper = new VijaySalesRankingScraper();
            $scraper->setScraperId($scraperId);
            $stats['vijaysales'] = $scraper->scrapeRankings($keywordIds ?: null);
        }

        $this->newLine();
        $this->info('Ranking scraping completed!');
        $this->newLine();

        foreach ($stats as $plat => $stat) {
            $this->info(strtoupper($plat) . ' Statistics:');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Keywords Processed', $stat['keywords_processed']],
                    ['Products Found', $stat['products_found']],
                    ['Rankings Recorded', $stat['rankings_recorded']],
                    ['Errors', $stat['errors_count']],
                ]
            );
            $this->newLine();
        }

        return Command::SUCCESS;
    } catch (\Exception $e) {
        $this->error('Error during ranking scraping: ' . $e->getMessage());
        if ($this->option('verbose')) {
            $this->error($e->getTraceAsString());
        }
        return Command::FAILURE;
    }
}
```

- [ ] **Step 5: Smoke test the command help**

```bash
php artisan scraper:rankings --help
```

Expected: shows `--scraper-id` option in the output.

- [ ] **Step 6: Run all unit tests**

```bash
php artisan test --testsuite=Unit -v
```

Expected: all tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/ScrapeRankingsCommand.php
git commit -m "feat: ScrapeRankingsCommand generates batch scraper_id, runs all platforms in parallel"
```

---

## Task 7: Routes + Controller fix + Seeder

**Files:**
- Modify: `routes/admin.php`
- Modify: `app/Http/Controllers/Admin/ScraperConfigurationController.php:63` and `:107`
- Create: `database/seeders/ScraperConfigurationSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Add scraper-config routes to admin.php**

In `routes/admin.php`, add the following import at the top:

```php
use App\Http\Controllers\Admin\ScraperConfigurationController;
```

Add the following route group inside the `Route::prefix('admin')->name('admin.')->group(...)` block, after the existing `scraper` group:

```php
// Scraper URL Configuration
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

- [ ] **Step 2: Verify routes are registered**

```bash
php artisan route:list --name=scraper-config
```

Expected: lists 9 routes (index, create, store, show, edit, update, destroy, run, toggle-status).

- [ ] **Step 3: Add amazon_jp to controller platforms list**

In `app/Http/Controllers/Admin/ScraperConfigurationController.php`, update the `$platforms` array in both `create()` (line ~63) and `edit()` (line ~107):

```php
$platforms = ['amazon', 'amazon_jp', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket', 'zepto'];
```

- [ ] **Step 4: Create the seeder**

Create `database/seeders/ScraperConfigurationSeeder.php`:

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

        $this->command->info('Scraper configurations seeded from config/scraper.php.');
    }

    private function inferCategory(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
        $k = $params['k'] ?? null;
        return $k ? urldecode(str_replace('+', ' ', $k)) : 'general';
    }
}
```

- [ ] **Step 5: Register seeder in DatabaseSeeder**

In `database/seeders/DatabaseSeeder.php`, update `run()`:

```php
public function run(): void
{
    $this->call(ScraperConfigurationSeeder::class);
}
```

- [ ] **Step 6: Run the seeder and verify**

```bash
php artisan db:seed --class=ScraperConfigurationSeeder
```

Expected output: `Scraper configurations seeded from config/scraper.php.`

Then verify rows were created:

```bash
php artisan tinker --execute="echo App\Models\ScraperConfiguration::count() . ' rows created';"
```

Expected: a positive number (at least one row per platform URL).

- [ ] **Step 7: Run all unit tests**

```bash
php artisan test --testsuite=Unit -v
```

Expected: all tests PASS.

- [ ] **Step 8: Commit**

```bash
git add routes/admin.php app/Http/Controllers/Admin/ScraperConfigurationController.php database/seeders/ScraperConfigurationSeeder.php database/seeders/DatabaseSeeder.php
git commit -m "feat: add scraper-config routes, seeder, and amazon_jp to controller platforms"
```

---

## Task 8: Admin views — scraper-config UI

**Files:**
- Create: `resources/views/admin/scraper-config/index.blade.php`
- Create: `resources/views/admin/scraper-config/create.blade.php`
- Create: `resources/views/admin/scraper-config/edit.blade.php`
- Create: `resources/views/admin/scraper-config/show.blade.php`

> These views extend `layouts.admin` and use Bootstrap 5 + Font Awesome 6, matching the existing admin style. Check `resources/views/admin/scraper/index.blade.php` for the exact layout conventions before building these.

- [ ] **Step 1: Create the index view**

Create `resources/views/admin/scraper-config/index.blade.php`:

```blade
@extends('layouts.admin')

@section('title', 'Scraper URL Configurations')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Scraper URL Configurations</h1>
        <a href="{{ route('admin.scraper-config.create') }}" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Add URL
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <select name="platform" class="form-select">
                        <option value="">All Platforms</option>
                        @foreach($platforms as $p)
                            <option value="{{ $p }}" {{ request('platform') == $p ? 'selected' : '' }}>{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="text" name="category" class="form-control" placeholder="Category" value="{{ request('category') }}">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                        <option value="paused" {{ request('status') == 'paused' ? 'selected' : '' }}>Paused</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.scraper-config.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Table --}}
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Platform</th>
                        <th>Category</th>
                        <th>URL</th>
                        <th>Status</th>
                        <th>Last Run</th>
                        <th>Total Runs</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($configurations as $config)
                    <tr>
                        <td><span class="badge bg-secondary">{{ $config->platform }}</span></td>
                        <td>{{ $config->category }}
                            @if($config->tag)
                                <br><small class="text-muted">{{ $config->tag }}</small>
                            @endif
                        </td>
                        <td>
                            <small class="text-muted" title="{{ $config->category_url }}">
                                {{ Str::limit($config->category_url, 55) }}
                            </small>
                        </td>
                        <td>
                            @php
                                $badgeClass = match($config->status) {
                                    'active'   => 'success',
                                    'inactive' => 'secondary',
                                    'paused'   => 'warning',
                                    default    => 'secondary'
                                };
                            @endphp
                            <span class="badge bg-{{ $badgeClass }}">{{ $config->status }}</span>
                        </td>
                        <td>{{ $config->last_run_at ? $config->last_run_at->diffForHumans() : 'Never' }}</td>
                        <td>{{ $config->total_runs }}</td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="{{ route('admin.scraper-config.show', $config) }}" class="btn btn-outline-primary" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="{{ route('admin.scraper-config.edit', $config) }}" class="btn btn-outline-secondary" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="{{ route('admin.scraper-config.toggle-status', $config) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-{{ $config->status === 'active' ? 'warning' : 'success' }}" title="{{ $config->status === 'active' ? 'Deactivate' : 'Activate' }}">
                                        <i class="fas fa-{{ $config->status === 'active' ? 'pause' : 'play' }}"></i>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.scraper-config.run', $config) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-success" title="Run now" onclick="return confirm('Run scraper for this URL?')">
                                        <i class="fas fa-play-circle"></i>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.scraper-config.destroy', $config) }}" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger" title="Delete" onclick="return confirm('Delete this configuration? This cannot be undone.')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            No configurations found.
                            <a href="{{ route('admin.scraper-config.create') }}">Add one</a> or run
                            <code>php artisan db:seed --class=ScraperConfigurationSeeder</code> to import from config.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($configurations->hasPages())
        <div class="card-footer">
            {{ $configurations->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
```

- [ ] **Step 2: Create the create view**

Create `resources/views/admin/scraper-config/create.blade.php`:

```blade
@extends('layouts.admin')

@section('title', 'Add Scraper URL')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center mb-4">
        <a href="{{ route('admin.scraper-config.index') }}" class="btn btn-outline-secondary me-3">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h1 class="h3 mb-0">Add Scraper URL</h1>
    </div>

    <div class="card" style="max-width: 700px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.scraper-config.store') }}">
                @csrf

                <div class="mb-3">
                    <label class="form-label fw-semibold">Platform <span class="text-danger">*</span></label>
                    <select name="platform" class="form-select @error('platform') is-invalid @enderror" required>
                        <option value="">— Select Platform —</option>
                        @foreach($platforms as $p)
                            <option value="{{ $p }}" {{ old('platform') == $p ? 'selected' : '' }}>{{ $p }}</option>
                        @endforeach
                    </select>
                    @error('platform')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                    <input type="text" name="category" class="form-control @error('category') is-invalid @enderror"
                           value="{{ old('category') }}" placeholder="e.g. printer, smartphone, diaper" required>
                    @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Tag <small class="text-muted fw-normal">(optional)</small></label>
                    <input type="text" name="tag" class="form-control" value="{{ old('tag') }}"
                           placeholder="e.g. Black Friday, Sale Nov 11">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Category URL <span class="text-danger">*</span></label>
                    <input type="url" name="category_url" class="form-control @error('category_url') is-invalid @enderror"
                           value="{{ old('category_url') }}" placeholder="https://www.amazon.co.jp/s?k=printer" required>
                    @error('category_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Description <small class="text-muted fw-normal">(optional)</small></label>
                    <textarea name="description" class="form-control" rows="2">{{ old('description') }}</textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="active" {{ old('status', 'active') == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ old('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                        <option value="paused" {{ old('status') == 'paused' ? 'selected' : '' }}>Paused</option>
                    </select>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save Configuration</button>
                    <a href="{{ route('admin.scraper-config.index') }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 3: Create the edit view**

Create `resources/views/admin/scraper-config/edit.blade.php`:

```blade
@extends('layouts.admin')

@section('title', 'Edit Scraper URL')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center mb-4">
        <a href="{{ route('admin.scraper-config.show', $configuration) }}" class="btn btn-outline-secondary me-3">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h1 class="h3 mb-0">Edit Scraper URL</h1>
    </div>

    <div class="card" style="max-width: 700px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.scraper-config.update', $configuration) }}">
                @csrf
                @method('PUT')

                <div class="mb-3">
                    <label class="form-label fw-semibold">Platform <span class="text-danger">*</span></label>
                    <select name="platform" class="form-select @error('platform') is-invalid @enderror" required>
                        <option value="">— Select Platform —</option>
                        @foreach($platforms as $p)
                            <option value="{{ $p }}" {{ old('platform', $configuration->platform) == $p ? 'selected' : '' }}>{{ $p }}</option>
                        @endforeach
                    </select>
                    @error('platform')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                    <input type="text" name="category" class="form-control @error('category') is-invalid @enderror"
                           value="{{ old('category', $configuration->category) }}" required>
                    @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Tag <small class="text-muted fw-normal">(optional)</small></label>
                    <input type="text" name="tag" class="form-control" value="{{ old('tag', $configuration->tag) }}">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Category URL <span class="text-danger">*</span></label>
                    <input type="url" name="category_url" class="form-control @error('category_url') is-invalid @enderror"
                           value="{{ old('category_url', $configuration->category_url) }}" required>
                    @error('category_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Description <small class="text-muted fw-normal">(optional)</small></label>
                    <textarea name="description" class="form-control" rows="2">{{ old('description', $configuration->description) }}</textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select" required>
                        <option value="active" {{ old('status', $configuration->status) == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ old('status', $configuration->status) == 'inactive' ? 'selected' : '' }}>Inactive</option>
                        <option value="paused" {{ old('status', $configuration->status) == 'paused' ? 'selected' : '' }}>Paused</option>
                    </select>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Update Configuration</button>
                    <a href="{{ route('admin.scraper-config.show', $configuration) }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 4: Create the show view**

Create `resources/views/admin/scraper-config/show.blade.php`:

```blade
@extends('layouts.admin')

@section('title', 'Scraper Configuration')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center">
            <a href="{{ route('admin.scraper-config.index') }}" class="btn btn-outline-secondary me-3">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="h3 mb-0">{{ $configuration->platform }} — {{ $configuration->category }}</h1>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.scraper-config.edit', $configuration) }}" class="btn btn-outline-secondary">
                <i class="fas fa-edit me-1"></i> Edit
            </a>
            <form method="POST" action="{{ route('admin.scraper-config.run', $configuration) }}">
                @csrf
                <button type="submit" class="btn btn-success" onclick="return confirm('Run scraper for this URL now?')">
                    <i class="fas fa-play-circle me-1"></i> Run Now
                </button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-4">
        {{-- Config Details --}}
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">Configuration</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Platform</dt>
                        <dd class="col-sm-8"><span class="badge bg-secondary">{{ $configuration->platform }}</span></dd>

                        <dt class="col-sm-4">Category</dt>
                        <dd class="col-sm-8">{{ $configuration->category }}</dd>

                        @if($configuration->tag)
                        <dt class="col-sm-4">Tag</dt>
                        <dd class="col-sm-8">{{ $configuration->tag }}</dd>
                        @endif

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">
                            @php
                                $badgeClass = match($configuration->status) {
                                    'active'   => 'success',
                                    'inactive' => 'secondary',
                                    'paused'   => 'warning',
                                    default    => 'secondary'
                                };
                            @endphp
                            <span class="badge bg-{{ $badgeClass }}">{{ $configuration->status }}</span>
                            <form method="POST" action="{{ route('admin.scraper-config.toggle-status', $configuration) }}" class="d-inline ms-2">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-{{ $configuration->status === 'active' ? 'warning' : 'success' }}">
                                    {{ $configuration->status === 'active' ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                        </dd>

                        <dt class="col-sm-4">URL</dt>
                        <dd class="col-sm-8">
                            <a href="{{ $configuration->category_url }}" target="_blank" class="small text-break">
                                {{ $configuration->category_url }}
                            </a>
                        </dd>

                        @if($configuration->description)
                        <dt class="col-sm-4">Description</dt>
                        <dd class="col-sm-8">{{ $configuration->description }}</dd>
                        @endif

                        <dt class="col-sm-4">Last Scraper ID</dt>
                        <dd class="col-sm-8"><code>{{ $configuration->last_scraper_id ?? '—' }}</code></dd>

                        <dt class="col-sm-4">Last Run</dt>
                        <dd class="col-sm-8">{{ $configuration->last_run_at ? $configuration->last_run_at->format('Y-m-d H:i') : 'Never' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        {{-- Statistics --}}
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">Statistics</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="border rounded p-3 text-center">
                                <div class="fs-4 fw-bold text-primary">{{ $statistics['total_runs'] }}</div>
                                <small class="text-muted">Total Runs</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3 text-center">
                                <div class="fs-4 fw-bold text-success">{{ $statistics['successful_runs'] }}</div>
                                <small class="text-muted">Successful</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3 text-center">
                                <div class="fs-4 fw-bold text-danger">{{ $statistics['failed_runs'] }}</div>
                                <small class="text-muted">Failed</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3 text-center">
                                <div class="fs-4 fw-bold">{{ $statistics['total_products'] ?? 0 }}</div>
                                <small class="text-muted">Products Scraped</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Runs --}}
    <div class="card mt-4">
        <div class="card-header fw-semibold">Last 10 Runs</div>
        <div class="card-body p-0">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Scraper ID</th>
                        <th>Status</th>
                        <th>Products</th>
                        <th>Errors</th>
                        <th>Duration</th>
                        <th>Started</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($configuration->scraperRuns as $run)
                    <tr>
                        <td><code>{{ $run->scraper_id }}</code></td>
                        <td>
                            @php
                                $rc = match($run->status) {
                                    'completed' => 'success',
                                    'failed'    => 'danger',
                                    'running'   => 'primary',
                                    default     => 'secondary'
                                };
                            @endphp
                            <span class="badge bg-{{ $rc }}">{{ $run->status }}</span>
                        </td>
                        <td>{{ $run->products_scraped ?? 0 }}</td>
                        <td>{{ $run->errors_count ?? 0 }}</td>
                        <td>{{ $run->formatted_duration }}</td>
                        <td>{{ $run->started_at ? $run->started_at->format('M d, H:i') : '—' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center py-3 text-muted">No runs yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Run all unit tests**

```bash
php artisan test --testsuite=Unit -v
```

Expected: all tests PASS.

- [ ] **Step 6: Verify routes render without 500 errors**

```bash
php artisan route:list --name=scraper-config
```

Expected: 9 routes listed cleanly.

- [ ] **Step 7: Commit**

```bash
git add resources/views/admin/scraper-config/
git commit -m "feat: build scraper-config admin views (index, create, edit, show)"
```

---

## Final Verification

- [ ] **Run full test suite**

```bash
php artisan test --testsuite=Unit -v
```

Expected: all tests PASS (minimum 17 unit tests).

- [ ] **Verify command help output**

```bash
php artisan scraper:run --help
php artisan scraper:rankings --help
```

Expected: both show `--scraper-id` option. `scraper:run` also shows `--category-url`.

- [ ] **Verify DB seeder**

```bash
php artisan db:seed --class=ScraperConfigurationSeeder
php artisan tinker --execute="echo App\Models\ScraperConfiguration::where('status','active')->count() . ' active configs';"
```

Expected: positive count matching the total number of category URLs in `config/scraper.php`.

- [ ] **Verify routes**

```bash
php artisan route:list --name=scraper-config
```

Expected: 9 routes (index, create, store, show, edit, update, destroy, run, toggle-status).
