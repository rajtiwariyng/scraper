<?php

namespace App\Services\Scrapers;

class AmazonSaRankingScraper extends AmazonRankingScraper
{
    protected string $platform = 'amazon_sa';

    protected function getHeaders(): array
    {
        $headers = parent::getHeaders();
        $headers['Accept-Language'] = 'ar-SA,ar;q=0.9,en;q=0.8';
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

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function testBuildSearchUrl(string $keyword, int $page): string
    {
        return $this->buildSearchUrl($keyword, $page);
    }
}
