<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Courier;
use App\Models\CourierSetting;
use Illuminate\Database\Seeder;

final class CourierSettingSeeder extends Seeder
{
    /**
     * Seeds one row per supported courier, pre-filled with the courier-wide
     * default secret. A site that owns its merchant account overrides this on
     * its own `webhook_transfers` row.
     */
    public function run(): void
    {
        foreach (Courier::cases() as $courier) {
            CourierSetting::query()->updateOrCreate(
                ['courier' => $courier->value],
                [
                    'secret' => config("couriers.{$courier->value}.ack.default_secret"),
                    'is_active' => true,
                ],
            );
        }
    }
}
