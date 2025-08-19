<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DataSanitizer
{
    /**
     * Sanitize and validate laptop data
     */
    public static function sanitizeLaptopData(array $data): array
    {
        $sanitized = [];

        // Required fields
        $sanitized['platform'] = self::sanitizeString($data['platform'] ?? '');
        $sanitized['sku'] = self::sanitizeString($data['sku'] ?? '');
        $sanitized['product_name'] = self::sanitizeString($data['product_name'] ?? '');

        // Optional text fields
        $sanitized['description'] = self::sanitizeText($data['description'] ?? null);
        $sanitized['offers'] = self::sanitizeText($data['offers'] ?? null);
        $sanitized['inventory_status'] = self::sanitizeString($data['inventory_status'] ?? null);
        $sanitized['brand'] = self::sanitizeString($data['brand'] ?? null);
        $sanitized['model_name'] = self::sanitizeString($data['model_name'] ?? null);
        $sanitized['screen_size'] = self::sanitizeString($data['screen_size'] ?? null);
        $sanitized['color'] = self::sanitizeString($data['color'] ?? null);
        $sanitized['hard_disk'] = self::sanitizeString($data['hard_disk'] ?? null);
        $sanitized['cpu_model'] = self::sanitizeString($data['cpu_model'] ?? null);
        $sanitized['ram'] = self::sanitizeString($data['ram'] ?? null);
        $sanitized['operating_system'] = self::sanitizeString($data['operating_system'] ?? null);
        $sanitized['special_features'] = self::sanitizeText($data['special_features'] ?? null);
        $sanitized['graphics_card'] = self::sanitizeString($data['graphics_card'] ?? null);
        $sanitized['product_url'] = self::sanitizeUrl($data['product_url'] ?? null);

        // Numeric fields
        $sanitized['price'] = self::sanitizePrice($data['price'] ?? null);
        $sanitized['sale_price'] = self::sanitizePrice($data['sale_price'] ?? null);
        $sanitized['rating'] = self::sanitizeRating($data['rating'] ?? null);
        $sanitized['review_count'] = self::sanitizeInteger($data['review_count'] ?? 0);

        // Array fields
        $sanitized['variants'] = self::sanitizeArray($data['variants'] ?? null);
        $sanitized['image_urls'] = self::sanitizeUrlArray($data['image_urls'] ?? []);
        $sanitized['video_urls'] = self::sanitizeUrlArray($data['video_urls'] ?? []);

        // Boolean fields
        $sanitized['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : true;

        // Remove empty values
        return array_filter($sanitized, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * Sanitize string field
     */
    public static function sanitizeString($value, int $maxLength = null): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_array($value)) {
        // Convert array to comma-separated string
        $value = implode(',', $value);
    }

    // Remove HTML tags and normalize whitespace
    $sanitized = trim(preg_replace('/\s+/', ' ', strip_tags($value)));

    // Remove special characters that might cause issues
    $sanitized = preg_replace('/[^\p{L}\p{N}\s\-_.,()&\/]/u', '', $sanitized);

    if ($maxLength && strlen($sanitized) > $maxLength) {
        $sanitized = substr($sanitized, 0, $maxLength);
    }

    return $sanitized ?: null;
}


    /**
     * Sanitize text field (allows more characters)
     */
    public static function sanitizeText(?string $value, int $maxLength = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Remove HTML tags but preserve line breaks
        $sanitized = strip_tags($value);
        $sanitized = trim(preg_replace('/\s+/', ' ', $sanitized));
        
        if ($maxLength && strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
        }

        return $sanitized ?: null;
    }

    /**
     * Sanitize URL
     */
    public static function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $url = trim($url);
        
        // Add protocol if missing
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        // Validate URL format
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return null;
    }

    /**
     * Sanitize price value
     */
    public static function sanitizePrice($price): ?float
    {
        if ($price === null || $price === '') {
            return null;
        }

        // Handle string prices
        if (is_string($price)) {
            // Remove currency symbols and commas
            $price = preg_replace('/[^\d.,]/', '', $price);
            $price = str_replace(',', '', $price);
        }

        $price = (float) $price;

        // Validate price range
        $priceRange = config('scraper.validation.price_range', ['min' => 1000, 'max' => 1000000]);
        
        if ($price < $priceRange['min'] || $price > $priceRange['max']) {
            Log::warning('Price out of valid range', ['price' => $price]);
            return null;
        }

        return $price;
    }

    /**
     * Sanitize rating value
     */
    public static function sanitizeRating($rating): ?float
    {
        if ($rating === null || $rating === '') {
            return null;
        }

        if (is_string($rating)) {
            // Extract numeric rating from string
            preg_match('/(\d+\.?\d*)/', $rating, $matches);
            $rating = isset($matches[1]) ? (float) $matches[1] : null;
        } else {
            $rating = (float) $rating;
        }

        // Validate rating range (0-5)
        if ($rating !== null && ($rating < 0 || $rating > 5)) {
            Log::warning('Rating out of valid range', ['rating' => $rating]);
            return null;
        }

        return $rating;
    }

    /**
     * Sanitize integer value
     */
    public static function sanitizeInteger($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_string($value)) {
            // Remove non-numeric characters
            $value = preg_replace('/[^\d]/', '', $value);
        }

        return (int) $value;
    }

    /**
     * Sanitize array field
     */
    public static function sanitizeArray($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            // Try to decode JSON
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                // Split by common delimiters
                $value = preg_split('/[,;|]/', $value);
            }
        }

        if (!is_array($value)) {
            return null;
        }

        // Clean array values
        $sanitized = array_map(function ($item) {
            return self::sanitizeString($item);
        }, $value);

        // Remove empty values
        $sanitized = array_filter($sanitized);

        return !empty($sanitized) ? array_values($sanitized) : null;
    }

    /**
     * Sanitize array of URLs
     */
    public static function sanitizeUrlArray($urls): ?array
    {
        if (!is_array($urls) || empty($urls)) {
            return null;
        }

        $sanitized = [];
        foreach ($urls as $url) {
            $cleanUrl = self::sanitizeUrl($url);
            if ($cleanUrl) {
                $sanitized[] = $cleanUrl;
            }
        }

        return !empty($sanitized) ? $sanitized : null;
    }

    /**
     * Extract SKU from URL or text
     */
    public static function extractSku(string $text, string $platform): ?string
    {
        $patterns = [
            'amazon' => [
                '/\/dp\/([A-Z0-9]{10})/',
                '/\/product\/([A-Z0-9]+)/',
                '/asin[=:]([A-Z0-9]{10})/i'
            ],
            'flipkart' => [
                '/\/p\/([a-zA-Z0-9]+)/',
                '/pid=([A-Z0-9]+)/',
                '/\/([A-Z0-9]{16})/'
            ],
            'default' => [
                '/product[_-]?id[=:]([A-Z0-9]+)/i',
                '/sku[=:]([A-Z0-9]+)/i',
                '/id[=:]([A-Z0-9]+)/i'
            ]
        ];

        $platformPatterns = $patterns[$platform] ?? $patterns['default'];

        foreach ($platformPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Normalize brand name
     */
    public static function normalizeBrand(?string $brand): ?string
    {
        if (!$brand) {
            return null;
        }

        $brand = self::sanitizeString($brand);
        
        // Common brand name mappings
        $brandMappings = [
            'hp' => 'HP',
            'dell' => 'Dell',
            'lenovo' => 'Lenovo',
            'asus' => 'ASUS',
            'acer' => 'Acer',
            'apple' => 'Apple',
            'msi' => 'MSI',
            'samsung' => 'Samsung',
            'lg' => 'LG',
            'sony' => 'Sony',
            'toshiba' => 'Toshiba',
            'fujitsu' => 'Fujitsu',
            'alienware' => 'Alienware',
            'razer' => 'Razer'
        ];

        $lowerBrand = strtolower($brand);
        return $brandMappings[$lowerBrand] ?? ucfirst($brand);
    }

    /**
     * Extract specifications from text
     */
    public static function extractSpecifications(string $text): array
    {
        $specs = [];

        // RAM extraction
        if (preg_match('/(\d+)\s*GB\s*(RAM|Memory)/i', $text, $matches)) {
            $specs['ram'] = $matches[1] . 'GB';
        }

        // Storage extraction
        if (preg_match('/(\d+)\s*(GB|TB)\s*(SSD|HDD|Storage)/i', $text, $matches)) {
            $specs['hard_disk'] = $matches[1] . $matches[2] . ' ' . strtoupper($matches[3]);
        }

        // Screen size extraction
        if (preg_match('/(\d+\.?\d*)["\s]*inch/i', $text, $matches)) {
            $specs['screen_size'] = $matches[1] . '"';
        }

        // Processor extraction
        if (preg_match('/(Intel|AMD)\s+([^,\n]+)/i', $text, $matches)) {
            $specs['cpu_model'] = trim($matches[1] . ' ' . $matches[2]);
        }

        // Graphics card extraction
        if (preg_match('/(NVIDIA|AMD|Intel)\s+([^,\n]+Graphics?[^,\n]*)/i', $text, $matches)) {
            $specs['graphics_card'] = trim($matches[1] . ' ' . $matches[2]);
        }

        // Operating system extraction
        if (preg_match('/(Windows|macOS|Linux|Ubuntu|Chrome OS)[^,\n]*/i', $text, $matches)) {
            $specs['operating_system'] = trim($matches[0]);
        }

        return $specs;
    }
}

