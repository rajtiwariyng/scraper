<?php

namespace Tests\Unit;

use App\Services\Scrapers\AmazonJpScraper;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class AmazonJpScraperTest extends TestCase
{
    private AmazonJpScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new AmazonJpScraper();
    }

    public function test_platform_is_amazon_jp(): void
    {
        $this->assertEquals('amazon_jp', $this->scraper->getPlatform());
    }

    public function test_extract_product_urls_uses_amazon_co_jp_domain(): void
    {
        // Simulate a search results page with a relative product link
        $html = <<<HTML
<html><body>
<div data-cy="title-recipe">
  <a class="a-link-normal s-no-outline" href="/dp/B08N5LNQCX/ref=sr_1_1">Product</a>
</div>
</body></html>
HTML;
        $crawler = new Crawler($html);
        $urls = $this->scraper->testExtractProductUrls($crawler, 'https://www.amazon.co.jp/s?k=printer');

        $this->assertCount(1, $urls);
        $this->assertStringStartsWith('https://www.amazon.co.jp', $urls[0]);
        $this->assertStringContainsString('/dp/', $urls[0]);
    }

    public function test_extract_product_urls_does_not_use_amazon_in_domain(): void
    {
        $html = <<<HTML
<html><body>
<div data-cy="title-recipe">
  <a class="a-link-normal s-no-outline" href="/dp/B08N5LNQCX/ref=sr_1_1">Product</a>
</div>
</body></html>
HTML;
        $crawler = new Crawler($html);
        $urls = $this->scraper->testExtractProductUrls($crawler, 'https://www.amazon.co.jp/s?k=printer');

        foreach ($urls as $url) {
            $this->assertStringNotContainsString('amazon.in', $url);
        }
    }

    public function test_extract_product_urls_skips_non_dp_links(): void
    {
        $html = <<<HTML
<html><body>
<div data-cy="title-recipe">
  <a class="a-link-normal s-no-outline" href="/gp/bestsellers/electronics">Not a product</a>
</div>
</body></html>
HTML;
        $crawler = new Crawler($html);
        $urls = $this->scraper->testExtractProductUrls($crawler, 'https://www.amazon.co.jp/s?k=printer');

        $this->assertEmpty($urls);
    }

    public function test_set_scraper_id_stores_and_retrieves_the_id(): void
    {
        $scraper = new AmazonJpScraper();
        $scraper->setScraperId('1000000045');
        $this->assertEquals('1000000045', $scraper->getScraperId());
    }

    public function test_scraper_id_defaults_to_empty_string(): void
    {
        $scraper = new AmazonJpScraper();
        $this->assertEquals('', $scraper->getScraperId());
    }
}
