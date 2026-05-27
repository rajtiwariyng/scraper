# Scrapers — per-platform reference & extension guide

This document covers (1) every scraper that already exists, what it pulls and
how it fetches it, and (2) the mechanical steps to add a new platform.
Conceptual flow is in [ARCHITECTURE.md](ARCHITECTURE.md); this is the
practical reference.

---

## 1. Existing scrapers

### 1.1 Product detail page (PDP) scrapers

All PDP scrapers extend `App\Services\Scrapers\BaseScraper` and live under
`app/Services/Scrapers/`.

| Class                          | Platform key       | Transport       | Notes                                              |
|--------------------------------|--------------------|-----------------|----------------------------------------------------|
| `AmazonScraper`                | `amazon`           | Guzzle HTTP     | Largest scraper (~1k LOC); category + PDP parsing  |
| `FlipkartScraper`              | `flipkart`         | **Browsershot** | `useJavaScript = true`; needs Chrome               |
| `CromaScraper`                 | `croma`            | Guzzle HTTP     | Category lists are huge for `mobile` queries       |
| `RelianceDigitalScraper`       | `reliancedigital`  | Guzzle HTTP     | Brand-scoped collections                            |
| `VijaySalesScraper`            | `vijaysales`       | Guzzle HTTP     | Mix of `/c/` and `/search-listing` URLs            |
| `BigBasketScraper`             | `bigbasket`        | Guzzle HTTP     | Single grocery category configured                  |
| `BlinkitScraper`               | `blinkit`          | Guzzle HTTP     | Single search-results URL                           |
| `MeeshoScraper`                | `meesho`           | Guzzle HTTP     | Not registered in `ScraperServiceProvider`         |
| `ZeptoScraper`                 | `zepto`            | Guzzle HTTP     | Multiple grocery categories                        |

Every PDP scraper implements three methods from `BaseScraper`:

```php
abstract protected function setupPlatformConfig(): void;
abstract protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array;
abstract protected function extractProductData(Crawler $crawler, string $productUrl): array;
```

`setupPlatformConfig()` toggles `$useJavaScript` and fills `$paginationConfig`
(max pages, page-param name, `delay_between_pages`, etc.). The other two
return arrays — URLs to follow and the field map for `Product::create()` /
`updateIfChanged()`.

### 1.2 Ranking scrapers

Track the position of products in a platform's keyword search results.
Inserts rows into `product_rankings` keyed on `(product_id, keyword_id,
created_at)`.

| Class                          | Platform     | Transport       |
|--------------------------------|--------------|-----------------|
| `AmazonRankingScraper`         | `amazon`     | Guzzle HTTP     |
| `FlipkartRankingScraper`       | `flipkart`   | **Browsershot** |
| `VijaySalesRankingScraper`     | `vijaysales` | Guzzle HTTP     |
| `CromaRankingScraper`          | `croma`      | Guzzle HTTP     |
| `BigBasketRankingScraper`      | `bigbasket`  | Guzzle HTTP     |
| `MeeshoRankingScraper`         | `meesho`     | Guzzle HTTP     |
| `RelianceDigitalRankingScraper`| `reliancedigital` | Guzzle HTTP|

Public entry point is `scrapeRankings(?array $keywordIds = null)` returning
a stats array (`keywords_processed`, `products_found`, `rankings_recorded`,
`errors_count`).

### 1.3 Review scrapers

Extract individual customer reviews into the `reviews` table.

| Class                            | Platform           | Transport            |
|----------------------------------|--------------------|----------------------|
| `AmazonReviewScraper`            | `amazon`           | Guzzle HTTP          |
| `AmazonReviewScraperBrowser`     | `amazon`           | Browsershot          |
| `AmazonReviewScraperWithAuth`    | `amazon` (auth'd)  | Browsershot + cookies|
| `FlipkartReviewScraper`          | `flipkart`         | Browsershot          |
| `CromaReviewScraper`             | `croma`            | Guzzle HTTP          |
| `RelianceDigitalReviewScraper`   | `reliancedigital`  | Guzzle HTTP          |
| `VijaySalesReviewScraper`        | `vijaysales`       | Guzzle HTTP          |
| `BigBasketReviewScraper`         | `bigbasket`        | Guzzle HTTP          |
| `MeeshoReviewScraper`            | `meesho`           | Guzzle HTTP          |

Public entry point is one of `scrapeReviews()` / `scrapeAllReviews()` —
the unified command (`scraper:reviews-platform`) abstracts over the naming
difference.

> Review scrapers do **not** extend `BaseScraper`. They re-implement their
> own retry, delay, and HTTP logic. This is duplication; the audit
> recommends a `BaseReviewScraper` parent.

---

## 2. What gets stored

### 2.1 Fields a PDP scraper is expected to return

`extractProductData()` returns an associative array. The following keys are
either required (validation) or optional but commonly populated:

| Key                  | Required | Type      | Notes                                 |
|----------------------|:--------:|-----------|---------------------------------------|
| `sku`                | ✅       | string    | Platform-unique product ID            |
| `title`              | ✅       | string    | ≤ 500 chars                           |
| `platform`           | (auto)   | string    | Filled in by `BaseScraper`            |
| `description`        |          | string    | ≤ 5 000 chars                         |
| `price`              |          | decimal   | INR, in `validation.price_range`      |
| `sale_price`         |          | decimal   | INR                                   |
| `rating`             |          | float     | 0–5                                   |
| `review_count`       |          | int       |                                       |
| `category`           |          | string    |                                       |
| `inventory_status`   |          | string    | `in_stock`, `out_of_stock`, …         |
| `brand`              |          | string    | ≤ 100 chars                           |
| `model`              |          | string    | ≤ 200 chars                           |
| `image_urls`         |          | string[]  | Cast to JSON in the model             |
| `variants`           |          | array     | Cast to JSON in the model             |
| `is_prime`           |          | boolean   | Amazon-specific badge                 |
| `is_sponsored`       |          | boolean   |                                       |
| `offers`             |          | string[]  | "Bank offer", "Exchange offer", …     |

`saveProductData()` will reject the row if `sku`, `title`, or `platform` is
missing (it logs a warning and increments `errors_count`).

### 2.2 Fields a review scraper is expected to return

Per review (one row in `reviews`):

| Key                 | Notes                                                 |
|---------------------|-------------------------------------------------------|
| `product_id`        | FK → `products.id`                                    |
| `review_id`         | Platform-specific identifier; uniqueness key          |
| `reviewer_name`     |                                                       |
| `rating`            | 1–5                                                   |
| `review_title`      |                                                       |
| `review_text`       | The body                                              |
| `review_date`       | Parse to a `Carbon` instance                          |
| `verified_purchase` | Boolean                                               |
| `helpful_count`     | Integer                                               |
| `variant_info`      | "Size: L", "Colour: Black", …                         |
| `review_images`     | Array, JSON-cast                                      |

### 2.3 Fields a ranking scraper inserts

Per `(keyword, product)` pair found in the SERP:

| Key           | Notes                                                |
|---------------|------------------------------------------------------|
| `product_id`  | FK → `products.id` (matched by SKU)                  |
| `keyword_id`  | FK → `keywords.id`                                   |
| `sku`         | Denormalised for query speed                         |
| `position`    | 1-based rank within `page`                           |
| `page`        | SERP page (1 = first page of results)                |

---

## 3. Adding a new platform

The repository has nine platforms today; adding a tenth touches **six**
locations. Take the existing `BlinkitScraper` as your template if the new
site is plain HTML; take `FlipkartScraper` if it requires headless Chrome.

### Step 1 — write the scraper class

`app/Services/Scrapers/MyStoreScraper.php`:

```php
<?php

namespace App\Services\Scrapers;

use Symfony\Component\DomCrawler\Crawler;

class MyStoreScraper extends BaseScraper
{
    public function __construct()
    {
        parent::__construct('mystore');     // platform key — must match config
    }

    protected function setupPlatformConfig(): void
    {
        // $this->useJavaScript = true;     // enable for JS-rendered sites
        $this->paginationConfig = [
            'type'                  => 'page_number',
            'page_param'            => 'page',
            'max_pages'             => 50,
            'max_consecutive_errors'=> 3,
            'delay_between_pages'   => [3, 6],
            'retry_failed_pages'    => true,
            'max_retries_per_page'  => 2,
        ];
    }

    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        return $crawler
            ->filter('a.product-card__link')   // <-- replace with real selector
            ->each(fn (Crawler $a) => 'https://www.mystore.com' . $a->attr('href'));
    }

    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        return [
            'sku'              => $this->extractSkuFromUrl($productUrl),
            'title'            => $this->cleanText(optional($crawler->filter('h1'))->text()),
            'price'            => $this->extractPrice(optional($crawler->filter('.price'))->text()),
            'rating'           => $this->extractRating(optional($crawler->filter('.rating'))->text()),
            'review_count'     => $this->extractReviewCount(optional($crawler->filter('.review-count'))->text()),
            'image_urls'       => $crawler->filter('img.product-image')->each(fn ($i) => $i->attr('src')),
            'inventory_status' => 'in_stock',
        ];
    }

    private function extractSkuFromUrl(string $url): ?string
    {
        return preg_match('#/p/([A-Za-z0-9_-]+)#', $url, $m) ? $m[1] : null;
    }
}
```

### Step 2 — register the platform in config

`config/scraper.php → platforms`:

```php
'mystore' => [
    'name'          => 'MyStore',
    'base_url'      => 'https://www.mystore.com',
    'category_urls' => [
        'https://www.mystore.com/category/printers',
    ],

    // Optional: smart wait for the browser transport. When set,
    // BrowserService::getPageContent returns as soon as any of these
    // selectors appears (typically 1–3 s on a fast page) instead of paying
    // the fallback fixed delay. Leave unset to keep the legacy delay path.
    // 'wait_for_selector'         => 'h1, [data-id]',
    // 'wait_for_selector_timeout' => 8000,

    // Optional: cookies attached to every Browsershot request for this
    // platform. Map of name => value. Sent as a single Cookie header.
    // 'cookies' => ['deliveryPincode' => '110001'],
],
```

### Step 3 — wire it into `ScrapeCommand`

`app/Console/Commands/ScrapeCommand.php`, in `createScraper()`:

```php
return match ($platform) {
    // ...existing cases
    'mystore' => new MyStoreScraper(),
    default   => null,
};
```

…and add `mystore` to the `{platform?}` argument's hint string (optional but
nice).

### Step 4 — wire it into `ProcessScrapingUrlsCommand`

Same `match` change, plus add the platform key to the `$platforms` array at
the top of `handle()`.

### Step 5 — register a service-container binding (optional but consistent)

`app/Providers/ScraperServiceProvider.php`:

```php
$this->app->bind('scraper.mystore', fn () => new MyStoreScraper());
```

…and add it to `provides()`.

### Step 6 — add a schedule entry (optional)

`app/Console/Kernel.php`:

```php
$schedule->command('scraper:run mystore')
         ->weekly()
         ->mondays()
         ->at('05:00')
         ->withoutOverlapping(43200);
```

### Step 7 — verify

```bash
php artisan config:cache
php artisan list scraper           # the help text should now mention mystore
php artisan scraper:run mystore --limit=5
php artisan scraper:status --platform=mystore --detailed
```

If the smoke test extracts at least one product, you are done. If not, see
[OPERATIONS.md → Debugging a failing scraper](OPERATIONS.md#debugging-a-failing-scraper).

---

## 4. Adding a new field

Schema → model → scrapers, in that order.

1. **Migration**:
   ```bash
   php artisan make:migration add_material_to_products_table --table=products
   ```
   ```php
   $table->string('material', 100)->nullable()->after('color');
   ```
   Run `php artisan migrate`.

2. **Model**: add `'material'` to `$fillable` in `App\Models\Product`.

3. **Scraper(s)**: extend the array returned by `extractProductData()`:
   ```php
   'material' => $this->cleanText(optional($crawler->filter('.spec-material'))->text()),
   ```

`updateIfChanged()` will pick up the new field automatically — no further
code changes needed.

---

## 5. Working with selectors

### CSS via the DOM crawler

```php
$title = $crawler->filter('h1.title')->text();
$price = $crawler->filter('span.a-price-whole')->each(fn ($n) => $n->text())[0] ?? null;
```

### XPath when CSS is not enough

```php
$json = $crawler->filterXPath('//script[@type="application/ld+json"]')->first()->text();
```

### Helper utilities on `BaseScraper`

| Helper                                         | Use                                            |
|------------------------------------------------|------------------------------------------------|
| `$this->cleanText(?string)`                    | strip tags, collapse whitespace, trim          |
| `$this->extractPrice(?string)`                 | strip `₹`/commas, return float or null         |
| `$this->extractRating(?string)`                | first numeric value, e.g. "4.3 out of 5" → 4.3 |
| `$this->extractReviewCount(?string)`           | first integer in the string                    |

Prefer these over re-implementing per-platform — they all handle nulls.

### Failure modes to guard against

- **Selector returns empty** — every `filter()->text()` will throw if there
  is no match. Wrap in `optional(...)->text()` or `count()` checks.
- **Currency symbols vary** — `extractPrice()` already strips them, but
  range strings like "₹999 – ₹1,499" return only the first number.
- **Lazy-loaded images** — Browsershot waits for `domcontentloaded`, not
  `networkidle`. Image URLs may be in `data-src` rather than `src`.
- **Prime / sponsored badges** — these are SERP-only, not on the PDP. Set
  them in `extractProductUrls()` and pass them through.

---

## 6. Testing a scraper locally

There are no automated tests for scrapers today. The pragmatic loop:

```bash
# 1. Hit one URL with the limit knob low
php artisan scraper:run flipkart --limit=1 --force

# 2. Watch the log
tail -f storage/logs/laravel.log

# 3. If parsing fails, dump the HTML the scraper saw
#    (temporarily, in BrowserService::getPageContent)
file_put_contents(storage_path('logs/debug.html'), $html);

# 4. Open storage/logs/debug.html in a real browser, inspect the markup
#    that came back, fix selectors, repeat.
```

Adding scraper tests is on the audit's medium-priority backlog.
