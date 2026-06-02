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
use App\Services\Scrapers\ZeptoScraper;
use App\Services\DatabaseService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ScrapeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'scraper:run
                            {platform? : Platform to scrape (amazon, amazon_jp, amazon_sa, flipkart, vijaysales, reliancedigital, croma, bigbasket, blinkit, meesho, zepto, all)}
                            {--force : Force scraping even if recently scraped}
                            {--limit=0 : Limit number of products per platform (0 = unlimited)}
                            {--timeout=0 : Maximum execution time in seconds (0 = unlimited)}
                            {--scraper-id= : Shared batch scraper ID (auto-generated for "all" runs)}';

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
        $platform  = $this->argument('platform') ?? 'all';
        $force     = $this->option('force');
        $limit     = (int) $this->option('limit');
        $timeout   = (int) $this->option('timeout');
        $scraperId = $this->option('scraper-id') ?: null;

        // Set execution time limit
        set_time_limit($timeout);

        $this->info("Starting laptop scraper for platform: {$platform}");
        Log::channel('scraper')->info("Scraper command started", [
            'platform'   => $platform,
            'force'      => $force,
            'limit'      => $limit,
            'timeout'    => $timeout,
            'scraper_id' => $scraperId,
        ]);

        try {
            if ($platform === 'all') {
                $this->scrapeAllPlatforms($force, $limit);
            } else {
                $this->scrapePlatform($platform, $force, true, $limit, $scraperId);
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
    protected function scrapeAllPlatforms(bool $force, int $limit = 0): void
    {
        $platforms = array_keys(config('scraper.platforms', []));

        // One shared scraper_id for every platform in this batch
        $batchScraperId = ScraperRun::generateScraperId();

        $this->info("Launching " . count($platforms) . " platform scrapers in parallel...");
        $this->info("Batch scraper ID: {$batchScraperId}");

        // Build the base artisan command arguments shared by every child
        $baseArgs = [];
        if ($force)  $baseArgs[] = '--force';
        $baseArgs[]  = '--limit=' . $limit;
        $baseArgs[]  = '--scraper-id=' . $batchScraperId;

        // Spawn one child process per platform
        $processes = [];
        foreach ($platforms as $platform) {
            $cmd = array_merge([PHP_BINARY, 'artisan', 'scraper:run', $platform], $baseArgs);
            $process = new Process($cmd, base_path());
            $process->setTimeout(null); // execution-time limit is handled inside each child
            $process->start();
            $processes[$platform] = $process;
            $this->line("  [started] {$platform} (pid {$process->getPid()})");
        }

        $done    = 0;
        $total   = count($processes);
        $failed  = [];
        $running = $processes;

        // Poll every 3 seconds until all children finish
        while (!empty($running)) {
            sleep(3);
            foreach ($running as $platform => $process) {
                if ($process->isRunning()) {
                    continue;
                }
                unset($running[$platform]);
                $done++;
                if ($process->isSuccessful()) {
                    $this->info("  [done {$done}/{$total}] {$platform}");
                } else {
                    $failed[] = $platform;
                    $errLine  = trim(last(array_filter(explode("\n", $process->getErrorOutput() ?: $process->getOutput()))) ?: 'unknown error');
                    $this->error("  [failed {$done}/{$total}] {$platform}: {$errLine}");
                    Log::channel('scraper')->error("Parallel scraper child failed", [
                        'platform' => $platform,
                        'exit_code' => $process->getExitCode(),
                        'error' => $errLine,
                    ]);
                }
            }
        }

        if (!empty($failed)) {
            $this->error("Failed platforms: " . implode(', ', $failed));
        }
    }

    /**
     * Scrape specific platform
     */
    protected function scrapePlatform(string $platform, bool $force, bool $showProgress = true, int $limit = 0, ?string $scraperId = null): void
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

        // Use provided batch scraper_id (from "all" run) or generate a fresh one
        $scraperId = $scraperId ?? ScraperRun::generateScraperId();
        $run = ScraperRun::create([
            'scraper_id'   => $scraperId,
            'platform'     => $platform,
            'status'       => 'running',
            'started_at'   => now(),
            'triggered_by' => 'cli',
        ]);

        $scraper->setScraperId($scraperId);

        if ($limit > 0) {
            $scraper->setMaxProducts($limit);
        }

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

