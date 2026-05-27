# Operations — schedule, logs, runbook

Everything you need to keep the scraper alive in production: how the
schedule is wired, where the logs go, how to triage a failing run, and
how to safely roll back. New operators should read this end-to-end before
going on call.

---

## 1. Schedule

Defined in `app/Console/Kernel.php → schedule()`. Timezone falls back to
`config('app.timezone', 'UTC')` — set `APP_TIMEZONE=Asia/Kolkata` in `.env`
if you want IST in the log timestamps.

The schedule is one **weekly** scrape per platform, staggered across the
seven days of the week so proxy / IP load is spread out and runs never
overlap. Each platform's `withoutOverlapping(43200)` guard caps a stuck
run at 12 hours.

| Day        | Time   | Job                                | Notes                                  |
|------------|--------|------------------------------------|----------------------------------------|
| Monday     | 02:00  | `scraper:run amazon`               | Conditional on `platforms.amazon.enabled` |
| Tuesday    | 02:00  | `scraper:run flipkart`             | Browser transport — slowest run        |
| Wednesday  | 02:00  | `scraper:run croma`                |                                        |
| Thursday   | 02:00  | `scraper:run vijaysales`           |                                        |
| Friday     | 02:00  | `scraper:run reliancedigital`      |                                        |
| Saturday   | 02:00  | `scraper:run bigbasket`            |                                        |
| Saturday   | 14:00  | `scraper:run blinkit`              | Lower-volume, paired onto Saturday PM  |
| Sunday     | 02:00  | `scraper:run meesho`               |                                        |
| Sunday     | 14:00  | `scraper:run zepto`                | Lower-volume, paired onto Sunday PM    |
| Sunday     | 03:00  | `scraper:cleanup`                  | Trims logs and inactive products       |
| Daily      | 06:00  | `scraper:status --detailed`        | Writes to `daily-status.log`           |
| Hourly     | 08–22  | `scraper:status`                   | Writes to `health-check.log`           |
| Hourly     | —      | Disk-space check                   | Runs `scraper:cleanup --logs=7 --inactive=30` if free space < 10 % |

To **disable** a platform without removing code, set
`config/scraper.php → platforms.<name>.enabled = false` and rerun
`php artisan config:cache`. The `->when(...)` guard on each entry will
skip it.

To run a platform **off-schedule** (e.g. ad-hoc this Tuesday afternoon),
trigger it from the CLI or admin UI; the schedule entries do not block
manual runs.

### Verifying the schedule on a host

```bash
# Show every job and its next run time
php artisan schedule:list

# Manually fire any scheduled jobs that are due
php artisan schedule:run

# Cron entry that ticks the scheduler every minute
crontab -l | grep schedule:run
```

---

## 2. Log files

Laravel logs to `storage/logs/`. The scraper writes to several focused
files in addition to the main `laravel.log`.

| File                                  | Source                                                 |
|---------------------------------------|--------------------------------------------------------|
| `storage/logs/laravel.log`            | Default app log — every `Log::info/warning/error/debug` from scrapers |
| `storage/logs/scraper-schedule.log`   | `appendOutputTo` for the twice-daily `scraper:run all` |
| `storage/logs/cleanup.log`            | Weekly `scraper:cleanup` output                        |
| `storage/logs/daily-status.log`       | Daily `scraper:status --detailed` output               |
| `storage/logs/health-check.log`       | Hourly `scraper:status` output                         |
| `storage/logs/debug.html`             | Created on demand when an operator dumps the page HTML during debugging |

The `scraper` log channel is registered in
`ScraperServiceProvider::configureLogging()` with daily rotation and a
30-day retention. Configure the channel cap in `config/scraper.php →
logging.retention_days`.

### Useful tails

```bash
# Live tail of everything
tail -f storage/logs/laravel.log

# Just one platform
tail -f storage/logs/laravel.log | grep -i "flipkart"

# Just errors
tail -f storage/logs/laravel.log | grep -iE "(error|warning|failed)"

# Status of recent runs at a glance
php artisan scraper:status --detailed
```

### Log volume

A full `scraper:run all` writes ~50–200 MB to `laravel.log` depending on
log level. If disk space is an issue, set `SCRAPER_LOG_LEVEL=warning` in
`.env` to drop the `INFO`/`DEBUG` chatter.

---

## 3. Database tables to watch

`scraping_logs` is the canonical "did the run work?" table. The
`scraper_runs` table covers manually-triggered runs from the admin panel
(it duplicates much of `scraping_logs` — both are kept).

```sql
-- Recent runs by platform
SELECT platform, status, started_at, completed_at,
       products_added, products_updated, errors_count
FROM   scraping_logs
ORDER  BY started_at DESC
LIMIT  50;

-- Failure rate over the last 7 days
SELECT platform,
       SUM(status='failed')   AS failures,
       SUM(status='completed') AS completions,
       ROUND(SUM(status='failed') / COUNT(*) * 100, 1) AS failure_pct
FROM   scraping_logs
WHERE  started_at >= NOW() - INTERVAL 7 DAY
GROUP  BY platform;

-- Stuck "started" rows (a process probably crashed)
SELECT id, platform, started_at
FROM   scraping_logs
WHERE  status = 'started'
  AND  started_at < NOW() - INTERVAL 1 DAY;
```

---

## 4. Runbook — "the scrape is broken"

### 4.1 Triage in the first 5 minutes

```bash
# 1. What is the scheduler doing right now?
php artisan schedule:list

# 2. What did the most recent runs look like?
php artisan scraper:status --detailed --days=2

# 3. Are there active errors in the log?
tail -n 200 storage/logs/laravel.log | grep -iE "(error|failed|exception)"

# 4. Is disk space the problem?
df -h .

# 5. Is MySQL up?
php artisan migrate:status
```

### 4.2 By symptom

#### "Every run says `failed` for one platform"

Likely a selector regression after a site redesign. See
[SCRAPERS.md → Testing a scraper locally](SCRAPERS.md#6-testing-a-scraper-locally).

```bash
php artisan scraper:run <platform> --limit=1 --force
# inspect storage/logs/laravel.log for the parser failure
# dump the HTML (storage/logs/debug.html) and find the new selector
```

#### "Every run says `failed` for every platform"

Probably infrastructure: DB unavailable, Chrome missing, or `node_modules`
deleted. Walk through:

```bash
php artisan migrate:status                     # DB up?
node --version                                 # Node alive?
ls node_modules/puppeteer >/dev/null && echo OK # Puppeteer installed?
php -r "echo php_sapi_name();" && echo         # PHP healthy?
```

#### "Browsershot times out after 60s on every URL"

Either the browser-automation binary cannot find Chrome, or the target
site is rate-limiting you. Check both:

```bash
# Force-launch Chrome from Puppeteer to confirm it works
node -e "const p=require('puppeteer'); (async()=>{const b=await p.launch();await b.newPage(); await b.close(); console.log('ok');})()"
```

If the launch works but pages still time out, see
[ANTI-BLOCKING.md → Operator playbook](ANTI-BLOCKING.md#4-operator-playbook--when-a-platform-starts-blocking).

#### "Disk filled up with logs"

```bash
php artisan scraper:cleanup --logs=7 --inactive=30
# or hard-truncate
> storage/logs/laravel.log
```

#### "A run has been `started` for 24+ hours"

The PHP process probably died without closing the row. Mark it failed
manually:

```sql
UPDATE scraping_logs
SET    status        = 'failed',
       completed_at  = NOW(),
       error_message = 'Manually closed; process abandoned'
WHERE  status = 'started'
  AND  started_at < NOW() - INTERVAL 1 DAY;
```

### 4.3 Disabling a misbehaving platform

Two ways to stop scraping a platform without deleting code:

1. **Config flag** — add an `enabled` key in
   `config/scraper.php → platforms.<name>` and gate the schedule entry:

   ```php
   $schedule->command('scraper:run croma')
       ->weekly()->wednesdays()->at('05:00')
       ->when(fn () => config('scraper.platforms.croma.enabled', true));
   ```

   Then set `enabled => false` in config and `php artisan config:cache`.

2. **Empty the URL list** — set `category_urls => []` in
   `config/scraper.php`. The scraper class is still loaded but has nothing
   to do.

For full rollback, comment out the relevant `$schedule->command(...)` block
in `Kernel.php`.

---

## 5. Backups

The scraper stores everything in MySQL. Treat the database as the system
of record; there is nothing useful in `storage/` outside of logs.

```bash
# Daily mysqldump
mysqldump --single-transaction --quick \
  --user=root --password \
  product_scraper > backups/product_scraper_$(date +%F).sql

# Restore
mysql --user=root --password product_scraper < backups/product_scraper_2026-04-27.sql
```

For larger installs, switch to `mydumper` or replication snapshots.

---

## 6. Performance tips

A few low-effort knobs that ease load before any architectural change:

- **`SCRAPER_LOG_LEVEL=warning`** — drops ~80 % of log volume in a busy
  scrape.
- **`SCRAPER_DELAY_MIN=4 SCRAPER_DELAY_MAX=10`** — slower but
  significantly less likely to be blocked.
- **`--limit=20`** on manual runs while iterating on selectors.
- **Disable image downloads** unless you need them
  (`SCRAPER_DOWNLOAD_IMAGES=false`) — saves disk and bandwidth.
- **Trim `category_urls`** that consistently produce zero usable products.

For the larger architectural recommendations (Puppeteer worker pool,
Laravel queue parallelism, change-detection scraping), see the audit's
high-priority backlog.

---

## 7. Where to get help

- Code references: this `docs/` directory.
- Live state: `php artisan scraper:status --detailed`,
  `tail -f storage/logs/laravel.log`.
- Schema reference:
  [ARCHITECTURE.md → Database schema](ARCHITECTURE.md#database-schema-summary).
- Adding/changing a platform: [SCRAPERS.md](SCRAPERS.md).
- Anti-blocking failures: [ANTI-BLOCKING.md](ANTI-BLOCKING.md).
- Admin / web UI: [ADMIN-PANEL.md](ADMIN-PANEL.md).
