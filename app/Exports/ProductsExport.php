<?php

namespace App\Exports;

use App\Models\Product;
use App\Services\Exports\ProductQueryService;

class ProductsExport extends BaseExport
{
    public function query()
    {
        return app(ProductQueryService::class)
            ->query($this->filters);
    }

    public function headings(): array
    {
        return [
            'ID',
            'Platform',
            'SKU',
            'Title',
            'Description',
            'Currency Code',
            'Price',
            'Sale Price',
            'Effective Price',
            'Discount %',
            'Offers',
            'Category',
            'Inventory Status',
            'Rating',
            'Review Count',
            'Brand',
            'Model Name',
            'Highlights',
            'Color',
            // 'Image URLs',
            // 'Video URLs',
            'Product URL',
            'Is Active',
            'Scraped Date',

            // Detailed specs
            'Manufacturer',
            'Weight',
            'Dimensions',

            // Offers
            // 'Detailed Offers',
            // 'Cashback Offers',
            // 'EMI Offers',
            // 'Bank Offers',
            // 'Partner Offers',

            // Flags
            'Is Prime',
            'Is Sponsored',
            'Amazon Choice',
            'Best Seller',

            // Additional info
            // 'Technical Details',
            // 'Additional Information',
             'Seller Name',
             'Product Badge',
             'Fulfilled By',
             'Delivery Date',
             'Delivery Price',

            // Timestamps
            // 'Created At',
            // 'Updated At',
        ];
    }

    /**
     * @param Product $p
     */
    public function map($p): array
    {
        return [
            $p->id,
            $p->platform,
            $p->sku,
            $p->title,
            $p->description,
            $p->currency_code,
            $p->price,
            $p->sale_price,
            $p->effective_price,
            $p->discount_percentage,
            $p->offers,
            $p->category,
            $p->inventory_status,
            $p->rating,
            $p->review_count,
            $p->brand,
            $p->model_name,
            $p->highlights,
            $p->color,

            // JSON → string (CSV safe)
            // $this->json($p->image_urls),
            // $this->json($p->video_urls),

            $p->product_url,
            $p->is_active ? 'Yes' : 'No',
            optional($p->scraped_date)->format('Y-m-d H:i:s'),

            // Detailed specs
            $p->manufacturer,
            $p->weight,
            $p->dimensions,

            // Offers
            // $this->json($p->detailed_offers),
            // $this->json($p->cashback_offers),
            // $this->json($p->emi_offers),
            // $this->json($p->bank_offers),
            // $this->json($p->partner_offers),

            // Flags
            $p->is_prime ? 'Yes' : 'No',
            $p->is_sponsored ? 'Yes' : 'No',
            $p->amazon_choice ? 'Yes' : 'No',
            $p->bestseller ? 'Yes' : 'No',

            // Additional info
            // $this->json($p->technical_details),
            // $this->json($p->additional_information),
            $p->seller_name,
            $p->product_badge,
            $p->fulfilled_by ? 'Yes' : 'No',
            optional($p->delivery_date)->format('Y-m-d'),
            $p->delivery_price,

            // Timestamps
            // $p->created_at->format('Y-m-d H:i:s'),
            // $p->updated_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Convert array / JSON to CSV-safe string
     */
    private function json($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return is_array($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE)
            : $value;
    }
}
