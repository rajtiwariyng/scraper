# Amazon KSA Scraper — Design Spec

**Date:** 2026-05-27
**Status:** Approved

## Overview

Add Amazon Saudi Arabia (`amazon.sa`) scraping support for the Mattresses category, filtered to two brands: **Sleepwell** and **Kurlon**. Data is stored in the same `products` and `product_rankings` tables used by Amazon India and Amazon Japan, identified by `platform = 'amazon_sa'`. Language headers are set to Arabic (`ar-SA`). No review scraper is needed.

---

## Architecture

Follows the exact pattern established by Amazon Japan:

- `AmazonJpScraper extends AmazonScraper` → `AmazonSaScraper extends AmazonScraper`
- `AmazonJpRankingScraper extends AmazonRankingScraper` → `AmazonSaRankingScraper extends AmazonRankingScraper`

All Amazon-specific HTML parsing logic (product detail extraction, BSR, ratings, images, etc.) lives in the parent `AmazonScraper` and is inherited unchanged. The KSA subclasses only override locale and domain.

---

## New Files

### `app/Services/Scrapers/AmazonSaScraper.php`

Extends `AmazonScraper`. Overrides:

- `setupPlatformConfig()` — sets `$this->platform = 'amazon_sa'`, calls `parent::setupPlatformConfig()`
- `$this->defaultHeaders['Accept-Language']` — set to `ar-SA,ar;q=0.9,en;q=0.8`
- `extractProductUrls()` — same selectors as parent, but relative URLs prefixed with `https://www.amazon.sa` instead of `https://www.amazon.in`
- Constructor — `parent::__construct('amazon_sa')`
- `getPlatform()` — returns `$this->platform`
- `testExtractProductUrls()` — test proxy (mirrors JP pattern)

### `app/Services/Scrapers/AmazonSaRankingScraper.php`

Extends `AmazonRankingScraper`. Overrides:

- `$platform = 'amazon_sa'`
- `getHeaders()` — calls `parent::getHeaders()`, sets `Accept-Language: ar-SA,ar;q=0.9,en;q=0.8`
- `buildSearchUrl(string $keyword, int $page)` — returns `https://www.amazon.sa/s?k={keyword}&page={page}`
- `getPlatform()` — returns `$this->platform`
- `testBuildSearchUrl()` — test proxy

---

## Modified Files

### `config/scraper.php`

Add `amazon_sa` to the `platforms` array:

```php
'amazon_sa' => [
    'name' => 'Amazon KSA',
    'base_url' => 'https://www.amazon.sa',
    'category_urls' => [
        'https://www.amazon.sa/s?k=mattress&rh=p_89%3ASleepwell',
        'https://www.amazon.sa/s?k=mattress&rh=p_89%3AKurlon',
    ],
],
```

Add `amazon_sa` price range to `validation.price_range_by_platform`:

```php
'amazon_sa' => [
    'min' => 50,    // SAR
    'max' => 20000, // SAR
],
```

### `app/Services/Scrapers/AmazonScraper.php`

Add Saudi Riyal to the currency symbol map in `extractCurrencyCode()`:

```php
'﷼' => 'SAR',
```

### `app/Console/Commands/ScrapeCommand.php`

1. Add `amazon_sa` to the `{platform?}` argument description string.
2. Add `use App\Services\Scrapers\AmazonSaScraper;` import.
3. Add to `createScraper()` match: `'amazon_sa' => new AmazonSaScraper()`.

---

## Data Storage

| Table | Platform value | Notes |
|---|---|---|
| `products` | `amazon_sa` | Same columns as India/JP. `currency_code` = `SAR`. |
| `product_rankings` | `amazon_sa` | Populated by `AmazonSaRankingScraper`. |

**No database migration required.** The schema already supports new platform values.

---

## Out of Scope

- Review scraper (not requested)
- Admin UI changes (platform will appear automatically wherever `platform` is listed)
- Keywords table seeding for ranking scraper (user manages keywords via existing admin interface)

---

## Risks

- Amazon.sa may serve Arabic-only page content for some selectors. The existing CSS selectors in `AmazonScraper` (e.g. `#productTitle`, `.a-price-whole`) are HTML attribute–based and language-agnostic, so they should work without change.
- SAR currency symbol on Amazon.sa may render as `ر.س` (two-character Arabic) rather than `﷼`. Both should be added to the currency map.
