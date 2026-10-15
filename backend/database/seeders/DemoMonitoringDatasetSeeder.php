<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Wrapper so Railway can run:
 * php artisan db:seed --class=DemoMonitoringDatasetSeeder --force
 */
class DemoMonitoringDatasetSeeder extends Seeder
{
    public function run(): void
    {
        $code = Artisan::call('demo:seed-dataset');
        $this->command?->getOutput()?->write(Artisan::output());

        if ($code !== 0) {
            throw new \RuntimeException('demo:seed-dataset failed with exit code '.$code);
        }
    }
}
