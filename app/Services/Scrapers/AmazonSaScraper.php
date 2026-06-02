<?php

namespace App\Services\Scrapers;

use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AmazonSaScraper extends AmazonScraper
{
    public function __construct()
    {
        parent::__construct('amazon_sa');
    }

    protected function setupPlatformConfig(): void
    {
        parent::setupPlatformConfig();
        $this->platform = 'amazon_sa';
        $this->defaultHeaders = ['Accept-Language' => 'en-US,en;q=0.9'];
    }

    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            $selectors = [
                'div[data-cy="title-recipe"] > a',
                'a.a-link-normal.s-no-outline',
                'div[data-cy="title-recipe"] > a.a-link-normal',
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.amazon.sa' . $href;
                        }
                        if (strpos($href, '/dp/') !== false || strpos($href, '/product/') !== false) {
                            $productUrls[] = $href;
                        }
                    }
                });
            }

            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50);

            Log::info("Extracted {count} product URLs from Amazon KSA category page", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Amazon KSA", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl,
            ]);
        }

        return $productUrls;
    }

}
