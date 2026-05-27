# Setup

Step-by-step install. The maintainer's primary environment is **XAMPP on
Windows 10**, so that path is the most thoroughly documented; Linux notes are
in §5. Time budget: ~30 minutes assuming a working dev machine.

---

## 1. Prerequisites

| Component       | Minimum  | Notes                                          |
|-----------------|----------|------------------------------------------------|
| PHP             | 8.1      | XAMPP 8.2+ ships PHP 8.2; both work            |
| Composer        | 2.x      | https://getcomposer.org                        |
| Node.js         | 18 LTS   | required by Puppeteer / Browsershot            |
| Chrome/Chromium | recent   | Puppeteer ships its own Chromium via `npm i`   |
| MySQL/MariaDB   | 5.7 / 10.3 | utf8mb4 collation                            |
| Disk            | ~1 GB    | Chromium download accounts for ~300 MB         |
| RAM             | 2 GB+    | Chrome is hungry; 4 GB recommended             |

Required PHP extensions (all enabled in default XAMPP):
`pdo_mysql`, `curl`, `openssl`, `mbstring`, `xml`, `json`, `fileinfo`,
`gd` (only if `images.download_enabled = true`).

```bash
php -m | grep -E "pdo_mysql|curl|openssl|mbstring|xml|json"
```

---

## 2. Get the code

```bash
cd c:/xampp/htdocs                 # Windows
# or:
cd /var/www/html                   # Linux

git clone <repo-url> laptop-scraper
cd laptop-scraper
```

---

## 3. Install dependencies

```bash
composer install
npm install
```

`npm install` downloads Puppeteer and pulls down a matching Chromium build
into `node_modules/puppeteer/.local-chromium/`. This is what Browsershot
launches; you do **not** need to install Chrome system-wide.

If you prefer a system Chrome (smaller `node_modules`), set
`PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=1` before `npm install` and point
`SCRAPER_CHROME_PATH` at your existing binary (see §4).

---

## 4. Environment configuration

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env`. The keys the scraper reads are:

```env
APP_NAME="Product Scraper"
APP_ENV=local
APP_DEBUG=true                       # set false in production
APP_URL=http://localhost/laptop-scraper/public

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=product_scraper
DB_USERNAME=root
DB_PASSWORD=

# Scraper behaviour
SCRAPER_TIMEOUT=30
SCRAPER_RETRIES=3
SCRAPER_DELAY_MIN=2
SCRAPER_DELAY_MAX=7
SCRAPER_USER_AGENT="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Safari/537.36"

# Schedule
SCRAPER_SCHEDULE_ENABLED=true
SCRAPER_INTERVAL_HOURS=168           # 7 days
SCRAPER_MAX_EXECUTION_TIME=86400     # 24 h cap per session

# Logging
SCRAPER_LOG_LEVEL=info
SCRAPER_DETAILED_ERRORS=true

# Optional: comma-separated proxy list, e.g.
#   SCRAPER_PROXIES="http://user:pass@1.2.3.4:8080,http://user:pass@5.6.7.8:8080"
SCRAPER_PROXIES=

# Optional: download product images (requires GD)
SCRAPER_DOWNLOAD_IMAGES=false
```

> `SCRAPER_DETAILED_ERRORS=false` in production — full stack traces in
> `scraping_logs.error_details` can leak internal paths.

---

## 5. Database

### Create the database

XAMPP / Windows (via phpMyAdmin or shell):

```sql
CREATE DATABASE product_scraper
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

Linux (recommended: dedicated DB user):

```sql
CREATE DATABASE product_scraper CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'scraper'@'localhost' IDENTIFIED BY 'replace-me';
GRANT ALL ON product_scraper.* TO 'scraper'@'localhost';
FLUSH PRIVILEGES;
```

### Run migrations

```bash
php artisan migrate
php artisan migrate:status        # verify all 16 migrations applied
```

Optional: a default admin user. There is no seeder shipped, so create one
manually via `php artisan tinker`:

```php
\App\Models\User::create([
    'name'     => 'Admin',
    'email'    => 'admin@example.com',
    'password' => bcrypt('replace-me'),
]);
```

---

## 6. Smoke test

```bash
# verify wiring
php artisan list scraper

# show registered cron entries (no actual scraping happens)
php artisan schedule:list

# scrape five Amazon products
php artisan scraper:run amazon --limit=5

# look at the result
php artisan scraper:status --platform=amazon --detailed
tail -f storage/logs/laravel.log
```

If `scraper:run amazon` returns rows in `products`, the install works.

If you see a Browsershot timeout or a "node not found" error, jump to
[OPERATIONS.md → Troubleshooting](OPERATIONS.md#troubleshooting).

---

## 7. Cron / scheduled runs

### Linux

Add one line to `crontab -e`:

```
* * * * * cd /var/www/html/laptop-scraper && php artisan schedule:run >> /dev/null 2>&1
```

The bundled `setup-cron.sh` script does this for you:

```bash
chmod +x setup-cron.sh
./setup-cron.sh
```

### Windows / XAMPP

Create a Task Scheduler entry that fires every minute:

1. Open *Task Scheduler* → *Create Task…*
2. **Triggers** → *New* → *Daily*, then *Repeat task every: 1 minute* for the
   *duration: Indefinitely*.
3. **Actions** → *New* → *Start a program*:
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `artisan schedule:run`
   - Start in: `C:\xampp\htdocs\laptop-scraper`
4. **General** → "Run whether user is logged on or not".

What the scheduler runs is defined in `app/Console/Kernel.php`. Per-platform
defaults are documented in [OPERATIONS.md](OPERATIONS.md#schedule).

> **Note:** the existing schedule is *not* a single weekly cycle — there are
> overlapping daily and weekly entries. If you only want one weekly run per
> platform, edit `Kernel.php` accordingly.

---

## 8. Web server

The repository ships with no special vhost requirements. Any of the
following works:

- **XAMPP**: place under `c:/xampp/htdocs/laptop-scraper`, then visit
  `http://localhost/laptop-scraper/public/`.
- **Apache vhost**: point `DocumentRoot` at `<repo>/public`,
  `AllowOverride All`. Example config in `INSTALLATION.md` (legacy doc).
- **Nginx + PHP-FPM**: standard Laravel block; ensure `fastcgi_read_timeout
  300` so admin-triggered scrapes don't time out the request.
- **`php artisan serve`** (development only):
  ```bash
  php artisan serve --port=8000
  ```

After the server is up, browse to `/dashboard` for read-only stats or
`/admin` for the management UI.

---

## 9. File-system permissions (Linux only)

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

On Windows / XAMPP no permission tweaks are needed — the XAMPP user already
owns `storage/`.

---

## 10. Optional: proxy pool

The scraper supports two proxy sources, both merged on boot:

1. `.env`:
   ```env
   SCRAPER_PROXIES="http://user:pass@host1:port,http://user:pass@host2:port"
   ```
2. `storage/app/proxies.txt`, one proxy per line:
   ```
   http://user:pass@host1:port
   socks5://host2:port
   ```

The pool is round-robin with failure tracking (see
[ANTI-BLOCKING.md](ANTI-BLOCKING.md)). It is currently used **only by the
HTTP transport** — Browsershot calls do not pick up proxies from this pool.

---

## 11. Verifying the install

A green install satisfies all of:

- [ ] `php artisan migrate:status` shows 16 migrations all `Ran`.
- [ ] `php artisan list scraper` lists at least 10 commands.
- [ ] `php artisan schedule:list` prints the cron entries from
      `Kernel.php` without errors.
- [ ] `php artisan scraper:run amazon --limit=5` populates `products`.
- [ ] `/dashboard` renders without a stack trace.
- [ ] `/admin/scraper` lets you trigger a run manually.

---

## 12. What changed from the legacy `INSTALLATION.md`

The previous install guide (kept as a stub at the repo root) is out of date
in three ways:

1. It does not mention Node.js or Puppeteer — both are required.
2. Several env keys it documents (`SCRAPER_INTERVAL_HOURS=48`, ports, etc.)
   no longer match the defaults.
3. Its "5 platforms" list is wrong — there are nine configured.

This document supersedes it.
