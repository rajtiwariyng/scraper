<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraper_runs', function (Blueprint $table) {
            // Allow multiple platform rows to share one batch scraper_id
            $table->dropUnique('scraper_runs_scraper_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('scraper_runs', function (Blueprint $table) {
            $table->unique('scraper_id', 'scraper_runs_scraper_id_unique');
        });
    }
};
