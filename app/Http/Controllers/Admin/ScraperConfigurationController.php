<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScraperConfiguration;
use Illuminate\Http\Request;

class ScraperConfigurationController extends Controller
{
    private const PLATFORMS = [
        'amazon'          => 'Amazon India',
        'amazon_jp'       => 'Amazon Japan',
        'flipkart'        => 'Flipkart',
        'vijaysales'      => 'Vijay Sales',
        'croma'           => 'Croma',
        'reliancedigital' => 'Reliance Digital',
        'blinkit'         => 'Blinkit',
        'bigbasket'       => 'BigBasket',
        'zepto'           => 'Zepto',
    ];

    public function index(Request $request)
    {
        $filterPlatform = $request->get('platform');
        $filterStatus   = $request->get('status');

        $query = ScraperConfiguration::query()->orderBy('platform')->orderBy('category');

        if ($filterPlatform) {
            $query->where('platform', $filterPlatform);
        }
        if ($filterStatus) {
            $query->where('status', $filterStatus);
        }

        $configs = $query->paginate(50);

        $stats = [
            'total'    => ScraperConfiguration::count(),
            'active'   => ScraperConfiguration::where('status', 'active')->count(),
            'inactive' => ScraperConfiguration::where('status', 'inactive')->count(),
        ];

        return view('admin.scraper-config.index', compact('configs', 'stats', 'filterPlatform', 'filterStatus'));
    }

    public function create()
    {
        return view('admin.scraper-config.create', [
            'platforms' => self::PLATFORMS,
            'config'    => null,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'platform'     => 'required|in:' . implode(',', array_keys(self::PLATFORMS)),
            'category_url' => 'required|url|max:2000',
            'category'     => 'nullable|string|max:100',
            'status'       => 'required|in:active,inactive',
        ]);

        $category = $request->filled('category')
            ? $request->category
            : $this->inferCategory($request->category_url);

        $exists = ScraperConfiguration::where('platform', $request->platform)
            ->where('category_url', $request->category_url)
            ->exists();

        if ($exists) {
            return back()->withInput()->with('error', 'This URL already exists for the selected platform.');
        }

        ScraperConfiguration::create([
            'platform'     => $request->platform,
            'category_url' => $request->category_url,
            'category'     => $category,
            'status'       => $request->status,
        ]);

        return redirect()->route('admin.scraper-config.index')
            ->with('success', 'Scraper URL added successfully.');
    }

    public function edit(ScraperConfiguration $scraperConfig)
    {
        return view('admin.scraper-config.create', [
            'platforms' => self::PLATFORMS,
            'config'    => $scraperConfig,
        ]);
    }

    public function update(Request $request, ScraperConfiguration $scraperConfig)
    {
        $request->validate([
            'platform'     => 'required|in:' . implode(',', array_keys(self::PLATFORMS)),
            'category_url' => 'required|url|max:2000',
            'category'     => 'nullable|string|max:100',
            'status'       => 'required|in:active,inactive',
        ]);

        $category = $request->filled('category')
            ? $request->category
            : $this->inferCategory($request->category_url);

        $duplicate = ScraperConfiguration::where('platform', $request->platform)
            ->where('category_url', $request->category_url)
            ->where('id', '!=', $scraperConfig->id)
            ->exists();

        if ($duplicate) {
            return back()->withInput()->with('error', 'This URL already exists for the selected platform.');
        }

        $scraperConfig->update([
            'platform'     => $request->platform,
            'category_url' => $request->category_url,
            'category'     => $category,
            'status'       => $request->status,
        ]);

        return redirect()->route('admin.scraper-config.index')
            ->with('success', 'Scraper URL updated successfully.');
    }

    public function destroy(ScraperConfiguration $scraperConfig)
    {
        $scraperConfig->delete();

        return redirect()->route('admin.scraper-config.index')
            ->with('success', 'Scraper URL deleted.');
    }

    public function toggleStatus(ScraperConfiguration $scraperConfig)
    {
        $scraperConfig->update([
            'status' => $scraperConfig->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', 'Status updated.');
    }

    private function inferCategory(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
        $k = $params['k'] ?? $params['q'] ?? null;
        return $k ? urldecode(str_replace('+', ' ', $k)) : 'general';
    }
}
