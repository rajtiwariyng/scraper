<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ZeptoScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'zepto';
        $this->useJavaScript = true;
        $this->paginationConfig = [
            'type' => 'single_page',
            'max_pages' => 1,
            'max_consecutive_errors' => 5,
            'delay_between_pages' => [2, 4],
            'retry_failed_pages' => false,
        ];
    }

    public function __construct()
    {
        parent::__construct('zepto');
    }

    protected function scrapeCategoryWithBrowser(string $categoryUrl): void
    {
        try {
            $content = $this->browserService->getPageContent($categoryUrl, 5, 90);

            if (!$content) {
                Log::warning('Zepto returned empty category content', ['url' => $categoryUrl]);
                return;
            }

            $this->processPageContent($content, $categoryUrl);
        } catch (\Exception $e) {
            $this->handleError("Failed to scrape with browser: {$categoryUrl}", $e);
        }
    }

    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        if ($this->isProductUrl($categoryUrl)) {
            return [$this->normalizeZeptoUrl($categoryUrl)];
        }

        $productUrls = [];

        try {
            $crawler->filter('a[href*="/pn/"][href*="/pvid/"]')->each(function (Crawler $node) use (&$productUrls) {
                $href = $node->attr('href');

                if (!$href) {
                    return;
                }

                $normalizedUrl = $this->normalizeZeptoUrl($href);
                if ($this->isProductUrl($normalizedUrl)) {
                    $productUrls[] = $normalizedUrl;
                }
            });

            if (empty($productUrls)) {
                $html = $this->getCrawlerHtml($crawler);
                preg_match_all('/\/pn\/[^"\'\s<>]+\/pvid\/[a-f0-9\-]+/i', $html, $matches);

                foreach ($matches[0] ?? [] as $match) {
                    $productUrls[] = $this->normalizeZeptoUrl($match);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract Zepto product urls', [
                'url' => $categoryUrl,
                'error' => $e->getMessage(),
            ]);
        }

        $productUrls = array_values(array_unique(array_filter($productUrls)));

        Log::info('Extracted Zepto product URLs', [
            'count' => count($productUrls),
            'category_url' => $categoryUrl,
        ]);

        return array_slice($productUrls, 0, 80);
    }

    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $schemaData = $this->extractSchemaProductData($crawler);
            $technicalDetails = $this->extractSectionKeyValueData($crawler, '#productHighlights');
            $additionalInformation = $this->extractSectionKeyValueData($crawler, '#productInformationL4');
            $pageText = $this->normalizeWhitespace($crawler->text(''));

            $sku = $schemaData['sku'] ?? $this->extractSkuFromUrl($productUrl);
            if (!$sku) {
                return [];
            }

            $title = $schemaData['title']
                ?? $this->extractText($crawler, 'h1')
                ?? $this->extractText($crawler, 'meta[itemprop="name"]', 'content');

            if (!$title) {
                return [];
            }

            $brand = $schemaData['brand']
                ?? ($technicalDetails['brand'] ?? null)
                ?? $this->extractText($crawler, 'a[href*="/brand/"] p');

            $salePrice = $schemaData['sale_price'] ?? $this->extractPriceFromText($pageText, '/Net Qty:.*?₹\s*([0-9,.]+)/u');
            $regularPrice = $this->extractPriceFromText($pageText, '/MRP\s*₹\s*([0-9,.]+)/u');

            if (!$regularPrice && $salePrice) {
                $regularPrice = $salePrice;
            }

            if (!$salePrice && $regularPrice) {
                $salePrice = $regularPrice;
            }

            $category = $this->extractCategoryFromBreadcrumbSchema($crawler)
                ?? $this->extractText($crawler, '[data-testid="pdp-breadcrumbs"] a:last-child');

            $size = $this->capLength($technicalDetails['size'] ?? null, 100);
            $unitCount = $this->capLength(
                $this->extractNetQuantity($pageText)
                    ?? ($technicalDetails['unit'] ?? null)
                    ?? ($technicalDetails['net quantity'] ?? null),
                100
            );
            $weight = $this->capLength($technicalDetails['weight'] ?? null, 255);

            $rating = $schemaData['rating'] ?? $this->extractDecimalFromText($pageText, '/•\s*([0-9]+(?:\.[0-9]+)?)/u');
            $reviewCount = $schemaData['review_count'] ?? $this->extractReviewCounts($pageText);

            $cashbackOffers = $this->extractCashbackOffers($crawler);
            $offers = $this->buildDiscountOffer($regularPrice, $salePrice);
            $inventoryStatus = $schemaData['inventory_status'] ?? $this->extractInventoryStatus($crawler);
            $imageUrls = $this->extractImages($crawler, $schemaData['image_urls'] ?? []);
            $description = $schemaData['description']
                ?? ($this->technicalDetailToDescription($technicalDetails) ?: null);

            $data = [
                'sku' => $sku,
                'product_url' => $this->normalizeZeptoUrl($productUrl),
                'title' => $title,
                'description' => $description,
                'currency_code' => $schemaData['currency_code'] ?? 'INR',
                'price' => $regularPrice,
                'sale_price' => $salePrice,
                'offers' => $offers,
                'cashback_offers' => $cashbackOffers,
                'category' => $category,
                'inventory_status' => $inventoryStatus,
                'rating' => $rating,
                'review_count' => $reviewCount,
                'brand' => $brand,
                'size' => $size,
                'unit_count' => $unitCount,
                'model_name' => $technicalDetails['model name'] ?? null,
                'highlights' => $technicalDetails['key features'] ?? $this->technicalDetailToDescription($technicalDetails),
                'color' => $technicalDetails['color'] ?? null,
                'variants' => $technicalDetails['variant'] ?? null,
                'image_urls' => $imageUrls,
                'manufacturer' => $additionalInformation['manufacturer or marketer name'] ?? null,
                'weight' => $weight,
                'technical_details' => $technicalDetails,
                'additional_information' => $additionalInformation,
                'seller_name' => $additionalInformation['seller name'] ?? null,
                'is_active' => $inventoryStatus !== 'out_of_stock',
            ];

            $sanitized = DataSanitizer::sanitizeProductData($data);

            if (!empty($cashbackOffers)) {
                $sanitized['cashback_offers'] = $cashbackOffers;
            }

            return $sanitized;
        } catch (\Exception $e) {
            Log::error('Failed to extract Zepto product data', [
                'url' => $productUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function extractSchemaProductData(Crawler $crawler): array
    {
        $data = [];

        try {
            $productNode = $crawler->filter('[itemtype="http://schema.org/Product"]')->first();
            if ($productNode->count() === 0) {
                return $data;
            }

            $data['sku'] = $this->extractText($productNode, 'meta[itemprop="sku"]', 'content');
            $data['title'] = $this->extractText($productNode, 'meta[itemprop="name"]', 'content');
            $data['description'] = $this->extractText($productNode, 'meta[itemprop="description"]', 'content');
            $data['brand'] = $this->extractText($productNode, '[itemprop="brand"] meta[itemprop="name"]', 'content');
            $data['rating'] = $this->extractDecimal($this->extractText($productNode, '[itemprop="aggregateRating"] meta[itemprop="ratingValue"]', 'content'));
            $data['review_count'] = $this->extractInteger($this->extractText($productNode, '[itemprop="aggregateRating"] meta[itemprop="reviewCount"]', 'content'));
            $data['sale_price'] = $this->extractDecimal($this->extractText($productNode, '[itemprop="offers"] span[itemprop="price"]', 'content'));
            $data['currency_code'] = $this->extractText($productNode, '[itemprop="offers"] meta[itemprop="priceCurrency"]', 'content') ?? 'INR';
            $data['inventory_status'] = $this->normalizeAvailability($this->extractText($productNode, '[itemprop="offers"] link[itemprop="availability"]', 'href'));

            $primaryImage = $this->extractText($productNode, 'link[itemprop="image"]', 'href');
            if ($primaryImage) {
                $data['image_urls'] = [$primaryImage];
            }
        } catch (\Exception $e) {
            Log::debug('Zepto schema extraction failed', ['error' => $e->getMessage()]);
        }

        return array_filter($data, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function extractSectionKeyValueData(Crawler $crawler, string $sectionSelector): array
    {
        $details = [];

        try {
            $section = $crawler->filter($sectionSelector);
            if ($section->count() === 0) {
                return $details;
            }

            $section->filter('.KjTQZ')->each(function (Crawler $row) use (&$details) {
                $label = $this->normalizeWhitespace($this->extractText($row, 'h3'));
                $value = $this->normalizeWhitespace($this->extractText($row, 'p'));

                if (!$label || !$value) {
                    return;
                }

                $details[mb_strtolower($label)] = $value;
            });
        } catch (\Exception $e) {
            Log::debug('Zepto section extraction failed', [
                'selector' => $sectionSelector,
                'error' => $e->getMessage(),
            ]);
        }

        return $details;
    }

    private function extractCategoryFromBreadcrumbSchema(Crawler $crawler): ?string
    {
        try {
            $json = $this->extractText($crawler, 'script#breadcrumbSchema');
            if (!$json) {
                return null;
            }

            $data = json_decode($json, true);
            $items = $data['itemListElement'] ?? [];

            if (count($items) >= 2) {
                return $items[1]['name'] ?? null;
            }
        } catch (\Exception $e) {
            Log::debug('Zepto breadcrumb extraction failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function extractCashbackOffers(Crawler $crawler): ?string
    {
        try {
            $offers = [];

            $crawler->filter('button[data-couponid]')->each(function (Crawler $node) use (&$offers) {
                $text = $this->normalizeWhitespace($node->text(''));
                if ($text) {
                    $offers[] = $text;
                }
            });

            $offers = array_values(array_unique(array_filter($offers)));

            return empty($offers) ? null : implode(' | ', array_slice($offers, 0, 10));
        } catch (\Exception $e) {
            Log::debug('Zepto cashback offers extraction failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function buildDiscountOffer(?float $regularPrice, ?float $salePrice): ?string
    {
        if (!$regularPrice || !$salePrice || $salePrice >= $regularPrice) {
            return null;
        }

        $percent = (int) round((($regularPrice - $salePrice) / $regularPrice) * 100);

        return $percent > 0 ? $percent . '% off' : null;
    }

    private function capLength(?string $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function extractInventoryStatus(Crawler $crawler): string
    {
        $pageText = $this->normalizeWhitespace($crawler->text(''));

        if (stripos($pageText, 'out of stock') !== false) {
            return 'out_of_stock';
        }

        if ($crawler->filter('button[aria-label="Increase quantity by one"], button.WJXJe')->count() > 0) {
            return 'in_stock';
        }

        return 'in_stock';
    }

    private function extractImages(Crawler $crawler, array $seedImages = []): array
    {
        $images = $seedImages;

        try {
            $crawler->filter('#left-carousel img[src*="cms/product_variant"], img[alt][src*="cms/product_variant"]')->each(function (Crawler $node) use (&$images) {
                $src = $node->attr('src');
                if ($src) {
                    $images[] = $src;
                }
            });
        } catch (\Exception $e) {
            Log::debug('Zepto image extraction failed', ['error' => $e->getMessage()]);
        }

        return array_values(array_unique(array_filter($images)));
    }

    private function extractNetQuantity(string $pageText): ?string
    {
        if (preg_match('/Net Qty:\s*([^•|\r\n]{1,80}?)(?=\s*(?:•|MRP|₹|$))/u', $pageText, $matches)) {
            $value = $this->normalizeWhitespace($matches[1]);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        if (preg_match('/\b(\d+(?:\.\d+)?\s*(?:pc|pcs|piece|pieces|pack|packs|gm|gms|gram|grams|g|kg|kgs|ml|l|litre|litres|liters|set|sets)\b(?:\s*\([^)]{1,40}\))?)/iu', $pageText, $matches)) {
            return $this->normalizeWhitespace($matches[1]);
        }

        return null;
    }

    private function technicalDetailToDescription(array $technicalDetails): ?string
    {
        if (empty($technicalDetails)) {
            return null;
        }

        $preferredKeys = [
            'key features',
            'ingredients',
            'usage instruction',
            'product type',
            'fragrance',
        ];

        $parts = [];
        foreach ($preferredKeys as $key) {
            if (!empty($technicalDetails[$key])) {
                $parts[] = ucfirst($key) . ': ' . $technicalDetails[$key];
            }
        }

        return empty($parts) ? null : implode(' | ', $parts);
    }

    private function extractReviewCounts(string $pageText): ?int
    {
        if (preg_match('/\(([0-9.,]+)\s*([kKmM]?)\)/u', $pageText, $matches)) {
            $value = (float) str_replace(',', '', $matches[1]);
            $suffix = strtolower($matches[2] ?? '');

            if ($suffix === 'k') {
                $value *= 1000;
            } elseif ($suffix === 'm') {
                $value *= 1000000;
            }

            return (int) round($value);
        }

        return null;
    }

    private function extractPriceFromText(string $text, string $pattern): ?float
    {
        if (preg_match($pattern, $text, $matches)) {
            return $this->extractDecimal($matches[1]);
        }

        return null;
    }

    private function extractDecimalFromText(string $text, string $pattern): ?float
    {
        if (preg_match($pattern, $text, $matches)) {
            return $this->extractDecimal($matches[1]);
        }

        return null;
    }

    private function extractText(Crawler $crawler, string $selector, ?string $attribute = null): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();
            if ($node->count() === 0) {
                return null;
            }

            $value = $attribute ? $node->attr($attribute) : $node->text('');
            return $this->normalizeWhitespace($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractDecimal(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '', preg_replace('/[^0-9.]/', '', $value));
        return $normalized === '' ? null : (float) $normalized;
    }

    private function extractInteger(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = preg_replace('/[^0-9]/', '', $value);
        return $normalized === '' ? null : (int) $normalized;
    }

    private function normalizeAvailability(?string $availabilityUrl): string
    {
        if (!$availabilityUrl) {
            return 'in_stock';
        }

        return str_contains(strtolower($availabilityUrl), 'outofstock') ? 'out_of_stock' : 'in_stock';
    }

    private function isProductUrl(string $url): bool
    {
        return (bool) preg_match('/\/pn\/[^\/]+\/pvid\/[a-f0-9\-]+/i', $url);
    }

    private function extractSkuFromUrl(string $url): ?string
    {
        if (preg_match('/\/pvid\/([a-f0-9\-]+)/i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function normalizeZeptoUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return 'https://www.zepto.com' . $url;
    }

    private function getCrawlerHtml(Crawler $crawler): string
    {
        try {
            return $crawler->filter('html')->html() ?? $crawler->html();
        } catch (\Exception $e) {
            try {
                return $crawler->html();
            } catch (\Exception $innerException) {
                return '';
            }
        }
    }

    private function normalizeWhitespace(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value));

        return $value === '' ? null : $value;
    }
}
