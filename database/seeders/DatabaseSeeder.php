<?php

namespace Database\Seeders;

use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScraperConfigurationSeeder;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(ScraperConfigurationSeeder::class);
    }
}
