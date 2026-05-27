<?php

namespace App\Console\Commands;

use App\Services\Scrapers\AmazonReviewScraper;
use App\Services\Scrapers\AmazonReviewScraperBrowser;
use App\Services\Scrapers\AmazonReviewScraperWithAuth;
use App\Services\Scrapers\FlipkartReviewScraper;
use App\Services\Scrapers\VijaySalesReviewScraper;
use App\Services\Scrapers\RelianceDigitalReviewScraper;
use App\Services\Scrapers\CromaReviewScraper;
use Illuminate\Console\Command;

class ScrapeReviewsUnifiedCommand extends Command
{
    protected $signature = 'scraper:reviews-platform
                            {platform : Platform to scrape (amazon, flipkart, vijaysales, reliancedigital, croma, all)}
                            {--product-ids=* : Specific product IDs to scrape}
                            {--limit= : Limit number of products to scrape}
                            {--mode=http : Amazon-only transport: http | browser | auth}';

    protected $description = 'Scrape product reviews for specified platform(s)';

    public function handle()
    {
        $platform = $this->argument('platform');
        $productIds = $this->option('product-ids');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $mode = strtolower((string) $this->option('mode')) ?: 'http';

        if (!in_array($mode, ['http', 'browser', 'auth'], true)) {
            $this->error("Invalid --mode '{$mode}'. Use one of: http, browser, auth.");
            return Command::FAILURE;
        }

        if ($mode !== 'http' && !in_array($platform, ['amazon', 'all'], true)) {
            $this->warn("--mode={$mode} is only honoured for Amazon; ignoring for '{$platform}'.");
        }

        $this->info("Starting review scraping for: {$platform}");
        $this->newLine();

        try {
            $stats = [];

            if ($platform === 'all' || $platform === 'amazon') {
                [$amazonScraper, $modeLabel] = $this->buildAmazonScraper($mode);
                if ($amazonScraper === null) {
                    return Command::FAILURE;
                }
                $this->info("Scraping Amazon reviews ({$modeLabel} mode)...");
                $stats['amazon'] = $amazonScraper->scrapeReviews(
                    !empty($productIds) ? array_map('intval', $productIds) : null,
                    $limit
                );
            }

            if ($platform === 'all' || $platform === 'flipkart') {
                $this->info('Scraping Flipkart reviews...');
                $scraper = new FlipkartReviewScraper();
                $stats['flipkart'] = $scraper->scrapeReviews(
                    !empty($productIds) ? array_map('intval', $productIds) : null,
                    $limit
                );
            }

            if ($platform === 'all' || $platform === 'vijaysales') {
                $this->info('Scraping VijaySales reviews...');
                $scraper = new VijaySalesReviewScraper();
                $stats['vijaysales'] = $scraper->scrapeReviews(
                    !empty($productIds) ? array_map('intval', $productIds) : null,
                    $limit
                );
            }

            if ($platform === 'all' || $platform === 'reliancedigital') {
                $this->info('Scraping RelianceDigital reviews...');
                $scraper = new RelianceDigitalReviewScraper();
                $stats['reliancedigital'] = $scraper->scrapeAllReviews(
                    !empty($productIds) ? array_map('intval', $productIds) : null,
                    $limit
                );
            }

            if ($platform === 'all' || $platform === 'croma') {
                $this->info('Scraping croma reviews...');
                $scraper = new CromaReviewScraper();
                $stats['croma'] = $scraper->scrapeAllReviews(
                    !empty($productIds) ? array_map('intval', $productIds) : null,
                    $limit
                );
            }

            $this->newLine();
            $this->info('Review scraping completed!');
            $this->newLine();

            // Display statistics
            foreach ($stats as $plat => $stat) {
                $this->info(strtoupper($plat) . ' Statistics:');
                $this->table(
                    ['Metric', 'Count'],
                    [
                        ['Products Processed', $stat['products_processed']],
                        ['Reviews Found', $stat['reviews_found']],
                        ['Reviews Added', $stat['reviews_added']],
                        ['Reviews Updated', $stat['reviews_updated']],
                        ['Errors', $stat['errors_count']],
                    ]
                );
                $this->newLine();
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during review scraping: ' . $e->getMessage());
            
            if ($this->getOutput()->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Pick the Amazon review scraper for the requested mode. Returns
     * [scraper, label]; scraper is null when the mode could not be set up
     * (e.g. `auth` mode with no cookies configured).
     */
    protected function buildAmazonScraper(string $mode): array
    {
        switch ($mode) {
            case 'browser':
                return [new AmazonReviewScraperBrowser(), 'browser'];

            case 'auth':
                $cookies = config('amazon_cookies.cookies', []);
                if (empty($cookies)) {
                    $this->error('No Amazon cookies configured.');
                    $this->warn('Configure them in config/amazon_cookies.php before using --mode=auth.');
                    return [null, 'auth'];
                }
                $this->info('Amazon cookies loaded: ' . count($cookies));
                return [new AmazonReviewScraperWithAuth(), 'authenticated browser'];

            case 'http':
            default:
                return [new AmazonReviewScraper(), 'http'];
        }
    }
}
