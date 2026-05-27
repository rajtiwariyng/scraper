<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('scraper:run all')
         ->twiceDaily(2, 14) // Run at 2 AM and 2 PM
         ->withoutOverlapping(7200) // Prevent overlap, max 2 hours
         ->runInBackground()
         ->emailOutputOnFailure(config('mail.admin_email'))
         ->appendOutputTo(storage_path('logs/scraper-schedule.log'));

// Alternative schedule option - every 48 hours exactly
// Schedule::command('scraper:run all')
//          ->cron('0 2 */2 * *') // Every 2 days at 2 AM
//          ->withoutOverlapping(7200)
//          ->runInBackground();

// Cleanup old data weekly
// Schedule::command('scraper:cleanup')
//          ->weekly()
//          ->sundays()
//          ->at('03:00')
//          ->appendOutputTo(storage_path('logs/cleanup.log'));

// Generate daily status report
Schedule::command('scraper:status --detailed')
         ->daily()
         ->at('06:00')
         ->appendOutputTo(storage_path('logs/daily-status.log'));

// Health check every hour
Schedule::command('scraper:status')
         ->hourly()
         ->between('8:00', '22:00') // Only during business hours
         ->appendOutputTo(storage_path('logs/health-check.log'));

// Platform-specific schedules (if needed for different intervals)

// Amazon - more frequent due to high volume
Schedule::command('scraper:run amazon')
         ->weekly()
         ->at('01:00')
         ->withoutOverlapping(3600)
         ->when(function () {
             return config('scraper.platforms.amazon.enabled', true);
         });

// Flipkart - daily
Schedule::command('scraper:run flipkart')
         ->weekly()
         ->at('03:00')
         ->withoutOverlapping(3600)
         ->when(function () {
             return config('scraper.platforms.flipkart.enabled', true);
         });

// Other platforms - every 2 days
Schedule::command('scraper:run vijaysales')
         ->weekly(1, 4, '05:00') // Monday and Thursday
         ->withoutOverlapping(1800);

Schedule::command('scraper:run reliancedigital')
         ->weekly(2, 5, '05:00') // Tuesday and Friday
         ->withoutOverlapping(1800);

Schedule::command('scraper:run croma')
         ->weekly(3, 6, '05:00') // Wednesday and Saturday
         ->withoutOverlapping(1800);

// Emergency cleanup if disk space is low
// Schedule::call(function () {
//     $diskUsage = disk_free_space('/') / disk_total_space('/');
//     if ($diskUsage < 0.1) { // Less than 10% free space
//         \Artisan::call('scraper:cleanup', ['--logs' => 7, '--inactive' => 30]);
//     }
// })->monthly();
