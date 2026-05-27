# Architecture

This document describes how the scraper is wired together: the components that
exist, how a scraping run flows through them, and what the database schema
looks like. New developers should read this before [SCRAPERS.md](SCRAPERS.md)
or [COMMANDS.md](COMMANDS.md).

---

## High-level component map

```
┌──────────────────────────────────────────────────────────────────────┐
│  Cron (Linux) / Windows Task Scheduler                               │
│      → ticks `php artisan schedule:run` every minute                  │
└──────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Laravel Scheduler  (app/Console/Kernel.php)                          │
│      schedules: scraper:run all (twice daily)                         │
│                  scraper:run <platform> (daily/weekly per platform)   │
│                  scraper:cleanup, scraper:status                      │
└──────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Console Commands  (app/Console/Commands/*.php)                       │
│   - ScrapeCommand           "scraper:run"                             │
│   - ScrapeReviewsUnified    "scraper:reviews-platform"                │
│   - ScrapeRankingsCommand   "scraper:rankings"                        │
│   - ProcessScrapingUrls     "scraper:process-urls"                    │
│   - StatusCommand           "scraper:status"                          │
│   - CleanupCommand          "scraper:cleanup"                         │
│   - ManageKeywordsCommand   "keywords:manage"                         │
│   - ScrapeReviews{,Browser,Auth}Command   (Amazon-specific variants)  │
└──────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Platform Scrapers  (app/Services/Scrapers/)                          │
│                                                                       │
│   BaseScraper                                                         │
│      ├─ AmazonScraper, FlipkartScraper, CromaScraper, …  (PDP)        │
│      ├─ AmazonRankingScraper, FlipkartRankingScraper, …  (Rankings)   │
│      └─ AmazonReviewScraper, FlipkartReviewScraper, …    (Reviews)    │
│                                                                       │
│   Each scraper picks one of two transports:                           │
│      (a) Guzzle HTTP   — for plain-HTML sites (Amazon, Croma, …)      │
│      (b) BrowserService — for JS-rendered sites (Flipkart, reviews)   │
└──────────────────────────────────────────────────────────────────────┘
                ┌────────────────┴────────────────┐
                ▼                                 ▼
┌────────────────────────────┐   ┌──────────────────────────────────────┐
│ Guzzle HTTP                │   │ BrowserService (app/Services/)       │
│  + ProxyRotator            │   │  → spatie/browsershot                │
│  + UserAgentRotator        │   │     → spawns Node child per page     │
│                            │   │        → puppeteer + headless Chrome │
└────────────────────────────┘   └──────────────────────────────────────┘
                                  │
                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Eloquent models  →  MySQL                                            │
│                                                                       │
│  products, reviews, product_rankings, keywords,                       │
│  scraping_logs, scraping_urls, scraper_configurations, scraper_runs   │
└──────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│  Web layer                                                            │
│   - Public dashboard:   /dashboard               (DashboardController)│
│   - Admin panel:        /admin/*                                      │
│       /admin/products, /admin/reviews,                                │
│       /admin/keywords,  /admin/scraper,                               │
│       /admin/scraping-urls                                            │
└──────────────────────────────────────────────────────────────────────┘
```

---

## Data flow — one PDP scraping run

The path taken when you run `php artisan scraper:run flipkart`:

1. **Command**
   `app/Console/Commands/ScrapeCommand.php` resolves `flipkart` to
   `FlipkartScraper` via a `match` statement (`createScraper()`).

2. **URL set**
   `getCategoryUrls('flipkart')` returns the array configured in
   `config/scraper.php → platforms.flipkart.category_urls`.

3. **Recency guard**
   `wasRecentlyScraped()` queries `scraping_logs` for the latest run; if it is
   younger than 12 hours and `--force` was not passed, the command exits.

4. **Session start**
   `BaseScraper::scrape()` opens a row in `scraping_logs` with status
   `started`.

5. **Per-category loop**
   For each category URL the scraper either:
   - Uses pagination via Guzzle (`scrapeCategoryWithPagination`), or
   - Drives a headless Chrome via `BrowserService` if `useJavaScript = true`
     (Flipkart uses this).

6. **Product URL extraction**
   `processPageContent()` parses HTML into a `Symfony\DomCrawler\Crawler` and
   calls the platform-specific `extractProductUrls()`.

7. **Per-product loop**
   For every product URL, `scrapeProductPage()` fetches the PDP, parses it,
   and calls platform-specific `extractProductData()`. A random delay
   (`config/scraper.php → delay_min..delay_max`) is applied between products.

8. **Persistence**
   `saveProductData()` calls `Product::findByPlatformAndSku()`. If the row
   exists, `updateIfChanged()` writes only changed fields; otherwise
   `Product::create()` inserts a new row. Stats (`products_added`,
   `products_updated`, `errors_count`, …) are accumulated on the scraper.

9. **Session end**
   When all categories finish, `ScrapingLog::complete($stats)` closes the
   row with status `completed` and `duration_seconds`. On uncaught exception
   the session is closed with status `failed` and the stack trace is
   serialised into `error_details`.

The same skeleton applies to ranking and review runs, with platform-specific
extractors and a different target table (`product_rankings` or `reviews`).

---

## Two transports

### HTTP transport — `BaseScraper::fetchPage()`

Used by: Amazon, Croma, Reliance Digital, VijaySales, BigBasket, Blinkit,
Meesho, Zepto.

Stack:
- `GuzzleHttp\Client` initialised in `initializeHttpClient()`
- per-request cookie jar (`GuzzleHttp\Cookie\CookieJar`)
- header bundle from `UserAgentRotator::getRandomizedHeaders()`
- proxy from `ProxyRotator::getNextProxy()` if any are configured
- exponential backoff on retry (`pow(2, $attempt) + rand(1, 3)` seconds)

### Browser transport — `BrowserService::getPageContent()`

Used by: Flipkart (PDP) and all the `*ReviewScraper` / `*RankingScraper`
classes that drive headless Chrome.

Stack:
- `Spatie\Browsershot\Browsershot` — a PHP wrapper that spawns
  `node vendor/spatie/browsershot/bin/browser.cjs` once per page
- Chrome launched with `--no-sandbox`, `--disable-dev-shm-usage`,
  `--disable-gpu`, image-loading disabled (`blink-settings=imagesEnabled=false`)
- a fixed 6 s (listing) or 15 s (PDP) `delay()` after `domcontentloaded`
- two attempts per URL with timeout doubling on the second attempt

> The browser transport currently does **not** use `ProxyRotator` or
> `UserAgentRotator` — both are wired only for the Guzzle transport. See
> [ANTI-BLOCKING.md](ANTI-BLOCKING.md) for the implications.

---

## Configuration model

`config/scraper.php` is the single config file for the scraper.

| Key                                | Source                                                |
|------------------------------------|-------------------------------------------------------|
| `timeout`                          | `SCRAPER_TIMEOUT` env, default 30 s                   |
| `retries`                          | `SCRAPER_RETRIES` env, default 3                      |
| `delay_min` / `delay_max`          | `SCRAPER_DELAY_MIN` / `SCRAPER_DELAY_MAX`, default 2/7|
| `user_agent`                       | `SCRAPER_USER_AGENT` env                              |
| `platforms.<name>.category_urls`   | array of seed URLs (the only required per-platform key)|
| `schedule.interval_hours`          | `SCRAPER_INTERVAL_HOURS`, default 168 (7 d)           |
| `schedule.max_execution_time`      | `SCRAPER_MAX_EXECUTION_TIME`, default 86 400 s (24 h) |
| `validation.*`                     | required fields, length caps, price range            |
| `images.download_enabled`          | `SCRAPER_DOWNLOAD_IMAGES`, default `false`            |
| `logging.retention_days`           | 30                                                    |

Proxies are read from the `SCRAPER_PROXIES` env (comma-separated) and from
`storage/app/proxies.txt` (one per line). Both sources are merged.

---

## Database schema (summary)

Migrations live in `database/migrations/` and run in order. The 9 substantive
tables:

### `products`
The main domain object. One row per `(platform, sku)`. Holds the full
denormalised product description: title, descriptions, prices, ratings,
review counts, image URLs (JSON), variants (JSON), category breadcrumbs,
brand/model, stock status, badges (`is_prime`, `is_sponsored`), and
`include_exclude` (admin override flag). Indexed on `platform`, `sku`,
`brand`, `is_active`, `scraped_date`.

### `reviews`
One row per review. FK → `products.id` with cascade delete. Unique on
`(product_id, review_id)` so re-scraping is idempotent. Has `platform`
column (added in migration 7) and `sku` column (migration 16) so reviews can
be looked up without joining to `products`.

### `product_rankings`
Time-series of search-rank positions. FK → `products.id` and
`keywords.id`. One row per `(product, keyword, scrape-time)`; `position` is
1-based and `page` is the SERP page the product appeared on. Has `platform`
column (migration 8).

### `keywords`
Keywords tracked for ranking, scoped per platform. `status` (boolean) toggles
whether the scheduler picks them up.

### `scraping_logs`
Per-session metrics. `status` ∈ `started | completed | failed | partial`;
holds counts (`products_found/updated/added/deactivated`, `errors_count`),
the error message + serialised trace, and `duration_seconds`.

### `scraping_urls`
The "manual URL queue". Admins paste product URLs here; the
`scraper:process-urls` command picks them up. `status` ∈
`pending | processing | completed | failed`. Carries `priority`,
`retry_count`, `last_scraped_at`, `error_message`.

### `scraper_configurations`
Reusable scrape templates. Created via the admin scraper UI. Holds a
`category_url`, a free-form `category` and `tag`, and run counters
(`total_runs`, `last_run_at`, `last_scraper_id`).

### `scraper_runs`
Per-execution record produced by manual triggers in the admin panel.
Links back to the configuration that spawned it. Has a 10-digit unique
`scraper_id` (string), counts of products/reviews/rankings scraped, and a
`triggered_by` enum (`manual | schedule | api`).

### `users`
Standard Laravel auth table; only used to attribute `scraper_runs` and to
gate admin routes.

---

## Service container bindings

`app/Providers/ScraperServiceProvider.php`:

- `DatabaseService` — registered as a singleton
- `scraper.amazon`, `scraper.flipkart`, `scraper.vijaysales`,
  `scraper.reliancedigital`, `scraper.croma`, `scraper.blinkit`,
  `scraper.bigbasket`, `scraper.zepto` — each bound as a fresh-per-resolution
  factory
- Custom validation rules: `valid_sku`, `valid_price`, `valid_rating`
- A `scraper` log channel (daily rotation, 30-day retention)

> `MeeshoScraper` is **not** registered in the service provider even though
> it is a configured platform. The console commands instantiate it directly
> with `new MeeshoScraper()` rather than through the container, so this works
> in practice but is inconsistent with the other platforms. See the audit for
> a fix.

---

## Where things live (cheat sheet)

| You want to…                         | Edit                                                     |
|--------------------------------------|----------------------------------------------------------|
| Add a new category URL               | `config/scraper.php → platforms.<name>.category_urls`    |
| Change scraping interval / retries   | `.env` (`SCRAPER_*`) or `config/scraper.php`             |
| Add or edit a CSS selector           | `app/Services/Scrapers/<Platform>Scraper.php`            |
| Add a new platform                   | See [SCRAPERS.md](SCRAPERS.md) — six places to touch     |
| Change the cron schedule             | `app/Console/Kernel.php`                                 |
| Add an admin endpoint                | `routes/admin.php` + `app/Http/Controllers/Admin/*`      |
| Configure proxies                    | `.env → SCRAPER_PROXIES` or `storage/app/proxies.txt`    |
| Change product validation rules      | `app/Providers/ScraperServiceProvider.php`               |
