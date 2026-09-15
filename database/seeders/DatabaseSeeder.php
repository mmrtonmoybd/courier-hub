<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Safe to run anywhere: it only ensures the five couriers exist. Routing
     * rows come from WebhookTransferSeeder, demo traffic from DemoDataSeeder.
     */
    public function run(): void
    {
        $this->call(CourierSettingSeeder::class);
    }
}
