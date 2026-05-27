<?php

namespace App\Exports;

use App\Services\Exports\KeywordQueryService;

class KeywordsExport extends BaseExport
{
    public function query()
    {
        return app(KeywordQueryService::class)
            ->query($this->filters);
    }

    public function headings(): array
    {
        return ['ID', 'Platform', 'Keyword', 'Status', 'Created At'];
    }

    public function map($k): array
    {
        return [
            $k->id,
            $k->platform,
            $k->keyword,
            $k->status ? 'Active' : 'Inactive',
            $k->created_at,
        ];
    }
}
