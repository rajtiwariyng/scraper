<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExportHistory;
use App\Jobs\RunExportJob;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    private array $map = [
        'keywords' => \App\Exports\KeywordsExport::class,
        'products' => \App\Exports\ProductsExport::class,
        'reviews'  => \App\Exports\ReviewsExport::class,
        'rankings' => \App\Exports\RankingsExport::class,
    ];

    public function export(Request $request, string $module)
    {
        abort_unless(isset($this->map[$module]), 404);

        $format = $request->get('format', 'xlsx'); // default excel

        $extension = $format === 'csv' ? 'csv' : 'xlsx';
        $file = "{$module}_" . now()->format('Ymd_His') . ".{$extension}";
        $path = "exports/{$file}";

        $history = ExportHistory::create([
            'admin_id' => auth('admin')->id(),
            'module' => $module,
            'file_name' => $file,
            'file_path' => $path,
            'status' => 'pending',
        ]);

        RunExportJob::dispatch(
            $history,
            $this->map[$module],
            $request->all(),
            $format
        );

        return back()->with('success', strtoupper($format).' export queued successfully');
    }

}
