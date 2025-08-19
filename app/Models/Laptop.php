<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Laptop extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'sku',
        'product_name',
        'description',
        'price',
        'sale_price',
        'offers',
        'inventory_status',
        'rating',
        'review_count',
        'variants',
        'brand',
        'model_name',
        'screen_size',
        'color',
        'hard_disk',
        'cpu_model',
        'ram',
        'operating_system',
        'special_features',
        'graphics_card',
        'image_urls',
        'video_urls',
        'product_url',
        'is_active',
        'last_scraped_at',
        // New detailed specifications
        'manufacturer',
        'series',
        'form_factor',
        'screen_resolution',
        'package_dimensions',
        'item_model_number',
        'processor_brand',
        'processor_type',
        'processor_speed',
        'processor_count',
        'memory_technology',
        'computer_memory_type',
        'maximum_memory_supported',
        'hard_disk_description',
        'hard_disk_interface',
        'graphics_coprocessor',
        'graphics_chipset_brand',
        'number_of_usb_ports',
        'connectivity_type',
        'wireless_type',
        'bluetooth_version',
        'battery_life',
        'weight',
        'dimensions',
        // Detailed offers
        'detailed_offers',
        'cashback_offers',
        'emi_offers',
        'bank_offers',
        'partner_offers',
        // Additional product information
        'key_features',
        'technical_details',
        'availability_status',
        'mrp_price',
        'discount_percentage',
        'seller_name',
        'amazon_choice',
        'bestseller'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'mrp_price' => 'decimal:2',
        'rating' => 'decimal:2',
        'review_count' => 'integer',
        'processor_count' => 'integer',
        'discount_percentage' => 'integer',
        'variants' => 'array',
        'image_urls' => 'array',
        'video_urls' => 'array',
        'detailed_offers' => 'array',
        'technical_details' => 'array',
        'is_active' => 'boolean',
        'amazon_choice' => 'boolean',
        'bestseller' => 'boolean',
        'last_scraped_at' => 'datetime'
    ];

    /**
     * Scope to filter by platform
     */
    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope to filter active products
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by brand
     */
    public function scopeBrand(Builder $query, string $brand): Builder
    {
        return $query->where('brand', $brand);
    }

    /**
     * Find laptop by platform and SKU
     */
    public static function findByPlatformAndSku(string $platform, string $sku): ?self
    {
        return self::where('platform', $platform)
                   ->where('sku', $sku)
                   ->first();
    }

    /**
     * Get the effective price (sale price if available, otherwise regular price)
     */
    public function getEffectivePriceAttribute(): ?float
    {
        return $this->sale_price ?? $this->price;
    }

    /**
     * Get the discount percentage
     */
    public function getDiscountPercentageAttribute(): ?float
    {
        if (!$this->price || !$this->sale_price || $this->sale_price >= $this->price) {
            return null;
        }

        return round((($this->price - $this->sale_price) / $this->price) * 100, 2);
    }

    /**
     * Get the primary image URL
     */
    public function getPrimaryImageAttribute(): ?string
    {
        return $this->image_urls[0] ?? null;
    }

    /**
     * Check if product data has changed
     */
    public function hasDataChanged(array $newData): bool
    {
        $fieldsToCheck = [
            'product_name', 'description', 'price', 'sale_price', 'offers',
            'inventory_status', 'rating', 'review_count', 'variants',
            'brand', 'model_name', 'screen_size', 'color', 'hard_disk',
            'cpu_model', 'ram', 'operating_system', 'special_features',
            'graphics_card', 'image_urls', 'video_urls'
        ];

        foreach ($fieldsToCheck as $field) {
            if (isset($newData[$field])) {
                $currentValue = $this->getAttribute($field);
                $newValue = $newData[$field];

                // Handle JSON fields
                if (in_array($field, ['variants', 'image_urls', 'video_urls'])) {
                    $currentValue = is_array($currentValue) ? $currentValue : json_decode($currentValue, true);
                    $newValue = is_array($newValue) ? $newValue : json_decode($newValue, true);
                }

                if ($currentValue != $newValue) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Update product data if changed
     */
    public function updateIfChanged(array $newData): bool
    {
        if ($this->hasDataChanged($newData)) {
            $newData['last_scraped_at'] = now();
            $this->update($newData);
            return true;
        }

        // Update only the last_scraped_at timestamp
        $this->update(['last_scraped_at' => now()]);
        return false;
    }
}

