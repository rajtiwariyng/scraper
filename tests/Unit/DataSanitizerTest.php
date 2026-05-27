<?php

namespace Tests\Unit;

use App\Services\DataSanitizer;
use Tests\TestCase;

class DataSanitizerTest extends TestCase
{
    public function test_sanitize_price_rejects_price_below_inr_min(): void
    {
        // INR min is 10 — price of 5 should be rejected
        $result = DataSanitizer::sanitizePrice(5);
        $this->assertNull($result);
    }

    public function test_sanitize_price_accepts_valid_inr_price(): void
    {
        $result = DataSanitizer::sanitizePrice(15000);
        $this->assertEquals(15000.0, $result);
    }

    public function test_sanitize_price_accepts_jpy_price_with_jpy_range(): void
    {
        // ¥50,000 is valid JPY — should pass with JPY range
        $jpyRange = ['min' => 100, 'max' => 5000000];
        $result = DataSanitizer::sanitizePrice(50000, $jpyRange);
        $this->assertEquals(50000.0, $result);
    }

    public function test_sanitize_price_rejects_jpy_price_below_jpy_min_with_jpy_range(): void
    {
        $jpyRange = ['min' => 100, 'max' => 5000000];
        $result = DataSanitizer::sanitizePrice(50, $jpyRange);
        $this->assertNull($result);
    }

    public function test_sanitize_product_data_uses_jpy_range_for_amazon_jp(): void
    {
        // ¥500 is above JPY min (100) — should pass
        $data = [
            'platform' => 'amazon_jp',
            'sku' => 'B001234567',
            'title' => 'Test Product',
            'price' => 500,
            'sale_price' => 450,
        ];

        $result = DataSanitizer::sanitizeProductData($data);

        $this->assertEquals(500.0, $result['price']);
        $this->assertEquals(450.0, $result['sale_price']);
    }

    public function test_sanitize_product_data_uses_inr_range_for_amazon_india(): void
    {
        // ₹5 is below INR min (10) — should be null
        $data = [
            'platform' => 'amazon',
            'sku' => 'B001234567',
            'title' => 'Test Product',
            'price' => 5,
        ];

        $result = DataSanitizer::sanitizeProductData($data);

        $this->assertNull($result['price'] ?? null);
    }

    public function test_sanitize_product_data_scraper_id_is_absent_when_not_in_input(): void
    {
        // When scraper_id is not provided, it should not appear in output
        // (array_filter in DataSanitizer removes null values)
        $data = [
            'platform' => 'amazon',
            'sku' => 'B00TEST123',
            'title' => 'Test Product',
        ];
        $result = DataSanitizer::sanitizeProductData($data);
        $this->assertArrayNotHasKey('scraper_id', $result);
    }

    public function test_sanitize_product_data_scraper_id_is_preserved_when_provided(): void
    {
        $data = [
            'platform' => 'amazon',
            'sku' => 'B00TEST123',
            'title' => 'Test Product',
            'scraper_id' => '1000000045',
        ];
        $result = DataSanitizer::sanitizeProductData($data);
        $this->assertEquals('1000000045', $result['scraper_id']);
    }
}
