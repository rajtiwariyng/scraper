<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProductComparisonService;
use Illuminate\Http\Request;

class ComparisonController extends Controller
{
    protected $comparisonService;

    public function __construct(ProductComparisonService $comparisonService)
    {
        $this->comparisonService = $comparisonService;
    }

    public function index(Request $request)
    {
        $platform = $request->get('platform');
        $runs = $this->comparisonService->getRecentRuns($platform);

        $platforms = \App\Models\Product::distinct()->pluck('platform');

        return view('admin.comparison.index', compact('runs', 'platforms'));
    }

    public function compare(Request $request)
    {
        $request->validate([
            'current_run' => 'required|exists:scraper_runs,scraper_id',
            'previous_run' => 'required|exists:scraper_runs,scraper_id',
        ]);

        $comparison = $this->comparisonService->compareRuns(
            $request->current_run,
            $request->previous_run
        );

        return view('admin.comparison.show', $comparison);
    }
}
