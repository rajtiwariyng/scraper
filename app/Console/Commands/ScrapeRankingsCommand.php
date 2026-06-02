<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Scrapers\AmazonRankingScraper;
use App\Services\Scrapers\AmazonJpRankingScraper;
use App\Services\Scrapers\FlipkartRankingScraper;
use Illuminate\Console\Command;

class ScrapeRankingsCommand extends Command
{
    protected $signature = 'scraper:rankings
                        {platform : Platform to scrape (amazon, amazon_jp, flipkart, all)}
                        {--keyword-ids=* : Specific keyword IDs to scrape}
                        {--scraper-id= : Scraper batch ID (auto-generated if not provided)}';

    protected $description = 'Scrape product rankings for keywords';

    protected function getNextScraperId(): string
    {
        if ($id = $this->option('scraper-id')) {
            return $id;
        }
        $max = Product::max('scraper_id');
        $next = $max ? ((int) $max) + 1 : 1000000044;
        return (string) $next;
    }

    protected function scrapeAllRankingPlatformsInParallel(string $scraperId): void
    {
        $platforms = ['amazon', 'amazon_jp', 'flipkart'];

        $this->info("Launching " . count($platforms) . " ranking scrapers in parallel (scraper_id: {$scraperId})...");

        $processes = [];
        foreach ($platforms as $p) {
            $cmd = PHP_BINARY . ' ' . base_path('artisan') . ' scraper:rankings ' . escapeshellarg($p)
                . ' --scraper-id=' . escapeshellarg($scraperId);

            $process = proc_open(
                $cmd,
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes
            );

            if (is_resource($process)) {
                $processes[$p] = $process;
                $this->info("Started rankings: {$p}");
            } else {
                $this->error("Failed to start subprocess for rankings: {$p}");
            }
        }

        $this->info("Waiting for all ranking scrapers to complete...");

        foreach ($processes as $p => $process) {
            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                $this->warn("Rankings for {$p} exited with code {$exitCode}");
            } else {
                $this->info("Finished rankings: {$p}");
            }
        }

        $this->info("All ranking platforms complete.");
    }

    public function handle()
    {
        $platform = $this->argument('platform');
        $keywordIds = $this->option('keyword-ids');
        $scraperId = $this->getNextScraperId();

        $this->info("Starting ranking scraping for: {$platform} (scraper_id: {$scraperId})");
        $this->newLine();

        try {
            // Parallel path for all platforms
            if ($platform === 'all') {
                $this->scrapeAllRankingPlatformsInParallel($scraperId);
                return Command::SUCCESS;
            }

            // Single-platform synchronous path
            $stats = [];

            if ($platform === 'amazon') {
                $this->info('Scraping Amazon rankings...');
                $scraper = new AmazonRankingScraper();
                $scraper->setScraperId($scraperId);
                $stats['amazon'] = $scraper->scrapeRankings($keywordIds ?: null);
            }

            if ($platform === 'amazon_jp') {
                $this->info('Scraping Amazon Japan rankings...');
                $scraper = new AmazonJpRankingScraper();
                $scraper->setScraperId($scraperId);
                $stats['amazon_jp'] = $scraper->scrapeRankings($keywordIds ?: null);
            }

            if ($platform === 'flipkart') {
                $this->info('Scraping Flipkart rankings...');
                $scraper = new FlipkartRankingScraper();
                $scraper->setScraperId($scraperId);
                $stats['flipkart'] = $scraper->scrapeRankings($keywordIds ?: null);
            }

            $this->newLine();
            $this->info('Ranking scraping completed!');
            $this->newLine();

            // Display statistics
            foreach ($stats as $plat => $stat) {
                $this->info(strtoupper($plat) . ' Statistics:');
                $this->table(
                    ['Metric', 'Count'],
                    [
                        ['Keywords Processed', $stat['keywords_processed']],
                        ['Products Found', $stat['products_found']],
                        ['Rankings Recorded', $stat['rankings_recorded']],
                        ['Errors', $stat['errors_count']],
                    ]
                );
                $this->newLine();
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during ranking scraping: ' . $e->getMessage());

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
