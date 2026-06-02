<?php

namespace App\Services\Scrapers;

class AmazonSaRankingScraper extends AmazonRankingScraper
{
    protected string $platform = 'amazon_sa';

    protected function getHeaders(): array
    {
        $headers = parent::getHeaders();
        $headers['Accept-Language'] = 'en-US,en;q=0.9';
        return $headers;
    }

    protected function buildSearchUrl(string $keyword, int $page): string
    {
        $params = [
            'k' => $keyword,
            'page' => $page,
        ];

        return 'https://www.amazon.sa/s?' . http_build_query($params);
    }

}
