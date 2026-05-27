<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExportHistory;
use App\Models\Keyword;
use App\Models\Product;
use App\Models\ProductRanking;
use App\Models\Review;
use App\Models\ScraperRun;
use App\Models\ScrapingLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const PLATFORMS = ['amazon', 'amazon_jp', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket', 'zepto'];

    private const PLATFORM_NAMES = [
        'amazon'         => 'Amazon India',
        'amazon_jp'      => 'Amazon Japan',
        'flipkart'       => 'Flipkart',
        'vijaysales'     => 'Vijay Sales',
        'croma'          => 'Croma',
        'reliancedigital'=> 'Reliance Digital',
        'blinkit'        => 'Blinkit',
        'bigbasket'      => 'BigBasket',
        'zepto'          => 'Zepto',
    ];

    public function index(Request $request)
    {
        $days = (int) $request->get('days', 7);
        $since = now()->subDays($days);

        $overview = [
            'totalProducts'    => Product::count(),
            'activeProducts'   => Product::where('is_active', true)->count(),
            'totalPlatforms'   => count(self::PLATFORMS),
            'recentScrapes'    => ScraperRun::where('created_at', '>=', $since)->count(),
            'successfulScrapes'=> ScraperRun::where('status', 'completed')->where('created_at', '>=', $since)->count(),
            'avgPrice'         => Product::where('is_active', true)->avg('price'),
            'topBrand'         => Product::whereNotNull('brand')
                                    ->groupBy('brand')
                                    ->selectRaw('brand, COUNT(*) as cnt')
                                    ->orderByDesc('cnt')
                                    ->first()?->brand,
        ];

        $platformPerformance = [];
        foreach (self::PLATFORMS as $platform) {
            $total   = ScraperRun::where('platform', $platform)->where('created_at', '>=', $since)->count();
            $success = ScraperRun::where('platform', $platform)->where('status', 'completed')->where('created_at', '>=', $since)->count();
            $lastRun = ScraperRun::where('platform', $platform)->orderByDesc('created_at')->first();

            $platformPerformance[$platform] = [
                'name'          => self::PLATFORM_NAMES[$platform] ?? ucfirst($platform),
                'success_rate'  => $total > 0 ? round(($success / $total) * 100, 1) : 0,
                'total_runs'    => $total,
                'total_products'=> Product::where('platform', $platform)->count(),
                'avg_duration'  => ScraperRun::where('platform', $platform)->where('created_at', '>=', $since)->avg('duration_seconds'),
                'last_run'      => $lastRun?->created_at,
            ];
        }

        $chartData = [
            'dailyActivity' => ScrapingLog::selectRaw('DATE(created_at) as date, COUNT(*) as count, status')
                ->where('created_at', '>=', $since)
                ->groupBy('date', 'status')
                ->orderBy('date')
                ->get()
                ->groupBy('date'),
            'platformDistribution' => Product::groupBy('platform')
                ->selectRaw('platform, COUNT(*) as count')
                ->get(),
            'priceRanges' => [
                '< 30k'   => Product::where('price', '<', 30000)->count(),
                '30k-50k' => Product::whereBetween('price', [30000, 50000])->count(),
                '50k-80k' => Product::whereBetween('price', [50000, 80000])->count(),
                '80k-120k'=> Product::whereBetween('price', [80000, 120000])->count(),
                '> 120k'  => Product::where('price', '>', 120000)->count(),
            ],
        ];

        $recentActivity = ScraperRun::orderByDesc('created_at')->limit(10)->get();
        $recentRuns     = ScraperRun::orderByDesc('created_at')->limit(10)->get();
        $systemHealth   = $this->getSystemHealth();

        return view('admin.dashboard', compact(
            'days',
            'overview',
            'systemHealth',
            'platformPerformance',
            'chartData',
            'recentActivity',
            'recentRuns'
        ));
    }

    public function platform(Request $request, string $platform)
    {
        $days  = (int) $request->get('days', 7);
        $since = now()->subDays($days);

        $platformName = self::PLATFORM_NAMES[$platform] ?? ucfirst($platform);

        $platformConfigRaw = config("scraper.platforms.{$platform}", []);
        $platformConfig = [
            'base_url'      => $platformConfigRaw['base_url'] ?? '#',
            'category_urls' => $platformConfigRaw['category_urls'] ?? [],
            'enabled'       => $platformConfigRaw['enabled'] ?? true,
        ];

        $stats = [
            'total_products'       => Product::where('platform', $platform)->count(),
            'active_products'      => Product::where('platform', $platform)->where('is_active', true)->count(),
            'avg_price'            => Product::where('platform', $platform)->avg('price') ?? 0,
            'min_price'            => Product::where('platform', $platform)->min('price') ?? 0,
            'max_price'            => Product::where('platform', $platform)->max('price') ?? 0,
            'avg_rating'           => Product::where('platform', $platform)->whereNotNull('rating')->avg('rating') ?? 0,
            'products_with_rating' => Product::where('platform', $platform)->whereNotNull('rating')->count(),
            'brands_count'         => Product::where('platform', $platform)->whereNotNull('brand')->distinct('brand')->count('brand'),
            'last_scrape'          => ScraperRun::where('platform', $platform)->max('created_at'),
        ];

        $recentLogs = ScraperRun::where('platform', $platform)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get();

        $products = Product::where('platform', $platform)
            ->orderByDesc('updated_at')
            ->paginate(20);

        $chartData = [
            'dailyChanges' => ScraperRun::where('platform', $platform)
                ->where('created_at', '>=', $since)
                ->selectRaw('DATE(created_at) as date,
                    SUM(COALESCE(products_scraped, 0)) as added,
                    0 as updated')
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
            'brandDistribution' => Product::where('platform', $platform)
                ->whereNotNull('brand')
                ->selectRaw('brand, COUNT(*) as count')
                ->groupBy('brand')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
        ];

        return view('admin.platform', compact(
            'platform', 'platformName', 'days',
            'stats', 'platformConfig',
            'recentLogs', 'products', 'chartData'
        ));
    }

    public function logs(Request $request)
    {
        $query = ScrapingLog::orderByDesc('created_at');

        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('days')) {
            $query->where('created_at', '>=', now()->subDays((int) $request->days));
        }

        $logs      = $query->paginate(50)->withQueryString();
        $platforms = ['amazon', 'amazon_jp', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket', 'zepto'];
        $filters   = $request->only(['platform', 'status', 'days']);

        return view('admin.logs', compact('logs', 'platforms', 'filters'));
    }

    public function apiStats(Request $request)
    {
        return response()->json([
            'total_products'  => Product::count(),
            'total_reviews'   => Review::count(),
            'total_keywords'  => Keyword::count(),
            'total_rankings'  => ProductRanking::count(),
            'recent_runs'     => ScraperRun::orderByDesc('created_at')->limit(5)->get(),
            'timestamp'       => now()->toISOString(),
        ]);
    }

    public function products(Request $request)
    {
        $query = Product::query();

        $filters = $request->only(['platform', 'brand', 'status', 'search', 'sort', 'order']);

        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }
        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        $sortCol = in_array($request->get('sort'), ['price', 'rating', 'created_at', 'updated_at'])
            ? $request->get('sort')
            : 'updated_at';
        $sortDir = $request->get('order') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortCol, $sortDir);

        $products  = $query->paginate(50);
        $platforms = Product::select('platform')->distinct()->pluck('platform');
        $brands    = Product::select('brand')->distinct()->whereNotNull('brand')->pluck('brand');
        $exports   = ExportHistory::orderByDesc('created_at')->limit(10)->get();

        return view('admin.products', compact('products', 'filters', 'platforms', 'brands', 'exports'));
    }

    protected function getSystemHealth(): array
    {
        $health = [
            'database'       => 'healthy',
            'recentActivity' => 'healthy',
            'dataFreshness'  => 'healthy',
            'errorRate'      => 'healthy',
        ];

        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $health['database'] = 'error';
        }

        if (ScrapingLog::where('created_at', '>=', now()->subHours(48))->count() === 0) {
            $health['recentActivity'] = 'warning';
        }

        $total = Product::count();
        if ($total > 0) {
            $fresh = Product::where('scraped_date', '>=', now()->subDays(3))->count();
            $pct   = ($fresh / $total) * 100;
            $health['dataFreshness'] = $pct < 50 ? 'error' : ($pct < 80 ? 'warning' : 'healthy');
        }

        $logs = ScrapingLog::where('created_at', '>=', now()->subDays(7))->count();
        if ($logs > 0) {
            $failed = ScrapingLog::where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count();
            $rate   = ($failed / $logs) * 100;
            $health['errorRate'] = $rate > 30 ? 'error' : ($rate > 10 ? 'warning' : 'healthy');
        }

        return $health;
    }
}
