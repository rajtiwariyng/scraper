# Amazon KSA Scraper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Amazon Saudi Arabia (amazon.sa) product and ranking scrapers for Mattresses (Sleepwell & Kurlon brands), storing data in the existing `products` and `product_rankings` tables under `platform = 'amazon_sa'`.

**Architecture:** Two thin subclasses mirror the Amazon JP pattern — `AmazonSaScraper extends AmazonScraper` and `AmazonSaRankingScraper extends AmazonRankingScraper`. Only the domain, Accept-Language header, and search URL builder are overridden. All product data extraction logic is inherited unchanged from the India parent classes.

**Tech Stack:** PHP 8.x, Laravel, GuzzleHTTP, Symfony DomCrawler, PHPUnit

---

## File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Modify | `app/Services/Scrapers/AmazonScraper.php` | Add SAR currency symbols to currency map |
| Create | `app/Services/Scrapers/AmazonSaScraper.php` | KSA product scraper — overrides domain + locale |
| Create | `app/Services/Scrapers/AmazonSaRankingScraper.php` | KSA ranking scraper — overrides domain + search URL |
| Create | `tests/Unit/AmazonSaScraperTest.php` | Unit tests for AmazonSaScraper |
| Create | `tests/Unit/AmazonSaRankingScraperTest.php` | Unit tests for AmazonSaRankingScraper |
| Modify | `config/scraper.php` | Add amazon_sa platform block + price range |
| Modify | `app/Console/Commands/ScrapeCommand.php` | Register amazon_sa in match + signature |

---

## Task 1: Add SAR Currency Symbols to AmazonScraper

**Files:**
- Modify: `app/Services/Scrapers/AmazonScraper.php`

The `extractCurrencyCode()` method in `AmazonScraper` maps price symbols to ISO currency codes. Amazon KSA uses two SAR representations: `﷼` (U+FDFC, Arabic rial sign) and `ر.س` (two-character Arabic abbreviation).

- [ ] **Step 1: Open the currency map in AmazonScraper**

In `app/Services/Scrapers/AmazonScraper.php`, find `extractCurrencyCode()` around line 317. Locate the `$currencyMap` array:

```php
$currencyMap = [
    '₹' => 'INR',
    '$' => 'USD',
    '£' => 'GBP',
    '€' => 'EUR',
    '¥' => 'JPY',
    '₩' => 'KRW',
];
```

Replace it with:

```php
$currencyMap = [
    '₹' => 'INR',
    '$' => 'USD',
    '£' => 'GBP',
    '€' => 'EUR',
    '¥' => 'JPY',
    '₩' => 'KRW',
    '﷼' => 'SAR',
    'ر.س' => 'SAR',
];
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Scrapers/AmazonScraper.php
git commit -m "feat: add SAR currency symbols to Amazon currency map"
```

---

## Task 2: Create AmazonSaScraper (TDD)

**Files:**
- Create: `tests/Unit/AmazonSaScraperTest.php`
- Create: `app/Services/Scrapers/AmazonSaScraper.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/AmazonSaScraperTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Scrapers\AmazonSaScraper;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class AmazonSaScraperTest extends TestCase
{
    private AmazonSaScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new AmazonSaScraper();
    }

    public function test_platform_is_amazon_sa(): void
    {
        $this->assertEquals('amazon_sa', $this->scraper->getPlatform());
    }

    public function test_extract_product_urls_uses_amazon_sa_domain(): void
    {
        $html = <<<HTML
<html><body>
<div data-cy="title-recipe">
  <a class="a-link-normal s-no-outline" href="/dp/B08N5LNQCX/ref=sr_1_1">Product</a>
</div>
</body></html>
HTML;
        $crawler = new Crawler($html);
        $urls = $this->scraper->testExtractProductUrls($crawler, 'https://www.amazon.sa/s?k=mattress');

        $this->assertCount(1, $urls);
        $this->assertStringStartsWith('https://www.amazon.sa', $urls[0]);
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
        $urls = $this->scraper->testExtractProductUrls($crawler, 'https://www.amazon.sa/s?k=mattress');

        foreach ($urls as $url) {
            $this->assertStringNotContainsString('amazon.in', $url);
        }
    }

    public function test_extract_product_urls_skips_non_dp_links(): void
    {
        $html = <<<HTML
<html><body>
<div data-cy="title-recipe">
  <a class="a-link-normal s-no-outline" href="/gp/bestsellers/home">Not a product</a>
</div>
</body></html>
HTML;
        $crawler = new Crawler($html);
        $urls = $this->scraper->testExtractProductUrls($crawler, 'https://www.amazon.sa/s?k=mattress');

        $this->assertEmpty($urls);
    }

    public function test_set_scraper_id_stores_and_retrieves_the_id(): void
    {
        $scraper = new AmazonSaScraper();
        $scraper->setScraperId('1000000099');
        $this->assertEquals('1000000099', $scraper->getScraperId());
    }

    public function test_scraper_id_defaults_to_empty_string(): void
    {
        $scraper = new AmazonSaScraper();
        $this->assertEquals('', $scraper->getScraperId());
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php vendor/bin/phpunit tests/Unit/AmazonSaScraperTest.php --testdox
```

Expected output: `FAIL` — class `AmazonSaScraper` not found.

- [ ] **Step 3: Create AmazonSaScraper**

Create `app/Services/Scrapers/AmazonSaScraper.php`:

```php
<?php

namespace App\Services\Scrapers;

use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AmazonSaScraper extends AmazonScraper
{
    public function __construct()
    {
        parent::__construct('amazon_sa');
    }

    protected function setupPlatformConfig(): void
    {
        parent::setupPlatformConfig();
        $this->platform = 'amazon_sa';
        $this->defaultHeaders = ['Accept-Language' => 'ar-SA,ar;q=0.9,en;q=0.8'];
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
                            $href = 'https://www.amazon.sa' . $href;
                        }
                        if (strpos($href, '/dp/') !== false || strpos($href, '/product/') !== false) {
                            $productUrls[] = $href;
                        }
                    }
                });
            }

            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50);

            Log::info("Extracted {count} product URLs from Amazon KSA category page", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Amazon KSA", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl,
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

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php vendor/bin/phpunit tests/Unit/AmazonSaScraperTest.php --testdox
```

Expected output: All 5 tests `PASS`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Scrapers/AmazonSaScraper.php tests/Unit/AmazonSaScraperTest.php
git commit -m "feat: add AmazonSaScraper for Amazon KSA market"
```

---

## Task 3: Create AmazonSaRankingScraper (TDD)

**Files:**
- Create: `tests/Unit/AmazonSaRankingScraperTest.php`
- Create: `app/Services/Scrapers/AmazonSaRankingScraper.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/AmazonSaRankingScraperTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Scrapers\AmazonSaRankingScraper;
use Tests\TestCase;

class AmazonSaRankingScraperTest extends TestCase
{
    private AmazonSaRankingScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new AmazonSaRankingScraper();
    }

    public function test_platform_is_amazon_sa(): void
    {
        $this->assertEquals('amazon_sa', $this->scraper->getPlatform());
    }

    public function test_build_search_url_uses_amazon_sa(): void
    {
        $url = $this->scraper->testBuildSearchUrl('mattress', 1);

        $this->assertStringStartsWith('https://www.amazon.sa/s', $url);
        $this->assertStringContainsString('k=mattress', $url);
        $this->assertStringContainsString('page=1', $url);
    }

    public function test_build_search_url_does_not_use_amazon_in(): void
    {
        $url = $this->scraper->testBuildSearchUrl('mattress', 1);

        $this->assertStringNotContainsString('amazon.in', $url);
    }

    public function test_build_search_url_page_2(): void
    {
        $url = $this->scraper->testBuildSearchUrl('sleepwell mattress', 2);

        $this->assertStringContainsString('page=2', $url);
        $this->assertStringContainsString('k=sleepwell+mattress', $url);
    }

    public function test_set_scraper_id_is_inherited(): void
    {
        $scraper = new AmazonSaRankingScraper();
        $scraper->setScraperId('1000000099');
        $this->assertEquals('1000000099', $scraper->getScraperId());
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php vendor/bin/phpunit tests/Unit/AmazonSaRankingScraperTest.php --testdox
```

Expected output: `FAIL` — class `AmazonSaRankingScraper` not found.

- [ ] **Step 3: Create AmazonSaRankingScraper**

Create `app/Services/Scrapers/AmazonSaRankingScraper.php`:

```php
<?php

namespace App\Services\Scrapers;

class AmazonSaRankingScraper extends AmazonRankingScraper
{
    protected string $platform = 'amazon_sa';

    protected function getHeaders(): array
    {
        $headers = parent::getHeaders();
        $headers['Accept-Language'] = 'ar-SA,ar;q=0.9,en;q=0.8';
        return $headers;
    }

    protected function buildSearchUrl(string $keyword, int $page): string
    {
        $params = [
            'k' => $keyword,
            'page' => $page,
        ];

        return 'https://www.amazon.sa/s?' . http_build_query($params);
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

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php vendor/bin/phpunit tests/Unit/AmazonSaRankingScraperTest.php --testdox
```

Expected output: All 5 tests `PASS`.

- [ ] **Step 5: Run the full unit test suite to check for regressions**

```bash
php vendor/bin/phpunit --testsuite Unit --testdox
```

Expected output: All existing tests still `PASS`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Scrapers/AmazonSaRankingScraper.php tests/Unit/AmazonSaRankingScraperTest.php
git commit -m "feat: add AmazonSaRankingScraper for Amazon KSA market"
```

---

## Task 4: Register amazon_sa in config/scraper.php

**Files:**
- Modify: `config/scraper.php`

- [ ] **Step 1: Add the amazon_sa platform block**

In `config/scraper.php`, inside the `'platforms'` array, add the following block after the `'amazon_jp'` entry (around line 56):

```php
'amazon_sa' => [
    'name' => 'Amazon KSA',
    'base_url' => 'https://www.amazon.sa',
    'category_urls' => [
        // Sleepwell mattresses
        'https://www.amazon.sa/s?k=mattress&rh=p_89%3ASleepwell',
        // Kurlon mattresses
        'https://www.amazon.sa/s?k=mattress&rh=p_89%3AKurlon',
    ],
],
```

- [ ] **Step 2: Add price range validation for amazon_sa**

In `config/scraper.php`, inside `'validation' → 'price_range_by_platform'` (around line 245), add:

```php
'amazon_sa' => [
    'min' => 50,    // SAR
    'max' => 20000, // SAR
],
```

The full `price_range_by_platform` block after the change should look like:

```php
'price_range_by_platform' => [
    'amazon_jp' => [
        'min' => 100,
        'max' => 5000000,
    ],
    'amazon_sa' => [
        'min' => 50,
        'max' => 20000,
    ],
],
```

- [ ] **Step 3: Commit**

```bash
git add config/scraper.php
git commit -m "feat: add amazon_sa platform config with Sleepwell and Kurlon mattress URLs"
```

---

## Task 5: Register amazon_sa in ScrapeCommand

**Files:**
- Modify: `app/Console/Commands/ScrapeCommand.php`

- [ ] **Step 1: Add the import**

At the top of `app/Console/Commands/ScrapeCommand.php`, add the import after the existing Amazon imports:

```php
use App\Services\Scrapers\AmazonSaScraper;
```

The import block should look like:

```php
use App\Services\Scrapers\AmazonScraper;
use App\Services\Scrapers\AmazonJpScraper;
use App\Services\Scrapers\AmazonSaScraper;
```

- [ ] **Step 2: Update the command signature**

Find the `$signature` property (around line 27). Update the `{platform?}` description line to include `amazon_sa`:

```php
protected $signature = 'scraper:run 
                        {platform? : Platform to scrape (amazon, amazon_jp, amazon_sa, flipkart, vijaysales, reliancedigital, croma, bigbasket, blinkit, meesho, zepto, all)}
                        {--force : Force scraping even if recently scraped}
                        {--limit=50 : Limit number of products per platform}
                        {--timeout=7200 : Maximum execution time in seconds}';
```

- [ ] **Step 3: Register in createScraper()**

Find `createScraper()` (around line 174). Add `amazon_sa` to the match expression:

```php
return match ($platform) {
    'amazon'          => new AmazonScraper(),
    'amazon_jp'       => new AmazonJpScraper(),
    'amazon_sa'       => new AmazonSaScraper(),
    'flipkart'        => new FlipkartScraper(),
    'vijaysales'      => new VijaySalesScraper(),
    'reliancedigital' => new RelianceDigitalScraper(),
    'croma'           => new CromaScraper(),
    'bigbasket'       => new BigBasketScraper(),
    'blinkit'         => new BlinkitScraper(),
    'meesho'          => new MeeshoScraper(),
    'zepto'           => new ZeptoScraper(),
    default           => null,
};
```

- [ ] **Step 4: Verify the command recognises the new platform**

```bash
php artisan scraper:run --help
```

Expected: `amazon_sa` appears in the platform argument description.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ScrapeCommand.php
git commit -m "feat: register amazon_sa in ScrapeCommand"
```

---

## Done

After all tasks complete, verify the full unit suite is green:

```bash
php vendor/bin/phpunit --testsuite Unit --testdox
```

Run a dry-run to confirm the platform is wired up end-to-end:

```bash
php artisan scraper:run amazon_sa --limit=2
```

Expected: scraper starts, fetches from `https://www.amazon.sa/s?k=mattress&rh=p_89%3ASleepwell`, and saves products with `platform = amazon_sa` and `currency_code = SAR`.
