<?php

namespace App\Services\Scrapers;

use App\Models\Keyword;
use App\Models\Product;
use App\Models\ProductRanking;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AmazonRankingScraper
{
    protected Client $httpClient;
    protected string $platform = 'amazon';
    protected string $scraperId = '';
    protected int $maxPages = 4;
    protected int $maxRetries = 3;
    protected int $retryDelay = 5; // seconds
    protected array $stats = [
        'keywords_processed' => 0,
        'products_found' => 0,
        'rankings_recorded' => 0,
        'errors_count' => 0,
        'retries_count' => 0,
        '503_errors' => 0,
    ];

    // User-Agent rotation list
    protected array $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
    ];

    public function __construct()
    {
        $this->initializeHttpClient();
    }

    public function setScraperId(string $id): void
    {
        $this->scraperId = $id;
    }

    public function getScraperId(): string
    {
        return $this->scraperId;
    }

    /**
     * Initialize HTTP client with better configuration
     */
    protected function initializeHttpClient(): void
    {
        $this->httpClient = new Client([
            'timeout' => config('scraper.timeout', 60),
            'connect_timeout' => 30,
            'headers' => $this->getHeaders(),
            'verify' => false,
            'allow_redirects' => true,
            'http_errors' => false,
            'cookies' => true,
        ]);
    }

    /**
     * Get random headers with User-Agent rotation
     */
    protected function getHeaders(): array
    {
        $userAgent = $this->userAgents[array_rand($this->userAgents)];
        
        return [
            'User-Agent' => $userAgent,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Cache-Control' => 'max-age=0',
        ];
    }

    /**
     * Scrape rankings for all active keywords
     */
    public function scrapeRankings(?array $keywordIds = null): array
    {
        Log::info("Starting Amazon ranking scraping", [
            'keyword_ids' => $keywordIds,
            'max_pages' => $this->maxPages,
            'max_retries' => $this->maxRetries
        ]);

        // Get keywords to process
        $query = Keyword::where('platform', $this->platform)
            ->where('status', true);
            //->where('category', 'detergent'); //Mobile  printer diaper detergent

        if ($keywordIds) {
            $query->whereIn('id', $keywordIds);
        }

        $keywords = $query->get();

        Log::info("Found {$keywords->count()} keywords to process");

        foreach ($keywords as $keyword) {
            try {
                $this->scrapeKeywordRankings($keyword);
                $this->stats['keywords_processed']++;

                // Add longer delay between keywords
                $this->randomDelay(15, 30);
            } catch (\Exception $e) {
                Log::error("Failed to scrape rankings for keyword", [
                    'keyword_id' => $keyword->id,
                    'keyword' => $keyword->keyword,
                    'error' => $e->getMessage()
                ]);
                $this->stats['errors_count']++;
            }
        }

        Log::info("Amazon ranking scraping completed", $this->stats);

        return $this->stats;
    }

    /**
     * Scrape rankings for a single keyword
     */
    protected function scrapeKeywordRankings(Keyword $keyword): void
    {
        Log::info("Scraping rankings for keyword", [
            'keyword_id' => $keyword->id,
            'keyword' => $keyword->keyword
        ]);

        $organicPositionCounter = 0;

        for ($page = 1; $page <= $this->maxPages; $page++) {
            try {
                $url = $this->buildSearchUrl($keyword->keyword, $page);
                
                Log::info("Fetching search results page", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'url' => $url,
                    'current_organic_position' => $organicPositionCounter
                ]);

                // Fetch with retry logic
                $html = $this->fetchPageWithRetry($url);
                
                if (!$html) {
                    Log::warning("Failed to fetch search results page after retries", [
                        'keyword' => $keyword->keyword,
                        'page' => $page
                    ]);
                    break;
                }

                $crawler = new Crawler($html);
                $result = $this->extractProductsFromPage($crawler, $page, $organicPositionCounter);
                $products = $result['products'];
                $organicCount = $result['organic_count'];

                if (empty($products)) {
                    Log::info("No more products found, stopping pagination", [
                        'keyword' => $keyword->keyword,
                        'page' => $page
                    ]);
                    break;
                }

                // Save rankings
                foreach ($products as $productData) {
                    $this->saveRanking($keyword, $productData);
                }

                $organicPositionCounter += $organicCount;
                
                Log::info("Completed page scraping", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'organic_products_on_page' => $organicCount,
                    'total_organic_position' => $organicPositionCounter
                ]);

                // Add longer delay between pages
                $this->randomDelay(15, 30);
            } catch (\Exception $e) {
                Log::error("Error scraping search results page", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                break;
            }
        }
    }

    /**
     * Fetch page with retry logic and exponential backoff
     */
    protected function fetchPageWithRetry(string $url): ?string
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            try {
                $attempt++;
                
                Log::debug("Fetching page", [
                    'url' => $url,
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxRetries
                ]);

                $response = $this->httpClient->get($url);
                $statusCode = $response->getStatusCode();

                if ($statusCode === 200) {
                    Log::debug("Page fetched successfully", [
                        'url' => $url,
                        'status_code' => $statusCode
                    ]);
                    return $response->getBody()->getContents();
                }

                // Handle 503 Service Unavailable
                if ($statusCode === 503) {
                    $this->stats['503_errors']++;
                    $lastError = "503 Service Unavailable";
                    
                    Log::warning("503 Service Unavailable - will retry", [
                        'url' => $url,
                        'attempt' => $attempt,
                        'max_attempts' => $this->maxRetries
                    ]);

                    if ($attempt < $this->maxRetries) {
                        // Exponential backoff: 5s, 15s, 45s
                        $delay = $this->retryDelay * pow(3, $attempt - 1);
                        Log::info("Waiting before retry", [
                            'delay_seconds' => $delay,
                            'attempt' => $attempt
                        ]);
                        sleep($delay);
                        $this->stats['retries_count']++;
                        continue;
                    }
                }

                // Handle other non-200 status codes
                Log::warning("Non-200 status code received", [
                    'url' => $url,
                    'status_code' => $statusCode,
                    'attempt' => $attempt
                ]);

                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay);
                    $this->stats['retries_count']++;
                    continue;
                }

                return null;

            } catch (RequestException $e) {
                $lastError = $e->getMessage();
                
                Log::warning("HTTP request failed", [
                    'url' => $url,
                    'error' => $lastError,
                    'attempt' => $attempt
                ]);

                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay);
                    $this->stats['retries_count']++;
                    continue;
                }

                Log::error("Failed to fetch page after all retries", [
                    'url' => $url,
                    'error' => $lastError,
                    'attempts' => $attempt
                ]);

                return null;
            }
        }

        return null;
    }

    /**
     * Build Amazon search URL
     */
    protected function buildSearchUrl(string $keyword, int $page): string
    {
        $baseUrl = 'https://www.amazon.in/s';
        $params = [
            'k' => $keyword,
            'page' => $page,
        ];

        return $baseUrl . '?' . http_build_query($params);
    }

    /**
     * Extract products from search results page
     */
    protected function extractProductsFromPage(Crawler $crawler, int $page, int $startPosition): array
    {
        $products = [];
        $organicPosition = 0;
        $totalProducts = 0;
        $sponsoredCount = 0;

        try {
            $crawler->filter('div[data-component-type="s-search-result"]')->each(function (Crawler $node) use (&$products, $page, &$organicPosition, &$totalProducts, &$sponsoredCount, $startPosition) {
                try {
                    $totalProducts++;
                    
                    $asin = $node->attr('data-asin');
                    
                    if (!$asin || empty($asin)) {
                        return;
                    }

                    $isSponsored = $this->isSponsored($node);
                    
                    if ($isSponsored) {
                        $sponsoredCount++;
                        Log::debug("Skipping sponsored product", [
                            'asin' => $asin,
                            'page' => $page,
                            'position_on_page' => $totalProducts
                        ]);
                        return;
                    }

                    $organicPosition++;
                    $globalPosition = $startPosition + $organicPosition;

                    $title = null;
                    $titleNode = $node->filter('h2 a span');
                    if ($titleNode->count() > 0) {
                        $title = trim($titleNode->first()->text());
                    }

                    $products[] = [
                        'sku' => $asin,
                        'position' => $globalPosition,
                        'page' => $page,
                        'title' => $title,
                    ];

                    $this->stats['products_found']++;
                    
                    Log::debug("Found organic product", [
                        'asin' => $asin,
                        'organic_position' => $globalPosition,
                        'page' => $page,
                        'position_on_page' => $totalProducts
                    ]);
                } catch (\Exception $e) {
                    Log::warning("Failed to extract product from search result", [
                        'error' => $e->getMessage()
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error("Failed to extract products from page", [
                'error' => $e->getMessage()
            ]);
        }

        Log::info("Page extraction summary", [
            'page' => $page,
            'total_products' => $totalProducts,
            'sponsored_products' => $sponsoredCount,
            'organic_products' => $organicPosition
        ]);

        return [
            'products' => $products,
            'organic_count' => $organicPosition
        ];
    }

    /**
     * Check if a product is sponsored
     */
    protected function isSponsored(Crawler $node): bool
    {
        try {
            $sponsoredBadge = $node->filter('span.puis-label-popover-default, span[data-component-type="s-sponsored-label"]');
            if ($sponsoredBadge->count() > 0) {
                $badgeText = strtolower($sponsoredBadge->text());
                if (strpos($badgeText, 'sponsored') !== false) {
                    return true;
                }
            }

            $html = $node->html();
            if (stripos($html, 'sponsored') !== false || 
                stripos($html, 'ad badge') !== false) {
                return true;
            }

            if ($node->attr('data-is-sponsored') === 'true') {
                return true;
            }

            if ($node->filter('.AdHolder, .s-sponsored-list-item, [data-component-type="sp-sponsored-result"]')->count() > 0) {
                return true;
            }

        } catch (\Exception $e) {
            Log::debug("Could not determine if product is sponsored", [
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    /**
     * Save ranking to database
     */
    protected function saveRanking(Keyword $keyword, array $productData): void
    {
        try {
            $product = Product::where('platform', $this->platform)
                ->where('sku', $productData['sku'])
                ->first();

            $rankingData = [
                'product_id' => $product ? $product->id : null,
                'scraper_id' => $this->scraperId ?: null,
                'sku' => $productData['sku'],
                'keyword_id' => $keyword->id,
                'platform' => "$this->platform",
                'position' => $productData['position'],
                'page' => $productData['page'],
            ];

            ProductRanking::create($rankingData);
            $this->stats['rankings_recorded']++;

            Log::debug("Recorded ranking", [
                'keyword' => $keyword->keyword,
                'sku' => $productData['sku'],
                'position' => $productData['position'],
                'page' => $productData['page'],
                'title' => $productData['title'] ?? 'N/A'
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to save ranking", [
                'keyword_id' => $keyword->id,
                'sku' => $productData['sku'],
                'error' => $e->getMessage()
            ]);
            $this->stats['errors_count']++;
        }
    }

    /**
     * Random delay to avoid rate limiting
     */
    protected function randomDelay(int $min = 5, int $max = 15): void
    {
        $delay = rand($min * 1000000, $max * 1000000);
        usleep($delay);
    }

    /**
     * Get scraping statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
