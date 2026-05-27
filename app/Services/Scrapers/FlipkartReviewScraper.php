<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\Review;
use App\Services\BrowserService;
use GuzzleHttp\Client;
use Carbon\Carbon;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Final Optimized Flipkart Review Scraper
 * 
 * Features:
 * 1. Skip reviews without reviewer_name
 * 2. Skip products without valid reviews
 * 3. Only store SKU for products without reviews
 * 4. Auto-scroll functionality
 * 5. Database error fixes
 */
class FlipkartReviewScraper
{
    protected Client $httpClient;
    protected BrowserService $browserService;
    protected ?string $currentProductSku = null;
    
    // Configuration for review scraping
    protected int $maxReviewsPerProduct = 50;
    protected int $maxScrollAttempts = 10;
    protected int $scrollWaitTime = 2000;
    protected int $maxTimePerProduct = 30;
    protected int $minReviewsPerScroll = 3;
    
    protected array $stats = [
        'products_processed' => 0,
        'reviews_found' => 0,
        'reviews_added' => 0,
        'reviews_updated' => 0,
        'reviews_skipped_no_name' => 0,      // ✅ NEW: Reviews skipped due to no reviewer_name
        'products_with_reviews' => 0,        // ✅ NEW: Products with valid reviews
        'products_without_reviews' => 0,     // ✅ NEW: Products without reviews
        'errors_count' => 0,
        'products_skipped_timeout' => 0,
        'products_skipped_limit' => 0,
    ];

    public function __construct()
    {
        $this->initializeHttpClient();
        $this->browserService = new BrowserService();
    }

    protected function initializeHttpClient(): void
    {
        $this->httpClient = new Client([
            'timeout' => config('scraper.timeout', 30),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
            'verify' => false,
            'allow_redirects' => true,
            'http_errors' => false
        ]);
    }

    public function setConfig(array $config): void
    {
        if (isset($config['max_reviews_per_product'])) {
            $this->maxReviewsPerProduct = $config['max_reviews_per_product'];
        }
        if (isset($config['max_scroll_attempts'])) {
            $this->maxScrollAttempts = $config['max_scroll_attempts'];
        }
        if (isset($config['scroll_wait_time'])) {
            $this->scrollWaitTime = $config['scroll_wait_time'];
        }
        if (isset($config['max_time_per_product'])) {
            $this->maxTimePerProduct = $config['max_time_per_product'];
        }
        if (isset($config['min_reviews_per_scroll'])) {
            $this->minReviewsPerScroll = $config['min_reviews_per_scroll'];
        }
    }

    public function scrapeReviews(?array $productIds = null, ?int $limit = null): array
    {
        Log::info("Starting Flipkart review scraping with validation", [
            'max_reviews_per_product' => $this->maxReviewsPerProduct,
            'max_time_per_product' => $this->maxTimePerProduct,
            'max_scroll_attempts' => $this->maxScrollAttempts
        ]);

        // $query = Product::where('platform', 'flipkart')
        //         ->where('is_active', true)
        //         ->whereNotNull('product_url')
        //         ->where('review_count', '>', 0)
        //         ->whereNotNull('sku')
        //         ->groupBy('sku');

        $query = Product::where('platform', 'flipkart')
                        ->where('is_active', true)
                        ->whereNotNull('product_url')
                        ->where('review_count', '>', 0)
                        ->whereNotNull('sku')
                        ->whereNotExists(function ($q) {
                            $q->select(\DB::raw(1))
                            ->from('reviews')
                            ->whereColumn('reviews.sku', 'products.sku');
                        })
                        ->orderBy('id', 'desc')
                        ->select('id', 'sku', 'product_url');

        if ($productIds) {
            $query->whereIn('id', $productIds);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $products = $query->get();

        foreach ($products as $product) {
            try {
                $this->scrapeProductReviews($product);
                $this->stats['products_processed']++;
                $this->randomDelay(2, 4);
            } catch (\Exception $e) {
                Log::error("Failed to scrape Flipkart reviews", [
                    'product_id' => $product->id,
                    'error' => $e->getMessage()
                ]);
                $this->stats['errors_count']++;
            }
        }

        Log::info("Flipkart review scraping completed", $this->stats);
        return $this->stats;
    }

    /**
     * ✅ UPDATED: Scrape reviews for a single product
     * 
     * अब यह:
     * 1. Reviews extract करता है
     * 2. Valid reviews को store करता है
     * 3. अगर कोई valid review नहीं है तो skip करता है
     */
    protected function scrapeProductReviews(Product $product): void
    {
        $this->currentProductSku = $product->sku;
        $reviewsUrl = $this->getReviewsUrl($product->sku);

        if (!$reviewsUrl) {
            Log::warning("Could not generate reviews URL for product", ['product_id' => $product->id]);
            return;
        }

        Log::info("Starting review scraping for product", [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'url' => $reviewsUrl
        ]);

        try {
            $html = $this->fetchPageWithAutoScroll($reviewsUrl, $product->id);

            if (!$html) {
                Log::warning("Failed to fetch reviews page", ['product_id' => $product->id]);
                $this->stats['products_without_reviews']++;
                return;
            }

            $crawler = new Crawler($html);
            $reviews = $this->extractReviews($crawler, $product->id);

            Log::info("Reviews extracted from page", [
                'product_id' => $product->id,
                'reviews_count' => count($reviews)
            ]);

            // ✅ NEW: Check if we have valid reviews
            if (empty($reviews)) {
                Log::info("No valid reviews found for product", [
                    'product_id' => $product->id,
                    'sku' => $product->sku
                ]);
                $this->stats['products_without_reviews']++;
                return;
            }

            // ✅ Save reviews
            $savedCount = 0;
            foreach ($reviews as $reviewData) {
                if ($this->saveReview($reviewData)) {
                    $savedCount++;
                }
            }

            // ✅ Only count as product_with_reviews if at least one review was saved
            if ($savedCount > 0) {
                $this->stats['products_with_reviews']++;
                Log::info("Reviews saved successfully", [
                    'product_id' => $product->id,
                    'reviews_saved' => $savedCount
                ]);
            } else {
                $this->stats['products_without_reviews']++;
                Log::info("No reviews were saved for product", [
                    'product_id' => $product->id
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Error scraping product reviews", [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
            $this->stats['products_without_reviews']++;
        }
    }

    protected function fetchPageWithAutoScroll(string $url, int $productId): ?string
    {
        try {
            Log::debug("Fetching page with auto-scroll", ['url' => $url]);

            $startTime = time();
            $scrollAttempt = 0;
            $previousReviewCount = 0;
            $noNewReviewsCount = 0;

            $html = $this->browserService->getPageContent($url, 3, 120);

            if (!$html || strlen($html) < 500) {
                Log::warning("Initial page fetch failed", ['url' => $url]);
                return null;
            }

            while ($scrollAttempt < $this->maxScrollAttempts) {
                $elapsedTime = time() - $startTime;
                if ($elapsedTime > $this->maxTimePerProduct) {
                    Log::info("Timeout reached for product reviews", [
                        'product_id' => $productId,
                        'elapsed_time' => $elapsedTime,
                        'max_time' => $this->maxTimePerProduct,
                        'scroll_attempts' => $scrollAttempt
                    ]);
                    $this->stats['products_skipped_timeout']++;
                    break;
                }

                $currentReviewCount = $this->countReviewsInHtml($html);
                if ($currentReviewCount >= $this->maxReviewsPerProduct) {
                    Log::info("Review limit reached for product", [
                        'product_id' => $productId,
                        'reviews_count' => $currentReviewCount,
                        'max_reviews' => $this->maxReviewsPerProduct
                    ]);
                    $this->stats['products_skipped_limit']++;
                    break;
                }

                $newReviewsCount = $currentReviewCount - $previousReviewCount;
                if ($newReviewsCount < $this->minReviewsPerScroll && $scrollAttempt > 0) {
                    $noNewReviewsCount++;
                    if ($noNewReviewsCount >= 2) {
                        Log::info("No new reviews loaded, stopping scroll", [
                            'product_id' => $productId,
                            'scroll_attempts' => $scrollAttempt
                        ]);
                        break;
                    }
                } else {
                    $noNewReviewsCount = 0;
                }

                $previousReviewCount = $currentReviewCount;

                Log::debug("Scrolling to load more reviews", [
                    'product_id' => $productId,
                    'scroll_attempt' => $scrollAttempt + 1,
                    'current_reviews' => $currentReviewCount
                ]);

                $html = $this->scrollAndFetch($url, $scrollAttempt);
                $scrollAttempt++;

                usleep($this->scrollWaitTime * 1000);
            }

            Log::info("Auto-scroll completed", [
                'product_id' => $productId,
                'total_scroll_attempts' => $scrollAttempt,
                'final_review_count' => $this->countReviewsInHtml($html),
                'elapsed_time' => time() - $startTime
            ]);

            return $html;

        } catch (\Exception $e) {
            Log::error("Failed to fetch page with auto-scroll", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function scrollAndFetch(string $url, int $scrollAttempt): ?string
    {
        try {
            $html = $this->browserService->getPageContent($url, 3, 120);

            if ($html && strlen($html) > 500) {
                return $html;
            }

            return null;
        } catch (\Exception $e) {
            Log::warning("Failed to scroll and fetch", [
                'error' => $e->getMessage(),
                'scroll_attempt' => $scrollAttempt
            ]);
            return null;
        }
    }

    protected function countReviewsInHtml(string $html): int
    {
        try {
            $crawler = new Crawler($html);
            return $crawler->filter('div.fWi7J_')->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function getReviewsUrl(string $sku): ?string
    {
        if (!$sku) {
            Log::warning("Cannot generate Flipkart review URL without SKU");
            return null;
        }
        
        return "https://www.flipkart.com/product/product-reviews/{$sku}";
    }

    protected function extractReviews(Crawler $crawler, int $productId): array
    {
        $reviews = [];
        $reviewCount = 0;

        try {
            $crawler->filter('div.fWi7J_')->each(function (Crawler $reviewNode) use (&$reviews, &$reviewCount, $productId) {
                if ($reviewCount >= $this->maxReviewsPerProduct) {
                    return;
                }

                try {
                    $reviewData = [
                        'product_id' => $productId,
                        'platform' => "flipkart",
                        'sku' => $this->currentProductSku,
                        'review_id' => $this->extractReviewId($reviewNode),
                        'reviewer_name' => $this->extractReviewerName($reviewNode),
                        'rating' => $this->extractRating($reviewNode),
                        'review_title' => $this->extractReviewTitle($reviewNode),
                        'review_text' => $this->extractReviewText($reviewNode),
                        'review_date' => $this->extractReviewDate($reviewNode),
                        'verified_purchase' => $this->extractVerifiedPurchase($reviewNode),
                        'helpful_count' => $this->extractHelpfulCount($reviewNode),
                        'review_images' => $this->extractReviewImages($reviewNode),
                        'video_urls' => $this->extractVideoUrls($reviewNode),
                        'variant_info' => $this->extractVariantInfo($reviewNode),
                    ];

                    // ✅ NEW: Skip if reviewer_name is NULL
                    if (!$reviewData['reviewer_name']) {
                        Log::debug("Skipping review without reviewer_name", [
                            'product_id' => $productId,
                            'review_id' => $reviewData['review_id']
                        ]);
                        $this->stats['reviews_skipped_no_name']++;
                        return;
                    }

                    if ($reviewData['review_id'] || $reviewData['review_text']) {
                        $reviews[] = $reviewData;
                        $this->stats['reviews_found']++;
                        $reviewCount++;
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to extract review", [
                        'error' => $e->getMessage(),
                        'product_id' => $productId
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error("Failed to extract reviews from page", [
                'error' => $e->getMessage(),
                'product_id' => $productId
            ]);
        }

        return $reviews;
    }

    // ========== EXTRACTION METHODS ==========

    protected function extractReviewId(Crawler $reviewNode): ?string
    {
        try {
            $productId = $this->currentProductSku ?? '';
            $name = strtolower(trim($this->extractReviewerName($reviewNode) ?? ''));
            $date = $this->extractReviewDate($reviewNode) ?? '';

            $name = preg_replace('/\s+/', '_', $name);
            $name = preg_replace('/[^a-z0-9_]/', '', $name);
            $date = str_replace('-', '', $date);

            return 'FK_' . $productId . '_' . $name . '_' . $date;

        } catch (\Exception $e) {
            return null;
        }
    }

    protected function extractReviewerName(Crawler $reviewNode): ?string
    {
        try {
            $texts = $reviewNode
                ->filter('.css-146c3p1')
                ->each(fn($n) => trim($n->text()));

            for ($i = 0; $i < count($texts); $i++) {

                if (
                    preg_match('/^[A-Za-z ]{2,}$/', $texts[$i]) &&
                    !str_contains($texts[$i], 'Verified') &&
                    !str_contains($texts[$i], 'Helpful')
                ) {
                    if (
                        isset($texts[$i + 1]) &&
                        str_starts_with($texts[$i + 1], ',')
                    ) {
                        return $texts[$i];
                    }
                }
            }
        } catch (\Exception $e) {}

        return null;
    }

    protected function extractRating(Crawler $reviewNode): ?float
    {
        try {
            $text = $reviewNode->text();
            if (preg_match('/\b([1-5]\.0)\b/', $text, $m)) {
                return (float)$m[1];
            }
        } catch (\Exception $e) {}
        return null;
    }

    protected function extractReviewTitle(Crawler $reviewNode): ?string
    {
        try {
            $nodes = $reviewNode->filter('.css-146c3p1');

            foreach ($nodes as $node) {
                $text = trim($node->textContent);

                if (
                    empty($text) ||
                    stripos($text, 'Verified') !== false ||
                    stripos($text, 'Helpful') !== false ||
                    strpos($text, ',') !== false
                ) {
                    continue;
                }

                if (is_numeric($text) || preg_match('/^\d+(\.\d+)?$/', $text)) {
                    continue;
                }

                if (preg_match('/^[^\w]+$/', $text)) {
                    continue;
                }

                if (strlen($text) <= 2) {
                    continue;
                }

                if (strlen($text) <= 50) {
                    return $text;
                }
            }
        } catch (\Exception $e) {}

        return null;
    }

    protected function extractReviewText(Crawler $reviewNode): ?string
    {
        try {
            if ($reviewNode->filter('.css-1jxf684')->count()) {
                return trim($reviewNode->filter('.css-1jxf684')->text());
            }

            foreach ($reviewNode->filter('span, div') as $node) {
                $text = trim($node->textContent);

                if (
                    strlen($text) > 30 &&
                    stripos($text, 'Verified') === false &&
                    stripos($text, 'Helpful') === false &&
                    strpos($text, ',') === false
                ) {
                    return $text;
                }
            }

        } catch (\Exception $e) {}

        return null;
    }

    protected function extractReviewDate(Crawler $reviewNode): ?string
    {
        try {
            $text = $reviewNode->text();

            if (preg_match('/(\d+)\s+(day|month|year)s?\s+ago/i', $text, $m)) {
                $date = Carbon::now();

                switch (strtolower($m[2])) {
                    case 'day': $date->subDays($m[1]); break;
                    case 'month': $date->subMonths($m[1]); break;
                    case 'year': $date->subYears($m[1]); break;
                }

                return $date->format('Y-m-d');
            }

            if (preg_match('/([A-Za-z]{3}),\s*(\d{4})/', $text, $m)) {
                return Carbon::createFromFormat('M Y', $m[1] . ' ' . $m[2])
                    ->startOfMonth()
                    ->format('Y-m-d');
            }

            if (preg_match('/\b\d{1,2}\s+[A-Za-z]{3,}\s+\d{4}\b/', $text, $m)) {
                return Carbon::parse($m[0])->format('Y-m-d');
            }

        } catch (\Exception $e) {
            // optional log
        }

        return null;
    }

    protected function extractVerifiedPurchase(Crawler $reviewNode): bool
    {
        return $reviewNode->filter(':contains("Verified Purchase")')->count() > 0;
    }

    protected function extractHelpfulCount(Crawler $reviewNode): ?int
    {
        try {
            $nodes = $reviewNode->filter('.css-146c3p1');

            foreach ($nodes as $node) {
                $text = trim($node->textContent);

                if (stripos($text, 'Helpful for') !== false) {
                    if (preg_match('/Helpful for\s+(\d+)/i', $text, $m)) {
                        return (int) $m[1];
                    }
                }
            }
        } catch (\Exception $e) {}

        return null;
    }

    protected function extractReviewImages(Crawler $reviewNode): array
    {
        try {
            return array_values(array_filter(
                $reviewNode->filter('img')->each(function (Crawler $node) {
                    $src = $node->attr('src');

                    if ($src && strpos($src, 'blobio') !== false) {
                        return $src;
                    }

                    return null;
                })
            ));
        } catch (\Exception $e) {}

        return [];
    }

    protected function extractVideoUrls(Crawler $reviewNode): array
    {
        try {
            $videos = [];

            $reviewNode->filter('video')->each(function (Crawler $node) use (&$videos) {
                $src = $node->attr('src');
                if ($src) {
                    $videos[] = $src;
                }
            });

            $reviewNode->filter('video source')->each(function (Crawler $node) use (&$videos) {
                $src = $node->attr('src');
                if ($src) {
                    $videos[] = $src;
                }
            });

            return array_values(array_unique($videos));

        } catch (\Exception $e) {}

        return [];
    }

    protected function extractVariantInfo(Crawler $reviewNode): ?string
    {
        try {
            $nodes = $reviewNode->filter('.css-146c3p1');

            foreach ($nodes as $node) {
                $text = trim($node->textContent);

                if (stripos($text, 'Review for:') !== false) {
                    return trim(str_replace('Review for:', '', $text));
                }
            }
        } catch (\Exception $e) {}

        return null;
    }

    // ========== DATABASE SAVE ==========

    protected function validateReviewData(array $reviewData): array
    {
        return [
            'product_id' => (int)($reviewData['product_id'] ?? 0),
            'platform' => (string)($reviewData['platform'] ?? 'flipkart'),
            'sku' => $this->sanitizeString($reviewData['sku'] ?? null),
            'review_id' => $this->sanitizeString($reviewData['review_id'] ?? null),
            'reviewer_name' => $this->sanitizeString($reviewData['reviewer_name'] ?? null),
            'rating' => $reviewData['rating'] ? (float)$reviewData['rating'] : null,
            'review_title' => $this->sanitizeString($reviewData['review_title'] ?? null),
            'review_text' => $this->sanitizeString($reviewData['review_text'] ?? null),
            'review_date' => $this->sanitizeString($reviewData['review_date'] ?? null),
            'verified_purchase' => (bool)($reviewData['verified_purchase'] ?? false),
            'helpful_count' => (int)($reviewData['helpful_count'] ?? 0),
            'review_images' => $this->convertArrayToJson($reviewData['review_images'] ?? []),
            'video_urls' => $this->convertArrayToJson($reviewData['video_urls'] ?? []),
            'variant_info' => $this->sanitizeString($reviewData['variant_info'] ?? null),
        ];
    }

    protected function sanitizeString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    protected function convertArrayToJson($value): ?string
    {
        if (is_array($value)) {
            if (empty($value)) {
                return null;
            }
            return json_encode($value);
        }

        if (is_string($value)) {
            if (empty($value)) {
                return null;
            }
            json_decode($value);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
            return null;
        }

        return null;
    }

    /**
     * ✅ UPDATED: Save review with validation
     * 
     * Returns true if saved, false if skipped
     */
    protected function saveReview(array $reviewData): bool
    {
        try {
            $validatedData = $this->validateReviewData($reviewData);

            if (!$validatedData['review_id']) {
                Log::warning("Skipping review with invalid review_id", [
                    'reviewer_name' => $validatedData['reviewer_name'],
                    'product_id' => $validatedData['product_id']
                ]);
                return false;
            }

            Log::debug("Saving review with validated data", [
                'review_id' => $validatedData['review_id'],
                'product_id' => $validatedData['product_id'],
                'reviewer_name' => $validatedData['reviewer_name']
            ]);

            $existing = Review::where('review_id', $validatedData['review_id'])->first();

            if ($existing) {
                $existing->update($validatedData);
                $this->stats['reviews_updated']++;
                Log::debug("Review updated", ['review_id' => $validatedData['review_id']]);
            } else {
                Review::create($validatedData);
                $this->stats['reviews_added']++;
                Log::debug("Review created", ['review_id' => $validatedData['review_id']]);
            }

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to save review", [
                'review_id' => $reviewData['review_id'] ?? 'unknown',
                'product_id' => $reviewData['product_id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $this->stats['errors_count']++;
            return false;
        }
    }

    protected function randomDelay(int $min = 1, int $max = 3): void
    {
        sleep(random_int($min, $max));
    }
}
