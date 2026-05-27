<?php

namespace Tests\Unit;

use App\Services\Scrapers\AmazonJpRankingScraper;
use Tests\TestCase;

class AmazonJpRankingScraperTest extends TestCase
{
    private AmazonJpRankingScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new AmazonJpRankingScraper();
    }

    public function test_platform_is_amazon_jp(): void
    {
        $this->assertEquals('amazon_jp', $this->scraper->getPlatform());
    }

    public function test_build_search_url_uses_amazon_co_jp(): void
    {
        $url = $this->scraper->testBuildSearchUrl('printer', 1);

        $this->assertStringStartsWith('https://www.amazon.co.jp/s', $url);
        $this->assertStringContainsString('k=printer', $url);
        $this->assertStringContainsString('page=1', $url);
    }

    public function test_build_search_url_does_not_use_amazon_in(): void
    {
        $url = $this->scraper->testBuildSearchUrl('printer', 1);

        $this->assertStringNotContainsString('amazon.in', $url);
    }

    public function test_build_search_url_page_2(): void
    {
        $url = $this->scraper->testBuildSearchUrl('detergent', 2);

        $this->assertStringContainsString('page=2', $url);
        $this->assertStringContainsString('k=detergent', $url);
    }

    public function test_set_scraper_id_is_inherited_from_amazon_ranking_scraper(): void
    {
        // AmazonJpRankingScraper extends AmazonRankingScraper
        // After Task 3, AmazonRankingScraper will have setScraperId/getScraperId
        // AmazonJpRankingScraper inherits both
        $scraper = new AmazonJpRankingScraper();
        $scraper->setScraperId('1000000045');
        $this->assertEquals('1000000045', $scraper->getScraperId());
    }
}
