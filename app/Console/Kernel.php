<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * One platform per day at 02:00 (or 14:00 for weekend doubles), so
     * proxy/IP load is spread across the week and runs never overlap. Each
     * platform's `withoutOverlapping(43200)` guard caps a stuck run at 12 h.
     * `->when(...)` lets ops disable a platform via
     * `config/scraper.php → platforms.<name>.enabled = false`.
     */
    protected function schedule(Schedule $schedule): void
    {
        // ────────── Weekly product (PDP) scrape, one platform per slot ──────────
        $platformSlots = [
            // [platform key, day-of-week (0 = Sunday), HH:MM]
            ['amazon',          1, '02:00'],   // Monday
            ['flipkart',        2, '02:00'],   // Tuesday
            ['croma',           3, '02:00'],   // Wednesday
            ['vijaysales',      4, '02:00'],   // Thursday
            ['reliancedigital', 5, '02:00'],   // Friday
            ['zepto',           6, '02:00'],   // Saturday
        ];

        foreach ($platformSlots as [$platform, $dayOfWeek, $time]) {
            $schedule->command("scraper:run {$platform}")
                     ->weeklyOn($dayOfWeek, $time)
                     ->withoutOverlapping(43200)
                     ->runInBackground()
                     ->when(fn () => config("scraper.platforms.{$platform}.enabled", true))
                     ->appendOutputTo(storage_path('logs/scraper-schedule.log'));
        }

        // ────────── House-keeping ──────────
        $schedule->command('scraper:cleanup')
                 ->weekly()->sundays()->at('03:00')
                 ->appendOutputTo(storage_path('logs/cleanup.log'));

        $schedule->command('scraper:status --detailed')
                 ->daily()->at('06:00')
                 ->appendOutputTo(storage_path('logs/daily-status.log'));

        $schedule->command('scraper:status')
                 ->hourly()->between('8:00', '22:00')
                 ->appendOutputTo(storage_path('logs/health-check.log'));

        // Emergency cleanup when free disk space drops below 10 %.
        $schedule->call(function () {
            $diskRoot = base_path();
            $free = @disk_free_space($diskRoot);
            $total = @disk_total_space($diskRoot);
            if ($free && $total && ($free / $total) < 0.1) {
                \Artisan::call('scraper:cleanup', ['--logs' => 7, '--inactive' => 30]);
            }
        })->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    /**
     * Get the timezone that should be used by default for scheduled events.
     */
    protected function scheduleTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }
}

