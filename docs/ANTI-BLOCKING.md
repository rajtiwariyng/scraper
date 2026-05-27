# Anti-blocking — current state & playbook

E-commerce sites push back on scrapers in several ways: rate limits, IP
bans, captchas, JavaScript challenges, and in the worst case account-level
shadowbans. This document inventories what the project does today, what it
does **not** do, and the operator playbook when a platform starts blocking.

---

## 1. What is in place today

### 1.1 User-agent rotation

[`app/Services/UserAgentRotator.php`](../app/Services/UserAgentRotator.php)
holds a hand-picked pool of **18 user-agents** spanning Chrome / Firefox /
Edge / Safari on Windows, macOS, Linux, Android, and iOS.

Public surface:

```php
$rotator = new UserAgentRotator();
$rotator->getRandomUserAgent();      // → string
$rotator->getRandomizedHeaders();    // → array (UA + Accept-* + Sec-Fetch-*)
$rotator->getBrowserSessionHeaders($referer = null);
```

Where it is used:

- Picked up by `BaseScraper::fetchPage()` when no platform-specific headers
  are configured — i.e. by every Guzzle scraper.
- **Not** picked up by `BrowserService` — see §3 for the implication.

### 1.2 Proxy rotation

[`app/Services/ProxyRotator.php`](../app/Services/ProxyRotator.php) is a
simple round-robin pool with failure tracking.

Sources, merged on construction:

- `SCRAPER_PROXIES` env var — comma-separated list
- `storage/app/proxies.txt` — one proxy per line

Supported formats:

```
http://1.2.3.4:8080
http://user:pass@1.2.3.4:8080
socks5://1.2.3.4:1080
```

Behaviour:

- `getNextProxy()` round-robins through the pool, skipping any proxy that
  has been marked failed.
- `markProxyAsFailed($proxy)` removes the proxy from rotation.
- When every proxy has failed, the failed-list is reset and rotation
  restarts.
- `validateProxies()` exists to test each proxy against `httpbin.org/ip` —
  not invoked automatically; run it manually before a big scrape.

Where it is used:

- `BaseScraper::fetchPage()` — applies the proxy via Guzzle's `proxy`
  option. On HTTP ≥ 400 or transport error, the proxy is marked failed.
- **Not** wired into `BrowserService` — see §3.

### 1.3 Random delays

| Site                        | Delay strategy                                                 |
|-----------------------------|----------------------------------------------------------------|
| Between products (any HTTP) | `sleep(rand(SCRAPER_DELAY_MIN, SCRAPER_DELAY_MAX))` — default 2–7 s |
| Between paginated pages     | `paginationConfig.delay_between_pages`, default `[2, 5]` s       |
| Between Browsershot pages   | hardcoded `rand(3, 6)` s in `BrowserService::getAllPagesContent()`|
| Browsershot page-load       | smart `waitForSelector` when the platform configures `wait_for_selector`; otherwise a fixed `delay($waitTime * 1000)` (default 3 s) |
| HTTP retry backoff          | `pow(2, attempt) + rand(1, 3)` s — exponential                  |

**Smart wait** is keyed by `config/scraper.php → platforms.<name>.wait_for_selector`
(plus `wait_for_selector_timeout`, default 8000 ms). When set,
`BrowserService::getPageContent` uses Puppeteer's `page.waitForSelector` and
returns as soon as any of the comma-separated selectors resolves —
typically 1–3 s on a fast page. If the selector times out (slow render,
captcha, structural drift), the fetch is retried once without the wait
so the caller still gets HTML rather than null.

Today only Flipkart is configured (`'h1, [data-id]'`) — `h1` resolves on
PDPs, `[data-id]` on listing cards. Other platforms still pay the fixed
delay until they opt in.

### 1.4 Headless-Chrome flags

`BrowserService` launches Chrome with:

```
--no-sandbox
--disable-setuid-sandbox
--disable-dev-shm-usage
--disable-gpu
--disable-web-security
--disable-features=VizDisplayCompositor
--disable-background-timer-throttling
--disable-backgrounding-occluded-windows
--disable-renderer-backgrounding
--blink-settings=imagesEnabled=false
```

`imagesEnabled=false` is a real win — cuts page weight by ~70 % and
matches a "lazy human" profile reasonably. The rest are stability flags
required to run headless inside Docker / restricted accounts.

### 1.5 Cookie handling

- Guzzle scrapers create a fresh `CookieJar` per request, so session
  cookies persist within a single retry sequence but not across requests.
- Browsershot scrapers send platform-specific cookies via
  `BrowserService::setCookies()`, populated from
  `config/scraper.php → platforms.<name>.cookies` by `BaseScraper`. When
  a platform configures no cookies, no Cookie header is sent. Today only
  Flipkart sets one (`deliveryPincode=110001`, used to lock pricing to a
  specific warehouse). Other platforms can opt in by adding their own
  `cookies` map to the platform config.

### 1.6 Retries & circuit-breaking

Guzzle retries each fetch up to 3 times with exponential backoff. The
pagination loop also tracks consecutive errors and stops a category when
`max_consecutive_errors` is exceeded. There is no global circuit-breaker
that pauses a whole platform after a failure spike — adding one is on the
audit's medium-priority backlog.

---

## 2. What is **not** in place

These would help reliability but are not implemented today:

| Missing                                              | Impact                                  |
|------------------------------------------------------|-----------------------------------------|
| Proxy rotation for the **browser** transport         | Flipkart and reviews share one IP per run |
| User-agent rotation for the **browser** transport    | Single static UA across all browser runs|
| `puppeteer-extra` + stealth plugin                   | `navigator.webdriver = true` is detectable; default Puppeteer leaves several other fingerprintable flags |
| Captcha detection / solving                          | A captcha page silently succeeds with `200 OK` and stores empty data |
| Per-platform circuit breaker                         | A bad week for one site keeps eating quota                       |
| Persistent browser profile (cookies + storage)       | No login persistence across runs        |
| `executablePath` configuration                       | Cannot point Browsershot at a system Chrome easily            |
| `verify => true` on Guzzle                           | TLS verification is currently disabled  |

The audit recommends picking these up in a dedicated "anti-blocking sprint"
— see the audit's improvement backlog.

---

## 3. Configuring proxies

### 3.1 With residential / mobile proxies (recommended for India e-com)

Datacentre IPs are aggressively throttled by Amazon, Flipkart, and
Reliance Digital. Residential or mobile pools (Bright Data, Oxylabs,
SmartProxy, PacketStream, etc.) are the realistic choice.

```env
SCRAPER_PROXIES="http://customer-X-zone-IN:pwd@in.example.com:7777,http://customer-X-zone-IN-2:pwd@in.example.com:7777"
```

Or, if you have many:

```text
# storage/app/proxies.txt
http://customer-X:pwd@host1:7777
http://customer-X:pwd@host2:7777
http://customer-X:pwd@host3:7777
```

### 3.2 Validating the pool before a run

```bash
php artisan tinker
> (new \App\Services\ProxyRotator())->validateProxies()
```

This hits `httpbin.org/ip` through each proxy with a 10 s timeout and
removes the dead ones in-memory. It does **not** persist the cleaned list —
`storage/app/proxies.txt` should be curated separately.

### 3.3 Health stats

```php
$rotator->getStats();
// → ['total_proxies' => 12, 'failed_proxies' => 3, 'working_proxies' => 9, 'current_index' => 47]
```

### 3.4 Important — Browsershot does NOT use this pool

This is the single biggest gap. Until a future change wires `setProxyServer`
into `BrowserService`, browser-based scrapes (Flipkart PDP, every
`*ReviewScraper` driven by Browsershot, every `*RankingScraper` driven by
Browsershot) all share a single egress IP per run. Plan accordingly:

- Run them on a host whose IP can absorb a few hundred page loads/hour.
- Stagger them in `Kernel.php` so browser-based scrapes do not stack.
- Treat IP changes (DHCP / VPN switch) as your "rotation".

---

## 4. Operator playbook — when a platform starts blocking

### 4.1 Detecting a block

Symptoms by transport:

| Signal                                              | Likely cause                |
|-----------------------------------------------------|-----------------------------|
| HTTP 503, repeated                                  | Soft block / rate limit     |
| HTTP 403                                            | UA/IP banlisted             |
| HTTP 429                                            | Rate limit                  |
| 200 OK but `extractProductUrls` returns 0           | Captcha or interstitial     |
| Browsershot timeout after 60 s                      | JS challenge holding the page |
| `<title>` contains "captcha" / "Validate request"   | Captcha, definitive         |

There is no automated detector for the "200 OK but empty" case yet — watch
for `pageProductCount === 0` repeating across pages in
`storage/logs/laravel.log`.

### 4.2 First-aid checklist (in order)

1. **Increase delays** — bump `SCRAPER_DELAY_MAX` from 7 to 15.
2. **Force `--limit` down** — run `--limit=20` instead of 70 to cool things off.
3. **Run `(new ProxyRotator)->validateProxies()`** — drop dead proxies.
4. **Switch egress IP** — if you have one VPN / network change, do it now.
5. **Wait 30 minutes** — most rate-limit windows are 5–30 minutes.
6. **Disable the platform in `Kernel::schedule()`** — set the
   `->when(fn () => false)` guard to short-circuit, while you investigate.
7. **Inspect what came back** — temporarily save the HTML
   (`file_put_contents(storage_path('logs/debug.html'), $html)`) inside
   the failing scraper and open it in a real browser. Captcha pages are
   easy to spot.

### 4.3 If blocks persist

- Switch to the **browser transport** for that platform: set
  `$this->useJavaScript = true` in `setupPlatformConfig()`. JS-rendered
  fetches survive bot signals that plain HTTP does not.
- Add **mobile UAs** — many e-com sites have weaker bot defences on
  mobile endpoints (try `m.flipkart.com`, mobile Amazon templates).
- Reduce **breadth** — drop low-value `category_urls` from `config/scraper.php`.
- Reduce **frequency** — the audit recommends a single weekly cycle anyway;
  this is a good moment to commit to it.

### 4.4 Captchas

The codebase has **no captcha-solving integration**. Options:

- **Manual cookie injection** — solve once in a real browser, copy the
  cookies, use the `AmazonReviewScraperWithAuth` pattern as a template
  (it reads a static cookie list from `config/amazon_cookies.php`).
- **Third-party solver** — integrate 2captcha, Anti-Captcha, or
  CapMonster. The integration pattern would be a small Node helper
  invoked from `BrowserService` when a captcha selector is detected.
  This is on the audit's medium-priority backlog.

### 4.5 IP burn

If you suspect your IP is fully banned by a platform:

1. Disable the affected scraper in `Kernel.php` for **48 hours**.
2. During the cooldown, populate `storage/app/proxies.txt` with at least
   three residential proxies and run
   `(new ProxyRotator)->validateProxies()`.
3. Re-enable with `SCRAPER_DELAY_MAX=15` and `--limit=20` for the first
   manual smoke test.

---

## 5. Where to read the relevant code

| What                          | Where                                                      |
|-------------------------------|------------------------------------------------------------|
| HTTP retry / backoff          | `BaseScraper::fetchPage()`                                 |
| Proxy pool                    | `ProxyRotator`                                              |
| User-agent pool               | `UserAgentRotator`                                          |
| Browser flags                 | `BrowserService::__construct()` `defaultOptions['args']`   |
| Browser delay                 | `BrowserService::getPageContent()` `delay(6000/15000)`     |
| Per-product delay             | `BaseScraper::randomDelay()`                                |
| Pagination delay              | `BaseScraper::scrapeCategoryWithPagination()` `sleep($delay)` |

---

## 6. Reading a blocked-run log

A representative trace from `storage/logs/laravel.log` when Browsershot is
under pressure:

```
[INFO]    Fetching page content with browser (attempt 1) {"url": "..."}
[WARNING] Browser fetch attempt failed {"url": "...", "error": "exceeded the timeout of 60 seconds."}
[INFO]    Fetching page content with browser (attempt 2) {"url": "..."}
[INFO]    Successfully fetched page content {"url": "...", "content_length": 821976, "attempt": 2}
```

A timeout on attempt 1 followed by success on attempt 2 is acceptable — it
is the bot-detection-soft-throttle pattern. Three timeouts in a row, all
on the same platform, means it is time to apply §4.2.
