<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraper_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('scraper_runs', 'scraper_id')) {
                $table->string('scraper_id', 10)->nullable()->unique()->index()->after('id');
            }
            if (!Schema::hasColumn('scraper_runs', 'configuration_id')) {
                $table->unsignedBigInteger('configuration_id')->nullable()->after('scraper_id');
            }
            if (!Schema::hasColumn('scraper_runs', 'category')) {
                $table->string('category', 100)->nullable()->after('platform');
            }
            if (!Schema::hasColumn('scraper_runs', 'tag')) {
                $table->string('tag', 100)->nullable()->after('category');
            }
            if (!Schema::hasColumn('scraper_runs', 'category_url')) {
                $table->text('category_url')->nullable()->after('tag');
            }
            if (!Schema::hasColumn('scraper_runs', 'description')) {
                $table->text('description')->nullable()->after('category_url');
            }
            if (!Schema::hasColumn('scraper_runs', 'reviews_scraped')) {
                $table->integer('reviews_scraped')->default(0)->after('products_scraped');
            }
            if (!Schema::hasColumn('scraper_runs', 'rankings_scraped')) {
                $table->integer('rankings_scraped')->default(0)->after('reviews_scraped');
            }
            if (!Schema::hasColumn('scraper_runs', 'metadata')) {
                $table->json('metadata')->nullable()->after('error_message');
            }
            if (!Schema::hasColumn('scraper_runs', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('metadata');
            }
        });

        // Change triggered_by from bigint → varchar so it holds 'cli', 'manual', 'api'
        $col = DB::selectOne("
            SELECT DATA_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'scraper_runs'
              AND COLUMN_NAME  = 'triggered_by'
        ");
        if ($col && in_array(strtolower($col->DATA_TYPE), ['bigint','int','tinyint','smallint','mediumint'])) {
            DB::statement("ALTER TABLE scraper_runs MODIFY COLUMN triggered_by VARCHAR(100) NOT NULL DEFAULT 'manual'");
        }
    }

    public function down(): void
    {
        Schema::table('scraper_runs', function (Blueprint $table) {
            $cols = ['scraper_id','configuration_id','category','tag','category_url',
                     'description','reviews_scraped','rankings_scraped','metadata','user_id'];
            $toDrop = array_filter($cols, fn($c) => Schema::hasColumn('scraper_runs', $c));
            if (!empty($toDrop)) {
                $table->dropColumn(array_values($toDrop));
            }
        });
    }
};
