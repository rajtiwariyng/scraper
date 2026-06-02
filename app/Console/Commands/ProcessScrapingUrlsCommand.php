<?php

namespace App\Console\Commands;

use App\Models\ScrapingUrl;
use App\Services\Scrapers\AmazonScraper;
use App\Services\Scrapers\FlipkartScraper;
use App\Services\Scrapers\VijaySalesScraper;
use App\Services\Scrapers\CromaScraper;
use App\Services\Scrapers\RelianceDigitalScraper;
use App\Services\Scrapers\ZeptoScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScrapingUrlsCommand extends Command
{
    protected $signature = 'scraper:process-urls 
                            {platform? : Platform to process (amazon, flipkart, vijaysales, croma, reliancedigital, zepto, all)}
                            {--limit=10 : Number of URLs to process}';

    protected $description = 'Process pending URLs from scraping_urls table';

    public function handle()
    {
        $platform = $this->argument('platform') ?? 'all';
        $limit = (int) $this->option('limit');

        $this->info("Processing scraping URLs...");
        $this->info("Platform: {$platform}");
        $this->info("Limit: {$limit}");
        $this->newLine();

        try {
            $totalProcessed = 0;
            $totalErrors = 0;

            $platforms = ['amazon', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'zepto'];
            
            foreach ($platforms as $plat) {
                if ($platform === 'all' || $platform === $plat) {
                    $stats = $this->processPlatformUrls($plat, $limit);
                    $totalProcessed += $stats['processed'];
                    $totalErrors += $stats['errors'];
                }
            }

            $this->newLine();
            $this->info('URL processing completed!');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['URLs Processed', $totalProcessed],
                    ['Errors', $totalErrors],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error processing URLs: ' . $e->getMessage());
            
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    protected function processPlatformUrls(string $platform, int $limit): array
    {
        $this->info("Processing {$platform} URLs...");

        $urls = ScrapingUrl::getPendingUrls($platform, $limit);
        
        if ($urls->isEmpty()) {
            $this->warn("No pending URLs found for {$platform}");
            return ['processed' => 0, 'errors' => 0];
        }

        $processed = 0;
        $errors = 0;

        foreach ($urls as $scrapingUrl) {
            try {
                $scrapingUrl->markAsProcessing();

                $scraper = $this->getScraper($platform);

                if (!$scraper) {
                    $scrapingUrl->markAsFailed('Scraper not found for platform');
                    $errors++;
                    continue;
                }

                $productData = $this->scrapeUrl($scraper, $scrapingUrl->url, $platform);

                if ($productData && !empty($productData)) {
                    $scrapingUrl->markAsCompleted();
                    $processed++;
                    $this->info("✓ Processed: {$scrapingUrl->url}");
                } else {
                    $scrapingUrl->markAsFailed('No product data extracted');
                    $errors++;
                    $this->warn("✗ Failed: {$scrapingUrl->url}");
                }

                sleep(rand(2, 4));
            } catch (\Exception $e) {
                $scrapingUrl->markAsFailed($e->getMessage());
                $errors++;
                $this->error("✗ Error: {$scrapingUrl->url} - {$e->getMessage()}");
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    protected function getScraper(string $platform)
    {
        return match($platform) {
            'amazon'          => new AmazonScraper(),
            'flipkart'        => new FlipkartScraper(),
            'vijaysales'      => new VijaySalesScraper(),
            'croma'           => new CromaScraper(),
            'reliancedigital' => new RelianceDigitalScraper(),
            'zepto'           => new ZeptoScraper(),
            default           => null,
        };
    }

    protected function scrapeUrl($scraper, string $url, string $platform)
    {
        try {
            Log::channel('scraper')->info("Scraping individual product URL", [
                'platform' => $platform,
                'url' => $url,
            ]);

            $scraper->scrape([$url]);

            return ['success' => true];
        } catch (\Exception $e) {
            Log::channel('scraper')->error("Failed to scrape URL", [
                'platform' => $platform,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
