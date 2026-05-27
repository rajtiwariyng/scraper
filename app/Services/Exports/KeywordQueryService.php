<?php

namespace App\Services\Exports;

use App\Models\Keyword;

class KeywordQueryService
{
    public function query(array $filters = [])
    {
        return Keyword::query()
            ->when($filters['platform'] ?? null,
                fn ($q, $v) => $q->where('platform', $v))
            ->when($filters['status'] ?? null,
                fn ($q, $v) => $q->where('status', $v === 'active'));
    }
}
