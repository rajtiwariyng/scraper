<?php

namespace App\Console\Commands;

use App\Models\ScraperConfiguration;
use App\Models\ScraperRun;
use Illuminate\Console\Command;
use App\Services\Scrapers\AmazonScraper;
use App\Services\Scrapers\AmazonJpScraper;
use App\Services\Scrapers\AmazonSaScraper;
use App\Services\Scrapers\FlipkartScraper;
use App\Services\Scrapers\VijaySalesScraper;
use App\Services\Scrapers\RelianceDigitalScraper;
use App\Services\Scrapers\CromaScraper;
use App\Services\Scrapers\BigBasketScraper;
use App\Services\Scrapers\BlinkitScraper;
use App\Services\Scrapers\MeeshoScraper;
use App\Services\Scrapers\ZeptoScraper;
use App\Services\DatabaseService;
use Illuminate\Support\Facades\Log;

class ScrapeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'scraper:run 
                            {platform? : Platform to scrape (amazon, amazon_jp, amazon_sa, flipkart, vijaysales, reliancedigital, croma, bigbasket, blinkit, meesho, zepto, all)}
                            {--force : Force scraping even if recently scraped}
                            {--limit=50 : Limit number of products per platform}
                            {--timeout=7200 : Maximum execution time in seconds}';

    /**
     * The console command description.
     */
    protected $description = 'Run laptop data scraper for specified platform(s)';

    protected DatabaseService $databaseService;

    public function __construct(DatabaseService $databaseService)
    {
        parent::__construct();
        $this->databaseService = $databaseService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $platform = $this->argument('platform') ?? 'all';
        $force = $this->option('force');
        $timeout = (int) $this->option('timeout');

        // Set execution time limit
        set_time_limit($timeout);

        $this->info("Starting laptop scraper for platform: {$platform}");
        Log::channel('scraper')->info("Scraper command started", [
            'platform' => $platform,
            'force' => $force,
            'timeout' => $timeout
        ]);

        try {
            if ($platform === 'all') {
                $this->scrapeAllPlatforms($force);
            } else {
                $this->scrapePlatform($platform, $force);
            }

            $this->info("Scraping completed successfully!");
            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Scraping failed: " . $e->getMessage());
            Log::channel('scraper')->error("Scraper command failed", [
                'platform' => $platform,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Scrape all platforms
     */
    protected function scrapeAllPlatforms(bool $force): void
    {
        $platforms = array_keys(config('scraper.platforms', []));
        
        $this->info("Scraping " . count($platforms) . " platforms...");
        
        $progressBar = $this->output->createProgressBar(count($platforms));
        $progressBar->start();

        foreach ($platforms as $platform) {
            try {
                $this->scrapePlatform($platform, $force, false);
                $progressBar->advance();
            } catch (\Exception $e) {
                $this->error("\nFailed to scrape {$platform}: " . $e->getMessage());
                Log::channel('scraper')->error("Platform scraping failed", [
                    'platform' => $platform,
                    'error' => $e->getMessage()
                ]);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine();
    }

    /**
     * Scrape specific platform
     */
    protected function scrapePlatform(string $platform, bool $force, bool $showProgress = true): void
    {
        if (!$force && $this->wasRecentlyScraped($platform)) {
            $this->warn("Platform {$platform} was recently scraped. Use --force to override.");
            return;
        }

        $scraper = $this->createScraper($platform);
        if (!$scraper) {
            throw new \InvalidArgumentException("Unknown platform: {$platform}");
        }

        $categoryUrls = $this->getCategoryUrls($platform);
        if (empty($categoryUrls)) {
            throw new \InvalidArgumentException("No category URLs configured for platform: {$platform}");
        }

        // Create ScraperRun record and assign scraper_id BEFORE scraping
        $scraperId = ScraperRun::generateScraperId();
        $run = ScraperRun::create([
            'scraper_id'   => $scraperId,
            'platform'     => $platform,
            'status'       => 'running',
            'started_at'   => now(),
            'triggered_by' => 'cli',
        ]);

        $scraper->setScraperId($scraperId);

        if ($showProgress) {
            $this->info("Scraping {$platform} [run: {$scraperId}]...");
        }

        try {
            $scraper->scrape($categoryUrls);

            $stats = $scraper->getStats();
            $run->markAsCompleted([
                'products_scraped' => $stats['products_found']     ?? 0,
                'errors_count'     => $stats['errors_count']       ?? 0,
            ]);

            if ($showProgress) {
                $this->info("Completed {$platform}: {$stats['products_found']} products found.");
            }
        } catch (\Exception $e) {
            $run->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Create scraper instance for platform
     */
    protected function createScraper(string $platform): ?\App\Services\Scrapers\BaseScraper
    {
        return match ($platform) {
            'amazon'          => new AmazonScraper(),
            'amazon_jp'       => new AmazonJpScraper(),
            'amazon_sa'       => new AmazonSaScraper(),
            'flipkart'        => new FlipkartScraper(),
            'vijaysales'      => new VijaySalesScraper(),
            'reliancedigital' => new RelianceDigitalScraper(),
            'croma'           => new CromaScraper(),
            'bigbasket'       => new BigBasketScraper(),
            'blinkit'         => new BlinkitScraper(),
            'meesho'          => new MeeshoScraper(),
            'zepto'           => new ZeptoScraper(),
            default           => null,
        };
    }

    /**
     * Get category URLs for platform
     */
    protected function getCategoryUrls(string $platform): array
    {
        $dbUrls = ScraperConfiguration::where('platform', $platform)
            ->where('status', 'active')
            ->pluck('category_url')
            ->toArray();

        if (!empty($dbUrls)) {
            return $dbUrls;
        }

        // Fall back to config file if DB has no active entries for this platform
        $platformConfig = config("scraper.platforms.{$platform}");
        return $platformConfig['category_urls'] ?? [];
    }

    /**
     * Check if platform was recently scraped
     */
    protected function wasRecentlyScraped(string $platform): bool
    {
        $recentLogs = $this->databaseService->getRecentScrapingLogs(1, $platform);
        
        if ($recentLogs->isEmpty()) {
            return false;
        }

        $lastLog = $recentLogs->first();
        $hoursSinceLastScrape = now()->diffInHours($lastLog->created_at);
        
        // Consider recently scraped if less than 12 hours ago
        return $hoursSinceLastScrape < 12;
    }
}

