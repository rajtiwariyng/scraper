<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Models\ProductRanking;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display product listing with enhanced filters
     */
    /**
     * Update include/exclude status
     */
    public function updateIncludeExclude(Request $request, Product $product)
    {
        $request->validate([
            'include_exclude' => 'required|in:include,exclude',
        ]);

        $product->update([
            'include_exclude' => $request->include_exclude,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product status updated successfully',
        ]);
    }

    /**
     * Bulk update include/exclude status
     */
    public function bulkUpdateIncludeExclude(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
            'include_exclude' => 'required|in:include,exclude',
        ]);

        Product::whereIn('id', $request->product_ids)
            ->update(['include_exclude' => $request->include_exclude]);

        return response()->json([
            'success' => true,
            'message' => count($request->product_ids) . ' products updated successfully',
        ]);
    }

    /**
     * Show product details
     */
    public function show(Product $product)
    {
        $product->load(['reviews', 'rankings.keyword']);

        return view('admin.products.show', compact('product'));
    }

    /**
     * Get reviews for a specific product (AJAX)
     */
    public function getReviews(Product $product, Request $request)
    {
        $reviews = $product->reviews()
            ->orderBy('review_date', 'desc')
            ->paginate(20);

        if ($request->ajax()) {
            return response()->json([
                'reviews' => $reviews,
            ]);
        }

        return view('admin.products.reviews', compact('product', 'reviews'));
    }

    /**
     * Get rankings for a specific product (AJAX)
     */
    public function getRankings(Product $product, Request $request)
    {
        $rankings = $product->rankings()
            ->with('keyword')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        if ($request->ajax()) {
            return response()->json([
                'rankings' => $rankings,
            ]);
        }

        return view('admin.products.rankings', compact('product', 'rankings'));
    }

    /**
     * Export products to CSV
     */
    public function export(Request $request)
    {
        $query = Product::query();

        // Apply same filters as index
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }

        if ($request->filled('include_exclude')) {
            $query->where('include_exclude', $request->include_exclude);
        }

        if ($request->filled('scrape_date')) {
            $query->whereDate('scraped_date', $request->scrape_date);
        }

        $products = $query->get();

        $filename = 'products_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($products) {
            $file = fopen('php://output', 'w');
            
            // Headers
            fputcsv($file, [
                'ID', 'Platform', 'SKU', 'Title', 'Brand', 'Price', 'Sale Price',
                'Rating', 'Reviews Count', 'Include/Exclude', 'Last Scraped', 'Created At'
            ]);

            // Data
            foreach ($products as $product) {
                fputcsv($file, [
                    $product->id,
                    $product->platform,
                    $product->sku,
                    $product->title,
                    $product->brand,
                    $product->price,
                    $product->sale_price,
                    $product->rating,
                    $product->reviews_count,
                    $product->include_exclude,
                    $product->scraped_date,
                    $product->created_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
