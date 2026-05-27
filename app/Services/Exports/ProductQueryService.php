<?php

namespace App\Services\Exports;

use App\Models\Product;

class ProductQueryService
{
    public function query(array $filters = [])
    {
        return Product::query()
            ->when($filters['platform'] ?? null,
                fn ($q, $v) => $q->where('platform', $v))
            ->when($filters['status'] ?? null,
                fn ($q, $v) => $q->where('status', $v === 'active'));
    }
}
