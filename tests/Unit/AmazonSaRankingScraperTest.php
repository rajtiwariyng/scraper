<?php

namespace Tests\Unit;

use App\Services\Scrapers\AmazonSaRankingScraper;
use Tests\TestCase;

class AmazonSaRankingScraperTest extends TestCase
{
    private AmazonSaRankingScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new AmazonSaRankingScraper();
    }

    public function test_platform_is_amazon_sa(): void
    {
        $this->assertEquals('amazon_sa', $this->scraper->getPlatform());
    }

    public function test_build_search_url_uses_amazon_sa(): void
    {
        $url = $this->scraper->testBuildSearchUrl('mattress', 1);

        $this->assertStringStartsWith('https://www.amazon.sa/s', $url);
        $this->assertStringContainsString('k=mattress', $url);
        $this->assertStringContainsString('page=1', $url);
    }

    public function test_build_search_url_does_not_use_amazon_in(): void
    {
        $url = $this->scraper->testBuildSearchUrl('mattress', 1);

        $this->assertStringNotContainsString('amazon.in', $url);
    }

    public function test_build_search_url_page_2(): void
    {
        $url = $this->scraper->testBuildSearchUrl('sleepwell mattress', 2);

        $this->assertStringContainsString('page=2', $url);
        $this->assertStringContainsString('k=sleepwell+mattress', $url);
    }

    public function test_set_scraper_id_is_inherited(): void
    {
        $scraper = new AmazonSaRankingScraper();
        $scraper->setScraperId('1000000099');
        $this->assertEquals('1000000099', $scraper->getScraperId());
    }
}
