<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class CromaScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'croma';
        $this->useJavaScript = true;
        $this->paginationConfig = [
            'type' => 'view_more',
            'max_view_more_clicks' => 20,
            'delay_between_pages' => [3, 6],
            'retry_failed_pages' => true,
            'max_retries_per_page' => 3
        ];
    }

    /**
     * Override base browser scraping to handle Croma's "View More" button pattern.
     */
    protected function scrapeCategoryWithBrowser(string $categoryUrl): void
    {
        try {
            $html = $this->fetchAllProductsWithViewMore($categoryUrl);

            if ($html) {
                $this->processPageContent($html, $categoryUrl);
            }
        } catch (\Exception $e) {
            $this->handleError("Failed to scrape Croma category: {$categoryUrl}", $e);
        }
    }

    /**
     * Load a Croma listing page, then click "View More" until all products are visible.
     */
    private function fetchAllProductsWithViewMore(string $url): ?string
    {
        $maxClicks  = $this->paginationConfig['max_view_more_clicks'] ?? 20;
        $scriptPath = base_path('scripts/croma-view-more.cjs');
        $nodeBin    = config('scraper.node_binary', 'node');

        // Ensure System32 is in PATH so Node's child-process calls (taskkill etc.)
        // resolve correctly under XAMPP/Apache's stripped environment.
        if (PHP_OS_FAMILY === 'Windows') {
            $currentPath = getenv('PATH') ?: '';
            if (stripos($currentPath, 'Windows\\System32') === false) {
                putenv('PATH=' . $currentPath . ';C:\\Windows\\System32;C:\\Windows\\SysWOW64');
            }
        }

        try {
            Log::info("Fetching Croma category with view-more expansion", [
                'url'        => $url,
                'max_clicks' => $maxClicks,
            ]);

            $process = new Process(
                [$nodeBin, $scriptPath, $url, (string) $maxClicks],
                base_path(),
                null,
                null,
                240  // 4-minute timeout
            );

            $process->run();

            if (!$process->isSuccessful()) {
                Log::error("Croma view-more script failed", [
                    'url'    => $url,
                    'stderr' => $process->getErrorOutput(),
                ]);
                return null;
            }

            $html = $process->getOutput();

            Log::info("Croma view-more fetch complete", [
                'url'            => $url,
                'content_length' => strlen($html),
            ]);

            return strlen($html) > 1000 ? $html : null;

        } catch (\Exception $e) {
            Log::error("Croma fetchAllProductsWithViewMore failed", [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function __construct()
    {
        parent::__construct('croma');
    }

    /**
     * Extract product URLs from Croma category page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            // Croma product link selectors (updated for 2024)
            $selectors = [
                'a[href*="/p/"]',  // Product links with /p/ pattern
                '.product-item a',
                '.plp-product-tile a',
                '.product-tile-wrapper a',
                '.product-card a',
                '.cp-product a',
                'div[data-testid="product-card"] a',
                'a[data-testid="product-link"]',
            ];

            foreach ($selectors as $selector) {
                $nodes = $crawler->filter($selector);
                
                if ($nodes->count() > 0) {
                    Log::debug("Found Croma product links using selector", [
                        'selector' => $selector,
                        'count' => $nodes->count()
                    ]);

                    $nodes->each(function (Crawler $node) use (&$productUrls) {
                        $href = $node->attr('href');
                        if ($href) {
                            // Convert relative URLs to absolute
                            if (strpos($href, 'http') !== 0) {
                                $href = 'https://www.croma.com' . $href;
                            }
                            
                            // Only include product pages (with /p/ pattern)
                            if (strpos($href, '/p/') !== false) {
                                $productUrls[] = $href;
                            }
                        }
                    });

                    break; // Stop after finding products with first working selector
                }
            }

            $productUrls = array_unique($productUrls);

            Log::info("Extracted Croma product URLs", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Croma", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Extract product data from Croma product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];

            // Extract SKU
            $data['sku'] = $this->extractSkuFromUrl($productUrl) ?: $this->extractSkuFromPage($crawler);
            if (!$data['sku']) {
                Log::warning("Could not extract SKU from Croma URL: {$productUrl}");
                return [];
            }

            $data['product_url'] = $productUrl;
            $data['platform_id'] = $data['sku'];

            // Try JSON-LD first (most reliable)
            $jsonLdData = $this->extractFromJsonLd($crawler);
            if ($jsonLdData) {
                $data = array_merge($data, $jsonLdData);
            }

            // Extract with fallbacks
            $data['title'] = $data['title'] ?? $this->extractProductName($crawler);
            $data['description'] = $data['description'] ?? $this->extractDescription($crawler);
            $data['brand'] = $data['brand'] ?? $this->extractBrand($crawler);
            $data['category'] = $this->extractCategory($crawler);
            $data["color"] = $this->extractColour($crawler);
            $data['image_urls'] = $data['image_urls'] ?? $this->extractImages($crawler);
            $data["weight"] = $this->extractItemWeight($crawler);
            $data["dimensions"] = $this->extractProductDimensions($crawler);
            $data["highlights"] = $this->extractHighlights($crawler);
            $data["manufacturer"] = $this->extractManufacturer($crawler);
            $data["video_urls"] = $this->extractVideoUrls($crawler);
            $data["delivery_date"] = $this->extractDeliveryDate($crawler);

            // Prices
            if (!isset($data['price']) || !isset($data['sale_price'])) {
                $priceData = $this->extractPrices($crawler);
                $data['price'] = $data['price'] ?? $priceData['price'];
                $data['sale_price'] = $data['sale_price'] ?? $priceData['sale_price'];
            }
            $data['currency_code'] = 'INR';

            // Ratings
            if (!isset($data['rating']) || !isset($data['review_count'])) {
                $ratingData = $this->extractRatingAndReviews($crawler);
                $data['rating'] = $data['rating'] ?? $ratingData['rating'];
                $data['review_count'] = $data['review_count'] ?? $ratingData['review_count'];
            }
            $RatingHistogram = $this->extractRatingHistogram($crawler);
            $data["rating_1_star_percent"] = $RatingHistogram['rating_1_star_percent'];
            $data["rating_2_star_percent"] = $RatingHistogram['rating_2_star_percent'];
            $data["rating_3_star_percent"] = $RatingHistogram['rating_3_star_percent'];
            $data["rating_4_star_percent"] = $RatingHistogram['rating_4_star_percent'];
            $data["rating_5_star_percent"] = $RatingHistogram['rating_5_star_percent'];

            // Additional data
            $data['offers'] = $this->extractOffers($crawler);
            $data['inventory_status'] = $this->extractAvailability($crawler);
            $data['model_name'] = $this->extractModelName($crawler);
            $data["technical_details"] = $this->extractTechnicalDetails($crawler);
            $data['variation_attributes'] = $this->extractVariants($crawler);

            // Sanitize
            $data = DataSanitizer::sanitizeProductData($data);

            Log::debug("Extracted Croma product data", [
                'sku' => $data['sku'],
                'title' => $data['title'] ?? 'N/A'
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error("Failed to extract Croma product data", [
                'url' => $productUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Extract data from JSON-LD structured data
     */
    private function extractFromJsonLd(Crawler $crawler): ?array
    {
        try {
            $jsonLdNodes = $crawler->filter('script[type="application/ld+json"]');
            
            if ($jsonLdNodes->count() === 0) {
                return null;
            }

            foreach ($jsonLdNodes as $node) {
                $json = $node->textContent;
                $data = json_decode($json, true);

                if ($data && isset($data['@type']) && $data['@type'] === 'Product') {
                    $extracted = [];

                    if (isset($data['name'])) {
                        $extracted['title'] = $data['name'];
                    }

                    if (isset($data['description'])) {
                        $extracted['description'] = $data['description'];
                    }

                    if (isset($data['brand']['name'])) {
                        $extracted['brand'] = $data['brand']['name'];
                    } elseif (isset($data['brand']) && is_string($data['brand'])) {
                        $extracted['brand'] = $data['brand'];
                    }

                    if (isset($data['image'])) {
                        $extracted['image_urls'] = is_array($data['image']) ? $data['image'] : [$data['image']];
                    }

                    if (isset($data['offers'])) {
                        $offers = $data['offers'];
                        if (isset($offers['price'])) {
                            $extracted['sale_price'] = (float) $offers['price'];
                        }
                        if (isset($offers['highPrice'])) {
                            $extracted['price'] = (float) $offers['highPrice'];
                        }
                    }

                    if (isset($data['aggregateRating'])) {
                        $rating = $data['aggregateRating'];
                        if (isset($rating['ratingValue'])) {
                            $extracted['rating'] = (float) $rating['ratingValue'];
                        }
                        if (isset($rating['reviewCount'])) {
                            $extracted['review_count'] = (int) $rating['reviewCount'];
                        }
                    }

                    Log::debug("Extracted data from JSON-LD", ['fields' => array_keys($extracted)]);
                    return $extracted;
                }
            }

        } catch (\Exception $e) {
            Log::warning("Failed to extract from JSON-LD", ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function extractSkuFromUrl(string $url): ?string
    {
        // Pattern: /product-name/p/299691
        if (preg_match('/\/p\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }
        
        // Pattern: /product-name-sku
        if (preg_match('/\/([a-zA-Z0-9\-]+)$/', $url, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    private function extractSkuFromPage(Crawler $crawler): ?string
    {
        $selectors = [
            '[data-product-id]',
            '[data-sku]',
            '.product-code',
            '.model-number',
            '.sku-number',
            'meta[property="product:retailer_item_id"]',
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $sku = $element->attr('data-product-id') 
                    ?: $element->attr('data-sku')
                    ?: $element->attr('content')
                    ?: $element->text();
                if ($sku) {
                    return $this->cleanText($sku);
                }
            }
        }

        return null;
    }

    private function extractProductName(Crawler $crawler): ?string
    {
        $selectors = [
            'h1[data-testid="product-title"]',
            '.pdp-product-name',
            '.product-title h1',
            '.cp-product-name',
            'h1.title',
            '.product-name',
            'h1.pdp-title',
            '.pdp-title',
            '[data-testid="product-title"]',
            '.product-details h1',
            'h1[class*="product"]',
            'h1[class*="title"]',
            '[itemprop="name"]',
            'h1',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $element->first()->text();
                    $text = $this->cleanText($text);
                    
                    // Validate meaningful text
                    if (!empty($text) && strlen($text) > 3) {
                        Log::debug("Extracted Croma title using selector: {$selector}", ['title' => $text]);
                        return $text;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        Log::warning("Failed to extract Croma product title with any selector");
        return null;
    }

    private function extractDescription(Crawler $crawler): ?string
    {
        $descriptions = [];

        $descSelectors = [
            '.MuiAccordionDetails-root p',
            '.accordian-content p',
            '.overview-fixed-height p',
            '#overview_inner_container p',
            '.cp-overview p',
        ];

        foreach ($descSelectors as $selector) {
            if ($crawler->filter($selector)->count() > 0) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$descriptions) {
                    $text = $this->cleanText($node->text());
                    if ($text && strlen($text) > 20) {
                        $descriptions[] = $text;
                    }
                });
                break;
            }
        }

        return !empty($descriptions)
            ? implode('. ', array_unique($descriptions))
            : null;
    }


    private function extractPrices(Crawler $crawler): array
    {
        $prices = ['price' => null, 'sale_price' => null];

        // Sale price (current price)
        $saleSelector = '.cp-price.main-product-price .new-price .amount';
        $element = $crawler->filter($saleSelector)->first();
        if ($element->count() > 0) {
            $price = $this->extractPrice($element->text());
            if ($price) {
                $prices['sale_price'] = $price;
            }
        }

        // Original price (MRP)
        $mrpSelector = '.cp-price.discount .old-price .amount';
        $element = $crawler->filter($mrpSelector)->first();
        if ($element->count() > 0) {
            $price = $this->extractPrice($element->text());
            if ($price) {
                $prices['price'] = $price;
            }
        }

        // Fallback: if no MRP, use sale price as price
        if (!$prices['price'] && $prices['sale_price']) {
            $prices['price'] = $prices['sale_price'];
        }

        return $prices;
    }


    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        $selectors = [
            '.cp-price .dicount-value',
            '.outer-product-pricebox .offer-text',
            '.discount-info',
            'div[data-testid="offers"]',
        ];

        foreach ($selectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$offers) {
                $text = trim($node->text());
                if (!empty($text)) {
                    $offers[] = $text;
                }
            });

            // Stop at first selector that has offers
            if (!empty($offers)) {
                break;
            }
        }

        return !empty($offers) ? implode('; ', $offers) : null;
    }


    private function extractAvailability(Crawler $crawler): ?string
    {
        $availabilitySelectors = [
            'div[data-testid="stock-status"]',
            '.stock-status',
            '.availability-info',
            '.in-stock',
            'button[data-testid="add-to-cart"]',
        ];

        foreach ($availabilitySelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                if ($text) {
                    if (stripos($text, 'add') !== false || stripos($text, 'cart') !== false) {
                        return 'In Stock';
                    }
                    return $text;
                }
            }
        }

        return 'Available';
    }

    private function extractRatingAndReviews(Crawler $crawler): array
    {
        $data = ['rating' => null, 'review_count' => 0];

        $crawler->filter('div.cp-rating')->each(function (Crawler $node) use (&$data) {
            // Try nested span first
            $nestedText = $node->filter('span:first-child')->count() ? trim($node->filter('span:first-child')->text()) : '';
            
            // If no nested text, fallback to direct div text
            if (!$nestedText) {
                $nestedText = trim($node->text());
            }

            // Extract numeric rating
            if ($nestedText && preg_match('/\d+(\.\d+)?/', $nestedText, $matches)) {
                $data['rating'] = (float)$matches[0];
            }
        });

        $crawler->filter('div.cp-rating span.text a.pr-review')->each(function (Crawler $node) use (&$data) {
            $text = trim($node->text());
            if ($text && preg_match('/(\d+)\s+Reviews/', $text, $matches)) {
                $data['review_count'] = (int)$matches[1];
            }
        });

        return $data;
    }



    private function extractBrand(Crawler $crawler): ?string
    {
        $brand = null;

        $crawler->filter('.cp-specification-spec-info')->each(function (Crawler $node) use (&$brand) {

            if ($brand !== null) {
                return;
            }

            $labelNode = $node->filter('.cp-specification-spec-title h4')->first();
            $valueNode = $node->filter('.cp-specification-spec-details')->first();

            if ($labelNode->count() === 0 || $valueNode->count() === 0) {
                return;
            }

            $label = strtoupper(trim($labelNode->text()));
            $value = trim($valueNode->text());

            // Only pick if label is exactly "BRAND"
            if ($label === 'BRAND') {
                $brand = $this->cleanText($value);
            }
        });

        return $brand ?: null;
    }



    private function extractCategory(Crawler $crawler): ?string
    {
        $categories = [];

        $crawler->filter('.cp-breadcrumb ul.list li a')->each(function (Crawler $node) use (&$categories) {
            $text = trim($node->text());
            if ($text && !in_array($text, $categories) && strtolower($text) !== 'home') {
                $categories[] = $text;
            }
        });

        return !empty($categories) ? implode(' , ', $categories) : null;
    }


    private function extractColour(Crawler $crawler): ?string
    {
        $colourList = [];
        $colour = null;

        try {
            $crawler->filter('.cp-specification-spec-details')->each(function (Crawler $node) use (&$colourList) {
                $titleNode = $node->previousAll('.cp-specification-spec-title')->first();

                if ($titleNode->count() > 0) {
                    $label = strtolower(trim($titleNode->filter('h4')->text()));

                    if ($label === 'Color') {
                        $text = trim($node->text());
                        if ($text !== '') {
                            $colourList[] = $text;
                        }
                    }
                }
            });

            if (!empty($colourList)) {
                $colour = implode(', ', array_unique($colourList));
            }

        } catch (\Exception $e) {
            Log::warning('Colour extraction failed', ['error' => $e->getMessage()]);
        }

        return $colour ? $this->cleanText($colour) : null;
    }

    private function extractItemWeight(Crawler $crawler): ?string
    {
        $weight = null;

        $crawler->filter('.cp-specification-spec-details')->each(function (Crawler $node) use (&$weight) {

            $titleNode = $node->previousAll('.cp-specification-spec-title')->first();

            if ($titleNode->count() > 0) {
                $label = strtolower(trim($titleNode->filter('h4')->text()));

                // Match ONLY item weight field
                if ($label === 'main unit weight') {
                    $text = trim($node->text());
                    if ($text !== '') {
                        $weight = $text;
                    }
                }
            }
        });

        return $weight ? $this->cleanText($weight) : null;
    }
    
    private function extractProductDimensions(Crawler $crawler): ?string
    {
        $dimensions = [];

        $crawler->filter('.cp-specification-spec-details')->each(function (Crawler $node) use (&$dimensions) {

            $titleNode = $node->previousAll('.cp-specification-spec-title')->first();

            if ($titleNode->count() > 0) {
                $label = strtolower(trim($titleNode->filter('h4')->text()));
                $value = trim($node->text());

                if ($value === '') {
                    return;
                }

                // Prefer CM dimensions
                if ($label === 'dimensions in cm (wxdxh)') {
                    $dimensions['cm'] = $value. ' cm';
                }

                // Fallback: Inches
                if (!isset($dimensions['cm']) && $label === 'dimensions in inches (wxdxh)') {
                    $dimensions['in'] = $value. ' inch';
                }
            }
        });

        if (!empty($dimensions)) {
            return $this->cleanText($dimensions['cm'] ?? $dimensions['in']);
        }

        return null;
    }


    private function extractModelName(Crawler $crawler): ?string
    {
        $model = null;

        // Loop through all spec info
        $crawler->filter('.cp-specification-spec-info')->each(function (Crawler $node) use (&$model) {

            if ($model !== null) {
                return; // Already found, skip
            }

            $labelNode = $node->filter('.cp-specification-spec-title h4')->first();
            $valueNode = $node->filter('.cp-specification-spec-details')->first();

            if ($labelNode->count() === 0 || $valueNode->count() === 0) {
                return;
            }

            $label = strtoupper(trim($labelNode->text()));
            $value = trim($valueNode->text());

            if ($label === 'MODEL NUMBER' || $label === 'MODEL SERIES') {
                $model = $this->cleanText($value);
            }
        });

        return $model ?: null;
    }



    private function extractImages(Crawler $crawler): ?array
    {
        $images = [];

        $selectors = [
            'img[data-testid^="super-zoom-img"]', // main gallery images
            'img[data-testid^="galary-thumb-img"]', // thumbnail images
            '.pdp-product-image img',
            '.product-gallery img',
            '.main-image img',
        ];

        foreach ($selectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$images) {
                // Prefer full-size image in data-zoom, then data-src, then src
                $src = $node->attr('data-zoom') ?: $node->attr('data-src') ?: $node->attr('src') ?: $node->attr('srcset');

                if ($src) {
                    // srcset may contain multiple URLs
                    if (strpos($src, ',') !== false) {
                        $srcParts = explode(',', $src);
                        $src = trim(explode(' ', trim($srcParts[0]))[0]);
                    }

                    // Ensure full URL
                    if (strpos($src, 'http') === 0) {
                        $images[] = $src;
                    }
                }
            });
        }

        return !empty($images) ? array_unique($images) : null;
    }


    private function extractVariants(Crawler $crawler): ?array
    {
        $variants = [];

        $crawler->filter('.variant-options li, .color-swatches li, div[data-testid="variant"] button')->each(function (Crawler $node) use (&$variants) {
            $title = $node->attr('title') ?: $node->attr('aria-label') ?: $node->text();
            if ($title) {
                $variants[] = ['type' => 'variant', 'value' => $this->cleanText($title)];
            }
        });

        return !empty($variants) ? $variants : null;
    }

    private function extractHighlights(Crawler $crawler): ?string
    {
        $highlights = [];

        $crawler->filter('.key-features-box .cp-keyfeature ul li')->each(function (Crawler $node) use (&$highlights) {
            $text = trim($node->text());
            if (!empty($text)) {
                $highlights[] = $text;
            }
        });

        return !empty($highlights)
            ? implode('. ', array_unique($highlights))
            : null;
    }

    private function extractManufacturer(Crawler $crawler): ?string
    {
        $manufacturer = null;

        $crawler->filter('.cp-specification-spec-details')->each(function (Crawler $node) use (&$manufacturer) {

            if ($manufacturer !== null) {
                return;
            }

            $titleNode = $node->previousAll('.cp-specification-spec-title')->first();

            if ($titleNode->count() > 0) {
                $label = strtolower(trim($titleNode->filter('h4')->text()));

                if ($label === 'manufacturer/importer/marketer name & address') {
                    $text = trim($node->text());
                    if ($text !== '') {
                        $manufacturer = $text;
                    }
                }
            }
        });

        return $manufacturer ? $this->cleanText($manufacturer) : null;
    }

    private function extractVideoUrls(Crawler $crawler): ?array
    {
        $videoUrls = [];

        $crawler->filter('.cp-product-gallery img[data-video-url]')->each(function (Crawler $node) use (&$videoUrls) {
            $url = $node->attr('data-video-url');
            if ($url) {
                $videoUrls[] = $url;
            }
        });

        $crawler->filter('.cp-product-gallery video source')->each(function (Crawler $node) use (&$videoUrls) {
            $src = $node->attr('src');
            if ($src) {
                $videoUrls[] = $src;
            }
        });

        $crawler->filter('iframe[src*="youtube"], iframe[src*="vimeo"]')->each(function (Crawler $node) use (&$videoUrls) {
            $src = $node->attr('src');
            if ($src) {
                $videoUrls[] = $src;
            }
        });

        return !empty($videoUrls) ? array_values(array_unique($videoUrls)) : null;
    }

    private function extractDeliveryDate(Crawler $crawler): ?string
    {

        $node = $crawler->filter('.cp-ship-opt .del-date p.pdp-delivery-details')->first();

        if ($node->count() > 0) {
            $date = trim($node->text());
            if (!empty($date)) {
                return $this->cleanText($date);
            }
        }

        return null;
    }

    private function extractTechnicalDetails(Crawler $crawler): ?array
    {
        $details = [];

        $crawler->filter('.cp-specification-spec-info')->each(function (Crawler $ul) use (&$details) {

            $keyNode = $ul->filter('.cp-specification-spec-title h4')->first();
            $valueNode = $ul->filter('.cp-specification-spec-details')->first();

            if ($keyNode->count() > 0 && $valueNode->count() > 0) {
                $key = trim(preg_replace('/\s+/', ' ', $keyNode->text()));
                $value = trim(preg_replace('/\s+/', ' ', $valueNode->text()));

                if ($key && $value) {
                    $details[$key] = $value;
                }
            }
        });

        return !empty($details) ? $details : null;
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

        // Loop through each star row
        $crawler->filter('div.barAndStar, div.barAndStar-no-review')->each(function (Crawler $node) use (&$ratings) {

            // Star value: text inside .star-text, e.g., "5 star"
            $starText = $node->filter('.star-text')->first()->text('');
            if (preg_match('/(\d+)\s*star/i', $starText, $matches)) {
                $star = (int)$matches[1];

                // Get width from the bar inside .bar-container > div
                $barNode = $node->filter('.bar-container div')->first();
                if ($barNode->count() > 0) {
                    $style = $barNode->attr('style');
                    if (preg_match('/width:\s*([\d.]+)%/i', $style, $matchesWidth)) {
                        $ratings["rating_{$star}_star_percent"] = (float)$matchesWidth[1];
                    }
                }
            }
        });

        return $ratings;
    }






}
