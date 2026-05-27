<?php

namespace App\Services;

use App\Models\ScraperRun;

class ProductComparisonService
{
    public function getRecentRuns(?string $platform = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = ScraperRun::where('status', 'completed')
            ->where('products_scraped', '>', 0)
            ->orderByDesc('created_at')
            ->limit(30);

        if ($platform) {
            $query->where('platform', $platform);
        }

        return $query->get();
    }

    public function compareRuns(string $currentScraperId, string $previousScraperId): array
    {
        $currentRun  = ScraperRun::where('scraper_id', $currentScraperId)->first();
        $previousRun = ScraperRun::where('scraper_id', $previousScraperId)->first();

        $currentProducts  = \App\Models\Product::where('scraper_id', $currentScraperId)->get()->keyBy('sku');
        $previousProducts = \App\Models\Product::where('scraper_id', $previousScraperId)->get()->keyBy('sku');

        $currentSkus  = $currentProducts->keys()->toArray();
        $previousSkus = $previousProducts->keys()->toArray();

        $newSkus     = array_diff($currentSkus, $previousSkus);
        $missingSkus = array_diff($previousSkus, $currentSkus);
        $commonSkus  = array_intersect($currentSkus, $previousSkus);

        $newProducts     = $currentProducts->only($newSkus)->values();
        $missingProducts = $previousProducts->only($missingSkus)->values();

        $trackFields = [
            'price'        => 'Price',
            'title'        => 'Title',
            'rating'       => 'Rating',
            'reviews_count'=> 'Reviews',
            'availability' => 'Availability',
        ];

        $changedProducts = [];
        foreach ($commonSkus as $sku) {
            $cur  = $currentProducts[$sku];
            $prev = $previousProducts[$sku];
            $changes = [];
            foreach ($trackFields as $field => $label) {
                if ((string) $cur->$field !== (string) $prev->$field) {
                    $changes[$field] = [
                        'label' => $label,
                        'old'   => $prev->$field,
                        'new'   => $cur->$field,
                    ];
                }
            }
            if (!empty($changes)) {
                $changedProducts[$sku] = ['product' => $cur, 'changes' => $changes];
            }
        }

        return [
            'current_run'      => $currentRun,
            'previous_run'     => $previousRun,
            'new_products'     => $newProducts,
            'missing_products' => $missingProducts,
            'changed_products' => $changedProducts,
            'stats'            => [
                'new_count'     => count($newSkus),
                'missing_count' => count($missingSkus),
                'changed_count' => count($changedProducts),
                'total_current' => count($currentSkus),
            ],
        ];
    }
}
