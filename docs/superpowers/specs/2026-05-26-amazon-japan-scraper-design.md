# Amazon Japan Scraper + Admin Panel — Design Spec

**Date:** 2026-05-26  
**Status:** Approved  
**Scope:** Add Amazon Japan (amazon.co.jp) product + ranking scraping with admin panel support

---

## 1. Goals

- Scrape the same product categories from Amazon Japan (amazon.co.jp) as currently scraped from Amazon India (amazon.in): printers, mobiles, diapers/wipes, detergent
- Scrape product data and keyword rankings (no reviews)
- Store data under platform key `amazon_jp` — separate from existing `amazon` (India) records
- Handle JPY price validation correctly (separate price range from INR)
- Surface Amazon Japan as a manageable platform card in the admin scraper dashboard
- Zero impact on existing scrapers or stored data

---

## 2. Architecture

### 2.1 New Files

**`app/Services/Scrapers/AmazonJpScraper.php`**
- Extends `AmazonScraper`
- Overrides:
  - `$platform = 'amazon_jp'`
  - `setupPlatformConfig()` — sets base URL to `https://www.amazon.co.jp`, inherits all other pagination config
  - `extractProductUrls()` — replaces the hardcoded `amazon.in` domain with `amazon.co.jp` when converting relative URLs to absolute
- Inherits all 40+ extraction methods unchanged (title, price, rating, images, specs, etc.)
- CSS selectors are identical between amazon.in and amazon.co.jp — no selector changes needed
- ASIN pattern (`/dp/[A-Z0-9]{10}`) works globally — no SKU extraction change needed
- `sanitizeString()` uses `/\p{L}/u` Unicode mode — Japanese characters are preserved correctly

**`app/Services/Scrapers/AmazonJpRankingScraper.php`**
- Extends `AmazonRankingScraper`
- Overrides:
  - `$platform = 'amazon_jp'`
  - `buildSearchUrl()` — uses `https://www.amazon.co.jp/s` instead of `https://www.amazon.in/s`
- Inherits all retry logic, sponsored detection, UA rotation, ranking save logic

### 2.2 Data Flow

```
Admin "Run Products" (amazon_jp)
  → ScraperController::runScraper()
  → artisan scraper:run amazon_jp
  → ScrapeCommand::createScraper('amazon_jp')
  → new AmazonJpScraper()
  → BaseScraper::scrape(category_urls from config)
  → AmazonJpScraper::extractProductUrls() [amazon.co.jp URLs]
  → AmazonJpScraper::extractProductData() [inherited from AmazonScraper]
  → DataSanitizer::sanitizeProductData() [JPY price range applied]
  → Product::create/update with platform='amazon_jp'

Admin "Run Rankings" (amazon_jp)
  → artisan scraper:rankings amazon_jp
  → ScrapeRankingsCommand → new AmazonJpRankingScraper()
  → Keyword::where('platform', 'amazon_jp') [admin must add JP keywords]
  → AmazonJpRankingScraper::buildSearchUrl() [amazon.co.jp]
  → ProductRanking::create with platform='amazon_jp'
```

---

## 3. Config Changes (`config/scraper.php`)

### 3.1 New Platform Block

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

### 3.2 Per-Platform Price Range

Added inside `validation`:
```php
'price_range_by_platform' => [
    'amazon_jp' => [
        'min' => 100,       // ¥100 minimum
        'max' => 5000000    // ¥5,000,000 maximum
    ]
]
```

---

## 4. DataSanitizer Changes (`app/Services/DataSanitizer.php`)

**Change 1:** `sanitizePrice()` gains an optional `$priceRange` parameter:
```php
public static function sanitizePrice($price, ?array $priceRange = null): ?float
{
    // ... existing cleanup ...
    $priceRange = $priceRange ?? config('scraper.validation.price_range', ['min' => 10, 'max' => 1600000]);
    // ... existing range check ...
}
```

**Change 2:** `sanitizeProductData()` resolves the correct range before calling `sanitizePrice()`:
```php
$platform = $sanitized['platform'] ?? '';
$priceRange = config("scraper.validation.price_range_by_platform.{$platform}")
    ?? config('scraper.validation.price_range');

$sanitized['price'] = self::sanitizePrice($data['price'] ?? null, $priceRange);
$sanitized['sale_price'] = self::sanitizePrice($data['sale_price'] ?? null, $priceRange);
```

**Backwards compatibility:** All existing platforms pass `null` implicitly — fall back to global INR range exactly as before. Only `amazon_jp` gets the JPY range.

---

## 5. Controller & Command Changes

### 5.1 `ScraperController.php`

```php
// Platform list (line ~20):
$platforms = ['amazon', 'amazon_jp', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket', 'zepto'];

// Validation rule (line ~73):
'platform' => 'required|string|in:amazon,amazon_jp,flipkart,...,all',
```

### 5.2 `ScrapeCommand.php`

```php
// Add to createScraper() match:
'amazon_jp' => new AmazonJpScraper(),

// Add use import:
use App\Services\Scrapers\AmazonJpScraper;
```

Note: `scrapeAllPlatforms()` already reads `array_keys(config('scraper.platforms'))` — `amazon_jp` is auto-included in `all` runs once added to config.

### 5.3 `ScrapeRankingsCommand.php`

```php
// Add block after existing Amazon India block:
if ($platform === 'all' || $platform === 'amazon_jp') {
    $this->info('Scraping Amazon Japan rankings...');
    $scraper = new AmazonJpRankingScraper();
    $stats['amazon_jp'] = $scraper->scrapeRankings($keywordIds ?: null);
}

// Add use import:
use App\Services\Scrapers\AmazonJpRankingScraper;
```

---

## 6. Admin Panel View (`resources/views/admin/scraper/index.blade.php`)

The current file is effectively empty (1 line). The full scraper dashboard view needs to be built. It will display:

**Overall stats bar** (top):
- Total runs, successful runs, failed runs, currently running, total products scraped

**Per-platform grid** — one card per platform in `$platforms` array, including `amazon_jp`:
- Platform name (e.g. "Amazon Japan")
- Last run: timestamp + products scraped count (from `$platformStats[platform]`)
- Live "Running" badge if `is_running = true`
- Two action buttons: **Run Products** and **Run Rankings**
- Both buttons POST to `POST /admin/scraper/run` with `platform` + `type` fields

**Recent runs table** (bottom):
- Lists `$recentRuns` with platform, type, status, products scraped, duration, triggered at

**No new routes, no new JS needed.** The controller already provides all data. The run buttons use an HTML form POST — the existing endpoint handles any valid platform string including `amazon_jp`.

---

## 7. No Database Migrations Needed

- `platform` column is a string — accepts `amazon_jp` immediately
- `currency_code` column already exists and stores `JPY` (already mapped in `extractCurrencyCode()`)
- All existing data remains untouched — `amazon_jp` is a completely new partition of platform data

---

## 8. Files Changed

| File | Change Type | Risk |
|---|---|---|
| `app/Services/Scrapers/AmazonJpScraper.php` | New | None |
| `app/Services/Scrapers/AmazonJpRankingScraper.php` | New | None |
| `config/scraper.php` | Additive | None |
| `app/Services/DataSanitizer.php` | Targeted edit (2 locations) | Low — backwards compatible |
| `app/Http/Controllers/Admin/ScraperController.php` | 2 line edits | Low |
| `app/Console/Commands/ScrapeCommand.php` | 1 case + 1 import | Low |
| `app/Console/Commands/ScrapeRankingsCommand.php` | 1 block + 1 import | Low |
| `resources/views/admin/scraper/index.blade.php` | Add platform card | Low |

---

## 9. Out of Scope

- Amazon Japan reviews scraper (explicitly excluded)
- Fixing meesho missing from ScraperController (separate issue, noted but not in scope)
- Fixing hardcoded `scraper_id = "1000000044"` in ranking scrapers (separate issue)
- Converting JPY to INR — prices stored as-is in original currency
