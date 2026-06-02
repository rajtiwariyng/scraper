<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Cookie\CookieJar;

class FlipkartScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'flipkart';
        $this->useJavaScript = true;
        $this->paginationConfig = [
            'type' => 'regular',
            'max_pages' => 100,  // Will be overridden by actual page count detected
            'page_param' => 'page',
            // FIXED: Better selector for detecting next page
            'has_next_selector' => 'a[href*="page="]',
            'max_consecutive_errors' => 500,
            'delay_between_pages' => [3, 6], // Increased delays to avoid rate limiting
            'retry_failed_pages' => true,
            'max_retries_per_page' => 2
        ];
    }

    public function __construct()
    {
        parent::__construct('flipkart');
    }

    /**
     * Override scrape method to use Browsershot as primary mechanism
     * Flipkart has aggressive anti-scraping measures, so we use a real browser
     */
    public function scrape(array $categoryUrls): void
    {
        $this->scrapingLog = \App\Models\ScrapingLog::startSession($this->platform);

        try {
            Log::info("Starting Flipkart scraping with Browsershot", [
                'platform' => $this->platform,
                'categories' => count($categoryUrls)
            ]);

            foreach ($categoryUrls as $categoryUrl) {
                // Fetch pages one by one (page=1, page=2, ...) and process immediately.
                // No need to pre-fetch all pages — Flipkart pagination is just ?page=N.
                $this->scrapeCategoryWithPagination($categoryUrl);

                if ($this->isExecutionTimeLimitReached()) {
                    break;
                }
            }

            $this->scrapingLog->complete($this->stats);
        } catch (\Exception $e) {
            $this->handleError("Scraping failed for Flipkart", $e);
            $this->scrapingLog->fail($e->getMessage(), [], $this->stats);
        }
    }

    /**
     * Override randomDelay with longer delays for Flipkart
     * Flipkart has aggressive rate limiting, so we use longer delays
     */
    protected function randomDelay(): void
    {
        $delay = rand(2, 4);
        Log::debug("Random delay applied for Flipkart", ['delay_seconds' => $delay]);
        sleep($delay);
    }

    /**
     * Extract product URLs from Flipkart search/category page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            // Flipkart product links patterns
            $selectors = [
                'a[href*="/p/"]',
                'a.k7wcnx', 
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        // Convert relative URLs to absolute
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.flipkart.com' . $href;
                        }
                        // Only include product pages
                        if (strpos($href, '/p/') !== false) {
                            $productUrls[] = $href;
                        }
                    }
                });
            }

            // Remove duplicates and limit results
            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50); // Limit to 50 products per page

            Log::info("Extracted {count} product URLs from Flipkart category page", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Flipkart", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Extract product data from Flipkart product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];

            // Extract SKU from URL
            $data['sku'] = $this->extractSkuFromUrl($productUrl);
            if (!$data['sku']) {
                Log::warning("Could not extract SKU from Flipkart URL: {$productUrl}");
                return [];
            }

            $data['product_url'] = $productUrl = explode('?', $productUrl)[0];

            // Product name
            $data['title'] = $this->extractProductName($crawler);

            // Description
            $data['description'] = $this->extractDescription($crawler);
            $data["brand"] = $this->extractBrand($crawler);
            $data["size"] = $this->extractSize($crawler);
            $data["unit_count"] = $this->extractUnitCount($crawler);
            $data["model_name"] = $this->extractModelName($crawler);
            $data["color"] = $this->extractColour($crawler);
            $data["weight"] = $this->extractItemWeight($crawler);
            $data["dimensions"] = $this->extractProductDimensions($crawler);
            $data["highlights"] = $this->extractHighlights($crawler);
            $data["manufacturer"] = $this->extractManufacturer($crawler);
            $data["video_urls"] = $this->extractVideoUrls($crawler);
            $data["category"] = $this->extractCategory($crawler);
            $data["seller_name"] = $this->extractSellerName($crawler);
            $data["delivery_price"] = $this->extractDeliveryPrice($crawler);
            $data["delivery_date"] = $this->extractDeliveryDate($crawler);
            $data["technical_details"] = $this->extractTechnicalDetails($crawler);
            $data["additional_information"] = $this->extractAdditionalInformation($crawler);

            // Prices
            $priceData = $this->extractPrices($crawler);
            $data['price'] = $priceData['price'];
            $data['sale_price'] = $priceData['sale_price'];
            $data["currency_code"] = $this->extractCurrencyCode($crawler);

            // Offers
            $data['offers'] = $this->extractOffers($crawler);

            // Availability
            $data['inventory_status'] = $this->extractAvailability($crawler);

            // Rating and reviews
            $ratingData = $this->extractRatingAndReviews($crawler);
            $data['rating'] = $ratingData['rating'];
            $data['review_count'] = $ratingData['review_count'];
            $RatingHistogram = $this->extractRatingHistogram($crawler);
            $data["rating_1_star_percent"] = $RatingHistogram['rating_1_star_percent'];
            $data["rating_2_star_percent"] = $RatingHistogram['rating_2_star_percent'];
            $data["rating_3_star_percent"] = $RatingHistogram['rating_3_star_percent'];
            $data["rating_4_star_percent"] = $RatingHistogram['rating_4_star_percent'];
            $data["rating_5_star_percent"] = $RatingHistogram['rating_5_star_percent'];

            // Specifications
            $specs = $this->extractSpecifications($crawler);
            $data = array_merge($data, $specs);

            // Images
            $data['image_urls'] = $this->extractImages($crawler);

            // Variants
            $data['variants'] = $this->extractVariants($crawler);

            // Sanitize all data
            $data = DataSanitizer::sanitizeProductData($data);

            Log::debug("Extracted Flipkart product data", [
                'sku' => $data['sku'],
                'title' => $data['title'] ?? 'N/A'
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error("Failed to extract Flipkart product data", [
                'url' => $productUrl,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Extract SKU from Flipkart URL
     */
    private function extractSkuFromUrl(string $url): ?string
    {
        // Flipkart product ID pattern: /p/ITXXXXXXXXXXXX
        if (preg_match('/\/p\/(IT[A-Z0-9]+)/i', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extract product name
     */
    private function extractProductName(Crawler $crawler): ?string
    {
        $selectors = [
            '.v1zwn21l.v1zwn26',           // new Flipkart UI (h1 title)
            '.v1zwn21j.v1zwn26',           // older variant
            'div.v1zwn21j.v1zwn26',
            '[class*="v1zwn26"]',
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();

            if ($element->count() > 0) {

                // Try raw text
                $text = trim($element->text());

                // If text empty, try inner HTML without tags
                if (!$text) {
                    $text = trim(strip_tags($element->html()));
                }

                // Ensure final text is valid
                if ($text && strlen($text) > 3) {
                    return $this->cleanText($text);
                }
            }
        }

        return null;
    }


    /**
     * Extract product description
     */
    protected function extractDescription(Crawler $crawler): ?string
{
    try {

        // Directly target description container
        $node = $crawler->filter('div.r-1udh08x div.v1zwn21k.v1zwn26')->first();

        if ($node->count()) {

            $text = trim($node->text());

            // Basic sanity check
            if ($text && strlen($text) > 20) {
                return $text;
            }
        }

        return null;

    } catch (\Exception $e) {
        Log::warning("Failed to extract description", ['error' => $e->getMessage()]);
        return null;
    }
}



    /**
     * Extract prices
     */
    private function extractPrices(Crawler $crawler): array
    {
        $prices = ["price" => null, "sale_price" => null];

        // Sale price: v1zwn21l v1zwn20 (current Flipkart UI confirmed on Canon printer + Realme phone)
        // Ad panel uses v1zwn21l v1zwn2d (different second class) so no collision
        $salePriceSelectors = [
            '.v1zwn21l.v1zwn20',                    // new Flipkart UI (confirmed)
            '.v1zwn21k.v1zwn20',                    // alternate new UI
            '[class*="v1zwn21k"][class*="v1zwn20"]',
            '.Nx9bqj.CxhGGd',                      // older UI
            '.hZ3P6w.bnqy13',
            'span[class*="Nx9bqj"]'
        ];

        foreach ($salePriceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();

            if ($element->count()) {
                $priceText = $this->cleanText($element->text());
                $price = $this->extractPrice($priceText);

                if ($price) {
                    $prices["sale_price"] = $price;
                    break;
                }
            }
        }

        // MRP: v1zwn21m v1zwn21 with line-through (confirmed on Canon printer)
        $mrpPriceSelectors = [
            '.v1zwn21m.v1zwn21',   // new Flipkart UI (confirmed)
            '.v1zwn21l.v1zwn21',   // alternate new UI
            '.hl05eU .yRaY8j',     // older UI
            '.kRYCnD.yHYOcc'
        ];

        foreach ($mrpPriceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();

            if ($element->count()) {
                $priceText = $this->cleanText($element->text());
                $price = $this->extractPrice($priceText);

                if ($price) {
                    $prices["price"] = $price;
                    break;
                }
            }
        }

        // Fallback: find strikethrough element NOT inside an <a href="...">.
        // The main product price <a> has no href; ad panel items have href pointing to other products.
        if (!$prices["price"]) {
            $mrpNode = $crawler->filterXPath(
                '//*[contains(@style,"line-through") and not(ancestor::a[@href]) and normalize-space(text())!=""]'
            )->first();

            if ($mrpNode->count()) {
                $price = $this->extractPrice($this->cleanText($mrpNode->text()));
                if ($price) {
                    $prices["price"] = $price;
                }
            }
        }

        return $prices;
    }


     
    private function extractCurrencyCode(Crawler $crawler): string
    {
        return 'INR';
    }


    /**
     * Extract offers and discounts
     */
    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        $selectors = [
            '.v1zwn220.v1zwn20',          // new Flipkart UI discount % (font-m, main product)
            '.v1zwn21z.v1zwn20',          // older variant
            '[class*="v1zwn21z"][class*="v1zwn20"]',
            'div[class*="v1zwn21"][class*="20"]',
        ];

        foreach ($selectors as $selector) {

            $elements = $crawler->filter($selector);

            foreach ($elements as $node) {

                $element = new Crawler($node);
                $text = trim($element->text());

                // Only accept values containing %
                if (preg_match('/\d+\s?%/', $text)) {
                    $offers[] = $this->cleanText($text);
                }
            }

            if (!empty($offers)) {
                break;
            }
        }

        return !empty($offers) ? implode('; ', $offers) : null;
    }


    /**
     * Extract availability status
     */
    private function extractAvailability(Crawler $crawler): ?string
    {
        $availabilitySelectors = [
            '._16FRp0',
            '._3xgqrA',
            '._1fGeJ5',
            '.yN+eNk'
        ];

        foreach ($availabilitySelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                if ($text && (stripos($text, 'stock') !== false || stripos($text, 'available') !== false)) {
                    return $text;
                }
            }
        }

        return 'In Stock'; // Default for Flipkart
    }

    /**
     * Extract rating and review count
     */
    private function extractRatingAndReviews(Crawler $crawler): array
    {
        $data = ['rating' => null, 'review_count' => 0];

        $ratingSelectors = [
            '.css-146c3p1',                     // new Flipkart rating
            '.asbjxx .css-146c3p1',
            'div[class*="rating"] span',
            'span[class*="rating"]'
        ];

        foreach ($ratingSelectors as $selector) {
            $elements = $crawler->filter($selector);

            foreach ($elements as $node) {

                $text = trim((new Crawler($node))->text());

                if (preg_match('/^\d\.\d$/', $text)) {
                    $data['rating'] = (float)$text;
                    break 2;
                }
            }
        }

        $reviewSelectors = [
            'a[href*="ratings-reviews"] .css-146c3p1',
            'a[href*="ratings-reviews"]',
            '.css-146c3p1'
        ];

        foreach ($reviewSelectors as $selector) {

            $elements = $crawler->filter($selector);

            foreach ($elements as $node) {

                $text = trim((new Crawler($node))->text());

                if (preg_match('/\|\s*([\d,]+)/', $text, $match)) {
                    $data['review_count'] = (int)str_replace(',', '', $match[1]);
                    break 2;
                }

                if (preg_match('/([\d,]+)\s*Reviews?/', $text, $match)) {
                    $data['review_count'] = (int)str_replace(',', '', $match[1]);
                    break 2;
                }
            }
        }

        return $data;
    }



    /**
     * Extract technical specifications
     */
    private function extractSpecifications(Crawler $crawler): array
    {
        $specs = [];

        // Extract from specifications table
        $crawler->filter('._1s_Smc tr, ._21lJbe tr')->each(function (Crawler $row) use (&$specs) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = strtolower($this->cleanText($cells->eq(0)->text()));
                $value = $this->cleanText($cells->eq(1)->text());

                if (strpos($label, 'color') !== false || strpos($label, 'colour') !== false) {
                    $specs['color'] = $value;
                }
            }
        });

        // Extract from key features if specs table not found
        if (empty($specs)) {
            $description = $this->extractDescription($crawler);
            if ($description) {
                $extractedSpecs = DataSanitizer::extractSpecifications($description);
                $specs = array_merge($specs, $extractedSpecs);
            }
        }

        return $specs;
    }

    /**
     * Extract product images
     */
     private function extractImages(Crawler $crawler): ?array
    {
        $images = [];

        // lock inside product gallery container
        $crawler->filter('div._1psv1ze36 picture img')->each(function (Crawler $node) use (&$images) {

            $src = $node->attr('src');

            if (!$src) {
                $srcset = $node->attr('srcset');
                if ($srcset) {
                    $parts = explode(',', $srcset);
                    $src = trim(explode(' ', $parts[0])[0]);
                }
            }

            if ($src && str_contains($src, 'rukminim')) {
                $images[] = $src;
            }
        });

        return !empty($images) ? array_values(array_unique($images)) : null;
    }

    /**
     * Extract product variants
     */
    private function extractVariants(Crawler $crawler): ?string
    {
        $variants = [];

        // Only links inside variant blocks; exclude ad links which contain fm= parameter
        $crawler->filter('div[data-observerid] a[href*="/p/itm"]')
            ->each(function (Crawler $node) use (&$variants) {

                $href = $node->attr('href');
                if (!$href) return;

                // Ad carousel links contain fm= (base64 encoded "advertisement"); skip them
                if (strpos($href, 'fm=') !== false) return;

                if (preg_match('#/p/(itm[a-zA-Z0-9]+)#', $href, $match)) {
                    $variants[] = $match[1];
                }
            });

        $variants = array_values(array_unique($variants));

        return $variants ? implode(',', $variants) : null;
    }



    private function extractBrand(Crawler $crawler): ?string
    {
        $brand = null;

        // 1. Try spec section
        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $node) use (&$brand) {

            if ($brand !== null) return;

            $divs = $node->filter('div');

            if ($divs->count() < 2) return;

            $label = strtolower(trim($divs->eq(0)->text()));

            if ($label === 'brand') {
                $brand = trim($divs->eq(1)->text());
            }
        });

        // 2. Match known brands from product title
        if (!$brand) {

            $titleNode = $crawler->filter('h1')->first();

            if ($titleNode->count()) {

                $title = strtolower(trim($titleNode->text()));
                $words = preg_split('/\s+/', $title);

                $allowedBrands = [
                    'hp','epson','canon','brother','samsung',
                    'motorola','xiaomi','realme','oppo','lava','vivo'
                ];

                foreach ($words as $word) {
                    if (in_array($word, $allowedBrands)) {
                        $brand = ucfirst($word);
                        break;
                    }
                }

                // 3. Last resort: use first word of title
                if (!$brand && !empty($words[0])) {
                    $brand = ucfirst($words[0]);
                }
            }
        }

        return $brand;
    }

    private function extractSize(Crawler $crawler): ?string
    {
        $size = null;

        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $node) use (&$size) {

            if ($size !== null) return;

            $divs = $node->filter('div');

            for ($i = 0; $i < $divs->count(); $i++) {

                $text = strtolower(trim($divs->eq($i)->text()));

                if ($text === 'size') {

                    if ($divs->count() > $i + 1) {
                        $size = trim($divs->eq($i + 1)->text());
                        return;
                    }
                }
            }
        });

        return $size;
    }

    private function extractUnitCount(Crawler $crawler): ?int
    {
        $count = null;

        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $node) use (&$count) {

            if ($count !== null) return;

            $divs = $node->filter('div');

            for ($i = 0; $i < $divs->count(); $i++) {

                $text = strtolower(trim($divs->eq($i)->text()));

                // variations handle
                if (
                    $text === 'total no of pieces' ||
                    $text === 'total pieces' ||
                    $text === 'no of pieces' ||
                    $text === 'pieces'
                ) {

                    if ($divs->count() > $i + 1) {

                        $value = trim($divs->eq($i + 1)->text());

                        if (preg_match('/\d+/', $value, $match)) {
                            $count = (int) $match[0];
                            return;
                        }
                    }
                }
            }
        });

        return $count;
    }



    private function extractModelName(Crawler $crawler): ?string
    {
        $model = null;

        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$model) {

            if ($model !== null) {
                return;
            }

            // label = Model Name
            $labelNode = $row->filter('.v1zwn21l.v1zwn27')->first();

            if (!$labelNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));

            if ($label === 'model name') {

                // value
                $valueNode = $row->filter('.v1zwn21k.v1zwn26')->first();

                if ($valueNode->count()) {
                    $model = trim($valueNode->text());
                }
            }
        });

        return $model;
    }


    private function extractColour(Crawler $crawler): ?string
    {
        $colour = null;

        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $node) use (&$colour) {

            if ($colour !== null) return;

            $labelNode = $node->filter('.v1zwn21l')->first();
            if (!$labelNode->count()) return;

            $label = strtolower(trim($labelNode->text()));

            if ($label === 'color' || $label === 'colour') {
                $valueNode = $node->filter('.v1zwn21k')->first();
                if ($valueNode->count()) {
                    $colour = trim($valueNode->text());
                }
            }
        });

        return $colour;
    }





    private function extractItemWeight(Crawler $crawler): ?string
    {
        $weight = null;

        // New Flipkart layout
        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$weight) {

            if ($weight !== null) {
                return;
            }

            $labelNode = $row->filter('.v1zwn21l.v1zwn27')->first();
            $valueNode = $row->filter('.v1zwn21k.v1zwn26')->first();

            if (!$labelNode->count() || !$valueNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));

            if (strpos($label, 'weight') !== false) {
                $weight = trim($valueNode->text());
            }
        });

        

        return $weight;
    }

    private function extractProductDimensions(Crawler $crawler): ?string
    {
        $dimensions = [];

        // loop through spec rows
        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$dimensions) {

            $labelNode = $row->filter('.v1zwn21l.v1zwn27')->first();
            $valueNode = $row->filter('.v1zwn21k.v1zwn26')->first();

            if (!$labelNode->count() || !$valueNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));
            $value = trim($valueNode->text());

            if (str_contains($label, 'height')) {
                $dimensions['height'] = $value;
            } elseif (str_contains($label, 'width')) {
                $dimensions['width'] = $value;
            } elseif (str_contains($label, 'depth') || str_contains($label, 'thickness')) {
                $dimensions['depth'] = $value;
            }
        });

        // order fix (Width x Depth x Height)
        if (!empty($dimensions)) {

            $ordered = [
                $dimensions['width']  ?? null,
                $dimensions['depth']  ?? null,
                $dimensions['height'] ?? null,
            ];

            $ordered = array_filter($ordered);

            return implode(' x ', $ordered);
        }

        return null;
    }


    private function extractManufacturer(Crawler $crawler): ?string
    {
        $manufacturer = null;

        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$manufacturer) {

            if ($manufacturer !== null) {
                return;
            }

            $labelNode = $row->filter('.v1zwn21l.v1zwn27')->first();
            $valueNode = $row->filter('.v1zwn21k.v1zwn26')->first();

            if (!$labelNode->count() || !$valueNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));

            if (str_contains($label, 'manufacturer')) {
                $manufacturer = trim($valueNode->text());
            }
        });

        return $manufacturer ? $this->cleanText($manufacturer) : null;
    }


    private function extractVideoUrls(Crawler $crawler): ?array
    {
        $videoUrls = [];

        // Direct video / iframe embeds
        $crawler->filter("video source, video, iframe[src*='youtube'], iframe[src*='vimeo']")
            ->each(function (Crawler $node) use (&$videoUrls) {
                $src = $node->attr("src");
                if ($src) {
                    $videoUrls[] = $src;
                }
            });

        // New Flipkart layout: YouTube thumbnails (https://img.youtube.com/vi/VIDEO_ID/0.jpg)
        $crawler->filter('img[src*="img.youtube.com/vi/"]')
            ->each(function (Crawler $img) use (&$videoUrls) {
                $src = $img->attr('src');
                if (!$src) return;

                if (preg_match('~/vi/([^/]+)/~', $src, $m)) {
                    $videoId = $m[1];
                    $videoUrls[] = "https://www.youtube.com/watch?v=" . $videoId;
                    $videoUrls[] = "https://www.youtube.com/embed/" . $videoId;
                }
            });

        $videoUrls = array_values(array_unique($videoUrls));

        return !empty($videoUrls) ? $videoUrls : null;
    }


    private function extractCategory(Crawler $crawler): ?string
    {
        $categories = [];

        // Breadcrumb anchors — container class is stable, inner classes vary
        $crawler->filter('div.faikzn a')->each(function (Crawler $node) use (&$categories) {
            $text = trim($node->text());
            if ($text !== '') {
                $categories[] = $text;
            }
        });

        if (!empty($categories) && strtolower($categories[0]) === 'home') {
            array_shift($categories);
        }

        return !empty($categories) ? implode(', ', $categories) : null;
    }


    private function extractSellerName(Crawler $crawler): ?string
    {
        // Phone/electronics pages: "Sold by RetailerName | Fulfilled by Flipkart"
        $node = $crawler->filterXPath(
            "//*[contains(.,'Sold by') or contains(.,'sold by')]"
        )->first();

        if ($node->count()) {
            $text = trim($node->text());
            if (preg_match('/Sold by\s+(.+?)(?:\s*[|]\s*|\s*Fulfilled\s+by|\s*$)/i', $text, $match)) {
                $seller = $this->cleanText(trim($match[1]));
                if ($seller) {
                    return $seller;
                }
            }
        }

        // Fallback: "Fulfilled by [Seller]" (some categories only show this)
        $node = $crawler->filterXPath(
            "//*[contains(text(),'Fulfilled by')]"
        )->first();

        if (!$node->count()) {
            return null;
        }

        $text = preg_replace('/Fulfilled by/i', '', trim($node->text()));

        return $text ? $this->cleanText(trim($text)) : null;
    }

    private function extractDeliveryDate(Crawler $crawler): ?string
    {
        // delivery block inside delivery-page link
        $node = $crawler->filter('a[href*="delivery-page"] .v1zwn21k.v1zwn24')->last();

        if ($node->count() === 0) {
            return null;
        }

        $text = trim($node->text());

        if (!$text) {
            return null;
        }

        if (preg_match('/(in\s+\d+\s+days?|by\s+[A-Za-z0-9\s]+)/i', $text, $match)) {
            return $this->cleanText($match[0]);
        }

        return $this->cleanText($text);
    }



    private function extractDeliveryPrice(Crawler $crawler): ?string
    {
        // Flipkart often includes delivery charges inside a nearby div or span
        $price = null;

        // Common selector for delivery charge text
        $crawler->filter('div.hVvnXm, div._3XINqE, span._3XINqE')->each(function (Crawler $node) use (&$price) {
            $text = strtolower($node->text());

            // Match lines like "Free delivery" or "₹40 delivery charge"
            if (strpos($text, 'free delivery') !== false) {
                $price = 'Free';
            } elseif (preg_match('/₹\s?\d+/', $text, $matches)) {
                $price = trim($matches[0]);
            }
        });

        return $price ? $this->cleanText($price) : null;
    }

    
    private function extractHighlights(Crawler $crawler): ?string
    {
        $highlights = [];

        /*
        * New Flipkart UI (2025+)
        */
        $crawler->filter('div.grid-formation')->each(function (Crawler $node) use (&$highlights) {

            $title = $node->filter('.v1zwn21l.v1zwn27')->count() 
                ? trim($node->filter('.v1zwn21l.v1zwn27')->text()) 
                : '';

            $desc = $node->filter('.v1zwn21k.v1zwn25')->count() 
                ? trim($node->filter('.v1zwn21k.v1zwn25')->text()) 
                : '';

            $text = trim($title . ' ' . $desc);

            if ($text !== '') {
                $highlights[] = $text;
            }
        });

        /*
        * Previous Flipkart structure
        */
        if (empty($highlights)) {
            $crawler->filter('div.iNKhRz ul li.LS5qY1')->each(function (Crawler $node) use (&$highlights) {
                $text = trim($node->text());
                if ($text !== '') {
                    $highlights[] = $text;
                }
            });
        }

        /*
        * Older fallback
        */
        if (empty($highlights)) {
            $crawler->filter('div.xFVion ul li._7eSDEz')->each(function (Crawler $node) use (&$highlights) {
                $text = trim($node->text());
                if ($text !== '') {
                    $highlights[] = $text;
                }
            });
        }

        $highlights = array_unique($highlights);

        return !empty($highlights) ? implode(' | ', $highlights) : null;
    }





    private function extractTechnicalDetails(Crawler $crawler): ?array
    {
        $details = [];

        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $block) use (&$details) {

            $labelNode = $block->filterXPath(".//div[contains(@class,'v1zwn21l') and contains(@class,'v1zwn27')]")->first();
            $valueNode = $block->filterXPath(".//div[contains(@class,'v1zwn21k') and contains(@class,'v1zwn26')]")->first();

            if ($labelNode->count() && $valueNode->count()) {

                $label = trim($labelNode->text());
                $value = trim($valueNode->text());

                if ($label && $value) {
                    $details[$this->cleanText($label)] = $this->cleanText($value);
                }
            }
        });

        return !empty($details) ? $details : null;
    }


    private function extractAdditionalInformation(Crawler $crawler): ?array
    {
        $info = [];

        // Flipkart specification section (table rows)
        $rows = $crawler->filter('div.QZKsWF table.n7infM tr');

        if ($rows->count() == 0) {
            return null;
        }

        $rows->each(function (Crawler $row) use (&$info) {

            // Key (left column)
            $keyNode = $row->filter('td')->eq(0);
            // Value (right column)
            $valueNode = $row->filter('td')->eq(1);

            if ($keyNode->count() == 0 || $valueNode->count() == 0) {
                return;
            }

            $key = trim(preg_replace('/\s+/', ' ', $keyNode->text('')));

            // Value inside li > text
            $li = $valueNode->filter('li')->first();
            $value = $li->count()
                ? trim(preg_replace('/\s+/', ' ', $li->text()))
                : trim(preg_replace('/\s+/', ' ', $valueNode->text()));

            if ($key && $value) {
                $info[$key] = $value;
            }
        });

        return !empty($info) ? $info : null;
    }

    private function extractRatingHistogram(Crawler $crawler): array
    {
        $ratings = [
            'rating_5_star_percent' => null,
            'rating_4_star_percent' => null,
            'rating_3_star_percent' => null,
            'rating_2_star_percent' => null,
            'rating_1_star_percent' => null,
        ];

        // Older Flipkart UI: ul.lpfPv5 li .MDKzf4
        $nodes = $crawler->filter('ul.lpfPv5 li .MDKzf4');

        if ($nodes->count() >= 5) {

            $values = [];

            $nodes->each(function (Crawler $node) use (&$values) {
                $text = trim($node->text());
                $num = intval(str_replace(',', '', $text));
                $values[] = $num;
            });

            if (count($values) >= 5) {
                $ratings['rating_5_star_percent'] = $values[0];
                $ratings['rating_4_star_percent'] = $values[1];
                $ratings['rating_3_star_percent'] = $values[2];
                $ratings['rating_2_star_percent'] = $values[3];
                $ratings['rating_1_star_percent'] = $values[4];
            }
        }

        // New Flipkart UI: extract from breakdown text like "5★(6) 4★(2)..." if available
        if (!$ratings['rating_5_star_percent']) {
            $reviewLink = $crawler->filterXPath('//a[contains(@href,"ratings-reviews")]')->first();
            if ($reviewLink->count()) {
                $text = $reviewLink->text();
                if (preg_match_all('/(\d)\s*[★*]\s*\(?([\d,]+)\)?/', $text, $matches, PREG_SET_ORDER)) {
                    $total = array_sum(array_map(fn($m) => (int)str_replace(',', '', $m[2]), $matches));
                    if ($total > 0) {
                        foreach ($matches as $m) {
                            $star = (int)$m[1];
                            $count = (int)str_replace(',', '', $m[2]);
                            $key = "rating_{$star}_star_percent";
                            if (isset($ratings[$key])) {
                                $ratings[$key] = (int)round($count / $total * 100);
                            }
                        }
                    }
                }
            }
        }

        return $ratings;
    }
   

}
