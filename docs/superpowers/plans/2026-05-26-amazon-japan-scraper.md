# Amazon Japan Scraper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Amazon Japan (amazon.co.jp) product + ranking scraping and a full admin scraper dashboard view, with zero impact on existing platform scrapers or stored data.

**Architecture:** `AmazonJpScraper` extends `AmazonScraper` and overrides only `setupPlatformConfig()` (to set `platform = 'amazon_jp'`) and `extractProductUrls()` (to use `amazon.co.jp` URLs). `AmazonJpRankingScraper` extends `AmazonRankingScraper` and overrides `$platform` and `buildSearchUrl()`. DataSanitizer gains a per-platform price range lookup so JPY prices pass validation. The admin scraper view is built from scratch (current file is empty) using the existing Bootstrap 5 layout.

**Tech Stack:** Laravel 10, PHP 8.1, Bootstrap 5, PHPUnit (via `php artisan test`), Blade templates

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `tests/Unit/DataSanitizerTest.php` | Create | Unit tests for price range by platform |
| `app/Services/DataSanitizer.php` | Modify (2 locations) | Pass platform-specific price range to sanitizePrice |
| `config/scraper.php` | Modify (additive) | Add amazon_jp platform block + JPY price range |
| `tests/Unit/AmazonJpScraperTest.php` | Create | Verify platform key and URL extraction |
| `app/Services/Scrapers/AmazonJpScraper.php` | Create | Extends AmazonScraper, overrides platform + URL domain |
| `tests/Unit/AmazonJpRankingScraperTest.php` | Create | Verify platform key and search URL |
| `app/Services/Scrapers/AmazonJpRankingScraper.php` | Create | Extends AmazonRankingScraper, overrides platform + search URL |
| `app/Console/Commands/ScrapeCommand.php` | Modify | Add amazon_jp case to createScraper() |
| `app/Console/Commands/ScrapeRankingsCommand.php` | Modify | Add amazon_jp block + import |
| `app/Http/Controllers/Admin/ScraperController.php` | Modify (2 lines) | Add amazon_jp to platform list + validation |
| `resources/views/admin/scraper/index.blade.php` | Build | Full scraper dashboard view (currently empty) |

---

## Task 1: DataSanitizer — Currency-Aware Price Validation

**Files:**
- Create: `tests/Unit/DataSanitizerTest.php`
- Modify: `app/Services/DataSanitizer.php`

- [ ] **Step 1.1: Write the failing tests**

Create `tests/Unit/DataSanitizerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\DataSanitizer;
use Tests\TestCase;

class DataSanitizerTest extends TestCase
{
    public function test_sanitize_price_rejects_price_below_inr_min(): void
    {
        // INR min is 10 — price of 5 should be rejected
        $result = DataSanitizer::sanitizePrice(5);
        $this->assertNull($result);
    }

    public function test_sanitize_price_accepts_valid_inr_price(): void
    {
        $result = DataSanitizer::sanitizePrice(15000);
        $this->assertEquals(15000.0, $result);
    }

    public function test_sanitize_price_accepts_jpy_price_with_jpy_range(): void
    {
        // ¥50,000 is valid JPY — should pass with JPY range
        $jpyRange = ['min' => 100, 'max' => 5000000];
        $result = DataSanitizer::sanitizePrice(50000, $jpyRange);
        $this->assertEquals(50000.0, $result);
    }

    public function test_sanitize_price_rejects_jpy_price_below_jpy_min_with_jpy_range(): void
    {
        $jpyRange = ['min' => 100, 'max' => 5000000];
        $result = DataSanitizer::sanitizePrice(50, $jpyRange);
        $this->assertNull($result);
    }

    public function test_sanitize_product_data_uses_jpy_range_for_amazon_jp(): void
    {
        // ¥50 is below INR min (10) but also below JPY min (100)
        // ¥500 is above INR min (10) and above JPY min (100) — should pass
        $data = [
            'platform' => 'amazon_jp',
            'sku' => 'B001234567',
            'title' => 'Test Product',
            'price' => 500,
            'sale_price' => 450,
        ];

        $result = DataSanitizer::sanitizeProductData($data);

        $this->assertEquals(500.0, $result['price']);
        $this->assertEquals(450.0, $result['sale_price']);
    }

    public function test_sanitize_product_data_uses_inr_range_for_amazon_india(): void
    {
        // ₹5 is below INR min — should be null
        $data = [
            'platform' => 'amazon',
            'sku' => 'B001234567',
            'title' => 'Test Product',
            'price' => 5,
        ];

        $result = DataSanitizer::sanitizeProductData($data);

        $this->assertNull($result['price'] ?? null);
    }
}
```

- [ ] **Step 1.2: Run tests to verify they fail**

```bash
cd c:/xampp/htdocs/laptop-scraper && php artisan test --filter=DataSanitizerTest
```

Expected: FAIL — `sanitizePrice` has no second parameter, `sanitizeProductData` does not look up per-platform range.

- [ ] **Step 1.3: Modify `sanitizePrice()` to accept optional price range**

In `app/Services/DataSanitizer.php`, change the `sanitizePrice` signature and range lookup (around line 143):

```php
public static function sanitizePrice($price, ?array $priceRange = null): ?float
{
    if ($price === null || $price === '') {
        return null;
    }

    if (is_string($price)) {
        $price = preg_replace('/[^\d.,]/', '', $price);
        $price = str_replace(',', '', $price);
    }

    $price = (float) $price;

    $priceRange = $priceRange ?? config('scraper.validation.price_range', ['min' => 10, 'max' => 1600000]);

    if ($price < $priceRange['min'] || $price > $priceRange['max']) {
        Log::warning('Price out of valid range', ['price' => $price]);
        return null;
    }

    return $price;
}
```

- [ ] **Step 1.4: Modify `sanitizeProductData()` to resolve platform price range**

In `app/Services/DataSanitizer.php`, replace lines 44–45 (the two `sanitizePrice` calls) with:

```php
$platform = $sanitized['platform'] ?? '';
$priceRange = config("scraper.validation.price_range_by_platform.{$platform}")
    ?? config('scraper.validation.price_range');

$sanitized['price'] = self::sanitizePrice($data['price'] ?? null, $priceRange);
$sanitized['sale_price'] = self::sanitizePrice($data['sale_price'] ?? null, $priceRange);
```

Insert these lines to replace the existing `$sanitized['price']` and `$sanitized['sale_price']` lines. The `$platform` line goes immediately before them.

- [ ] **Step 1.5: Run tests to verify they pass**

```bash
php artisan test --filter=DataSanitizerTest
```

Expected: All 6 tests PASS.

> Note: The `test_sanitize_product_data_uses_jpy_range_for_amazon_jp` test requires the config addition in Task 2. If it fails with "config key not found," complete Task 2 first then re-run.

- [ ] **Step 1.6: Commit**

```bash
git add tests/Unit/DataSanitizerTest.php app/Services/DataSanitizer.php
git commit -m "feat: currency-aware price validation in DataSanitizer"
```

---

## Task 2: Config — Add amazon_jp Platform

**Files:**
- Modify: `config/scraper.php`

- [ ] **Step 2.1: Add amazon_jp platform block**

In `config/scraper.php`, inside the `'platforms'` array, add after the closing `]` of the `'amazon'` block (after line 39):

```php
'amazon_jp' => [
    'name' => 'Amazon Japan',
    'base_url' => 'https://www.amazon.co.jp',
    'category_urls' => [
        // Printers
        'https://www.amazon.co.jp/s?k=printer&rh=p_89%3AHP%7CCanon%7CEpson%7CBrother&dc',
        // Mobiles
        'https://www.amazon.co.jp/s?k=smartphone&rh=p_89%3ASamsung%7CApple%7CSony%7CSHARP&dc',
        // Diapers
        'https://www.amazon.co.jp/s?k=diaper&rh=p_89%3APampers%7CHuggies%7CUnicharm&dc',
        // Wipes
        'https://www.amazon.co.jp/s?k=baby+wipes&rh=p_89%3APampers%7CHuggies%7CUnicharm&dc',
        // Detergent
        'https://www.amazon.co.jp/s?k=laundry+detergent&rh=p_89%3ALion%7CAriel%7CTide&dc',
    ]
],
```

- [ ] **Step 2.2: Add JPY price range under validation**

In `config/scraper.php`, inside the `'validation'` array, add after the existing `'price_range'` entry:

```php
'price_range_by_platform' => [
    'amazon_jp' => [
        'min' => 100,
        'max' => 5000000,
    ]
],
```

- [ ] **Step 2.3: Verify config loads correctly**

```bash
php artisan tinker --execute="dd(config('scraper.platforms.amazon_jp'), config('scraper.validation.price_range_by_platform'))"
```

Expected output: shows the `amazon_jp` array with 5 category URLs, and the `price_range_by_platform` array with the JPY range.

- [ ] **Step 2.4: Run DataSanitizer tests again to confirm config is picked up**

```bash
php artisan test --filter=DataSanitizerTest
```

Expected: All 6 tests PASS (including the `amazon_jp` platform range test).

- [ ] **Step 2.5: Commit**

```bash
git add config/scraper.php
git commit -m "feat: add amazon_jp platform config with JPY price range"
```

---

## Task 3: AmazonJpScraper

**Files:**
- Create: `tests/Unit/AmazonJpScraperTest.php`
- Create: `app/Services/Scrapers/AmazonJpScraper.php`

- [ ] **Step 3.1: Write the failing tests**

Create `tests/Unit/AmazonJpScraperTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Scrapers\AmazonJpScraper;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class AmazonJpScraperTest extends TestCase
{
    private AmazonJpScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new AmazonJpScraper();
    }

    public function test_platform_is_amazon_jp(): void
    {
        $this->assertEquals('amazon_jp', $this->scraper->getPlatform());
    }

    public function test_extract_product_urls_uses_amazon_co_jp_domain(): void
    {
        // Simulate a search results page with a relative product link
        $html = <<<HTML
<html><body>
<div data-cy="title-recipe">
  <a class="a-link-normal s-no-outline" href="/dp/B08N5LNQCX/ref=sr_1_1">Product</a>
</div>
</body></html>
HTML;
        $crawler = new Crawler($html);
        $urls = $this->scraper->testExtractProductUrls($crawler, 'https://www.amazon.co.jp/s?k=printer');

        $this->assertCount(1, $urls);
        $this->assertStringStartsWith('https://www.amazon.co.jp', $urls[0]);
        $this->assertStringContainsString('/dp/', $urls[0]);
    }

    public function test_extract_product_urls_does_not_use_amazon_in_domain(): void
    {
        $html = <<<HTML
<html><body>
<div data-cy="title-recipe">
  <a class="a-link-normal s-no-outline" href="/dp/B08N5LNQCX/ref=sr_1_1">Product</a>
</div>
</body></html>
HTML;
        $crawler = new Crawler($html);
        $urls = $this->scraper->testExtractProductUrls($crawler, 'https://www.amazon.co.jp/s?k=printer');

        foreach ($urls as $url) {
            $this->assertStringNotContainsString('amazon.in', $url);
        }
    }

    public function test_extract_product_urls_skips_non_dp_links(): void
    {
        $html = <<<HTML
<html><body>
<div data-cy="title-recipe">
  <a class="a-link-normal s-no-outline" href="/gp/bestsellers/electronics">Not a product</a>
</div>
</body></html>
HTML;
        $crawler = new Crawler($html);
        $urls = $this->scraper->testExtractProductUrls($crawler, 'https://www.amazon.co.jp/s?k=printer');

        $this->assertEmpty($urls);
    }
}
```

- [ ] **Step 3.2: Run tests to verify they fail**

```bash
php artisan test --filter=AmazonJpScraperTest
```

Expected: FAIL — class `AmazonJpScraper` does not exist, `getPlatform()` and `testExtractProductUrls()` do not exist.

- [ ] **Step 3.3: Create `AmazonJpScraper.php`**

Create `app/Services/Scrapers/AmazonJpScraper.php`:

```php
<?php

namespace App\Services\Scrapers;

use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AmazonJpScraper extends AmazonScraper
{
    protected function setupPlatformConfig(): void
    {
        parent::setupPlatformConfig();
        $this->platform = 'amazon_jp';
    }

    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            $selectors = [
                'div[data-cy="title-recipe"] > a',
                'a.a-link-normal.s-no-outline',
                'div[data-cy="title-recipe"] > a.a-link-normal',
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.amazon.co.jp' . $href;
                        }
                        if (strpos($href, '/dp/') !== false || strpos($href, '/product/') !== false) {
                            $productUrls[] = $href;
                        }
                    }
                });
            }

            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50);

            Log::info("Extracted {count} product URLs from Amazon Japan category page", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Amazon Japan", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function testExtractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        return $this->extractProductUrls($crawler, $categoryUrl);
    }
}
```

- [ ] **Step 3.4: Run tests to verify they pass**

```bash
php artisan test --filter=AmazonJpScraperTest
```

Expected: All 3 tests PASS.

- [ ] **Step 3.5: Commit**

```bash
git add tests/Unit/AmazonJpScraperTest.php app/Services/Scrapers/AmazonJpScraper.php
git commit -m "feat: add AmazonJpScraper extending AmazonScraper with amazon.co.jp domain"
```

---

## Task 4: AmazonJpRankingScraper

**Files:**
- Create: `tests/Unit/AmazonJpRankingScraperTest.php`
- Create: `app/Services/Scrapers/AmazonJpRankingScraper.php`

- [ ] **Step 4.1: Write the failing tests**

Create `tests/Unit/AmazonJpRankingScraperTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Scrapers\AmazonJpRankingScraper;
use Tests\TestCase;

class AmazonJpRankingScraperTest extends TestCase
{
    private AmazonJpRankingScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new AmazonJpRankingScraper();
    }

    public function test_platform_is_amazon_jp(): void
    {
        $this->assertEquals('amazon_jp', $this->scraper->getPlatform());
    }

    public function test_build_search_url_uses_amazon_co_jp(): void
    {
        $url = $this->scraper->testBuildSearchUrl('printer', 1);

        $this->assertStringStartsWith('https://www.amazon.co.jp/s', $url);
        $this->assertStringContainsString('k=printer', $url);
        $this->assertStringContainsString('page=1', $url);
    }

    public function test_build_search_url_does_not_use_amazon_in(): void
    {
        $url = $this->scraper->testBuildSearchUrl('printer', 1);

        $this->assertStringNotContainsString('amazon.in', $url);
    }

    public function test_build_search_url_page_2(): void
    {
        $url = $this->scraper->testBuildSearchUrl('detergent', 2);

        $this->assertStringContainsString('page=2', $url);
        $this->assertStringContainsString('k=detergent', $url);
    }
}
```

- [ ] **Step 4.2: Run tests to verify they fail**

```bash
php artisan test --filter=AmazonJpRankingScraperTest
```

Expected: FAIL — class `AmazonJpRankingScraper` does not exist.

- [ ] **Step 4.3: Create `AmazonJpRankingScraper.php`**

Create `app/Services/Scrapers/AmazonJpRankingScraper.php`:

```php
<?php

namespace App\Services\Scrapers;

class AmazonJpRankingScraper extends AmazonRankingScraper
{
    protected string $platform = 'amazon_jp';

    protected function buildSearchUrl(string $keyword, int $page): string
    {
        $params = [
            'k' => $keyword,
            'page' => $page,
        ];

        return 'https://www.amazon.co.jp/s?' . http_build_query($params);
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function testBuildSearchUrl(string $keyword, int $page): string
    {
        return $this->buildSearchUrl($keyword, $page);
    }
}
```

- [ ] **Step 4.4: Run tests to verify they pass**

```bash
php artisan test --filter=AmazonJpRankingScraperTest
```

Expected: All 4 tests PASS.

- [ ] **Step 4.5: Run the full test suite to confirm no regressions**

```bash
php artisan test
```

Expected: All tests PASS. No existing tests broken.

- [ ] **Step 4.6: Commit**

```bash
git add tests/Unit/AmazonJpRankingScraperTest.php app/Services/Scrapers/AmazonJpRankingScraper.php
git commit -m "feat: add AmazonJpRankingScraper with amazon.co.jp search URL"
```

---

## Task 5: Wire amazon_jp into ScrapeCommand

**Files:**
- Modify: `app/Console/Commands/ScrapeCommand.php`

- [ ] **Step 5.1: Add import and case**

In `app/Console/Commands/ScrapeCommand.php`:

**Add import** (after the existing `use App\Services\Scrapers\ZeptoScraper;` line):
```php
use App\Services\Scrapers\AmazonJpScraper;
```

**Add case** to the `createScraper()` match expression (after the `'amazon' => new AmazonScraper(),` line):
```php
'amazon_jp' => new AmazonJpScraper(),
```

- [ ] **Step 5.2: Verify the command recognises amazon_jp**

```bash
php artisan scraper:run amazon_jp --help
```

Expected: No error about unknown platform. The help text shows the command signature.

- [ ] **Step 5.3: Verify amazon_jp appears in scrapeAllPlatforms (it already reads from config)**

```bash
php artisan tinker --execute="dd(array_keys(config('scraper.platforms')))"
```

Expected output includes `'amazon_jp'` in the array.

- [ ] **Step 5.4: Commit**

```bash
git add app/Console/Commands/ScrapeCommand.php
git commit -m "feat: wire amazon_jp into ScrapeCommand"
```

---

## Task 6: Wire amazon_jp into ScrapeRankingsCommand

**Files:**
- Modify: `app/Console/Commands/ScrapeRankingsCommand.php`

- [ ] **Step 6.1: Add import**

In `app/Console/Commands/ScrapeRankingsCommand.php`, after the existing imports add:

```php
use App\Services\Scrapers\AmazonJpRankingScraper;
```

- [ ] **Step 6.2: Add amazon_jp block**

In `ScrapeRankingsCommand::handle()`, after the existing Amazon India block (after the `if ($platform === 'all' || $platform === 'amazon')` closing brace), add:

```php
if ($platform === 'all' || $platform === 'amazon_jp') {
    $this->info('Scraping Amazon Japan rankings...');
    $scraper = new AmazonJpRankingScraper();
    $stats['amazon_jp'] = $scraper->scrapeRankings($keywordIds ?: null);
}
```

- [ ] **Step 6.3: Update command signature description**

In `ScrapeRankingsCommand`, update the `$signature` string to include `amazon_jp`:

```php
protected $signature = 'scraper:rankings 
                        {platform : Platform to scrape (amazon, amazon_jp, flipkart, vijaysales, all)}
                        {--keyword-ids=* : Specific keyword IDs to scrape}';
```

- [ ] **Step 6.4: Verify**

```bash
php artisan scraper:rankings --help
```

Expected: Signature shows `amazon_jp` in the platform description.

- [ ] **Step 6.5: Commit**

```bash
git add app/Console/Commands/ScrapeRankingsCommand.php
git commit -m "feat: wire amazon_jp into ScrapeRankingsCommand"
```

---

## Task 7: ScraperController — Add amazon_jp

**Files:**
- Modify: `app/Http/Controllers/Admin/ScraperController.php`

- [ ] **Step 7.1: Add amazon_jp to the platform list**

In `ScraperController::index()`, replace line 20:

```php
// Before:
$platforms = ['amazon', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket', 'zepto'];

// After:
$platforms = ['amazon', 'amazon_jp', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket', 'zepto'];
```

- [ ] **Step 7.2: Add amazon_jp to the runScraper validation rule**

In `ScraperController::runScraper()`, replace the platform validation on line 73:

```php
// Before:
'platform' => 'required|string|in:amazon,flipkart,vijaysales,croma,reliancedigital,blinkit,bigbasket,zepto,all',

// After:
'platform' => 'required|string|in:amazon,amazon_jp,flipkart,vijaysales,croma,reliancedigital,blinkit,bigbasket,zepto,all',
```

- [ ] **Step 7.3: Verify via tinker that the controller array is correct**

```bash
php artisan tinker --execute="echo implode(', ', ['amazon', 'amazon_jp', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket', 'zepto']);"
```

Expected: prints the platform list including `amazon_jp`.

- [ ] **Step 7.4: Commit**

```bash
git add app/Http/Controllers/Admin/ScraperController.php
git commit -m "feat: add amazon_jp to ScraperController platform list and validation"
```

---

## Task 8: Build Admin Scraper Dashboard View

**Files:**
- Build: `resources/views/admin/scraper/index.blade.php`

The current file is empty. Build the full view using Bootstrap 5 (already loaded in `layouts/admin.blade.php`), Font Awesome icons, and the data the controller already passes: `$platforms`, `$recentRuns`, `$platformStats`, `$stats`.

- [ ] **Step 8.1: Write the view**

Replace `resources/views/admin/scraper/index.blade.php` with:

```blade
@extends('layouts.admin')

@section('title', 'Scraper Management')

@section('content')
<div class="container-fluid">

    {{-- Page Header --}}
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="h3 mb-0"><i class="fas fa-robot text-primary me-2"></i>Scraper Management</h1>
            <p class="text-muted">Monitor and trigger scrapers for all platforms</p>
        </div>
    </div>

    {{-- Alert Messages --}}
    <div id="alertContainer"></div>

    {{-- Overall Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-primary">{{ number_format($stats['total_runs']) }}</div>
                    <small class="text-muted">Total Runs</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-success">{{ number_format($stats['successful_runs']) }}</div>
                    <small class="text-muted">Successful</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-danger">{{ number_format($stats['failed_runs']) }}</div>
                    <small class="text-muted">Failed</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center h-100">
                <div class="card-body py-3">
                    <div class="h4 mb-0 text-info">{{ number_format($stats['total_products_scraped']) }}</div>
                    <small class="text-muted">Products Scraped</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Platform Cards --}}
    <div class="row g-3 mb-4">
        @foreach($platforms as $platform)
        @php
            $stat = $platformStats[$platform] ?? [];
            $lastRun = $stat['last_run'] ?? null;
            $isRunning = $stat['is_running'] ?? false;
            $platformNames = [
                'amazon'          => 'Amazon India',
                'amazon_jp'       => 'Amazon Japan',
                'flipkart'        => 'Flipkart',
                'vijaysales'      => 'VijaySales',
                'croma'           => 'Croma',
                'reliancedigital' => 'Reliance Digital',
                'blinkit'         => 'Blinkit',
                'bigbasket'       => 'BigBasket',
                'zepto'           => 'Zepto',
                'meesho'          => 'Meesho',
            ];
            $displayName = $platformNames[$platform] ?? ucfirst(str_replace('_', ' ', $platform));
        @endphp
        <div class="col-md-6 col-xl-4">
            <div class="card h-100 {{ $isRunning ? 'border-warning' : '' }}">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">
                        <i class="fas fa-store me-1 text-secondary"></i>
                        {{ $displayName }}
                    </span>
                    @if($isRunning)
                        <span class="badge bg-warning text-dark">
                            <i class="fas fa-spinner fa-spin me-1"></i>Running
                        </span>
                    @elseif($lastRun && $lastRun->status === 'completed')
                        <span class="badge bg-success">Done</span>
                    @elseif($lastRun && $lastRun->status === 'failed')
                        <span class="badge bg-danger">Failed</span>
                    @else
                        <span class="badge bg-secondary">Never Run</span>
                    @endif
                </div>
                <div class="card-body py-2">
                    @if($lastRun)
                        <small class="text-muted d-block">
                            Last run: {{ $lastRun->completed_at?->diffForHumans() ?? 'N/A' }}
                        </small>
                        <small class="text-muted d-block">
                            Products: {{ number_format($lastRun->products_scraped ?? 0) }}
                            &nbsp;|&nbsp;
                            Errors: {{ $lastRun->errors_count ?? 0 }}
                        </small>
                    @else
                        <small class="text-muted">No runs yet</small>
                    @endif
                </div>
                <div class="card-footer bg-transparent border-top-0 pt-0 pb-2">
                    <div class="btn-group btn-group-sm w-100" role="group">
                        <button type="button"
                            class="btn btn-outline-primary run-scraper-btn {{ $isRunning ? 'disabled' : '' }}"
                            data-platform="{{ $platform }}"
                            data-type="products"
                            {{ $isRunning ? 'disabled' : '' }}>
                            <i class="fas fa-box me-1"></i>Products
                        </button>
                        <button type="button"
                            class="btn btn-outline-secondary run-scraper-btn {{ $isRunning ? 'disabled' : '' }}"
                            data-platform="{{ $platform }}"
                            data-type="rankings"
                            {{ $isRunning ? 'disabled' : '' }}>
                            <i class="fas fa-chart-bar me-1"></i>Rankings
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Recent Runs Table --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Runs</h5>
            <a href="{{ route('admin.scraper.history') }}" class="btn btn-sm btn-outline-secondary">
                View All
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Platform</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Products</th>
                            <th>Errors</th>
                            <th>Duration</th>
                            <th>Started</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentRuns as $run)
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark border">{{ $run->platform }}</span>
                            </td>
                            <td>{{ $run->type ?? 'manual' }}</td>
                            <td>
                                @if($run->status === 'completed')
                                    <span class="badge bg-success">Completed</span>
                                @elseif($run->status === 'running')
                                    <span class="badge bg-warning text-dark">Running</span>
                                @elseif($run->status === 'failed')
                                    <span class="badge bg-danger">Failed</span>
                                @else
                                    <span class="badge bg-secondary">{{ $run->status }}</span>
                                @endif
                            </td>
                            <td>{{ number_format($run->products_scraped ?? 0) }}</td>
                            <td>{{ $run->errors_count ?? 0 }}</td>
                            <td>{{ $run->formatted_duration ?? '-' }}</td>
                            <td>
                                <small>{{ $run->created_at?->format('d M H:i') }}</small>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No scraper runs yet</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

{{-- Run Scraper JS --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    document.querySelectorAll('.run-scraper-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const platform = this.dataset.platform;
            const type = this.dataset.type;

            if (!confirm(`Start ${type} scraper for ${platform}?`)) return;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Starting...';

            fetch('{{ route("admin.scraper.run") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ platform: platform, type: type })
            })
            .then(response => response.json())
            .then(data => {
                showAlert(data.success ? 'success' : 'danger', data.message);
                if (data.success) {
                    setTimeout(() => location.reload(), 2000);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = type === 'products'
                        ? '<i class="fas fa-box me-1"></i>Products'
                        : '<i class="fas fa-chart-bar me-1"></i>Rankings';
                }
            })
            .catch(err => {
                showAlert('danger', 'Request failed: ' + err.message);
                btn.disabled = false;
            });
        });
    });

    function showAlert(type, message) {
        const container = document.getElementById('alertContainer');
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
        container.prepend(alert);
        setTimeout(() => alert.classList.remove('show'), 5000);
    }
});
</script>
@endsection
```

- [ ] **Step 8.2: Verify the route name exists**

```bash
php artisan route:list --name=admin.scraper
```

Expected: Shows `admin.scraper.index`, `admin.scraper.run`, `admin.scraper.history`, etc.

- [ ] **Step 8.3: Clear view cache and open in browser**

```bash
php artisan view:clear
```

Then open `http://localhost/laptop-scraper/public/admin/scraper` in the browser.

Expected:
- Stats bar at top (4 numbers)
- Grid of platform cards — one for each platform in `$platforms`, including **Amazon Japan**
- Recent runs table at the bottom
- "Run Products" and "Run Rankings" buttons visible on each card

- [ ] **Step 8.4: Test the run button for amazon_jp**

In the browser, click **Products** on the Amazon Japan card.
- Confirm dialog appears
- After confirming: button shows spinner, then a green alert appears, page reloads after 2s
- If scraper is already running: a 409 error alert appears

- [ ] **Step 8.5: Commit**

```bash
git add resources/views/admin/scraper/index.blade.php
git commit -m "feat: build admin scraper dashboard view with amazon_jp platform card"
```

---

## Task 9: Final Verification

- [ ] **Step 9.1: Run the full test suite**

```bash
php artisan test
```

Expected: All tests PASS. Count includes the 3 new test files (DataSanitizerTest, AmazonJpScraperTest, AmazonJpRankingScraperTest).

- [ ] **Step 9.2: Verify amazon_jp is reachable in artisan commands**

```bash
php artisan scraper:run amazon_jp --help
php artisan scraper:rankings amazon_jp --help
```

Both commands should show without errors.

- [ ] **Step 9.3: Confirm existing platform data is untouched**

```bash
php artisan tinker --execute="echo \App\Models\Product::where('platform', 'amazon')->count() . ' amazon India products still present';"
```

Expected: Shows the existing count — same as before this work.

- [ ] **Step 9.4: Final commit summary**

```bash
git log --oneline -8
```

Expected to see these commits (most recent first):
```
feat: build admin scraper dashboard view with amazon_jp platform card
feat: add amazon_jp to ScraperController platform list and validation
feat: wire amazon_jp into ScrapeRankingsCommand
feat: wire amazon_jp into ScrapeCommand
feat: add AmazonJpRankingScraper with amazon.co.jp search URL
feat: add AmazonJpScraper extending AmazonScraper with amazon.co.jp domain
feat: add amazon_jp platform config with JPY price range
feat: currency-aware price validation in DataSanitizer
```

---

## Notes for Operators

- **Amazon Japan keywords** — rankings scraping reads from `Keyword::where('platform', 'amazon_jp')`. These must be added via the admin Keywords section (`/admin/keywords`) before running rankings for the first time.
- **Category URLs** — the configured URLs use keyword + brand filter format. If Amazon Japan changes their URL structure, update `config/scraper.php` `amazon_jp.category_urls` entries only — no code changes needed.
- **meesho ScraperController bug** — meesho is missing from `ScraperController` platform list and validation. Not in scope for this plan; tracked as a separate issue.
- **Hardcoded scraper_id** — `AmazonRankingScraper` and `FlipkartRankingScraper` use a hardcoded `scraper_id = "1000000044"`. `AmazonJpRankingScraper` inherits this. Not in scope; tracked as a separate issue.
