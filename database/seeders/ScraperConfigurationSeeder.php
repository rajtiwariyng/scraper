<?php

namespace Database\Seeders;

use App\Models\ScraperConfiguration;
use Illuminate\Database\Seeder;

class ScraperConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $platforms = config('scraper.platforms', []);

        foreach ($platforms as $platformKey => $platformConfig) {
            $urls = $platformConfig['category_urls'] ?? [];

            foreach ($urls as $url) {
                $category = $this->inferCategory($url);

                ScraperConfiguration::firstOrCreate(
                    ['platform' => $platformKey, 'category_url' => $url],
                    ['category' => $category, 'status' => 'active']
                );
            }
        }

        $this->command->info('Scraper configurations seeded from config/scraper.php.');
    }

    private function inferCategory(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
        $k = $params['k'] ?? null;
        return $k ? urldecode(str_replace('+', ' ', $k)) : 'general';
    }
}
