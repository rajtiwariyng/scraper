<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

class AmazonScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'amazon';
        $this->useJavaScript = false; // Amazon works with regular HTTP requests
        $this->paginationConfig = [
            'type' => 'regular',
            'max_pages' => 150, // Increased from 100 to handle more pages
            'page_param' => 'page',
            'has_next_selector' => '.a-pagination .a-last:not(.a-disabled)',
            'max_consecutive_errors' => 5, // Allow more errors before stopping
            'delay_between_pages' => [3, 7], // Longer delays to avoid rate limiting
            'retry_failed_pages' => true,
            'max_retries_per_page' => 3
        ];
    }

    public function __construct()
    {
        parent::__construct('amazon');
    }

    /**
     * Extract product URLs from Amazon search/category page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];
        
        try {
            // Amazon product links patterns
            $selectors = [
                'h2.a-size-mini a',
                'h2.s-size-mini a',
                '.s-result-item h3 a',
                '.s-result-item .a-link-normal',
                '[data-component-type="s-search-result"] h3 a'
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        // Convert relative URLs to absolute
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.amazon.in' . $href;
                        }
                        
                        // Only include laptop product pages
                        if (strpos($href, '/dp/') !== false || strpos($href, '/product/') !== false) {
                            $productUrls[] = $href;
                        }
                    }
                });
            }

            // Remove duplicates and limit results
            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50); // Limit to 50 products per page

            Log::info("Extracted {count} product URLs from Amazon category page", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Amazon", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Extract product data from Amazon product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];

            // Extract SKU from URL
            $data['sku'] = $this->extractSkuFromUrl($productUrl);
            if (!$data['sku']) {
                Log::warning("Could not extract SKU from Amazon URL: {$productUrl}");
                return [];
            }

            $data['product_url'] = $productUrl;

            // Product name
            $data['product_name'] = $this->extractProductName($crawler);

            // Description
            $data['description'] = $this->extractDescription($crawler);

            // Prices
            $priceData = $this->extractPrices($crawler);
            $data['price'] = $priceData['price'];
            $data['sale_price'] = $priceData['sale_price'];

            // Offers
            $data['offers'] = $this->extractOffers($crawler);

            // Availability
            $data['inventory_status'] = $this->extractAvailability($crawler);

            // Rating and reviews
            $ratingData = $this->extractRatingAndReviews($crawler);
            $data['rating'] = $ratingData['rating'];
            $data['review_count'] = $ratingData['review_count'];

            // Brand and model
            $brandData = $this->extractBrandAndModel($crawler);
            $data['brand'] = $brandData['brand'];
            $data['model_name'] = $brandData['model'];

            // Specifications
            $specs = $this->extractSpecifications($crawler);
            $data = array_merge($data, $specs);

            // Detailed technical specifications
            $detailedSpecs = $this->extractDetailedTechnicalSpecs($crawler);
            $data = array_merge($data, $detailedSpecs);

            // Detailed offers information
            $detailedOffers = $this->extractDetailedOffers($crawler);
            $data = array_merge($data, $detailedOffers);

            // Additional product information
            $additionalInfo = $this->extractAdditionalProductInfo($crawler);
            $data = array_merge($data, $additionalInfo);

            // Images
            $data['image_urls'] = $this->extractImages($crawler);

            // Variants
            $data['variants'] = $this->extractVariants($crawler);

            // Sanitize all data
            $data = DataSanitizer::sanitizeLaptopData($data);

            Log::debug("Extracted Amazon product data", [
                'sku' => $data['sku'],
                'product_name' => $data['product_name'] ?? 'N/A'
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error("Failed to extract Amazon product data", [
                'url' => $productUrl,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Extract SKU from Amazon URL
     */
    private function extractSkuFromUrl(string $url): ?string
    {
        // Amazon ASIN pattern
        if (preg_match('/\/dp\/([A-Z0-9]{10})/', $url, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/\/product\/([A-Z0-9]+)/', $url, $matches)) {
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
            '#productTitle',
            '.product-title',
            'h1.a-size-large',
            'h1 span'
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                return $this->cleanText($element->text());
            }
        }

        return null;
    }

    /**
     * Extract product description
     */
    private function extractDescription(Crawler $crawler): ?string
    {
        $descriptions = [];

        // Feature bullets
        $crawler->filter('#feature-bullets ul li span')->each(function (Crawler $node) use (&$descriptions) {
            $text = $this->cleanText($node->text());
            if ($text && strlen($text) > 10) {
                $descriptions[] = $text;
            }
        });

        // Product description
        $productDesc = $crawler->filter('#productDescription p')->first();
        if ($productDesc->count() > 0) {
            $descriptions[] = $this->cleanText($productDesc->text());
        }

        return !empty($descriptions) ? implode('. ', $descriptions) : null;
    }

    /**
     * Extract prices
     */
    private function extractPrices(Crawler $crawler): array
    {
        $prices = ['price' => null, 'sale_price' => null];

        // Current price selectors
        $priceSelectors = [
            '.a-price-current .a-offscreen',
            '.a-price .a-offscreen',
            '#priceblock_dealprice',
            '#priceblock_ourprice',
            '.a-price-range .a-offscreen'
        ];

        foreach ($priceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $price = $this->extractPrice($element->text());
                if ($price) {
                    $prices['sale_price'] = $price;
                    break;
                }
            }
        }

        // Original price (if on sale)
        $originalPriceSelectors = [
            '.a-price.a-text-price .a-offscreen',
            '#priceblock_listprice',
            '.a-price-was .a-offscreen'
        ];

        foreach ($originalPriceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $price = $this->extractPrice($element->text());
                if ($price) {
                    $prices['price'] = $price;
                    break;
                }
            }
        }

        // If no original price found, use sale price as regular price
        if (!$prices['price'] && $prices['sale_price']) {
            $prices['price'] = $prices['sale_price'];
            $prices['sale_price'] = null;
        }

        return $prices;
    }

    /**
     * Extract offers and discounts
     */
    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        // Discount percentage
        $discount = $crawler->filter('.savingsPercentage')->first();
        if ($discount->count() > 0) {
            $offers[] = $this->cleanText($discount->text());
        }

        // Special offers
        $crawler->filter('#dealBadge, .a-badge-text, .promoPriceBlockMessage')->each(function (Crawler $node) use (&$offers) {
            $text = $this->cleanText($node->text());
            if ($text) {
                $offers[] = $text;
            }
        });

        return !empty($offers) ? implode('; ', $offers) : null;
    }

    /**
     * Extract availability status
     */
    private function extractAvailability(Crawler $crawler): ?string
    {
        $availabilitySelectors = [
            '#availability span',
            '#availability .a-color-success',
            '#availability .a-color-state',
            '.a-color-price'
        ];

        foreach ($availabilitySelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                if ($text) {
                    return $text;
                }
            }
        }

        return 'Unknown';
    }

    /**
     * Extract rating and review count
     */
    private function extractRatingAndReviews(Crawler $crawler): array
    {
        $data = ['rating' => null, 'review_count' => 0];

        // Rating
        $ratingSelectors = [
            '.a-icon-alt',
            '[data-hook="average-star-rating"] .a-icon-alt',
            '.a-star-medium .a-icon-alt'
        ];

        foreach ($ratingSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $rating = $this->extractRating($element->text());
                if ($rating) {
                    $data['rating'] = $rating;
                    break;
                }
            }
        }

        // Review count
        $reviewSelectors = [
            '[data-hook="total-review-count"]',
            '#acrCustomerReviewText',
            '.a-link-normal'
        ];

        foreach ($reviewSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $reviewCount = $this->extractReviewCount($element->text());
                if ($reviewCount > 0) {
                    $data['review_count'] = $reviewCount;
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * Extract brand and model
     */
    private function extractBrandAndModel(Crawler $crawler): array
    {
        $data = ['brand' => null, 'model' => null];

        // Try to extract from product title
        $title = $this->extractProductName($crawler);
        if ($title) {
            // Common laptop brands
            $brands = ['HP', 'Dell', 'Lenovo', 'ASUS', 'Acer', 'Apple', 'MSI', 'Samsung', 'LG', 'Sony', 'Toshiba'];
            
            foreach ($brands as $brand) {
                if (stripos($title, $brand) !== false) {
                    $data['brand'] = $brand;
                    break;
                }
            }
        }

        // Try to extract from specifications table
        $crawler->filter('#productDetails_techSpec_section_1 tr, #productDetails_detailBullets_sections1 tr')->each(function (Crawler $row) use (&$data) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = $this->cleanText($cells->eq(0)->text());
                $value = $this->cleanText($cells->eq(1)->text());
                
                if (stripos($label, 'brand') !== false) {
                    $data['brand'] = $value;
                }
                if (stripos($label, 'model') !== false) {
                    $data['model'] = $value;
                }
            }
        });

        return $data;
    }

    /**
     * Extract technical specifications
     */
    private function extractSpecifications(Crawler $crawler): array
    {
        $specs = [];

        // Extract from tech specs table
        $crawler->filter('#productDetails_techSpec_section_1 tr, #productDetails_detailBullets_sections1 tr')->each(function (Crawler $row) use (&$specs) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = strtolower($this->cleanText($cells->eq(0)->text()));
                $value = $this->cleanText($cells->eq(1)->text());
                
                if (strpos($label, 'screen') !== false || strpos($label, 'display') !== false) {
                    $specs['screen_size'] = $value;
                }
                if (strpos($label, 'ram') !== false || strpos($label, 'memory') !== false) {
                    $specs['ram'] = $value;
                }
                if (strpos($label, 'storage') !== false || strpos($label, 'hard') !== false) {
                    $specs['hard_disk'] = $value;
                }
                if (strpos($label, 'processor') !== false || strpos($label, 'cpu') !== false) {
                    $specs['cpu_model'] = $value;
                }
                if (strpos($label, 'graphics') !== false || strpos($label, 'gpu') !== false) {
                    $specs['graphics_card'] = $value;
                }
                if (strpos($label, 'operating') !== false || strpos($label, 'os') !== false) {
                    $specs['operating_system'] = $value;
                }
                if (strpos($label, 'color') !== false || strpos($label, 'colour') !== false) {
                    $specs['color'] = $value;
                }
            }
        });

        // Extract from feature bullets if specs table not found
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

        // Main product images
        $crawler->filter('#landingImage, #imgTagWrapperId img, .a-dynamic-image')->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('src') ?: $node->attr('data-src');
            if ($src && strpos($src, 'http') === 0) {
                $images[] = $src;
            }
        });

        // Alternative image selectors
        if (empty($images)) {
            $crawler->filter('.imageThumb img, .a-button-thumbnail img')->each(function (Crawler $node) use (&$images) {
                $src = $node->attr('src') ?: $node->attr('data-src');
                if ($src && strpos($src, 'http') === 0) {
                    $images[] = $src;
                }
            });
        }

        return !empty($images) ? array_unique($images) : null;
    }

    /**
     * Extract product variants
     */
    private function extractVariants(Crawler $crawler): ?array
    {
        $variants = [];

        // Color variants
        $crawler->filter('#variation_color_name li, .swatches li')->each(function (Crawler $node) use (&$variants) {
            $title = $node->attr('title') ?: $node->text();
            if ($title) {
                $variants[] = ['type' => 'color', 'value' => $this->cleanText($title)];
            }
        });

        // Size/configuration variants
        $crawler->filter('#variation_size_name li, .a-button-group .a-button')->each(function (Crawler $node) use (&$variants) {
            $text = $this->cleanText($node->text());
            if ($text) {
                $variants[] = ['type' => 'configuration', 'value' => $text];
            }
        });

        return !empty($variants) ? $variants : null;
    }

    /**
     * Extract detailed technical specifications from product details table
     */
    private function extractDetailedTechnicalSpecs(Crawler $crawler): array
    {
        $specs = [];
        $technicalDetails = [];

        // Extract from main product overview table (po-* classes)
        $crawler->filter('.a-normal.a-spacing-micro tr')->each(function (Crawler $row) use (&$specs, &$technicalDetails) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = $this->cleanText($cells->eq(0)->text());
                $value = $this->cleanText($cells->eq(1)->text());
                
                if ($label && $value) {
                    $technicalDetails[$label] = $value;
                    $this->mapSpecificationField($label, $value, $specs);
                }
            }
        });

        // Extract from technical details section
        $crawler->filter('#productDetails_techSpec_section_1 tr, .prodDetTable tr')->each(function (Crawler $row) use (&$specs, &$technicalDetails) {
            $labelCell = $row->filter('th, td:first-child')->first();
            $valueCell = $row->filter('td:last-child')->first();
            
            if ($labelCell->count() > 0 && $valueCell->count() > 0) {
                $label = $this->cleanText($labelCell->text());
                $value = $this->cleanText($valueCell->text());
                
                if ($label && $value) {
                    $technicalDetails[$label] = $value;
                    $this->mapSpecificationField($label, $value, $specs);
                }
            }
        });

        // Store all technical details as JSON
        $specs['technical_details'] = $technicalDetails;

        return $specs;
    }

    /**
     * Map specification fields to database columns
     */
    private function mapSpecificationField(string $label, string $value, array &$specs): void
    {
        $label = strtolower($label);
        
        // Brand mapping
        if (strpos($label, 'brand') !== false) {
            $specs['brand'] = $value;
        }
        
        // Manufacturer mapping
        if (strpos($label, 'manufacturer') !== false) {
            $specs['manufacturer'] = $value;
        }
        
        // Series mapping
        if (strpos($label, 'series') !== false) {
            $specs['series'] = $value;
        }
        
        // Model name mapping
        if (strpos($label, 'model name') !== false || strpos($label, 'item model number') !== false) {
            if (strpos($label, 'item model number') !== false) {
                $specs['item_model_number'] = $value;
            } else {
                $specs['model_name'] = $value;
            }
        }
        
        // Color mapping
        if (strpos($label, 'colour') !== false || strpos($label, 'color') !== false) {
            $specs['color'] = $value;
        }
        
        // Form factor mapping
        if (strpos($label, 'form factor') !== false) {
            $specs['form_factor'] = $value;
        }
        
        // Screen size mapping
        if (strpos($label, 'screen size') !== false || strpos($label, 'standing screen display size') !== false) {
            $specs['screen_size'] = $value;
        }
        
        // Screen resolution mapping
        if (strpos($label, 'screen resolution') !== false || strpos($label, 'resolution') !== false) {
            $specs['screen_resolution'] = $value;
        }
        
        // Package dimensions mapping
        if (strpos($label, 'package dimensions') !== false) {
            $specs['package_dimensions'] = $value;
        }
        
        // Processor mappings
        if (strpos($label, 'processor brand') !== false) {
            $specs['processor_brand'] = $value;
        }
        if (strpos($label, 'processor type') !== false || strpos($label, 'cpu model') !== false) {
            $specs['processor_type'] = $value;
            $specs['cpu_model'] = $value;
        }
        if (strpos($label, 'processor speed') !== false) {
            $specs['processor_speed'] = $value;
        }
        if (strpos($label, 'processor count') !== false) {
            $specs['processor_count'] = (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
        }
        
        // Memory mappings
        if (strpos($label, 'ram memory installed size') !== false) {
            $specs['ram'] = $value;
        }
        if (strpos($label, 'memory technology') !== false) {
            $specs['memory_technology'] = $value;
        }
        if (strpos($label, 'computer memory type') !== false) {
            $specs['computer_memory_type'] = $value;
        }
        if (strpos($label, 'maximum memory supported') !== false) {
            $specs['maximum_memory_supported'] = $value;
        }
        
        // Storage mappings
        if (strpos($label, 'hard disk size') !== false || strpos($label, 'hard drive size') !== false) {
            $specs['hard_disk'] = $value;
        }
        if (strpos($label, 'hard disk description') !== false) {
            $specs['hard_disk_description'] = $value;
        }
        
        // Graphics mappings
        if (strpos($label, 'graphics card description') !== false) {
            $specs['graphics_card'] = $value;
        }
        if (strpos($label, 'graphics coprocessor') !== false) {
            $specs['graphics_coprocessor'] = $value;
        }
        if (strpos($label, 'graphics chipset brand') !== false) {
            $specs['graphics_chipset_brand'] = $value;
        }
        
        // Operating system mapping
        if (strpos($label, 'operating system') !== false) {
            $specs['operating_system'] = $value;
        }
        
        // Special features mapping
        if (strpos($label, 'special feature') !== false) {
            $specs['special_features'] = $value;
        }
        
        // Connectivity mappings
        if (strpos($label, 'number of usb ports') !== false) {
            $specs['number_of_usb_ports'] = $value;
        }
        if (strpos($label, 'connectivity type') !== false) {
            $specs['connectivity_type'] = $value;
        }
        if (strpos($label, 'wireless type') !== false) {
            $specs['wireless_type'] = $value;
        }
        if (strpos($label, 'bluetooth') !== false) {
            $specs['bluetooth_version'] = $value;
        }
        
        // Physical specifications
        if (strpos($label, 'weight') !== false) {
            $specs['weight'] = $value;
        }
        if (strpos($label, 'dimensions') !== false && strpos($label, 'package') === false) {
            $specs['dimensions'] = $value;
        }
        
        // Battery
        if (strpos($label, 'battery') !== false) {
            $specs['battery_life'] = $value;
        }
    }

    /**
     * Extract detailed offers information
     */
    private function extractDetailedOffers(Crawler $crawler): array
    {
        $offers = [
            'detailed_offers' => [],
            'cashback_offers' => null,
            'emi_offers' => null,
            'bank_offers' => null,
            'partner_offers' => null
        ];

        // Extract offers from carousel
        $crawler->filter('.offers-items')->each(function (Crawler $offerNode) use (&$offers) {
            $title = $this->cleanText($offerNode->filter('.offers-items-title')->text());
            $content = $this->cleanText($offerNode->filter('.offers-items-content')->text());
            $count = $this->cleanText($offerNode->filter('.vsx-offers-count')->text());
            
            if ($title && $content) {
                $offerData = [
                    'title' => $title,
                    'content' => $content,
                    'count' => $count
                ];
                
                $offers['detailed_offers'][] = $offerData;
                
                // Categorize offers
                $titleLower = strtolower($title);
                if (strpos($titleLower, 'cashback') !== false) {
                    $offers['cashback_offers'] = $content;
                } elseif (strpos($titleLower, 'emi') !== false || strpos($titleLower, 'no cost') !== false) {
                    $offers['emi_offers'] = $content;
                } elseif (strpos($titleLower, 'bank') !== false) {
                    $offers['bank_offers'] = $content;
                } elseif (strpos($titleLower, 'partner') !== false) {
                    $offers['partner_offers'] = $content;
                }
            }
        });

        // Extract additional offers from other sections
        $additionalOffers = [];
        $crawler->filter('#dealBadge, .promoPriceBlockMessage, .a-badge-text')->each(function (Crawler $node) use (&$additionalOffers) {
            $text = $this->cleanText($node->text());
            if ($text) {
                $additionalOffers[] = $text;
            }
        });

        if (!empty($additionalOffers)) {
            $offers['detailed_offers'] = array_merge($offers['detailed_offers'], array_map(function($offer) {
                return ['title' => 'Special Offer', 'content' => $offer, 'count' => ''];
            }, $additionalOffers));
        }

        return $offers;
    }

    /**
     * Extract additional product information
     */
    private function extractAdditionalProductInfo(Crawler $crawler): array
{
    $info = [];

    // Check for Amazon's Choice badge
    $info['amazon_choice'] = $crawler->filter('#acBadge, .ac-badge')->count() > 0;

    // Check for Bestseller badge
    $info['bestseller'] = false;
    $crawler->filter('.a-badge-text')->each(function (Crawler $node) use (&$info) {
        if (stripos($node->text(), 'bestseller') !== false) {
            $info['bestseller'] = true;
        }
    });

    // Extract MRP price
    $mrpElement = $crawler->filter('.a-price.a-text-price .a-offscreen, #priceblock_listprice')->first();
    if ($mrpElement->count() > 0) {
        $info['mrp_price'] = $this->extractPrice($mrpElement->text());
    }

    // Extract seller information
    $sellerElement = $crawler->filter('#sellerProfileTriggerId, .a-link-normal')->first();
    if ($sellerElement->count() > 0) {
        $info['seller_name'] = $this->cleanText($sellerElement->text());
    }

    // Extract availability status
    $availabilityElement = $crawler->filter('#availability span, .a-color-success, .a-color-state')->first();
    if ($availabilityElement->count() > 0) {
        $info['availability_status'] = $this->cleanText($availabilityElement->text());
    }

    // Extract key features
    $keyFeatures = [];
    $crawler->filter('#feature-bullets ul li span')->each(function (Crawler $node) use (&$keyFeatures) {
        $text = $this->cleanText($node->text());
        if ($text && strlen($text) > 10) {
            $keyFeatures[] = $text;
        }
    });

    if (!empty($keyFeatures)) {
        $info['key_features'] = implode('; ', $keyFeatures);
    }

    return $info;
}

}

