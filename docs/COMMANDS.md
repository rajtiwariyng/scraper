# CLI Reference

Every artisan command shipped by the scraper, with the signature, real-world
options, and a few examples for each. Defined in
`app/Console/Commands/`; auto-discovered by `Kernel::commands()`.

| Command                       | What it does                                         |
|-------------------------------|------------------------------------------------------|
| `scraper:run`                 | Run PDP scraping for one platform or all of them     |
| `scraper:reviews-platform`    | Review scraping for one or all platforms (Amazon supports `--mode`) |
| `scraper:rankings`            | Track keyword search positions per platform          |
| `scraper:process-urls`        | Drain the manual-URL queue (`scraping_urls` table)   |
| `scraper:status`              | Print a health/statistics report                     |
| `scraper:cleanup`             | Trim old logs and inactive products                  |
| `keywords:manage`             | CRUD for tracked keywords                            |

Run `php artisan list scraper` to see the same list at the prompt.

---

## `scraper:run` — main PDP scrape

```
scraper:run {platform?}
            {--force}
            {--limit=70}
            {--timeout=83200}
```

| Argument / option | Default | Description                                                                |
|-------------------|---------|----------------------------------------------------------------------------|
| `platform`        | `all`   | One of `amazon`, `flipkart`, `vijaysales`, `reliancedigital`, `croma`, `blinkit`, `bigbasket`, `meesho`, `zepto`, or `all`. |
| `--force`         | off     | Skip the 12-hour recency guard and run anyway.                             |
| `--limit`         | `70`    | Maximum products per platform per run. Treated as a soft cap.              |
| `--timeout`       | `83200` | Hard PHP execution-time limit, seconds. Wall-clock budget for the command. |

The command:

1. Resolves the scraper class via `match`.
2. Reads `category_urls` from `config/scraper.php` for that platform.
3. Calls `BaseScraper::scrape()` which writes a row to `scraping_logs`.

Examples:

```bash
# Scrape every configured platform (sequential, one at a time)
php artisan scraper:run all

# Scrape Flipkart, ignoring the recency guard
php artisan scraper:run flipkart --force

# Quick smoke test
php artisan scraper:run amazon --limit=5

# Override the wall-clock budget to 1 h
php artisan scraper:run croma --timeout=3600
```

> Setting `--limit=0` does **not** mean "unlimited" — pass a large number
> (e.g. `--limit=10000`) instead.

---

## `scraper:reviews-platform` — unified reviews command

```
scraper:reviews-platform {platform}
                         {--product-ids=*}
                         {--limit=}
                         {--mode=http}
```

| Argument / option   | Description                                                                              |
|---------------------|------------------------------------------------------------------------------------------|
| `platform`          | `amazon`, `flipkart`, `vijaysales`, `reliancedigital`, `croma`, or `all`.                |
| `--product-ids=*`   | Repeatable. Restrict scraping to specific `products.id` values.                          |
| `--limit=`          | Total number of products to process across the run.                                      |
| `--mode=`           | Amazon-only transport: `http` (default), `browser`, or `auth`. Ignored for other platforms. |

Amazon transport modes:

| Mode      | Backing scraper                  | When to use                                              |
|-----------|----------------------------------|----------------------------------------------------------|
| `http`    | `AmazonReviewScraper`            | Default. Plain Guzzle. Subject to anti-bot blocks.       |
| `browser` | `AmazonReviewScraperBrowser`     | Headless Chrome via Browsershot. Slower but bypasses most blocks. |
| `auth`    | `AmazonReviewScraperWithAuth`    | Headless Chrome with authenticated cookies from `config/amazon_cookies.php`. Highest success rate but cookies expire. |

Examples:

```bash
# All Amazon products, plain HTTP
php artisan scraper:reviews-platform amazon

# Same, but use the browser transport to bypass anti-bot
php artisan scraper:reviews-platform amazon --mode=browser

# Authenticated browser session (requires config/amazon_cookies.php)
php artisan scraper:reviews-platform amazon --mode=auth --limit=50

# Just two specific products on Flipkart
php artisan scraper:reviews-platform flipkart --product-ids=42 --product-ids=99

# Limit the run to 25 products
php artisan scraper:reviews-platform croma --limit=25

# Every platform that has a review scraper
php artisan scraper:reviews-platform all
```

Output: a per-platform table of `Products Processed / Reviews Found /
Reviews Added / Reviews Updated / Errors`.

> The legacy Amazon-only commands `scraper:reviews`, `scraper:reviews-browser`,
> and `scraper:reviews-auth` were removed in favour of the `--mode` flag.
> Update any cron entries / scripts that still call them.

---

## `scraper:rankings` — keyword position tracking

```
scraper:rankings {platform}
                 {--keyword-ids=*}
```

| Argument / option   | Description                                                              |
|---------------------|--------------------------------------------------------------------------|
| `platform`          | `amazon`, `flipkart`, `vijaysales`, or `all`.                            |
| `--keyword-ids=*`   | Repeatable. Restrict to specific `keywords.id` values; otherwise scrape all `status=true` keywords for the platform. |

Examples:

```bash
# Track all active Amazon keywords
php artisan scraper:rankings amazon

# Just two specific keywords on Flipkart
php artisan scraper:rankings flipkart --keyword-ids=3 --keyword-ids=7

# Every supported platform
php artisan scraper:rankings all
```

Each run inserts time-series rows into `product_rankings` so you can plot
position-over-time per keyword in the admin panel.

---

## `scraper:process-urls` — drain the manual-URL queue

```
scraper:process-urls {platform?}
                     {--limit=10}
```

| Argument / option | Default | Description                                                          |
|-------------------|---------|----------------------------------------------------------------------|
| `platform`        | `all`   | One of the supported platforms or `all`.                             |
| `--limit`         | `10`    | Maximum URLs to process per platform per invocation.                 |

`scraping_urls.status='pending'` rows are picked up in priority order. Each
URL is marked `processing` → `completed` (or `failed` with `error_message`).
A 2–4 second random delay is applied between URLs.

Examples:

```bash
# Drain 10 pending URLs across all platforms
php artisan scraper:process-urls

# Just Flipkart, 50 at a time
php artisan scraper:process-urls flipkart --limit=50
```

> The current implementation calls `scraper->scrape([$url])`, which still
> runs the full pagination loop on a single URL. Treat the `--limit` value
> as approximate.

---

## `scraper:status` — health / stats report

```
scraper:status {--platform=}
               {--days=7}
               {--detailed}
```

| Option        | Default | Description                                                |
|---------------|---------|------------------------------------------------------------|
| `--platform=` | (all)   | Restrict the report to a single platform.                  |
| `--days=`     | `7`     | Lookback window for run statistics.                        |
| `--detailed`  | off     | Include per-run breakdown, error counts, and durations.    |

Examples:

```bash
# Snapshot of every platform over the last 7 days
php artisan scraper:status

# Detailed Amazon-only report over the last 30 days
php artisan scraper:status --platform=amazon --days=30 --detailed

# Quick health check
php artisan scraper:status
```

This command is also wired into `Kernel::schedule()` to run hourly between
8 AM and 10 PM and write to `storage/logs/health-check.log`.

---

## `scraper:cleanup` — house-keeping

```
scraper:cleanup {--logs=30}
                {--inactive=90}
                {--dry-run}
```

| Option       | Default | Description                                                        |
|--------------|---------|--------------------------------------------------------------------|
| `--logs=`    | `30`    | Keep `scraping_logs` rows newer than N days; older rows are deleted.|
| `--inactive=`| `90`    | Hard-delete products with `is_active=false` older than N days.      |
| `--dry-run`  | off     | Print what would be deleted, change nothing.                        |

Examples:

```bash
# Default: 30 d logs, 90 d inactive products
php artisan scraper:cleanup

# Aggressive: 7 d logs, 30 d inactive products
php artisan scraper:cleanup --logs=7 --inactive=30

# Preview only
php artisan scraper:cleanup --dry-run
```

Scheduled to run weekly on Sundays at 03:00.

---

## `keywords:manage` — keyword CRUD

```
keywords:manage {action}
                {platform?}
                {--keyword=}
                {--id=}
                {--file=}
```

| Argument / option | Description                                                       |
|-------------------|-------------------------------------------------------------------|
| `action`          | One of `list`, `add`, `activate`, `deactivate`, `delete`.         |
| `platform`        | Required for `add`/`list`; e.g. `amazon`, `flipkart`.             |
| `--keyword=`      | Single keyword text for `add`.                                    |
| `--id=`           | Keyword ID for `activate` / `deactivate` / `delete`.              |
| `--file=`         | File path with one keyword per line (used with `add`).            |

Examples:

```bash
# List every Amazon keyword
php artisan keywords:manage list amazon

# Add a single keyword
php artisan keywords:manage add amazon --keyword="canon printer"

# Bulk-import from a file
php artisan keywords:manage add flipkart --file=storage/app/keywords-flipkart.txt

# Toggle activation
php artisan keywords:manage deactivate --id=42
php artisan keywords:manage activate   --id=42

# Delete
php artisan keywords:manage delete --id=42
```

The same data is editable in the admin UI under
[`/admin/keywords`](ADMIN-PANEL.md#keywords).

---

## Built-in Laravel commands worth knowing

```bash
# List every scheduled job and its next run time
php artisan schedule:list

# Manually fire the scheduler once (don't wait for cron)
php artisan schedule:run

# Find all scraper-namespaced commands at a glance
php artisan list scraper

# Tail the most recent log entries
tail -f storage/logs/laravel.log
```

---

## Exit codes

All scraper commands return:

- `0` — success
- `1` — failure (caught exception)

Cron / Task Scheduler should treat non-zero as an alertable failure. The
schedule entries in `Kernel::schedule()` use `emailOutputOnFailure()` for
this purpose; configure `mail.admin_email` (or the equivalent in
`config/mail.php`) to receive the messages.
