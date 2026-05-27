# Admin panel & dashboard

The web UI has two halves:

- **`/dashboard`** — read-only overview, stats, charts. Shipped under
  `routes/web.php` via `DashboardController`.
- **`/admin/*`** — management UI for products, reviews, keywords, the
  scraper itself, and the manual-URL queue. Shipped under
  `routes/admin.php` and `routes/web_scraping_urls.php`.

There is no built-in authentication on the routes today — the routes are
*defined* under an `admin.` name prefix but no middleware is attached. If
the deployment is internet-facing, put it behind HTTP basic auth at the web
server level or add Laravel's `auth` middleware before exposing it.

---

## 1. Public dashboard — `/dashboard`

Routes (`routes/web.php`):

| URL                                 | Controller method                  | Purpose                                |
|-------------------------------------|------------------------------------|----------------------------------------|
| `GET /`                             | redirect → `dashboard.index`       |                                        |
| `GET /dashboard`                    | `DashboardController::index`       | Overview: counts, last run, charts     |
| `GET /dashboard/platform/{platform}`| `DashboardController::platform`    | Per-platform deep-dive                 |
| `GET /dashboard/products`           | `DashboardController::products`    | Browsable product list                 |
| `GET /dashboard/logs`               | `DashboardController::logs`        | Recent `scraping_logs` entries         |
| `GET /dashboard/api/stats`          | `DashboardController::apiStats`    | JSON for AJAX charts                   |

The AJAX endpoint takes a `?days=N` query param (default 7) and returns the
stats payload the dashboard's charts consume.

---

## 2. Admin panel — `/admin/*`

### 2.1 Products — `/admin/products`

| URL                                              | Method | Purpose                                                  |
|--------------------------------------------------|--------|----------------------------------------------------------|
| `/admin/products`                                | GET    | Searchable, filterable product list                      |
| `/admin/products/export`                         | GET    | CSV export                                               |
| `/admin/products/{product}`                      | GET    | Single-product detail view                               |
| `/admin/products/{product}/update-status`        | POST   | Toggle a product's `include_exclude` flag                |
| `/admin/products/bulk-update-status`             | POST   | Bulk toggle `include_exclude` over a selected list       |
| `/admin/products/{product}/reviews`              | GET    | Reviews attached to that product                         |
| `/admin/products/{product}/rankings`             | GET    | Ranking history for that product                         |

The `include_exclude` field is an admin-controlled boolean that lives on
`products`. Excluded products are filtered out of dashboard rollups and
exports. Scraping still happens — exclusion only affects display.

### 2.2 Reviews — `/admin/reviews`

| URL                              | Method | Purpose                                  |
|----------------------------------|--------|------------------------------------------|
| `/admin/reviews`                 | GET    | List with platform / rating / date filters |
| `/admin/reviews/export`          | GET    | CSV export                               |
| `/admin/reviews/{review}`        | GET    | Single review                            |
| `/admin/reviews/{review}`        | DELETE | Soft delete (or hard, per controller)    |
| `/admin/reviews/bulk-delete`     | POST   | Bulk delete                              |

### 2.3 Keywords — `/admin/keywords`

Keywords drive the ranking scraper. The same data is editable on the CLI
via `keywords:manage` ([COMMANDS.md](COMMANDS.md#keywordsmanage--keyword-crud)).

| URL                                        | Method | Purpose                              |
|--------------------------------------------|--------|--------------------------------------|
| `/admin/keywords`                          | GET    | Full keyword list                    |
| `/admin/keywords/create`                   | GET    | New-keyword form                     |
| `/admin/keywords`                          | POST   | Store one keyword                    |
| `/admin/keywords/{keyword}/edit`           | GET    | Edit form                            |
| `/admin/keywords/{keyword}`                | PUT    | Update                               |
| `/admin/keywords/{keyword}`                | DELETE | Remove                               |
| `/admin/keywords/bulk-create`              | POST   | Paste-many endpoint                  |
| `/admin/keywords/bulk-update-status`       | POST   | Activate / deactivate in bulk        |
| `/admin/keywords/bulk-delete`              | POST   | Bulk delete                          |
| `/admin/keywords/{keyword}/rankings`       | GET    | Time-series chart for that keyword   |
| `/admin/keywords/export`                   | GET    | CSV export                           |

### 2.4 Scraper — `/admin/scraper`

This is the manual-trigger UI. Each manual run becomes a row in
`scraper_runs` and is linked to the user who triggered it.

| URL                                  | Method | Purpose                                                  |
|--------------------------------------|--------|----------------------------------------------------------|
| `/admin/scraper`                     | GET    | "Trigger a run" form + recent runs table                 |
| `/admin/scraper/run`                 | POST   | Kick off a scrape — synchronous; the request blocks      |
| `/admin/scraper/status/{id}`         | GET    | Poll endpoint — returns status JSON for run `id`         |
| `/admin/scraper/history`             | GET    | Paginated history of past runs                           |
| `/admin/scraper/{id}`                | GET    | Detail view of a single run                              |
| `/admin/scraper/{id}/stop`           | POST   | Mark a `running` row as `stopped` (best-effort)          |
| `/admin/scraper/{id}`                | DELETE | Remove the row from `scraper_runs`                       |

> The current `POST /admin/scraper/run` runs synchronously — the browser
> blocks until the scrape finishes. For a multi-platform run that can be
> 30+ minutes. Either run it from the CLI or use the small admin form to
> kick off a single platform with a tight `--limit`.

### 2.5 Manual-URL queue — `/admin/scraping-urls`

Routes from `routes/web_scraping_urls.php`. Lets a human paste a list of
PDP URLs that should be scraped on the next `scraper:process-urls` run.

| URL                                  | Method | Purpose                              |
|--------------------------------------|--------|--------------------------------------|
| `/admin/scraping-urls`               | GET    | Queue listing with status filters    |
| `/admin/scraping-urls/create`        | GET    | New-URL form                         |
| `/admin/scraping-urls`               | POST   | Add one or more URLs                 |
| `/admin/scraping-urls/{id}/retry`    | POST   | Reset `failed` → `pending`           |
| `/admin/scraping-urls/{id}`          | DELETE | Remove a queue row                   |
| `/admin/scraping-urls/bulk-delete`   | POST   | Bulk remove                          |
| `/admin/scraping-urls/bulk-retry`    | POST   | Bulk reset to `pending`              |

The queue is drained by `php artisan scraper:process-urls`
([COMMANDS.md](COMMANDS.md#scraperprocess-urls--drain-the-manual-url-queue)).

---

## 3. View templates

Blade templates live under `resources/views/`. They are not described in
detail here — open `resources/views/admin/` and follow the route → view
mapping in each controller.

The dashboard charts are JavaScript-rendered and pull data from
`/dashboard/api/stats`. There is no build step beyond `npm run dev` /
`npm run build` (Vite); see `vite.config.js`.

---

## 4. Authentication

Right now the admin routes have **no middleware**. To gate them:

1. Create a user in tinker (see [SETUP.md](SETUP.md#5-database)).
2. In `routes/admin.php`, wrap the group:

   ```php
   Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {
       // …
   });
   ```

3. Do the same for `routes/web_scraping_urls.php`.
4. Add a login route — Laravel Breeze is the simplest fit
   (`composer require laravel/breeze --dev && php artisan breeze:install`).

If the host is on the public internet, do step 1–4 **before** opening it
up. The audit considers gating these endpoints a high-priority follow-up.

---

## 5. Known limitations

These are documented here so operators are not surprised by them:

- **No live progress streaming.** When you trigger a scrape from the admin
  UI, the page sits and waits. Progress only becomes visible after the run
  completes and updates `scraper_runs`.
- **No alerting on scrape failure.** `Kernel.php` calls
  `emailOutputOnFailure(config('mail.admin_email'))`, but there is no
  `admin_email` key in `config/mail.php`, so no mail is ever sent.
- **No proxy health UI.** `ProxyRotator::getStats()` exists but is not
  surfaced in any view.
- **No success-rate widget per platform.** The data is all there in
  `scraping_logs` and `scraper_runs`; just unused.

The audit's improvement backlog tracks each of these as separate items.
