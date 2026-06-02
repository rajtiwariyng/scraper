<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

class BrowserService
{
    protected array $defaultOptions;

    /**
     * Cookies to attach to every request as a Cookie header.
     * Map of name => value. Empty by default; populated per-platform by
     * BaseScraper from config('scraper.platforms.<name>.cookies').
     */
    protected array $cookies = [];

    public function __construct()
    {
        $this->defaultOptions = [
            'timeout' => 60,
            'headless' => true,
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'windowSize' => [1920, 1080],
            'args' => [
                'no-sandbox',
                'disable-setuid-sandbox',
                'disable-dev-shm-usage',
                'disable-gpu',
                'disable-web-security',
                'disable-features=VizDisplayCompositor',
                'disable-background-timer-throttling',
                'disable-backgrounding-occluded-windows',
                'disable-renderer-backgrounding',
                'blink-settings=imagesEnabled=false'
            ]
        ];
    }

    /**
     * Configure the Cookie header sent with every request. Pass an empty
     * array to clear. Returns $this for chaining.
     */
    public function setCookies(array $cookies): self
    {
        $this->cookies = $cookies;
        return $this;
    }

    /**
     * Build the value of the Cookie HTTP header from $this->cookies.
     * Returns null when no cookies are configured so the Browsershot call
     * can skip setExtraHttpHeaders entirely.
     */
    protected function cookieHeader(): ?string
    {
        if (empty($this->cookies)) {
            return null;
        }
        $parts = [];
        foreach ($this->cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        return implode('; ', $parts);
    }

    /**
     * Get page content with JavaScript rendering.
     *
     * If $waitForSelector is provided, the snapshot fires as soon as the
     * selector appears in the DOM (typically 1–3s on a fast page) instead
     * of paying a fixed delay. The selector may be a comma-separated list;
     * Puppeteer resolves on the first match. On selector timeout we fall
     * back to a fixed delay and snapshot anyway so the caller still gets
     * partial HTML rather than null.
     *
     * @param  string       $url                  page URL
     * @param  int          $waitTime             fallback delay in seconds (used when no selector or selector times out)
     * @param  int|null     $customTimeout        override the navigation timeout, ms
     * @param  string|null  $waitForSelector      CSS selector to wait for, or null for the fallback delay
     * @param  int          $selectorTimeout      timeout for the selector wait, ms (default 8000)
     */
    public function getPageContent(
        string $url,
        int $waitTime = 3,
        ?int $customTimeout = null,
        ?string $waitForSelector = null,
        int $selectorTimeout = 8000
    ): ?string {
        $attempts = 0;
        $maxAttempts = 2;

        while ($attempts < $maxAttempts) {
            try {
                Log::info("Fetching page content with browser (attempt " . ($attempts + 1) . ")", ['url' => $url]);

                if ($customTimeout !== null) {
                    $timeout = $customTimeout;
                } else {
                    $timeout = $attempts === 0 ? $this->defaultOptions['timeout'] : ($this->defaultOptions['timeout'] * 2);
                }

                $browsershot = Browsershot::url($url)
                               ->timeout($timeout)
                               ->userAgent($this->defaultOptions['userAgent'])
                               ->windowSize(
                                   $this->defaultOptions['windowSize'][0],
                                   $this->defaultOptions['windowSize'][1]
                               )
                               ->waitUntil('domcontentloaded');

                if ($cookieHeader = $this->cookieHeader()) {
                    $browsershot->setExtraHttpHeaders(['Cookie' => $cookieHeader]);
                }

                foreach ($this->defaultOptions['args'] as $arg) {
                    $browsershot->addChromiumArguments([$arg]);
                }

                if ($waitForSelector !== null && $waitForSelector !== '') {
                    // Smart wait: return as soon as the target selector resolves.
                    // The timeout below is enforced inside Puppeteer; if it fires,
                    // browser.cjs throws and we fall through to the catch below
                    // for a one-time small-delay retry.
                    $browsershot->waitForSelector($waitForSelector, ['timeout' => $selectorTimeout]);
                } else {
                    $browsershot->delay($waitTime * 1000);
                }

                $html = $browsershot->bodyHtml();

                Log::info("Successfully fetched page content", [
                    'url' => $url,
                    'content_length' => strlen($html),
                    'attempt' => $attempts + 1,
                    'wait_strategy' => $waitForSelector !== null ? 'selector' : 'delay',
                ]);

                return $html;

            } catch (\Exception $e) {
                $attempts++;
                $isSelectorTimeout = $waitForSelector !== null
                    && stripos($e->getMessage(), 'waiting for selector') !== false;

                Log::warning("Browser fetch attempt failed", [
                    'url' => $url,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                    'cause' => $isSelectorTimeout ? 'selector_timeout' : 'navigation_or_other',
                ]);

                if ($isSelectorTimeout) {
                    // The page came back but the expected selector never appeared
                    // (slow render, selector drift, captcha, etc.). Re-fetch once
                    // without the selector wait so the caller gets *some* HTML.
                    $waitForSelector = null;
                }

                if ($attempts < $maxAttempts) {
                    sleep(rand(3, 5));
                }
            }
        }

        Log::error("Failed to fetch page content after all attempts", ['url' => $url]);
        return null;
    }

    /**
     * Get all pages content with proper pagination handling
     */
    public function getAllPagesContent(string $baseUrl, array $paginationConfig = []): array
    {
        $allContent = [];
        $currentPage = 1;
        $maxPages = $paginationConfig['max_pages'] ?? 250;
        $pageParam = $paginationConfig['page_param'] ?? 'page';
        $noContentCount = 0;
        $maxNoContentPages = 3;
        $totalPages = null;

        Log::info("Starting pagination scraping", [
            'base_url' => $baseUrl,
            'max_pages_config' => $maxPages,
            'page_param' => $pageParam
        ]);

        while ($currentPage <= $maxPages && $noContentCount < $maxNoContentPages) {
            $url = $this->buildPaginationUrl($baseUrl, $pageParam, $currentPage);
            
            Log::info("Scraping page {$currentPage} with browser", [
                'url' => $url,
                'total_pages_detected' => $totalPages
            ]);
            
            $content = $this->getPageContent($url, 5);
            
            if (!$content || strlen($content) < 1000) {
                $noContentCount++;
                Log::warning("No content or minimal content on page {$currentPage}");
                
                if ($noContentCount >= $maxNoContentPages) {
                    Log::info("Stopping pagination due to consecutive empty pages");
                    break;
                }
                
                $currentPage++;
                continue;
            }

            $noContentCount = 0;
            
            // Extract total pages from pagination info (if not already extracted)
            if ($totalPages === null) {
                $totalPages = $this->extractTotalPages($content, $paginationConfig);
                if ($totalPages !== null) {
                    $maxPages = min($maxPages, $totalPages);
                    Log::info("Detected total pages from pagination", [
                        'total_pages' => $totalPages,
                        'adjusted_max_pages' => $maxPages
                    ]);
                }
            }
            
            $allContent[] = [
                'page' => $currentPage,
                'url' => $url,
                'content' => $content
            ];

            Log::info("Successfully scraped page {$currentPage}", [
                'content_length' => strlen($content),
                'total_pages_so_far' => count($allContent)
            ]);

            // Check if there's a next page indicator
            if (isset($paginationConfig['has_next_selector'])) {
                if (!$this->hasNextPageImproved($content, $paginationConfig['has_next_selector'])) {
                    Log::info("No more pages found after page {$currentPage}");
                    break;
                }
            }

            $currentPage++;
            
            // Add delay between pages
            $delay = rand(3, 6);
            Log::debug("Waiting {$delay} seconds before next page");
            sleep($delay);
        }

        Log::info("Completed pagination scraping", [
            'base_url' => $baseUrl,
            'total_pages_scraped' => count($allContent),
            'last_page' => $currentPage - 1,
            'total_pages_detected' => $totalPages
        ]);

        return $allContent;
    }

    /**
     * Extract total number of pages from pagination section
     */
    protected function extractTotalPages(string $content, array $paginationConfig): ?int
    {
        try {
            $crawler = new Crawler($content);
            
            // Try to find pagination info like "Page X of Y"
            $paginationText = null;
            
            // Look for pagination container with page info
            $crawler->filter('div.lvJbLV, nav.iu0OAI, div[class*="pagination"]')->each(function (Crawler $node) use (&$paginationText) {
                $text = $node->text();
                if (preg_match('/Page\s+(\d{1,5})\s+of\s+(\d{1,5})\b/i', $text, $matches)) {
                    $paginationText = $matches;
                }
            });

            if ($paginationText && isset($paginationText[2])) {
                $totalPages = intval($paginationText[2]);
                Log::info("Extracted total pages from pagination text", [
                    'total_pages' => $totalPages,
                    'current_page' => $paginationText[1]
                ]);
                return $totalPages;
            }

            // Fallback: Count visible page numbers in pagination
            $pageLinks = [];
            $crawler->filter('nav a, div[class*="pagination"] a')->each(function (Crawler $link) use (&$pageLinks) {
                $text = trim($link->text());
                if (is_numeric($text)) {
                    $pageLinks[] = intval($text);
                }
            });

            if (!empty($pageLinks)) {
                $maxPage = max($pageLinks);
                Log::info("Extracted total pages from page links", [
                    'total_pages' => $maxPage,
                    'page_links_found' => count($pageLinks)
                ]);
                return $maxPage;
            }

        } catch (\Exception $e) {
            Log::warning("Failed to extract total pages", [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Check for next page using DOM parsing
     */
    protected function hasNextPageImproved(string $content, string $selector): bool
    {
        try {
            $crawler = new Crawler($content);
            
            // Check if next page button/link exists and is clickable
            $nextButtons = $crawler->filter('a[href*="page="], a:contains("Next"), a[class*="next"]');
            
            if ($nextButtons->count() > 0) {
                // Filter out disabled/inactive next buttons
                $activeNext = false;
                $nextButtons->each(function (Crawler $node) use (&$activeNext) {
                    $class = $node->attr('class') ?? '';
                    $href = $node->attr('href') ?? '';
                    
                    // Check if it's not disabled
                    if (strpos($class, 'disabled') === false && 
                        strpos($class, 'inactive') === false &&
                        !empty($href)) {
                        $activeNext = true;
                    }
                });
                
                if ($activeNext) {
                    Log::debug("Next page button found and is active");
                    return true;
                }
            }

            // Fallback: Check for selector pattern
            if (!empty($selector) && strpos($content, $selector) !== false) {
                Log::debug("Next page indicator found using selector");
                return true;
            }

            Log::debug("No next page found");
            return false;

        } catch (\Exception $e) {
            Log::warning("Error checking for next page", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Handle infinite scroll pages
     */
    public function getInfiniteScrollContent(string $url, int $scrolls = 10): ?string
    {
        try {
            Log::info("Fetching infinite scroll content", ['url' => $url, 'scrolls' => $scrolls]);

            $browsershot = Browsershot::url($url)
                ->timeout(120)
                ->userAgent($this->defaultOptions['userAgent'])
                ->windowSize($this->defaultOptions['windowSize'][0], $this->defaultOptions['windowSize'][1])
                ->waitUntil('domcontentloaded');

            if ($cookieHeader = $this->cookieHeader()) {
                $browsershot->setExtraHttpHeaders(['Cookie' => $cookieHeader]);
            }

            foreach ($this->defaultOptions['args'] as $arg) {
                $browsershot->addChromiumArguments([$arg]);
            }

            $scrollScript = "
                let scrollCount = 0;
                const maxScrolls = {$scrolls};
                
                async function scrollAndWait() {
                    while (scrollCount < maxScrolls) {
                        window.scrollTo(0, document.body.scrollHeight);
                        scrollCount++;
                        
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        
                        const currentHeight = document.body.scrollHeight;
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        
                        if (currentHeight === document.body.scrollHeight) {
                            break;
                        }
                    }
                }
                
                await scrollAndWait();
            ";

            $html = $browsershot
                ->evaluate($scrollScript)
                ->bodyHtml();

            Log::info("Successfully fetched infinite scroll content", [
                'url' => $url,
                'content_length' => strlen($html)
            ]);

            return $html;

        } catch (\Exception $e) {
            Log::error('Infinite scroll error', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Execute custom JavaScript and get result
     */
    public function executeScript(string $url, string $script, int $waitTime = 3): ?string
    {
        try {
            Log::info("Executing custom script", ['url' => $url]);

            $browsershot = Browsershot::url($url)
                ->timeout($this->defaultOptions['timeout'])
                ->userAgent($this->defaultOptions['userAgent'])
                ->windowSize($this->defaultOptions['windowSize'][0], $this->defaultOptions['windowSize'][1])
                ->waitUntil('domcontentloaded')
                ->delay($waitTime * 1000);

            if ($cookieHeader = $this->cookieHeader()) {
                $browsershot->setExtraHttpHeaders(['Cookie' => $cookieHeader]);
            }

            foreach ($this->defaultOptions['args'] as $arg) {
                $browsershot->addChromiumArguments([$arg]);
            }

            $result = $browsershot->evaluate($script);

            return $result;

        } catch (\Exception $e) {
            Log::error('Script execution error', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Take screenshot of page
     */
    public function takeScreenshot(string $url, string $savePath): bool
    {
        try {
            Log::info("Taking screenshot", ['url' => $url, 'path' => $savePath]);

            $browsershot = Browsershot::url($url)
                ->timeout($this->defaultOptions['timeout'])
                ->userAgent($this->defaultOptions['userAgent'])
                ->windowSize($this->defaultOptions['windowSize'][0], $this->defaultOptions['windowSize'][1])
                ->waitUntil('domcontentloaded')
                ->delay(10000);

            if ($cookieHeader = $this->cookieHeader()) {
                $browsershot->setExtraHttpHeaders(['Cookie' => $cookieHeader]);
            }

            $browsershot->save($savePath);

            return file_exists($savePath);

        } catch (\Exception $e) {
            Log::error('Screenshot error', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Build pagination URL
     */
    protected function buildPaginationUrl(string $baseUrl, string $pageParam, int $page): string
    {
        // Strip any pre-existing page param so stale category URLs don't produce ?page=26&page=2
        $baseUrl = preg_replace('/([?&])' . preg_quote($pageParam, '/') . '=\d+/', '$1', $baseUrl);
        $baseUrl = preg_replace('/[?&]$/', '', $baseUrl);   // trim trailing ? or &

        if ($page === 1) {
            return $baseUrl;
        }

        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';
        return $baseUrl . $separator . $pageParam . '=' . $page;
    }

    /**
     * Check if browser dependencies are installed
     */
    public static function checkDependencies(): array
    {
        $status = [
            'node' => false,
            'npm' => false,
            'chrome' => false,
            'puppeteer' => false,
            'ready' => false
        ];

        try {
            $nodeResult = shell_exec('node --version 2>/dev/null');
            $status['node'] = !empty($nodeResult);

            $npmResult = shell_exec('npm --version 2>/dev/null');
            $status['npm'] = !empty($npmResult);

            $chromeResult = shell_exec('which chromium-browser 2>/dev/null || which google-chrome 2>/dev/null');
            $status['chrome'] = !empty($chromeResult);

            $puppeteerResult = shell_exec('npm list -g puppeteer 2>/dev/null | grep puppeteer');
            $status['puppeteer'] = !empty($puppeteerResult);

            $status['ready'] = $status['node'] && $status['npm'] && $status['chrome'];

        } catch (\Exception $e) {
            Log::error('Dependency check error', ['error' => $e->getMessage()]);
        }

        return $status;
    }
}
