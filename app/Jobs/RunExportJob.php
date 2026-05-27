<?php

namespace App\Jobs;

use App\Models\ExportHistory;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Maatwebsite\Excel\Excel;

class RunExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ExportHistory $history,
        public string $exportClass,
        public array $filters = [],
        public string $format = 'xlsx'
    ) {}

    public function handle(): void
{
    $this->history->update(['status' => 'processing']);

    $writerType = $this->format === 'csv'
        ? Excel::CSV
        : Excel::XLSX;

    ExcelFacade::store(
        new $this->exportClass($this->filters),
        $this->history->file_path,
        'public',
        $writerType
    );

    $this->history->update(['status' => 'completed']);
}


}
